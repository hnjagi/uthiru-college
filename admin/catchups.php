<?php
session_start();
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

// Include libraries for PDF and Excel export
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/tcpdf/tcpdf.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Fetch logged-in administrator's name for printing
$admin_stmt = $pdo->prepare("SELECT first_name, surname FROM administrators WHERE id = ?");
$admin_stmt->execute([$_SESSION['user_id']]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);
$admin_name = $admin ? htmlspecialchars($admin['first_name'] . ' ' . $admin['surname']) : 'Unknown Admin';

// Initialize variables
$error = '';
$success = '';
$form_data = $_POST ?: [];
$form_visible = isset($_POST['form_visible']) && $_POST['form_visible'] == '1';

// Fetch form data (dropdowns)
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("
    SELECT l.*, p.name AS program_name 
    FROM levels l 
    JOIN programs p ON l.program_id = p.id 
    ORDER BY p.name, l.sequence
")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("
    SELECT u.*, l.name AS level_name, p.name AS program_name
    FROM units u
    JOIN levels l ON u.level_id = l.id
    JOIN programs p ON l.program_id = p.id
    JOIN class_sessions cs ON u.id = cs.unit_id
    WHERE cs.academic_year = ? AND cs.is_closed = 1
    GROUP BY u.id
    ORDER BY p.name, l.sequence, u.name
");
$stmt->execute([date('Y')]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);
$valid_unit_ids = array_column($units, 'id') ?: [];

// Create a mapping of unit_id to level_id
$unit_to_level_map = [];
foreach ($units as $unit) {
    $unit_to_level_map[$unit['id']] = $unit['level_id'];
}

// Fetch distinct catch-up dates for dropdown
$stmt = $pdo->query("SELECT DISTINCT DATE(catchup_date) AS catchup_date FROM catchups WHERE deleted_at IS NULL ORDER BY catchup_date DESC");
$catchup_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle date filter (default to most recent catch-up date or today)
$date_filter = isset($_GET['date']) ? $_GET['date'] : (!empty($catchup_dates) ? $catchup_dates[0] : date('Y-m-d'));

// Sorting and Pagination for Catch-Ups
$sort_column = $_GET['sort'] ?? 'student_name';
$sort_order = $_GET['order'] ?? 'ASC';
$valid_columns = ['reg_number', 'student_name', 'unit_name', 'catchup_date', 'created_at'];
$sort_column = in_array($sort_column, $valid_columns) ? $sort_column : 'student_name';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$next_order = $sort_order === 'ASC' ? 'DESC' : 'ASC';

$page = max(1, $_GET['page'] ?? 1);
$per_page_options = [10, 50, 100, 'All'];
$per_page = $_GET['per_page'] ?? 10;
if (!in_array($per_page, $per_page_options)) $per_page = 10;
$offset = ($page - 1) * $per_page;

// Fetch catch-ups
$catchup_sql = "
    SELECT c.id, c.catchup_date, c.created_at, c.center_name, c.coordinator_name, c.coordinator_phone, c.lecturer_name,
           s.reg_number, s.first_name, s.other_name, s.surname, 
           u.name AS unit_name, l.name AS level_name, p.name AS program_name,
           CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS student_name
    FROM catchups c
    JOIN students s ON c.student_id = s.id
    JOIN units u ON c.unit_id = u.id
    JOIN levels l ON u.level_id = l.id
    JOIN programs p ON l.program_id = p.id
    WHERE DATE(c.catchup_date) = :date AND s.deleted_at IS NULL AND c.deleted_at IS NULL
";
$catchup_params = [':date' => $date_filter];
$order_by = '';
switch ($sort_column) {
    case 'reg_number':
        $order_by = "s.reg_number $sort_order";
        break;
    case 'student_name':
        $order_by = "student_name $sort_order";
        break;
    case 'unit_name':
        $order_by = "u.name $sort_order";
        break;
    case 'catchup_date':
        $order_by = "c.catchup_date $sort_order";
        break;
    case 'created_at':
        $order_by = "c.created_at $sort_order";
        break;
}
$catchup_sql .= " ORDER BY $order_by";

// Fetch total catch-ups for pagination
$total_sql = "SELECT COUNT(*) FROM catchups c JOIN students s ON c.student_id = s.id WHERE DATE(c.catchup_date) = :date AND s.deleted_at IS NULL AND c.deleted_at IS NULL";
$stmt = $pdo->prepare($total_sql);
$stmt->execute($catchup_params);
$total_catchups = $stmt->fetchColumn();
$total_pages = ($per_page === 'All') ? 1 : ceil($total_catchups / $per_page);

// Fetch paginated catch-ups
$display_sql = $catchup_sql;
if ($per_page !== 'All') {
    $display_sql .= " LIMIT :offset, :per_page";
}
$stmt = $pdo->prepare($display_sql);
$stmt->bindValue(':date', $date_filter, PDO::PARAM_STR);
if ($per_page !== 'All') {
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
}
$stmt->execute();
$catchups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    class CustomTCPDF extends TCPDF {
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(0, 10, 'Generated on: ' . date('Y-m-d'), 0, 0, 'L');
            $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    }

    $pdf = new CustomTCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('IGAC Uthiru Center');
    $pdf->SetTitle('Catch-Ups Report - ' . $date_filter);
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();

    $html = "<h1>IGAC Uthiru Center for NPBC</h1>";
    $html .= "<h2>Catch-Ups Report - " . htmlspecialchars($date_filter) . "</h2>";

    // Catch-Ups
    $html .= "<h3>Catch-Ups</h3>";
    if (empty($catchups)) {
        $html .= "<p>No catch-ups recorded on this date.</p>";
    } else {
        $html .= "<table border='1'>";
        $html .= "<tr><th>Reg Number</th><th>Student</th><th>Level</th><th>Unit</th><th>Center</th><th>Coordinator</th><th>Phone</th><th>Lecturer</th><th>Catch-Up Date</th><th>Created At</th></tr>";
        foreach ($catchups as $catchup) {
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($catchup['reg_number']) . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['first_name'] . ' ' . ($catchup['other_name'] ?? '') . ' ' . $catchup['surname']) . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['level_name']) . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['unit_name']) . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['center_name'] ?? 'N/A') . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['coordinator_name'] ?? 'N/A') . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['coordinator_phone'] ?? 'N/A') . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['lecturer_name'] ?? 'N/A') . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['catchup_date']) . "</td>";
            $html .= "<td>" . htmlspecialchars($catchup['created_at']) . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
    }

    $html .= "<p>Printed by: " . htmlspecialchars($admin_name) . "</p>";
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('catchups_report_' . $date_filter . '.pdf', 'I');
    exit;
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $row = 1;

    $sheet->setCellValue('A' . $row, 'IGAC Uthiru Center for NPBC');
    $row++;
    $sheet->setCellValue('A' . $row, 'Catch-Ups Report - ' . $date_filter);
    $row += 2;

    // Catch-Ups
    $sheet->setCellValue('A' . $row, 'Catch-Ups');
    $row++;
    $sheet->setCellValue('A' . $row, 'Reg Number');
    $sheet->setCellValue('B' . $row, 'Student');
    $sheet->setCellValue('C' . $row, 'Level');
    $sheet->setCellValue('D' . $row, 'Unit');
    $sheet->setCellValue('E' . $row, 'Center');
    $sheet->setCellValue('F' . $row, 'Coordinator');
    $sheet->setCellValue('G' . $row, 'Phone');
    $sheet->setCellValue('H' . $row, 'Lecturer');
    $sheet->setCellValue('I' . $row, 'Catch-Up Date');
    $sheet->setCellValue('J' . $row, 'Created At');
    $row++;
    if (!empty($catchups)) {
        foreach ($catchups as $catchup) {
            $sheet->setCellValue('A' . $row, $catchup['reg_number']);
            $sheet->setCellValue('B' . $row, $catchup['first_name'] . ' ' . ($catchup['other_name'] ?? '') . ' ' . $catchup['surname']);
            $sheet->setCellValue('C' . $row, $catchup['level_name']);
            $sheet->setCellValue('D' . $row, $catchup['unit_name']);
            $sheet->setCellValue('E' . $row, $catchup['center_name'] ?? 'N/A');
            $sheet->setCellValue('F' . $row, $catchup['coordinator_name'] ?? 'N/A');
            $sheet->setCellValue('G' . $row, $catchup['coordinator_phone'] ?? 'N/A');
            $sheet->setCellValue('H' . $row, $catchup['lecturer_name'] ?? 'N/A');
            $sheet->setCellValue('I' . $row, $catchup['catchup_date']);
            $sheet->setCellValue('J' . $row, $catchup['created_at']);
            $row++;
        }
    } else {
        $sheet->setCellValue('A' . $row, 'No catch-ups recorded on this date.');
        $row++;
    }

    // Add Printed by
    $row++;
    $sheet->setCellValue('A' . $row, 'Printed by: ' . $admin_name);

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="catchups_report_' . $date_filter . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}

// Handle catch-up submission
$action = $_POST['action'] ?? 'submit';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action === 'update_dropdown') {
    $form_data = $_POST;
    $success = '';
    $error = '';
    $form_visible = true; // Keep form visible after dropdown update
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = filter_var($_POST['student_id'] ?? 0, FILTER_VALIDATE_INT);
    $unit_id = filter_var($_POST['unit_id'] ?? 0, FILTER_VALIDATE_INT);
    $center_name = trim($_POST['center_name'] ?? '');
    $coordinator_name = trim($_POST['coordinator_name'] ?? '');
    $coordinator_phone = trim($_POST['coordinator_phone'] ?? '');
    $lecturer_name = trim($_POST['lecturer_name'] ?? '');
    $catchup_date = trim($_POST['catchup_date'] ?? '');
    $form_visible = true; // Keep form visible after submission attempt

    // Debug logging
    $form_inputs = json_encode($_POST);
    file_put_contents('../debug.log', "Catch-up Submission - Unit ID: $unit_id, Student ID: $student_id, Valid Unit IDs: " . json_encode($valid_unit_ids) . ", Form Inputs: $form_inputs, Time: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

    // Validate inputs
    if (!$student_id || $student_id <= 0) {
        $error = "Please select a valid student.";
        file_put_contents('../debug.log', "Invalid student ID: $student_id\n", FILE_APPEND);
    } elseif (!$unit_id || $unit_id <= 0) {
        $error = "Please select a valid unit.";
        file_put_contents('../debug.log', "Invalid unit ID: $unit_id\n", FILE_APPEND);
    } elseif (empty($catchup_date)) {
        $error = "Catch-up date is required.";
        file_put_contents('../debug.log', "Missing catch-up date\n", FILE_APPEND);
    } elseif (empty($valid_unit_ids) || !in_array($unit_id, $valid_unit_ids)) {
        $error = "Selected unit is not valid or not available for catch-up.";
        file_put_contents('../debug.log', "Invalid unit_id - Unit ID: $unit_id, Valid Unit IDs: " . json_encode($valid_unit_ids) . "\n", FILE_APPEND);
    } else {
        try {
            // Validate student
            $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$student_id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $error = "Student not found or has been deleted.";
                file_put_contents('../debug.log', "Student not found - Student ID: $student_id\n", FILE_APPEND);
            }

            // Validate unit
            $stmt = $pdo->prepare("SELECT level_id, name FROM units WHERE id = ?");
            $stmt->execute([$unit_id]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unit) {
                $error = "Invalid unit selected.";
                file_put_contents('../debug.log', "Invalid unit - Unit ID: $unit_id\n", FILE_APPEND);
            }

            // If any validation errors, skip further processing
            if ($error) {
                $form_data = $_POST; // Preserve form inputs
            } else {
                $level_id = $unit['level_id'];
                $unit_name = $unit['name'];
                $current_year = date('Y');

                // Validate enrollment and attendance
                $stmt = $pdo->prepare("
                    SELECT e.id, 
                           (SELECT COUNT(*) 
                            FROM attendance a
                            JOIN class_sessions cs ON a.session_id = cs.id
                            WHERE a.student_id = e.student_id AND cs.unit_id = ? AND cs.academic_year = ?) AS attendance_count
                    FROM enrollments e
                    WHERE e.student_id = ? AND e.level_id = ? AND e.academic_year = ?
                ");
                $stmt->execute([$unit_id, $current_year, $student_id, $level_id, $current_year]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$result) {
                    $error = "Student is not enrolled in the level for this academic year.";
                    file_put_contents('../debug.log', "Enrollment mismatch - Student ID: $student_id, Level ID: $level_id, Year: $current_year\n", FILE_APPEND);
                    $form_data = $_POST;
                } elseif ($result['attendance_count'] > 0) {
                    $error = "Student has already attended the unit '$unit_name' at the center.";
                    file_put_contents('../debug.log', "Attendance exists - Student ID: $student_id, Unit: $unit_name (ID: $unit_id)\n", FILE_APPEND);
                    $form_data = $_POST;
                } else {
                    // Validate unique catch-up
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM catchups WHERE student_id = ? AND unit_id = ? AND deleted_at IS NULL");
                    $stmt->execute([$student_id, $unit_id]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = "Catch-up already recorded for this student and unit.";
                        file_put_contents('../debug.log', "Duplicate catch-up - Student ID: $student_id, Unit: $unit_name (ID: $unit_id)\n", FILE_APPEND);
                        $form_data = $_POST;
                    } else {
                        // Process catch-up
                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO catchups (student_id, center_name, coordinator_name, coordinator_phone, lecturer_name, unit_id, catchup_date, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$student_id, $center_name, $coordinator_name, $coordinator_phone, $lecturer_name, $unit_id, $catchup_date, $_SESSION['user_id']]);
                            $record_id = $pdo->lastInsertId();

                            // Log audit trail
                            $stmt = $pdo->prepare("
                                INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time)
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            $stmt->execute([
                                $_SESSION['user_id'], 'Added Catch-Up', 'catchups', $record_id,
                                "Student ID: $student_id, Unit: $unit_name (ID: $unit_id)"
                            ]);

                            $pdo->commit();
                            $success = "Catch-up recorded successfully.";
                            $form_data = [];
                            $form_visible = false; // Hide form after successful submission
                        } catch (PDOException $e) {
                            $pdo->rollBack();
                            $error = "Database error: " . $e->getMessage();
                            file_put_contents('../debug.log', "Database Error - Unit: $unit_name (ID: $unit_id), Student ID: $student_id, Error: " . $e->getMessage() . "\n", FILE_APPEND);
                            $form_data = $_POST;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
            file_put_contents('../debug.log', "Database Error - Unit ID: $unit_id, Student ID: $student_id, Error: " . $e->getMessage() . "\n", FILE_APPEND);
            $form_data = $_POST;
        }
    }
}

// Fetch students for dropdown: enrolled in the unit's level, no attendance, no prior catch-ups
$students = [];
if (isset($form_data['unit_id']) && filter_var($form_data['unit_id'], FILTER_VALIDATE_INT) && in_array($form_data['unit_id'], $valid_unit_ids)) {
    $current_year = date('Y');
    $stmt = $pdo->prepare("SELECT name FROM units WHERE id = ?");
    $stmt->execute([$form_data['unit_id']]);
    $unit_name = $stmt->fetchColumn() ?: 'Unknown';
    file_put_contents('../debug.log', "Fetching students for unit: $unit_name (ID: {$form_data['unit_id']})\n", FILE_APPEND);

    $stmt = $pdo->prepare("
        SELECT s.id, s.reg_number, s.first_name, s.other_name, s.surname
        FROM students s
        JOIN enrollments e ON s.id = e.student_id
        JOIN units u ON u.id = ? AND e.level_id = u.level_id
        WHERE s.deleted_at IS NULL
        AND e.academic_year = ?
        AND NOT EXISTS (
            SELECT 1 
            FROM catchups c 
            WHERE c.student_id = s.id AND c.unit_id = ? AND c.deleted_at IS NULL
        )
        AND NOT EXISTS (
            SELECT 1 
            FROM attendance a
            JOIN class_sessions cs ON a.session_id = cs.id
            WHERE a.student_id = s.id AND cs.unit_id = ? AND cs.academic_year = ?
        )
        ORDER BY s.reg_number
    ");
    $stmt->execute([$form_data['unit_id'], $current_year, $form_data['unit_id'], $form_data['unit_id'], $current_year]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents('../debug.log', "Found " . count($students) . " eligible students for unit: $unit_name\n", FILE_APPEND);
}

?>

<?php include '../includes/header.php'; ?>
<h2>Catch-Up Management - <?php echo htmlspecialchars(date('Y-m-d', strtotime($date_filter))); ?></h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<!-- Date Filter Form -->
<form method="GET" action="">
    <label>Date:</label>
    <select name="date" onchange="this.form.submit()" required>
        <?php if (empty($catchup_dates)): ?>
            <option value="<?php echo date('Y-m-d'); ?>">No catch-ups recorded</option>
        <?php else: ?>
            <?php foreach ($catchup_dates as $date): ?>
                <option value="<?php echo $date; ?>" <?php echo $date_filter === $date ? 'selected' : ''; ?>>
                    <?php echo date('m/d/Y', strtotime($date)); ?>
                </option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <label>Records per page:</label>
    <select name="per_page" onchange="this.form.submit()">
        <?php foreach ($per_page_options as $option): ?>
            <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                <?php echo $option; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
    <a href="?export=pdf&date=<?php echo urlencode($date_filter); ?>&per_page=<?php echo urlencode($per_page); ?>"><button type="button">Export to PDF</button></a>
    <a href="?export=excel&date=<?php echo urlencode($date_filter); ?>&per_page=<?php echo urlencode($per_page); ?>"><button type="button">Export to Excel</button></a>
</form>

<!-- Add New Catch-Up Button -->
<?php if (empty($units)): ?>
    <p>No units are available for catch-up sessions at this time.</p>
<?php else: ?>
    <button id="toggleFormBtn" onclick="toggleForm()">Add New Catch-Up</button>

    <div id="catchupForm" style="display: <?php echo !empty($error) || $form_visible ? 'block' : 'none'; ?>;">
        <h3>Add New Catch-Up</h3>
        <form method="POST" id="catchupFormElement" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="form_visible" id="form_visible" value="<?php echo $form_visible ? '1' : '0'; ?>">
            <label>Program:</label>
            <select name="program_id" id="program_id" required onchange="filterLevels()">
                <option value="">Select Program</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?php echo $program['id']; ?>" <?php echo ($form_data['program_id'] ?? '') == $program['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($program['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Level:</label>
            <select name="level_id" id="level_id" required onchange="filterUnits()">
                <option value="">Select Level</option>
                <?php foreach ($levels as $level): ?>
                    <option value="<?php echo $level['id']; ?>" data-program="<?php echo $level['program_id']; ?>" <?php echo ($form_data['level_id'] ?? '') == $level['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Unit:</label>
            <select name="unit_id" id="unit_id" required>
                <option value="">Select Unit</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?php echo $unit['id']; ?>" data-level="<?php echo $unit['level_id']; ?>" <?php echo ($form_data['unit_id'] ?? '') == $unit['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($unit['program_name'] . ' - ' . $unit['level_name'] . ' - ' . $unit['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="updateStudentDropdown()">Load Students</button>
            <label>Student:</label>
            <select name="student_id" id="student_id" required>
                <option value="">Select Student</option>
                <?php if (empty($students) && isset($form_data['unit_id'])): ?>
                    <option value="" disabled>No eligible students available</option>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>" <?php echo ($form_data['student_id'] ?? '') == $student['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['reg_number'] . ' - ' . $student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <label>Center Name:</label>
            <input type="text" name="center_name" value="<?php echo htmlspecialchars($form_data['center_name'] ?? ''); ?>">
            <label>Coordinator Name:</label>
            <input type="text" name="coordinator_name" value="<?php echo htmlspecialchars($form_data['coordinator_name'] ?? ''); ?>">
            <label>Coordinator Phone:</label>
            <input type="text" name="coordinator_phone" value="<?php echo htmlspecialchars($form_data['coordinator_phone'] ?? ''); ?>">
            <label>Lecturer Name:</label>
            <input type="text" name="lecturer_name" value="<?php echo htmlspecialchars($form_data['lecturer_name'] ?? ''); ?>">
            <label>Catch-Up Date:</label>
            <input type="date" name="catchup_date" value="<?php echo htmlspecialchars($form_data['catchup_date'] ?? date('Y-m-d')); ?>" required>
            <button type="submit">Record Catch-Up</button>
            <a href="#" onclick="toggleForm(); return false;" class="cancel-link">Cancel</a>
        </form>
    </div>
<?php endif; ?>

<!-- Catch-Ups -->
<h3>Catch-Ups</h3>
<?php if (empty($catchups)): ?>
    <p>No catch-ups recorded on this date.</p>
<?php else: ?>
    <table class="table-striped" id="catchupsTable">
        <thead>
            <tr>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&sort=reg_number&order=<?php echo $sort_column == 'reg_number' ? $next_order : 'ASC'; ?>&per_page=<?php echo urlencode($per_page); ?>">Reg Number <?php echo $sort_column == 'reg_number' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&sort=student_name&order=<?php echo $sort_column == 'student_name' ? $next_order : 'ASC'; ?>&per_page=<?php echo urlencode($per_page); ?>">Student <?php echo $sort_column == 'student_name' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&sort=unit_name&order=<?php echo $sort_column == 'unit_name' ? $next_order : 'ASC'; ?>&per_page=<?php echo urlencode($per_page); ?>">Unit <?php echo $sort_column == 'unit_name' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>Center</th>
                <th>Coordinator</th>
                <th>Phone</th>
                <th>Lecturer</th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&sort=catchup_date&order=<?php echo $sort_column == 'catchup_date' ? $next_order : 'ASC'; ?>&per_page=<?php echo urlencode($per_page); ?>">Catch-Up Date <?php echo $sort_column == 'catchup_date' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&sort=created_at&order=<?php echo $sort_column == 'created_at' ? $next_order : 'ASC'; ?>&per_page=<?php echo urlencode($per_page); ?>">Created At <?php echo $sort_column == 'created_at' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($catchups as $catchup): 
                $student_name = htmlspecialchars($catchup['first_name'] . ' ' . ($catchup['other_name'] ?? '') . ' ' . $catchup['surname']);
            ?>
            <tr>
                <td><?php echo htmlspecialchars($catchup['reg_number']); ?></td>
                <td><?php echo $student_name; ?></td>
                <td><?php echo htmlspecialchars($catchup['unit_name']); ?></td>
                <td><?php echo htmlspecialchars($catchup['center_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($catchup['coordinator_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($catchup['coordinator_phone'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($catchup['lecturer_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($catchup['catchup_date']); ?></td>
                <td><?php echo htmlspecialchars($catchup['created_at']); ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $catchup['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="delete">Delete</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($per_page !== 'All'): ?>
        <nav aria-label="Catch-Ups pagination">
            <ul class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?date=<?php echo urlencode($date_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>&per_page=<?php echo urlencode($per_page); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
/* Align with payments.php styling */
.table-striped thead tr th {
    background-color: #2980b9;
    color: white;
}
.table-striped th a {
    color: white;
    text-decoration: none;
}
.table-striped th a:hover {
    text-decoration: underline;
}
.action-dropdown, .action-link {
    color: #2980b9;
}
.action-link:hover {
    text-decoration: underline;
}
.table-striped tbody tr:nth-child(odd) {
    background-color: #f9f9f9;
}
.table-striped tbody tr:nth-child(even) {
    background-color: #ffffff;
}
form label {
    margin-right: 10px;
}
form select, form input[type="text"], form input[type="date"], form button {
    margin-right: 20px;
    padding: 5px;
}
</style>

<script>
// Client-side validation
const validUnitIds = <?php echo json_encode($valid_unit_ids); ?>;
const formData = <?php echo json_encode($form_data); ?>;
let isSubmitting = false;

function validateForm() {
    if (isSubmitting) return false;
    const unitId = document.getElementById('unit_id').value;
    const studentId = document.getElementById('student_id').value;
    if (!unitId || !validUnitIds.includes(parseInt(unitId))) {
        alert('Please select a valid unit.');
        return false;
    }
    if (!studentId) {
        alert('Please select a student.');
        return false;
    }
    isSubmitting = true;
    document.getElementById('form_visible').value = '1';
    return true;
}

function toggleForm() {
    const form = document.getElementById('catchupForm');
    const isVisible = form.style.display === 'block';
    form.style.display = isVisible ? 'none' : 'block';
    document.getElementById('form_visible').value = isVisible ? '0' : '1';
}

function filterLevels() {
    const programId = document.getElementById('program_id').value;
    const levelSelect = document.getElementById('level_id');
    const options = levelSelect.getElementsByTagName('option');
    for (let i = 0; i < options.length; i++) {
        const optionProgram = options[i].getAttribute('data-program');
        options[i].style.display = (programId === '' || optionProgram === programId) ? '' : 'none';
    }
    if (!formData.level_id || formData.program_id !== programId) {
        levelSelect.value = '';
    }
    filterUnits();
}

function filterUnits() {
    const levelId = document.getElementById('level_id').value;
    const unitSelect = document.getElementById('unit_id');
    const options = unitSelect.getElementsByTagName('option');
    for (let i = 0; i < options.length; i++) {
        const optionLevel = options[i].getAttribute('data-level');
        options[i].style.display = (levelId === '' || optionLevel === levelId) ? '' : 'none';
    }
    if (!formData.unit_id || formData.level_id !== levelId) {
        unitSelect.value = '';
    }
}

function updateStudentDropdown() {
    if (isSubmitting) return;
    const unitId = document.getElementById('unit_id').value;
    const studentSelect = document.getElementById('student_id');
    if (unitId && !isNaN(unitId) && validUnitIds.includes(parseInt(unitId))) {
        isSubmitting = true;
        document.getElementsByName('action')[0].value = 'update_dropdown';
        document.getElementById('form_visible').value = '1';
        document.getElementById('catchupFormElement').submit();
    } else {
        studentSelect.innerHTML = '<option value="">Select Student</option>';
        alert('Please select a valid unit before loading students.');
    }
}

function handleAction(select, catchupId) {
    const action = select.value;
    if (action) {
        if (action === 'delete') {
            if (confirm('Are you sure you want to delete this catch-up?')) {
                window.location.href = `catchups.php?delete=${catchupId}&date=<?php echo urlencode($date_filter); ?>&per_page=<?php echo urlencode($per_page); ?>`;
            }
        }
        select.value = '';
    }
}

window.onload = function() {
    if (formData.program_id) {
        document.getElementById('program_id').value = formData.program_id;
    }
    filterLevels();
    if (formData.level_id) {
        document.getElementById('level_id').value = formData.level_id;
    }
    filterUnits();
    if (formData.unit_id) {
        document.getElementById('unit_id').value = formData.unit_id;
    }
};
</script>