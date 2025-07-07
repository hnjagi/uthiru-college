<?php
require '../includes/db_connect.php';

// Redirect if not logged in or not an admin/clerk
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../index.php");
    exit;
}

// Fetch logged-in administrator's name for printing
$admin_stmt = $pdo->prepare("SELECT first_name, surname FROM administrators WHERE id = ?");
$admin_stmt->execute([$_SESSION['user_id']]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);
$admin_name = $admin ? htmlspecialchars($admin['first_name'] . ' ' . $admin['surname']) : 'Unknown Admin';

// Determine student ID and level ID from GET parameters
$selected_level_id = isset($_GET['level_id']) ? filter_var($_GET['level_id'], FILTER_VALIDATE_INT) : null;
$selected_student_id = isset($_GET['selected_student_id']) ? filter_var($_GET['selected_student_id'], FILTER_VALIDATE_INT) : null;

if ($selected_level_id === false || $selected_level_id <= 0) {
    $selected_level_id = null;
}
if ($selected_student_id === false || $selected_student_id <= 0) {
    $selected_student_id = null;
}

$error = '';
$student = null;
$enrollment = null;
$attendance = [];
$payments = [];
$invoices = [];
$balance = 0;
$units = [];
$makeup_units = [];

// Fetch student details if student_id is set
if ($selected_student_id) {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS full_name,
               s.is_catchup,
               s.catchup_center
        FROM students s 
        WHERE s.id = ? AND s.deleted_at IS NULL
    ");
    $stmt->execute([$selected_student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $error = "Student not found or has been deleted.";
    } else {
        // Fetch current enrollment
        $stmt = $pdo->prepare("
            SELECT e.*, l.name AS level_name, p.name AS program_name 
            FROM enrollments e 
            JOIN levels l ON e.level_id = l.id 
            JOIN programs p ON e.program_id = p.id 
            WHERE e.student_id = ? AND e.academic_year = YEAR(CURDATE())
            ORDER BY e.enrolled_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$selected_student_id]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch all units for the level (for Graduation Readiness, only for non-catch-up students)
        $level_id = $enrollment ? $enrollment['level_id'] : null;
        if ($level_id && !$student['is_catchup']) { // Skip for catch-up students
            $stmt = $pdo->prepare("
                SELECT u.id AS unit_id, u.name AS unit_name
                FROM units u
                WHERE u.level_id = ?
            ");
            $stmt->execute([$level_id]);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch attendance, assignment, and payment status for each unit
            foreach ($units as &$unit) {
                // Date Attended (check catchups first, then regular attendance)
                $stmt = $pdo->prepare("
                    SELECT catchup_date
                    FROM catchups c
                    WHERE c.student_id = ? AND c.unit_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$selected_student_id, $unit['unit_id']]);
                $unit['date_attended'] = $stmt->fetchColumn();
                $unit['is_makeup'] = $unit['date_attended'] ? true : false;

                if (!$unit['date_attended']) {
                    $stmt = $pdo->prepare("
                        SELECT cs.session_date
                        FROM attendance a
                        JOIN class_sessions cs ON a.session_id = cs.id
                        WHERE a.student_id = ? AND cs.unit_id = ? AND cs.academic_year = YEAR(CURDATE())
                        LIMIT 1
                    ");
                    $stmt->execute([$selected_student_id, $unit['unit_id']]);
                    $unit['date_attended'] = $stmt->fetchColumn() ?: 'Not Attended';
                }

                // Date Assignment Submitted
                $stmt = $pdo->prepare("
                    SELECT submission_date
                    FROM assignments a
                    WHERE a.student_id = ? AND a.unit_id = ? AND a.submission_date IS NOT NULL AND a.deleted_at IS NULL
                    LIMIT 1
                ");
                $stmt->execute([$selected_student_id, $unit['unit_id']]);
                $unit['date_assignment_submitted'] = $stmt->fetchColumn() ?: 'Not Submitted';

                // Amount Paid (check invoices and payments for this unit)
                $stmt = $pdo->prepare("
                    SELECT i.id, i.amount_due
                    FROM invoices i
                    JOIN class_sessions cs ON i.session_id = cs.id
                    WHERE i.student_id = ? AND cs.unit_id = ?
                ");
                $stmt->execute([$selected_student_id, $unit['unit_id']]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                $unit['amount_due'] = $invoice ? (float)$invoice['amount_due'] : 0;
                $unit['amount_paid'] = 0;
                if ($invoice) {
                    $stmt = $pdo->prepare("
                        SELECT SUM(amount_paid) AS total_paid
                        FROM payments p
                        WHERE p.invoice_id = ?
                    ");
                    $stmt->execute([$invoice['id']]);
                    $unit['amount_paid'] = (float)$stmt->fetchColumn();
                }
            }
            unset($unit);

            // Separate make-up units
            $makeup_units = [];
            $regular_units = [];
            foreach ($units as $unit) {
                if ($unit['is_makeup']) {
                    $makeup_units[] = $unit;
                } else {
                    $regular_units[] = $unit;
                }
            }
            $units = $regular_units; // Update units to exclude make-up units
        }

        // Fetch attendance with marks (updated to focus on marks only)
        $stmt = $pdo->prepare("
            SELECT DISTINCT cs.unit_id, u.name AS unit_name,
                   (SELECT mark FROM marks m WHERE m.student_id = ? AND m.unit_id = cs.unit_id AND m.deleted_at IS NULL LIMIT 1) AS mark
            FROM attendance a
            JOIN class_sessions cs ON a.session_id = cs.id
            JOIN units u ON cs.unit_id = u.id
            WHERE a.student_id = ? AND cs.academic_year = YEAR(CURDATE())
        ");
        $stmt->execute([$selected_student_id, $selected_student_id]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch payments
        $stmt = $pdo->prepare("
            SELECT p.*, r.receipt_number, r.balance_bf, r.balance_cf, i.session_id
            FROM payments p
            LEFT JOIN receipts r ON p.id = r.payment_id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE p.student_id = ?
            ORDER BY p.payment_date DESC
        ");
        $stmt->execute([$selected_student_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch invoices (for Invoices Status)
        $stmt = $pdo->prepare("
            SELECT i.*, cs.session_date, u.name AS unit_name
            FROM invoices i
            LEFT JOIN class_sessions cs ON i.session_id = cs.id
            LEFT JOIN units u ON cs.unit_id = u.id
            WHERE i.student_id = ?
            ORDER BY i.invoice_date DESC
        ");
        $stmt->execute([$selected_student_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add Amount Paid to each invoice
        foreach ($invoices as &$invoice) {
            $stmt = $pdo->prepare("
                SELECT SUM(amount_paid) AS total_paid
                FROM payments p
                WHERE p.invoice_id = ?
            ");
            $stmt->execute([$invoice['id']]);
            $invoice['amount_paid'] = (float)$stmt->fetchColumn();
        }
        unset($invoice);

        // Calculate balance
        $total_due = array_sum(array_column($invoices, 'amount_due'));
        $total_paid = array_sum(array_column($payments, 'amount_paid'));
        $balance = $total_due - $total_paid;
    }
}

// Handle section visibility (default to true)
$show_biodata = isset($_POST['show_biodata']) ? (bool)$_POST['show_biodata'] : true;
$show_attendance = isset($_POST['show_attendance']) ? (bool)$_POST['show_attendance'] : true;
$show_payments = isset($_POST['show_payments']) ? (bool)$_POST['show_payments'] : true;

// Handle thermal printer statement
if (isset($_GET['print_statement']) && $selected_student_id && $student) {
    try {
        // ESC/POS commands for 80mm thermal printer
        $esc = "\x1B";
        $gs = "\x1D";
        $nl = "\n";

        // Receipt content
        $receipt = $esc . "@"; // Initialize printer
        $receipt .= $esc . "a1"; // Center align
        $receipt .= "NPBC IGAC Uthiru Center\n";
        $receipt .= "STUDENT STATEMENT\n";
        $receipt .= "----------------------------------------\n";
        $receipt .= $esc . "a0"; // Left align
        $receipt .= "Date: " . date('Y-m-d H:i:s') . $nl;
        $receipt .= "Student: " . htmlspecialchars($student['full_name']) . $nl;
        $receipt .= "Reg No: " . htmlspecialchars($student['reg_number']) . $nl;

        if ($show_biodata) {
            $receipt .= "----------------------------------------\n";
            $receipt .= "Biodata:\n";
            $receipt .= "  Level: " . htmlspecialchars($enrollment['level_name'] ?? 'Not Enrolled') . $nl;
            $receipt .= "  Year Joined: " . htmlspecialchars($student['year_joined']) . $nl;
            $receipt .= "  Phone: " . htmlspecialchars($student['phone_number']) . $nl;
            $receipt .= "  Email: " . htmlspecialchars($student['email'] ?? 'N/A') . $nl;
        }

        if ($show_attendance) {
            $receipt .= "----------------------------------------\n";
            $receipt .= "Units Attended:\n";
            if (empty($attendance)) {
                $receipt .= "  None\n";
            } else {
                foreach ($attendance as $record) {
                    $receipt .= "  " . htmlspecialchars($record['unit_name']) . " (Mark: " . htmlspecialchars($record['mark'] ?? 'N/A') . ")\n";
                }
            }
        }

        if ($show_payments) {
            $receipt .= "----------------------------------------\n";
            $receipt .= "Financial Summary:\n";
            $receipt .= "  Total Paid: KES " . number_format($total_paid, 2) . $nl;
            $receipt .= "  Total Due: KES " . number_format($total_due, 2) . $nl;
            $receipt .= "  Balance: KES " . number_format($balance, 2) . " (" . ($balance > 0 ? 'Due' : ($balance < 0 ? 'Prepayment' : 'Cleared')) . ")\n";
        }

        // Add Graduation Readiness Summary (only for non-catch-up students)
        if (!$student['is_catchup']) {
            $receipt .= "----------------------------------------\n";
            $receipt .= "Graduation Readiness:\n";
            $receipt .= "----------------------------------------\n";
            $receipt .= "Regular Units:\n";
            if (empty($units)) {
                $receipt .= "  None\n";
            } else {
                $unit_counter = 1;
                foreach ($units as $unit) {
                    $receipt .= "  " . $unit_counter++ . ". " . htmlspecialchars($unit['unit_name']) . "\n";
                    $receipt .= "     Attended: " . htmlspecialchars($unit['date_attended']) . "\n";
                    $receipt .= "     Assignment: " . htmlspecialchars($unit['date_assignment_submitted']) . "\n";
                    $receipt .= "     Paid: " . number_format($unit['amount_paid'], 2) . "\n";
                }
            }

            if (!empty($makeup_units)) {
                $receipt .= "----------------------------------------\n";
                $receipt .= "Make-up Units:\n";
                $unit_counter = 1;
                foreach ($makeup_units as $unit) {
                    $receipt .= "  " . $unit_counter++ . ". " . htmlspecialchars($unit['unit_name']) . "\n";
                    $receipt .= "     Attended: " . htmlspecialchars($unit['date_attended']) . "\n";
                    $receipt .= "     Assignment: " . htmlspecialchars($unit['date_assignment_submitted']) . "\n";
                    $receipt .= "     Paid: " . number_format($unit['amount_paid'], 2) . "\n";
                }
            }
        }

        $receipt .= "----------------------------------------\n";
        $receipt .= $esc . "a0"; // Left align
        $receipt .= "Printed by: " . $admin_name . $nl;
        $receipt .= $esc . "a1"; // Center align
        $receipt .= "End of Statement\n";
        $receipt .= $nl . $nl . $nl;
        $receipt .= $gs . "V0"; // Cut paper

        // Send file as download with new naming convention
        $clean_surname = preg_replace('/[^a-zA-Z0-9]/', '', $student['surname']);
        $clean_firstname = preg_replace('/[^a-zA-Z0-9]/', '', $student['first_name']);
        $filename = 'smt_' . $clean_surname . $clean_firstname . '_' . date('YmdHis') . '.bin';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($receipt));
        echo $receipt;
        exit;
    } catch (Exception $e) {
        $error = "Error generating statement receipt: " . $e->getMessage();
    }
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] === 'pdf' && $selected_student_id && $student) {
    require_once '../vendor/tcpdf/tcpdf.php';
    $pdf = new TCPDF();
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();
    $html = "<h1>NPBC IGAC Uthiru Center</h1>";
    $html .= "<h1>Student Statement: " . htmlspecialchars($student['reg_number']) . "</h1>";

    if ($show_biodata) {
        $html .= "<h2>Biodata</h2>";
        $html .= "<table border='1' cellpadding='5'>";
        $html .= "<tr><th style='width: 30%; background-color: #2980b9; color: white;'>Field</th><th style='width: 70%;'>Value</th></tr>";
        $html .= "<tr><td>Full Name</td><td>" . htmlspecialchars($student['full_name']) . "</td></tr>";
        $html .= "<tr><td>Registration Number</td><td>" . htmlspecialchars($student['reg_number']) . "</td></tr>";
        $html .= "<tr><td>Phone Number</td><td>" . htmlspecialchars($student['phone_number']) . "</td></tr>";
        $html .= "<tr><td>Email</td><td>" . htmlspecialchars($student['email'] ?? 'N/A') . "</td></tr>";
        $html .= "<tr><td>Date of Birth</td><td>" . htmlspecialchars($student['date_of_birth']) . "</td></tr>";
        $html .= "<tr><td>Nationality</td><td>" . htmlspecialchars($student['nationality']) . "</td></tr>";
        $html .= "<tr><td>ID Type</td><td>" . htmlspecialchars($student['id_type']) . "</td></tr>";
        $html .= "<tr><td>ID Number</td><td>" . htmlspecialchars($student['id_number']) . "</td></tr>";
        $html .= "<tr><td>Church Name</td><td>" . htmlspecialchars($student['church_name']) . "</td></tr>";
        $html .= "<tr><td>Church Position</td><td>" . htmlspecialchars($student['church_position'] ?? 'N/A') . "</td></tr>";
        $html .= "<tr><td>Year Joined</td><td>" . htmlspecialchars($student['year_joined']) . "</td></tr>";
        $html .= "<tr><td>Current Level</td><td>" . htmlspecialchars($enrollment['level_name'] ?? 'Not Enrolled') . "</td></tr>";
        $html .= "</table>";
    }

    if ($show_attendance) {
        $html .= "<h2>Units Attended</h2>";
        if (empty($attendance)) {
            $html .= "<p>No attendance recorded.</p>";
        } else {
            $html .= "<table border='1'><tr><th>Unit</th><th>Mark</th></tr>";
            foreach ($attendance as $record) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($record['unit_name']) . "</td>";
                $html .= "<td>" . htmlspecialchars($record['mark'] ?? 'N/A') . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
        }
    }

    if ($show_payments) {
        $html .= "<h2>Payments and Balance</h2>";
        $html .= "<h3>Payments Made</h3>";
        if (empty($payments)) {
            $html .= "<p>No payments recorded.</p>";
        } else {
            $html .= "<table border='1'><tr><th>Date</th><th>Amount</th><th>Mode</th><th>Receipt</th><th>Balance B/F</th><th>Balance C/F</th></tr>";
            foreach ($payments as $payment) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($payment['payment_date']) . "</td>";
                $html .= "<td>" . htmlspecialchars(number_format($payment['amount_paid'], 2)) . "</td>";
                $html .= "<td>" . ($payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : htmlspecialchars($payment['payment_mode'] . ($payment['mpesa_code'] ? ' (' . $payment['mpesa_code'] . ')' : '')))) . "</td>";
                $html .= "<td>" . htmlspecialchars($payment['receipt_number'] ?? 'N/A') . "</td>";
                $html .= "<td>" . htmlspecialchars(number_format($payment['balance_bf'] ?? 0, 2)) . "</td>";
                $html .= "<td>" . htmlspecialchars(number_format($payment['balance_cf'] ?? 0, 2)) . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
        }

        $html .= "<h3>Invoices Status</h3>";
        if (empty($invoices)) {
            $html .= "<p>No invoices issued.</p>";
        } else {
            $html .= "<table border='1'><tr><th>Date</th><th>Related Unit</th><th>Invoiced Amount</th><th>Amount Paid</th></tr>";
            foreach ($invoices as $invoice) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($invoice['invoice_date']) . "</td>";
                $html .= "<td>" . htmlspecialchars($invoice['unit_name'] ?? 'N/A') . "</td>";
                $html .= "<td>" . htmlspecialchars(number_format($invoice['amount_due'], 2)) . "</td>";
                $html .= "<td>" . htmlspecialchars(number_format($invoice['amount_paid'], 2)) . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
        }

        $html .= "<p><strong>Total Balance:</strong> KES " . number_format($balance, 2) . " (" . ($balance > 0 ? 'Due' : ($balance < 0 ? 'Prepayment' : 'Cleared')) . ")</p>";
    }

    // Add Graduation Readiness Summary (only for non-catch-up students)
    if (!$student['is_catchup']) {
        $html .= "<h2>Graduation Readiness Status</h2>";
        $html .= "<p><strong>Student Name:</strong> " . htmlspecialchars($student['full_name']) . "</p>";
        $html .= "<p><strong>Registration Number:</strong> " . htmlspecialchars($student['reg_number']) . "</p>";
        $html .= "<h3>Regular Units</h3>";
        if (empty($units)) {
            $html .= "<p>No units found for this level.</p>";
        } else {
            $html .= "<table border='1'><tr><th>Unit Name</th><th>Date Attended</th><th>Date Assignment Submitted</th><th>Amount Paid</th></tr>";
            foreach ($units as $unit) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($unit['unit_name']) . "</td>";
                $html .= "<td>" . htmlspecialchars($unit['date_attended']) . "</td>";
                $html .= "<td>" . htmlspecialchars($unit['date_assignment_submitted']) . "</td>";
                $html .= "<td>" . number_format($unit['amount_paid'], 2) . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
        }

        if (!empty($makeup_units)) {
            $html .= "<h3>Make-up Units</h3>";
            $html .= "<table border='1'><tr><th>Unit Name</th><th>Date Attended</th><th>Date Assignment Submitted</th><th>Amount Paid</th></tr>";
            foreach ($makeup_units as $unit) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($unit['unit_name']) . "</td>";
                $html .= "<td>" . htmlspecialchars($unit['date_attended']) . "</td>";
                $html .= "<td>" . htmlspecialchars($unit['date_assignment_submitted']) . "</td>";
                $html .= "<td>" . number_format($unit['amount_paid'], 2) . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
        }
    }

    $html .= "<p>Printed by: " . $admin_name . "</p>";
    $pdf->writeHTML($html);
    $pdf->Output("statement_{$student['reg_number']}.pdf", 'I');
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<h2>Student Statement</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="GET" action="" id="studentFilterForm">
    <label for="level_id">Select Level:</label>
    <select name="level_id" id="level_id" onchange="this.form.submit()">
        <option value="">-- Select Level --</option>
        <?php
        // Fetch all levels
        $stmt = $pdo->query("SELECT id, name FROM levels ORDER BY sequence");
        $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($levels as $level) {
            echo "<option value='{$level['id']}' " . ($selected_level_id == $level['id'] ? 'selected' : '') . ">{$level['name']}</option>";
        }
        ?>
    </select>

    <label for="selected_student_id" style="margin-left: 20px;">Select Student:</label>
    <select name="selected_student_id" id="selected_student_id" onchange="this.form.submit()">
        <option value="">-- Select Student --</option>
        <?php
        // Fetch students based on selected level
        if ($selected_level_id) {
            $stmt = $pdo->prepare("
                SELECT s.id, s.reg_number, CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS full_name
                FROM students s
                JOIN enrollments e ON s.id = e.student_id
                WHERE e.level_id = ? AND e.academic_year = YEAR(CURDATE()) AND s.deleted_at IS NULL
                ORDER BY s.reg_number
            ");
            $stmt->execute([$selected_level_id]);
        } else {
            $stmt = $pdo->query("
                SELECT s.id, s.reg_number, CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS full_name
                FROM students s
                WHERE s.deleted_at IS NULL
                ORDER BY s.reg_number
            ");
        }
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($students as $row) {
            echo "<option value='{$row['id']}' " . ($selected_student_id == $row['id'] ? 'selected' : '') . ">{$row['reg_number']} - {$row['full_name']}</option>";
        }
        ?>
    </select>
</form>

<?php if ($student): ?>
    <form method="POST">
        <label><input type="checkbox" name="show_biodata" <?php echo $show_biodata ? 'checked' : ''; ?>> Show Biodata</label>
        <label><input type="checkbox" name="show_attendance" <?php echo $show_attendance ? 'checked' : ''; ?>> Show Attendance</label>
        <label><input type="checkbox" name="show_payments" <?php echo $show_payments ? 'checked' : ''; ?>> Show Payments</label>
        <button type="submit" class="btn btn-primary">Update View</button>
        <a href="?level_id=<?php echo $selected_level_id; ?>&selected_student_id=<?php echo $selected_student_id; ?>&export=pdf&show_biodata=<?php echo $show_biodata ? '1' : '0'; ?>&show_attendance=<?php echo $show_attendance ? '1' : '0'; ?>&show_payments=<?php echo $show_payments ? '1' : '0'; ?>"><button type="button" class="btn btn-primary">Export to PDF</button></a>
        <a href="?level_id=<?php echo $selected_level_id; ?>&selected_student_id=<?php echo $selected_student_id; ?>&print_statement=1&show_biodata=<?php echo $show_biodata ? '1' : '0'; ?>&show_attendance=<?php echo $show_attendance ? '1' : '0'; ?>&show_payments=<?php echo $show_payments ? '1' : '0'; ?>"><button type="button" class="btn btn-primary">Print Statement</button></a>
    </form>

    <?php if ($show_biodata): ?>
        <h3>Biodata</h3>
        <div class="table-responsive">
            <table class="standard-table biodata">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Full Name</td>
                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                    </tr>
                    <tr>
                        <td>Registration Number</td>
                        <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                    </tr>
                    <tr>
                        <td>Phone Number</td>
                        <td><?php echo htmlspecialchars($student['phone_number']); ?></td>
                    </tr>
                    <tr>
                        <td>Email</td>
                        <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td>Date of Birth</td>
                        <td><?php echo htmlspecialchars($student['date_of_birth']); ?></td>
                    </tr>
                    <tr>
                        <td>Nationality</td>
                        <td><?php echo htmlspecialchars($student['nationality']); ?></td>
                    </tr>
                    <tr>
                        <td>ID Type</td>
                        <td><?php echo htmlspecialchars($student['id_type']); ?></td>
                    </tr>
                    <tr>
                        <td>ID Number</td>
                        <td><?php echo htmlspecialchars($student['id_number']); ?></td>
                    </tr>
                    <tr>
                        <td>Church Name</td>
                        <td><?php echo htmlspecialchars($student['church_name']); ?></td>
                    </tr>
                    <tr>
                        <td>Church Position</td>
                        <td><?php echo htmlspecialchars($student['church_position'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td>Year Joined</td>
                        <td><?php echo htmlspecialchars($student['year_joined']); ?></td>
                    </tr>
                    <tr>
                        <td>Current Level</td>
                        <td><?php echo htmlspecialchars($enrollment['level_name'] ?? 'Not Enrolled'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($show_attendance): ?>
        <h3>Units Attended</h3>
        <?php if (empty($attendance)): ?>
            <p>No attendance recorded.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="standard-table units-attended">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Mark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $record): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($record['unit_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['mark'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($show_payments): ?>
        <h3>Payments and Balance</h3>
        <h4>Payments Made</h4>
        <?php if (empty($payments)): ?>
            <p>No payments recorded.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="standard-table payments-made">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Payment Mode</th>
                            <th>Receipt Number</th>
                            <th>Balance B/F</th>
                            <th>Balance C/F</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                <td><?php echo htmlspecialchars(number_format($payment['amount_paid'], 2)); ?></td>
                                <td><?php echo $payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : htmlspecialchars($payment['payment_mode'] . ($payment['mpesa_code'] ? ' (' . $payment['mpesa_code'] . ')' : ''))); ?></td>
                                <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(number_format($payment['balance_bf'] ?? 0, 2)); ?></td>
                                <td><?php echo htmlspecialchars(number_format($payment['balance_cf'] ?? 0, 2)); ?></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>admin/print_receipt.php?payment_id=<?php echo $payment['id']; ?>" class="action-link">Print Receipt</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h4>Invoices Status</h4>
        <?php if (empty($invoices)): ?>
            <p>No invoices issued.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="standard-table invoices-status">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Related Unit</th>
                            <th>Invoiced Amount</th>
                            <th>Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($invoice['invoice_date']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['unit_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(number_format($invoice['amount_due'], 2)); ?></td>
                                <td><?php echo htmlspecialchars(number_format($invoice['amount_paid'], 2)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p><strong>Total Balance:</strong> KES <?php echo number_format($balance, 2); ?> (<?php echo $balance > 0 ? 'Due' : ($balance < 0 ? 'Prepayment' : 'Cleared'); ?>)</p>
    <?php endif; ?>

    <!-- Graduation Readiness Summary (only for non-catch-up students) -->
    <?php if (!$student['is_catchup']): ?>
        <h2>Graduation Readiness Status</h2>
        <p><strong>Student Name:</strong> <?php echo htmlspecialchars($student['full_name']); ?></p>
        <p><strong>Registration Number:</strong> <?php echo htmlspecialchars($student['reg_number']); ?></p>
        <h3>Regular Units</h3>
        <?php if (empty($units)): ?>
            <p>No units found for this level.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="standard-table graduation-readiness">
                    <thead>
                        <tr>
                            <th>Unit Name</th>
                            <th>Date Attended</th>
                            <th>Date Assignment Submitted</th>
                            <th>Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($units as $unit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($unit['unit_name']); ?></td>
                                <td><?php echo htmlspecialchars($unit['date_attended']); ?></td>
                                <td><?php echo htmlspecialchars($unit['date_assignment_submitted']); ?></td>
                                <td><?php echo number_format($unit['amount_paid'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($makeup_units)): ?>
            <h3>Make-up Units</h3>
            <div class="table-responsive">
                <table class="standard-table graduation-readiness">
                    <thead>
                        <tr>
                            <th>Unit Name</th>
                            <th>Date Attended</th>
                            <th>Date Assignment Submitted</th>
                            <th>Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($makeup_units as $unit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($unit['unit_name']); ?></td>
                                <td><?php echo htmlspecialchars($unit['date_attended']); ?></td>
                                <td><?php echo htmlspecialchars($unit['date_assignment_submitted']); ?></td>
                                <td><?php echo number_format($unit['amount_paid'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
.standard-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.standard-table th, .standard-table td {
    border: 1px solid #ddd;
    padding: 12px 15px;
    word-break: break-word;
}
.standard-table th {
    background-color: #2980b9;
    color: white;
}
.action-link {
    color: #2980b9;
    text-decoration: none;
}
.action-link:hover {
    text-decoration: underline;
}
.table-responsive {
    margin-bottom: 20px;
}
/* Column widths for biodata table */
.standard-table.biodata th:nth-child(1), .standard-table.biodata td:nth-child(1) { width: 30%; min-width: 150px; }
.standard-table.biodata th:nth-child(2), .standard-table.biodata td:nth-child(2) { width: 70%; min-width: 200px; }
/* Column widths for units attended table */
.standard-table.units-attended th:nth-child(1), .standard-table.units-attended td:nth-child(1) { width: 70%; min-width: 200px; }
.standard-table.units-attended th:nth-child(2), .standard-table.units-attended td:nth-child(2) { width: 30%; min-width: 120px; text-align: center; }
/* Column widths for payments made table */
.standard-table.payments-made th:nth-child(1), .standard-table.payments-made td:nth-child(1) { width: 15%; min-width: 120px; }
.standard-table.payments-made th:nth-child(2), .standard-table.payments-made td:nth-child(2) { width: 10%; min-width: 100px; text-align: center; }
.standard-table.payments-made th:nth-child(3), .standard-table.payments-made td:nth-child(3) { width: 15%; min-width: 120px; }
.standard-table.payments-made th:nth-child(4), .standard-table.payments-made td:nth-child(4) { width: 15%; min-width: 120px; }
.standard-table.payments-made th:nth-child(5), .standard-table.payments-made td:nth-child(5) { width: 15%; min-width: 120px; text-align: center; }
.standard-table.payments-made th:nth-child(6), .standard-table.payments-made td:nth-child(6) { width: 15%; min-width: 120px; text-align: center; }
.standard-table.payments-made th:nth-child(7), .standard-table.payments-made td:nth-child(7) { width: 15%; min-width: 120px; }
/* Column widths for invoices status table */
.standard-table.invoices-status th:nth-child(1), .standard-table.invoices-status td:nth-child(1) { width: 25%; min-width: 120px; }
.standard-table.invoices-status th:nth-child(2), .standard-table.invoices-status td:nth-child(2) { width: 25%; min-width: 150px; }
.standard-table.invoices-status th:nth-child(3), .standard-table.invoices-status td:nth-child(3) { width: 25%; min-width: 120px; text-align: center; }
.standard-table.invoices-status th:nth-child(4), .standard-table.invoices-status td:nth-child(4) { width: 25%; min-width: 120px; text-align: center; }
/* Column widths for graduation readiness table */
.standard-table.graduation-readiness th:nth-child(1), .standard-table.graduation-readiness td:nth-child(1) { width: 25%; min-width: 150px; }
.standard-table.graduation-readiness th:nth-child(2), .standard-table.graduation-readiness td:nth-child(2) { width: 25%; min-width: 120px; text-align: center; }
.standard-table.graduation-readiness th:nth-child(3), .standard-table.graduation-readiness td:nth-child(3) { width: 25%; min-width: 120px; text-align: center; }
.standard-table.graduation-readiness th:nth-child(4), .standard-table.graduation-readiness td:nth-child(4) { width: 25%; min-width: 120px; text-align: center; }
</style>