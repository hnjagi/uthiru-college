<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: " . BASE_URL . "login.php");
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

// Initialize messages
$error = '';
$success = '';

// Detect make_payment, student_id, and level_id from students.php
$make_payment = isset($_GET['make_payment']) && $_GET['make_payment'] == '1';
$student_id = isset($_GET['student_id']) ? filter_var($_GET['student_id'], FILTER_VALIDATE_INT) : null;
$level_id = isset($_GET['level_id']) ? filter_var($_GET['level_id'], FILTER_VALIDATE_INT) : null;

// Validate student_id and level_id if make_payment is set
$selected_student = null;
if ($make_payment && $student_id && $level_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.reg_number, s.first_name, s.other_name, s.surname, 
                   EXISTS (
                       SELECT 1 
                       FROM enrollments e 
                       WHERE e.student_id = s.id 
                       AND e.level_id = ? 
                       AND e.academic_year = YEAR(CURDATE())
                   ) AS is_enrolled
            FROM students s 
            WHERE s.id = ? AND s.deleted_at IS NULL
        ");
        $stmt->execute([$level_id, $student_id]);
        $selected_student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$selected_student) {
            $error = "Student not found or deleted.";
        } elseif (!$selected_student['is_enrolled']) {
            $error = "Student is not enrolled in the selected level for the current academic year.";
        }
    } catch (PDOException $e) {
        $error = "Error validating student: " . $e->getMessage();
    }
}

// Fetch distinct payment dates for dropdown
$stmt = $pdo->query("SELECT DISTINCT DATE(payment_date) AS payment_date FROM payments ORDER BY payment_date DESC");
$payment_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle date filter (default to most recent payment date or today)
$date_filter = isset($_GET['date']) ? $_GET['date'] : (!empty($payment_dates) ? $payment_dates[0] : date('Y-m-d'));

// Sorting and Pagination for Daily Payments
$daily_sort_column = $_GET['daily_sort'] ?? 'payment_date';
$daily_sort_order = $_GET['daily_order'] ?? 'DESC';
$valid_daily_columns = ['reg_number', 'full_name', 'amount_paid', 'payment_mode', 'payment_purpose', 'payment_date'];
$daily_sort_column = in_array($daily_sort_column, $valid_daily_columns) ? $daily_sort_column : 'payment_date';
$daily_sort_order = strtoupper($daily_sort_order) === 'ASC' ? 'ASC' : 'DESC';
$daily_next_order = $daily_sort_order === 'ASC' ? 'DESC' : 'ASC';

$daily_page = max(1, $_GET['daily_page'] ?? 1);
$daily_per_page_options = [10, 50, 100, 'All'];
$daily_per_page = $_GET['daily_per_page'] ?? 10;
if (!in_array($daily_per_page, $daily_per_page_options)) $daily_per_page = 10;
$daily_offset = ($daily_page - 1) * $daily_per_page;

// 1. Daily Payment Summary
$daily_sql = "
    SELECT p.id, p.student_id, s.reg_number, CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS full_name,
           p.amount_paid, p.payment_mode, p.payment_purpose, p.is_prepayment, p.from_balance, p.payment_date
    FROM payments p
    JOIN students s ON p.student_id = s.id
    WHERE DATE(p.payment_date) = :date AND p.student_id IS NOT NULL AND s.deleted_at IS NULL
";
$daily_params = [':date' => $date_filter];

$daily_order_by = '';
switch ($daily_sort_column) {
    case 'reg_number':
        $daily_order_by = "s.reg_number $daily_sort_order";
        break;
    case 'full_name':
        $daily_order_by = "full_name $daily_sort_order";
        break;
    case 'amount_paid':
        $daily_order_by = "p.amount_paid $daily_sort_order";
        break;
    case 'payment_mode':
        $daily_order_by = "p.payment_mode $daily_sort_order";
        break;
    case 'payment_purpose':
        $daily_order_by = "p.payment_purpose $daily_sort_order";
        break;
    case 'payment_date':
        $daily_order_by = "p.payment_date $daily_sort_order";
        break;
}
$daily_sql .= " ORDER BY $daily_order_by";

// Fetch total daily payments for pagination
$total_daily_sql = "SELECT COUNT(*) FROM payments p JOIN students s ON p.student_id = s.id WHERE DATE(p.payment_date) = :date AND p.student_id IS NOT NULL AND s.deleted_at IS NULL";
$stmt = $pdo->prepare($total_daily_sql);
$stmt->execute($daily_params);
$total_daily = $stmt->fetchColumn();
$daily_total_pages = ($daily_per_page === 'All') ? 1 : ceil($total_daily / $daily_per_page);

// Fetch paginated daily payments
$display_daily_sql = $daily_sql;
if ($daily_per_page !== 'All') {
    $display_daily_sql .= " LIMIT :offset, :per_page";
}
$stmt = $pdo->prepare($display_daily_sql);
// Bind the :date parameter
$stmt->bindValue(':date', $date_filter, PDO::PARAM_STR);
// Bind the :offset and :per_page parameters as integers if pagination is enabled
if ($daily_per_page !== 'All') {
    $stmt->bindValue(':offset', $daily_offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $daily_per_page, PDO::PARAM_INT);
}
$stmt->execute();
$daily_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sorting and Pagination for Student Payment Status
$status_sort_column = $_GET['status_sort'] ?? 'balance';
$status_sort_order = $_GET['status_order'] ?? 'DESC';
$valid_status_columns = ['reg_number', 'full_name', 'expected', 'paid', 'balance'];
$status_sort_column = in_array($status_sort_column, $valid_status_columns) ? $status_sort_column : 'balance';
$status_sort_order = strtoupper($status_sort_order) === 'ASC' ? 'ASC' : 'DESC';
$status_next_order = $status_sort_order === 'ASC' ? 'DESC' : 'ASC';

$status_page = max(1, $_GET['status_page'] ?? 1);
$status_per_page_options = [10, 50, 100, 'All'];
$status_per_page = $_GET['status_per_page'] ?? 10;
if (!in_array($status_per_page, $valid_status_columns)) $status_per_page = 10;
$status_offset = ($status_page - 1) * $status_per_page;

// 2. Student Payment Status
$status_sql = "
    SELECT 
        s.id, s.reg_number, CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS full_name,
        COALESCE((SELECT SUM(i.amount_due) FROM invoices i WHERE i.student_id = s.id), 0) AS expected,
        COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.student_id = s.id), 0) AS paid,
        COALESCE((SELECT SUM(i.amount_due) FROM invoices i WHERE i.student_id = s.id), 0) - 
        COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.student_id = s.id), 0) AS balance
    FROM students s
    WHERE s.deleted_at IS NULL
";
$status_order_by = '';
switch ($status_sort_column) {
    case 'reg_number':
        $status_order_by = "s.reg_number $status_sort_order";
        break;
    case 'full_name':
        $status_order_by = "full_name $status_sort_order";
        break;
    case 'expected':
        $status_order_by = "expected $status_sort_order";
        break;
    case 'paid':
        $status_order_by = "paid $status_sort_order";
        break;
    case 'balance':
        $status_order_by = "balance $status_sort_order";
        break;
}
$status_sql .= " ORDER BY $status_order_by";

// Fetch total student status records for pagination
$total_status_sql = "SELECT COUNT(*) FROM students s WHERE s.deleted_at IS NULL";
$stmt = $pdo->query($total_status_sql);
$total_status = $stmt->fetchColumn();
$status_total_pages = ($status_per_page === 'All') ? 1 : ceil($total_status / $status_per_page);

// Fetch paginated student status
$display_status_sql = $status_sql;
if ($status_per_page !== 'All') {
    $display_status_sql .= " LIMIT :offset, :per_page";
}
$stmt = $pdo->prepare($display_status_sql);
if ($status_per_page !== 'All') {
    $stmt->bindValue(':offset', $status_offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $status_per_page, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $stmt->execute();
}
$student_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sorting and Pagination for Cumulative Debts
$debts_sort_column = $_GET['debts_sort'] ?? 'debt';
$debts_sort_order = $_GET['debts_order'] ?? 'DESC';
$valid_debts_columns = ['reg_number', 'full_name', 'debt'];
$debts_sort_column = in_array($debts_sort_column, $valid_debts_columns) ? $debts_sort_column : 'debt';
$debts_sort_order = strtoupper($debts_sort_order) === 'ASC' ? 'ASC' : 'DESC';
$debts_next_order = $debts_sort_order === 'ASC' ? 'DESC' : 'ASC';

$debts_page = max(1, $_GET['debts_page'] ?? 1);
$debts_per_page_options = [10, 50, 100, 'All'];
$debts_per_page = $_GET['debts_per_page'] ?? 10;
if (!in_array($debts_per_page, $debts_per_page_options)) $debts_per_page = 10;
$debts_offset = ($debts_page - 1) * $debts_per_page;

// 3. Cumulative Debts
$debts_sql = "
    SELECT d.student_id, s.reg_number, CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS full_name,
           SUM(d.amount_due - d.amount_paid) AS debt
    FROM debts d
    JOIN students s ON d.student_id = s.id
    WHERE d.cleared = 0 AND s.deleted_at IS NULL
    GROUP BY d.student_id, s.reg_number, s.first_name, s.surname
    HAVING debt > 0
";
$debts_order_by = '';
switch ($debts_sort_column) {
    case 'reg_number':
        $debts_order_by = "s.reg_number $debts_sort_order";
        break;
    case 'full_name':
        $debts_order_by = "full_name $debts_sort_order";
        break;
    case 'debt':
        $debts_order_by = "debt $debts_sort_order";
        break;
}
$debts_sql .= " ORDER BY $debts_order_by";

// Fetch total debts for pagination
$total_debts_sql = "
    SELECT COUNT(DISTINCT d.student_id)
    FROM debts d
    JOIN students s ON d.student_id = s.id
    WHERE d.cleared = 0 AND s.deleted_at IS NULL
    AND (d.amount_due - d.amount_paid) > 0
";
$stmt = $pdo->query($total_debts_sql);
$total_debts = $stmt->fetchColumn();
$debts_total_pages = ($debts_per_page === 'All') ? 1 : ceil($total_debts / $debts_per_page);

// Fetch paginated debts
$display_debts_sql = $debts_sql;
if ($debts_per_page !== 'All') {
    $display_debts_sql .= " LIMIT :offset, :per_page";
}
$stmt = $pdo->prepare($display_debts_sql);
if ($debts_per_page !== 'All') {
    $stmt->bindValue(':offset', $debts_offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $debts_per_page, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $stmt->execute();
}
$cumulative_debts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch levels for the level filter dropdown
$levels = $pdo->query("SELECT l.id, l.name, p.name AS program_name FROM levels l JOIN programs p ON l.program_id = p.id ORDER BY p.name, l.sequence")->fetchAll(PDO::FETCH_ASSOC);

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_payment'])) {
    $student_id = $_POST['student_id'];
    $amount_paid = floatval($_POST['amount_paid']);
    $payment_mode = $_POST['payment_mode'];
    $mpesa_code = ($payment_mode === 'MPESA') ? ($_POST['mpesa_code'] ?: null) : null;
    $payment_purpose = $_POST['payment_purpose'];
    $other_purpose = ($payment_purpose === 'Any Other') ? ($_POST['other_purpose'] ?: null) : null;
    $session_id = $_POST['session_id'] ?: null;
    $from_balance = isset($_POST['from_balance']) ? 1 : 0;
    $is_prepayment = isset($_POST['is_prepayment']) ? 1 : 0;

    // Validate inputs
    if (empty($student_id) || $amount_paid <= 0 || empty($payment_mode) || empty($payment_purpose)) {
        $error = "Student, amount, payment mode, and purpose are required.";
    } elseif ($payment_purpose === 'Any Other' && empty($other_purpose)) {
        $error = "Please specify the purpose for 'Any Other'.";
    } else {
        try {
            $pdo->beginTransaction();

            // Fetch the student's level_id from the enrollments table
            $current_year = date('Y');
            $stmt = $pdo->prepare("
                SELECT level_id
                FROM enrollments
                WHERE student_id = ? AND academic_year = ?
                LIMIT 1
            ");
            $stmt->execute([$student_id, $current_year]);
            $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$enrollment) {
                throw new Exception("Student is not enrolled in any level for the current academic year.");
            }
            $level_id = $enrollment['level_id'];

            // Calculate balance before the payment (balance_bf)
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE((SELECT SUM(i.amount_due) FROM invoices i WHERE i.student_id = ?), 0) AS total_expected,
                    COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.student_id = ?), 0) AS total_paid
            ");
            $stmt->execute([$student_id, $student_id]);
            $balance_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $balance_bf = $balance_data['total_expected'] - $balance_data['total_paid'];

            // If payment is for Class Fees, allocate to oldest unpaid invoices
            if ($payment_purpose === 'Class Fees' && !$from_balance && !$is_prepayment) {
                // Fetch unpaid invoices ordered by date (oldest first)
                $stmt = $pdo->prepare("
                    SELECT i.id, i.amount_due, COALESCE(SUM(p.amount_paid), 0) AS amount_paid
                    FROM invoices i
                    LEFT JOIN payments p ON p.invoice_id = i.id
                    WHERE i.student_id = ?
                    GROUP BY i.id
                    HAVING amount_due > amount_paid
                    ORDER BY i.invoice_date ASC
                ");
                $stmt->execute([$student_id]);
                $unpaid_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $remaining_amount = $amount_paid;

                foreach ($unpaid_invoices as $invoice) {
                    if ($remaining_amount <= 0) break;

                    $invoice_id = $invoice['id'];
                    $outstanding = $invoice['amount_due'] - $invoice['amount_paid'];
                    $amount_to_allocate = min($remaining_amount, $outstanding);

                    // Record the payment for this invoice
                    $stmt = $pdo->prepare("
                        INSERT INTO payments (student_id, invoice_id, amount_paid, payment_mode, mpesa_code, payment_purpose, payment_date, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$student_id, $invoice_id, $amount_to_allocate, $payment_mode, $mpesa_code, $payment_purpose, $_SESSION['user_id']]);
                    $payment_id = $pdo->lastInsertId();

                    // Update the invoice's paid amount (simplified; assuming debts table handles remaining balance)
                    $stmt = $pdo->prepare("
                        UPDATE debts
                        SET amount_paid = amount_paid + ?
                        WHERE invoice_id = ? AND cleared = 0
                    ");
                    $stmt->execute([$amount_to_allocate, $invoice_id]);

                    // Check if the invoice is fully paid
                    $stmt = $pdo->prepare("
                        SELECT amount_due, amount_paid
                        FROM debts
                        WHERE invoice_id = ?
                    ");
                    $stmt->execute([$invoice_id]);
                    $debt = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($debt['amount_due'] <= $debt['amount_paid']) {
                        $stmt = $pdo->prepare("UPDATE debts SET cleared = 1 WHERE invoice_id = ?");
                        $stmt->execute([$invoice_id]);
                    }

                    // Calculate balance after this payment (balance_cf)
                    $balance_cf = $balance_bf - $amount_to_allocate;

                    // Insert receipt
                    $stmt = $pdo->prepare("
                        INSERT INTO receipts (payment_id, receipt_number, balance_bf, balance_cf, issued_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $receipt_number = 'REC-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);
                    $stmt->execute([$payment_id, $receipt_number, $balance_bf, $balance_cf]);

                    // Log the payment in audit trail
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$_SESSION['user_id'], 'Recorded Payment', 'payments', $payment_id, "Student ID: $student_id, Amount: $amount_to_allocate, Invoice ID: $invoice_id"]);

                    $remaining_amount -= $amount_to_allocate;
                    $balance_bf = $balance_cf; // Update balance_bf for the next iteration
                }

                // If there's remaining amount, record it as a prepayment
                if ($remaining_amount > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments (student_id, amount_paid, payment_mode, mpesa_code, payment_purpose, is_prepayment, payment_date, created_by)
                        VALUES (?, ?, ?, ?, ?, 1, NOW(), ?)
                    ");
                    $stmt->execute([$student_id, $remaining_amount, $payment_mode, $mpesa_code, $payment_purpose, $_SESSION['user_id']]);
                    $prepayment_id = $pdo->lastInsertId();

                    // Calculate balance after prepayment (balance_cf)
                    $balance_cf = $balance_bf - $remaining_amount;

                    // Insert receipt for prepayment
                    $stmt = $pdo->prepare("
                        INSERT INTO receipts (payment_id, receipt_number, balance_bf, balance_cf, issued_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $receipt_number = 'REC-' . str_pad($prepayment_id, 6, '0', STR_PAD_LEFT);
                    $stmt->execute([$prepayment_id, $receipt_number, $balance_bf, $balance_cf]);

                    // Log the prepayment in audit trail
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$_SESSION['user_id'], 'Recorded Prepayment', 'payments', $prepayment_id, "Student ID: $student_id, Amount: $remaining_amount"]);
                }
            } else {
                // For other payment purposes, record the payment normally
                $stmt = $pdo->prepare("
                    INSERT INTO payments (student_id, invoice_id, amount_paid, payment_mode, mpesa_code, payment_purpose, payment_date, from_balance, is_prepayment, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
                ");
                $payment_purpose_value = $payment_purpose === 'Any Other' ? "$payment_purpose: $other_purpose" : $payment_purpose;
                $stmt->execute([$student_id, $session_id, $amount_paid, $payment_mode, $mpesa_code, $payment_purpose_value, $from_balance, $is_prepayment, $_SESSION['user_id']]);
                $payment_id = $pdo->lastInsertId();

                // If linked to an invoice, update the debts table
                if ($session_id) {
                    $stmt = $pdo->prepare("
                        UPDATE debts
                        SET amount_paid = amount_paid + ?
                        WHERE invoice_id = (
                            SELECT id FROM invoices WHERE session_id = ? AND student_id = ?
                        ) AND cleared = 0
                    ");
                    $stmt->execute([$amount_paid, $session_id, $student_id]);

                    // Check if the invoice is fully paid
                    $stmt = $pdo->prepare("
                        SELECT amount_due, amount_paid
                        FROM debts
                        WHERE invoice_id = (
                            SELECT id FROM invoices WHERE session_id = ? AND student_id = ?
                        )
                    ");
                    $stmt->execute([$session_id, $student_id]);
                    $debt = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($debt && $debt['amount_due'] <= $debt['amount_paid']) {
                        $stmt = $pdo->prepare("UPDATE debts SET cleared = 1 WHERE invoice_id = (SELECT id FROM invoices WHERE session_id = ? AND student_id = ?)");
                        $stmt->execute([$session_id, $student_id]);
                    }
                }

                // Calculate balance after this payment (balance_cf)
                $balance_cf = $balance_bf - $amount_paid;

                // Insert receipt
                $stmt = $pdo->prepare("
                    INSERT INTO receipts (payment_id, receipt_number, balance_bf, balance_cf, issued_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $receipt_number = 'REC-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);
                $stmt->execute([$payment_id, $receipt_number, $balance_bf, $balance_cf]);

                // Log the payment in audit trail
                $stmt = $pdo->prepare("
                    INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$_SESSION['user_id'], 'Recorded Payment', 'payments', $payment_id, "Student ID: $student_id, Amount: $amount_paid"]);
            }

            $pdo->commit();
            $success = "Payment recorded successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error recording payment: " . $e->getMessage();
        }
    }
}

// Handle thermal printer receipt
if (isset($_GET['print_receipt']) && isset($_GET['payment_id'])) {
    $payment_id = filter_var($_GET['payment_id'], FILTER_VALIDATE_INT);
    if ($payment_id === false || $payment_id <= 0) {
        $error = "Invalid payment ID.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, s.reg_number, s.first_name, s.surname, r.receipt_number, r.balance_bf, r.balance_cf
                FROM payments p
                JOIN students s ON p.student_id = s.id
                LEFT JOIN receipts r ON p.id = r.payment_id
                WHERE p.id = ? AND s.deleted_at IS NULL
            ");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                // ESC/POS commands for 80mm thermal printer
                $esc = "\x1B";
                $gs = "\x1D";
                $nl = "\n";

                // Receipt content
                $receipt = $esc . "@"; // Initialize printer
                $receipt .= $esc . "a1"; // Center align
                $receipt .= "NPBC IGAC Uthiru Center\n";
                $receipt .= "RECEIPT\n";
                $receipt .= "----------------------------------------\n";
                $receipt .= $esc . "a0"; // Left align
                $receipt .= "Receipt No: " . ($payment['receipt_number'] ?? 'N/A') . $nl;
                $receipt .= "Date: " . date('Y-m-d H:i:s') . $nl;
                $receipt .= "Student: " . htmlspecialchars($payment['first_name'] . ' ' . $payment['surname']) . $nl;
                $receipt .= "Reg No: " . htmlspecialchars($payment['reg_number']) . $nl;
                $receipt .= "----------------------------------------\n";
                $receipt .= "Amount: KES " . number_format($payment['amount_paid'], 2) . $nl;
                $receipt .= "Mode: " . ($payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : htmlspecialchars($payment['payment_mode'] . ($payment['mpesa_code'] ? ' (' . $payment['mpesa_code'] . ')' : '')))) . $nl;
                $receipt .= "Purpose: " . htmlspecialchars($payment['payment_purpose'] ?? 'N/A') . $nl;
                $receipt .= "Balance B/F: KES " . number_format($payment['balance_bf'] ?? 0, 2) . $nl;
                $receipt .= "Balance C/F: KES " . number_format($payment['balance_cf'] ?? 0, 2) . $nl;
                $receipt .= "----------------------------------------\n";
                $receipt .= $esc . "a0"; // Left align
                $receipt .= "Printed by: " . $admin_name . $nl;
                $receipt .= $esc . "a1"; // Center align
                $receipt .= "Thank you!\n";
                $receipt .= $nl . $nl . $nl;
                $receipt .= $gs . "V0"; // Cut paper

                // Send file as download
                $clean_surname = preg_replace('/[^a-zA-Z0-9]/', '', $payment['surname']);
                $clean_firstname = preg_replace('/[^a-zA-Z0-9]/', '', $payment['first_name']);
                $filename = 'rcpt_' . $clean_surname . $clean_firstname . '_' . date('YmdHis') . '.bin';
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($receipt));
                echo $receipt;
                exit;
            } else {
                $error = "Payment not found or student deleted.";
            }
        } catch (PDOException $e) {
            $error = "Error generating receipt: " . $e->getMessage();
        }
    }
}

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('NPBC IGAC Uthiru Center');
    $pdf->SetTitle('Payments Report - ' . $date_filter);
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();

    $html = "<h1>NPBC IGAC Uthiru Center</h1>";
    $html .= "<h1>Payments Report - " . htmlspecialchars($date_filter) . "</h1>";

    // Daily Payments
    $html .= "<h2>Daily Payments</h2>";
    if (empty($daily_payments)) {
        $html .= "<p>No payments recorded for this date.</p>";
    } else {
        $html .= "<table border='1'><tr><th>Reg Number</th><th>Student</th><th>Amount (KES)</th><th>Mode</th><th>Purpose</th><th>Date</th></tr>";
        foreach ($daily_payments as $payment) {
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($payment['reg_number']) . "</td>";
            $html .= "<td>" . htmlspecialchars($payment['full_name']) . "</td>";
            $html .= "<td>" . number_format($payment['amount_paid'], 2) . "</td>";
            $html .= "<td>" . ($payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : htmlspecialchars($payment['payment_mode']))) . "</td>";
            $html .= "<td>" . htmlspecialchars($payment['payment_purpose'] ?: 'N/A') . "</td>";
            $html .= "<td>" . htmlspecialchars($payment['payment_date']) . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
    }

    // Student Payment Status
    $html .= "<h2>Student Payment Status</h2>";
    $html .= "<table border='1'><tr><th>Reg Number</th><th>Student</th><th>Expected (KES)</th><th>Paid (KES)</th><th>Balance (KES)</th></tr>";
    foreach ($student_status as $status) {
        $html .= "<tr>";
        $html .= "<td>" . htmlspecialchars($status['reg_number']) . "</td>";
        $html .= "<td>" . htmlspecialchars($status['full_name']) . "</td>";
        $html .= "<td>" . number_format($status['expected'], 2) . "</td>";
        $html .= "<td>" . number_format($status['paid'], 2) . "</td>";
        $html .= "<td>" . number_format($status['balance'], 2) . "</td>";
        $html .= "</tr>";
    }
    $html .= "</table>";

    // Cumulative Debts
    $html .= "<h2>Cumulative Debts</h2>";
    if (empty($cumulative_debts)) {
        $html .= "<p>No outstanding debts.</p>";
    } else {
        $html .= "<table border='1'><tr><th>Reg Number</th><th>Student</th><th>Total Debt (KES)</th></tr>";
        foreach ($cumulative_debts as $debt) {
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($debt['reg_number']) . "</td>";
            $html .= "<td>" . htmlspecialchars($debt['full_name']) . "</td>";
            $html .= "<td>" . number_format($debt['debt'], 2) . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
    }

    $html .= "<p>Printed by: " . $admin_name . "</p>";
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('payments_report_' . $date_filter . '.pdf', 'I');
    exit;
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $row = 1;

    $sheet->setCellValue('A' . $row, 'NPBC IGAC Uthiru Center');
    $row++;
    $sheet->setCellValue('A' . $row, 'Payments Report - ' . $date_filter);
    $row += 2;

    // Daily Payments
    $sheet->setCellValue('A' . $row, 'Daily Payments');
    $row++;
    $sheet->setCellValue('A' . $row, 'Reg Number');
    $sheet->setCellValue('B' . $row, 'Student');
    $sheet->setCellValue('C' . $row, 'Amount (KES)');
    $sheet->setCellValue('D' . $row, 'Mode');
    $sheet->setCellValue('E' . $row, 'Purpose');
    $sheet->setCellValue('F' . $row, 'Date');
    $row++;
    if (!empty($daily_payments)) {
        foreach ($daily_payments as $payment) {
            $sheet->setCellValue('A' . $row, $payment['reg_number']);
            $sheet->setCellValue('B' . $row, $payment['full_name']);
            $sheet->setCellValue('C' . $row, number_format($payment['amount_paid'], 2));
            $sheet->setCellValue('D' . $row, $payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : $payment['payment_mode']));
            $sheet->setCellValue('E' . $row, $payment['payment_purpose'] ?: 'N/A');
            $sheet->setCellValue('F' . $row, $payment['payment_date']);
            $row++;
        }
    } else {
        $sheet->setCellValue('A' . $row, 'No payments recorded for this date.');
        $row++;
    }
    $row++;

    // Student Payment Status
    $sheet->setCellValue('A' . $row, 'Student Payment Status');
    $row++;
    $sheet->setCellValue('A' . $row, 'Reg Number');
    $sheet->setCellValue('B' . $row, 'Student');
    $sheet->setCellValue('C' . $row, 'Expected (KES)');
    $sheet->setCellValue('D' . $row, 'Paid (KES)');
    $sheet->setCellValue('E' . $row, 'Balance (KES)');
    $row++;
    foreach ($student_status as $status) {
        $sheet->setCellValue('A' . $row, $status['reg_number']);
        $sheet->setCellValue('B' . $row, $status['full_name']);
        $sheet->setCellValue('C' . $row, number_format($status['expected'], 2));
        $sheet->setCellValue('D' . $row, number_format($status['paid'], 2));
        $sheet->setCellValue('E' . $row, number_format($status['balance'], 2));
        $row++;
    }
    $row++;

    // Cumulative Debts
    $sheet->setCellValue('A' . $row, 'Cumulative Debts');
    $row++;
    $sheet->setCellValue('A' . $row, 'Reg Number');
    $sheet->setCellValue('B' . $row, 'Student');
    $sheet->setCellValue('C' . $row, 'Total Debt (KES)');
    $row++;
    if (!empty($cumulative_debts)) {
        foreach ($cumulative_debts as $debt) {
            $sheet->setCellValue('A' . $row, $debt['reg_number']);
            $sheet->setCellValue('B' . $row, $debt['full_name']);
            $sheet->setCellValue('C' . $row, number_format($debt['debt'], 2));
            $row++;
        }
    } else {
        $sheet->setCellValue('A' . $row, 'No outstanding debts.');
        $row++;
    }

    // Add Printed by at the bottom
    $row++;
    $sheet->setCellValue('A' . $row, 'Printed by: ' . $admin_name);

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="payments_report_' . $date_filter . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<h2>Payments Overview - <?php echo htmlspecialchars(date('Y-m-d', strtotime($date_filter))); ?></h2>

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
        <?php if (empty($payment_dates)): ?>
            <option value="<?php echo date('Y-m-d'); ?>">No payments recorded</option>
        <?php else: ?>
            <?php foreach ($payment_dates as $date): ?>
                <option value="<?php echo $date; ?>" <?php echo $date_filter === $date ? 'selected' : ''; ?>>
                    <?php echo date('m/d/Y', strtotime($date)); ?>
                </option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <button type="submit">Filter</button>
    <a href="?export=pdf&date=<?php echo urlencode($date_filter); ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>"><button type="button">Export to PDF</button></a>
    <a href="?export=excel&date=<?php echo urlencode($date_filter); ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>"><button type="button">Export to Excel</button></a>
</form>

<!-- Record Payment Button and Form -->
<button id="togglePaymentForm" onclick="togglePaymentForm()">Record Payment</button>

<div id="paymentForm" style="display: <?php echo $make_payment ? 'block' : 'none'; ?>;">
    <h3>Record New Payment</h3>
    <form method="POST" action="">
        <input type="hidden" name="record_payment" value="1">
        <label>Level:</label>
        <select name="level_id" id="level_id" onchange="filterStudents()" required <?php echo $make_payment ? 'disabled' : ''; ?>>
            <option value="">Select Level</option>
            <?php foreach ($levels as $level): ?>
                <option value="<?php echo $level['id']; ?>" <?php echo ($make_payment && $level_id == $level['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Student:</label>
        <select name="student_id" id="student_id" onchange="filterSessions()" required <?php echo $make_payment ? 'disabled' : ''; ?>>
            <option value="">Select Student</option>
            <?php if ($make_payment && $selected_student): ?>
                <option value="<?php echo $selected_student['id']; ?>" selected>
                    <?php echo htmlspecialchars($selected_student['reg_number'] . ' - ' . $selected_student['first_name'] . ' ' . ($selected_student['other_name'] ? $selected_student['other_name'] . ' ' : '') . $selected_student['surname']); ?>
                </option>
            <?php endif; ?>
        </select>
        <label>Amount Paid (KES):</label>
        <input type="number" name="amount_paid" step="0.01" min="0" required>
        <label>Payment Mode:</label>
        <select name="payment_mode" id="payment_mode" required onchange="toggleMpesaField()">
            <option value="Cash">Cash</option>
            <option value="MPESA">MPESA</option>
        </select>
        <div id="mpesa_field" style="display: none;">
            <label>MPESA Code:</label>
            <input type="text" name="mpesa_code">
        </div>
        <label>Payment Purpose:</label>
        <select name="payment_purpose" id="payment_purpose" required onchange="toggleOtherPurposeField()">
            <option value="">Select Purpose</option>
            <option value="Class Fees">Class Fees</option>
            <option value="Registration Fees">Registration Fees</option>
            <option value="Graduation Fee">Graduation Fee</option>
            <option value="Any Other">Any Other</option>
        </select>
        <div id="other_purpose_field" style="display: none;">
            <label>Specify Purpose:</label>
            <input type="text" name="other_purpose" id="other_purpose">
        </div>
        <label>Class Session (optional):</label>
        <select name="session_id" id="session_id">
            <option value="">Select Session (if applicable)</option>
        </select>
        <!-- Note: To enable multiple selections, change the above to: -->
        <!-- <select name="session_ids[]" id="session_id" multiple> -->
        <label>Use Prepaid Balance:</label>
        <input type="checkbox" name="from_balance" value="1">
        <label>Is Prepayment:</label>
        <input type="checkbox" name="is_prepayment" value="1">
        <button type="submit">Record Payment</button>
        <?php if ($make_payment): ?>
            <a href="students.php">Cancel</a>
        <?php else: ?>
            <a href="#" onclick="togglePaymentForm(); return false;">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<!-- 1. Daily Payment Summary -->
<h3>Daily Payments</h3>
<form method="GET">
    <label>Records per page:</label>
    <select name="daily_per_page" onchange="this.form.submit()">
        <?php foreach ($daily_per_page_options as $option): ?>
            <option value="<?php echo $option; ?>" <?php echo $daily_per_page == $option ? 'selected' : ''; ?>>
                <?php echo $option; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
    <input type="hidden" name="daily_sort" value="<?php echo htmlspecialchars($daily_sort_column); ?>">
    <input type="hidden" name="daily_order" value="<?php echo htmlspecialchars($daily_sort_order); ?>">
    <input type="hidden" name="status_per_page" value="<?php echo htmlspecialchars($status_per_page); ?>">
    <input type="hidden" name="debts_per_page" value="<?php echo htmlspecialchars($debts_per_page); ?>">
</form>
<?php if (empty($daily_payments)): ?>
    <p>No payments recorded for this date.</p>
<?php else: ?>
    <table class="table-striped">
        <thead>
            <tr>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&daily_sort=reg_number&daily_order=<?php echo $daily_sort_column == 'reg_number' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Reg Number <?php echo $daily_sort_column == 'reg_number' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&daily_sort=full_name&daily_order=<?php echo $daily_sort_column == 'full_name' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Student <?php echo $daily_sort_column == 'full_name' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&daily_sort=amount_paid&daily_order=<?php echo $daily_sort_column == 'amount_paid' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Amount (KES) <?php echo $daily_sort_column == 'amount_paid' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&daily_sort=payment_mode&daily_order=<?php echo $daily_sort_column == 'payment_mode' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Mode <?php echo $daily_sort_column == 'payment_mode' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&daily_sort=payment_purpose&daily_order=<?php echo $daily_sort_column == 'payment_purpose' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Purpose <?php echo $daily_sort_column == 'payment_purpose' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&daily_sort=payment_date&daily_order=<?php echo $daily_sort_column == 'payment_date' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Date <?php echo $daily_sort_column == 'payment_date' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($daily_payments as $payment): ?>
                <tr>
                    <td><?php echo htmlspecialchars($payment['reg_number']); ?></td>
                    <td><?php echo htmlspecialchars($payment['full_name']); ?></td>
                    <td><?php echo number_format($payment['amount_paid'], 2); ?></td>
                    <td><?php echo $payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : htmlspecialchars($payment['payment_mode'])); ?></td>
                    <td><?php echo htmlspecialchars($payment['payment_purpose'] ?: 'N/A'); ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($payment['payment_date'])); ?></td>
                    <td>
                        <a href="view_invoices.php?student_id=<?php echo $payment['student_id']; ?>" class="action-link">Invoices</a> |
                        <a href="view_payments.php?student_id=<?php echo $payment['student_id']; ?>" class="action-link">Payments</a> |
                        <a href="?print_receipt=1&payment_id=<?php echo $payment['id']; ?>" class="action-link">Print Receipt</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($daily_per_page !== 'All'): ?>
        <nav aria-label="Daily Payments pagination">
            <ul class="pagination">
                <?php for ($i = 1; $i <= $daily_total_pages; $i++): ?>
                    <li class="page-item <?php echo $i === $daily_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?date=<?php echo urlencode($date_filter); ?>&daily_sort=<?php echo $daily_sort_column; ?>&daily_order=<?php echo $daily_sort_order; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&daily_page=<?php echo $i; ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- 2. Student Payment Status -->
<h3>Student Payment Status</h3>
<form method="GET">
    <label>Records per page:</label>
    <select name="status_per_page" onchange="this.form.submit()">
        <?php foreach ($status_per_page_options as $option): ?>
            <option value="<?php echo $option; ?>" <?php echo $status_per_page == $option ? 'selected' : ''; ?>>
                <?php echo $option; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
    <input type="hidden" name="status_sort" value="<?php echo htmlspecialchars($status_sort_column); ?>">
    <input type="hidden" name="status_order" value="<?php echo htmlspecialchars($status_sort_order); ?>">
    <input type="hidden" name="daily_per_page" value="<?php echo htmlspecialchars($daily_per_page); ?>">
    <input type="hidden" name="debts_per_page" value="<?php echo htmlspecialchars($debts_per_page); ?>">
</form>
<table class="table-striped">
    <thead>
        <tr>
            <th><a href="?date=<?php echo urlencode($date_filter); ?>&status_sort=reg_number&status_order=<?php echo $status_sort_column == 'reg_number' ? $status_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Reg Number <?php echo $status_sort_column == 'reg_number' ? ($status_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
            <th><a href="?date=<?php echo urlencode($date_filter); ?>&status_sort=full_name&status_order=<?php echo $status_sort_column == 'full_name' ? $status_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Student <?php echo $status_sort_column == 'full_name' ? ($status_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
            <th><a href="?date=<?php echo urlencode($date_filter); ?>&status_sort=expected&status_order=<?php echo $status_sort_column == 'expected' ? $status_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Expected (KES) <?php echo $status_sort_column == 'expected' ? ($status_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
            <th><a href="?date=<?php echo urlencode($date_filter); ?>&status_sort=paid&status_order=<?php echo $status_sort_column == 'paid' ? $status_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Paid (KES) <?php echo $status_sort_column == 'paid' ? ($status_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
            <th><a href="?date=<?php echo urlencode($date_filter); ?>&status_sort=balance&status_order=<?php echo $status_sort_column == 'balance' ? $status_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Balance (KES) <?php echo $status_sort_column == 'balance' ? ($status_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($student_status as $status): ?>
            <tr>
                <td><?php echo htmlspecialchars($status['reg_number']); ?></td>
                <td><?php echo htmlspecialchars($status['full_name']); ?></td>
                <td><?php echo number_format($status['expected'], 2); ?></td>
                <td><?php echo number_format($status['paid'], 2); ?></td>
                <td class="<?php echo $status['balance'] > 0 ? 'text-danger' : ($status['balance'] < 0 ? 'text-success' : ''); ?>">
                    <?php echo number_format($status['balance'], 2); ?>
                </td>
                <td>
                    <a href="view_invoices.php?student_id=<?php echo $status['id']; ?>" class="action-link">Invoices</a> |
                    <a href="view_payments.php?student_id=<?php echo $status['id']; ?>" class="action-link">Payments</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    </table>
<?php if ($status_per_page !== 'All'): ?>
    <nav aria-label="Student Payment Status pagination">
        <ul class="pagination">
            <?php for ($i = 1; $i <= $status_total_pages; $i++): ?>
                <li class="page-item <?php echo $i === $status_page ? 'active' : ''; ?>">
                    <a class="page-link" href="?date=<?php echo urlencode($date_filter); ?>&status_sort=<?php echo $status_sort_column; ?>&status_order=<?php echo $status_sort_order; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&status_page=<?php echo $i; ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<!-- 3. Cumulative Debts -->
<h3>Cumulative Debts</h3>
<form method="GET">
    <label>Records per page:</label>
    <select name="debts_per_page" onchange="this.form.submit()">
        <?php foreach ($debts_per_page_options as $option): ?>
            <option value="<?php echo $option; ?>" <?php echo $debts_per_page == $option ? 'selected' : ''; ?>
                <?php echo $option; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
    <input type="hidden" name="debts_sort" value="<?php echo htmlspecialchars($debts_sort_column); ?>">
    <input type="hidden" name="debts_order" value="<?php echo htmlspecialchars($debts_sort_order); ?>">
    <input type="hidden" name="daily_per_page" value="<?php echo htmlspecialchars($daily_per_page); ?>">
    <input type="hidden" name="status_per_page" value="<?php echo htmlspecialchars($status_per_page); ?>">
</form>
<?php if (empty($cumulative_debts)): ?>
    <p>No outstanding debts.</p>
<?php else: ?>
    <table class="table-striped">
        <thead>
            <tr>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&debts_sort=reg_number&debts_order=<?php echo $debts_sort_column == 'reg_number' ? $debts_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Reg Number <?php echo $debts_sort_column == 'reg_number' ? ($debts_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&debts_sort=full_name&debts_order=<?php echo $debts_sort_column == 'full_name' ? $debts_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Student <?php echo $debts_sort_column == 'full_name' ? ($debts_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&debts_sort=debt&debts_order=<?php echo $debts_sort_column == 'debt' ? $debts_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>">Total Debt (KES) <?php echo $debts_sort_column == 'debt' ? ($debts_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cumulative_debts as $debt): ?>
                <tr>
                    <td><?php echo htmlspecialchars($debt['reg_number']); ?></td>
                    <td><?php echo htmlspecialchars($debt['full_name']); ?></td>
                    <td class="text-danger"><?php echo number_format($debt['debt'], 2); ?></td>
                    <td>
                        <a href="view_invoices.php?student_id=<?php echo $debt['student_id']; ?>" class="action-link">Invoices</a> |
                        <a href="view_payments.php?student_id=<?php echo $debt['student_id']; ?>" class="action-link">Payments</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($debts_per_page !== 'All'): ?>
        <nav aria-label="Cumulative Debts pagination">
            <ul class="pagination">
                <?php for ($i = 1; $i <= $debts_total_pages; $i++): ?>
                    <li class="page-item <?php echo $i === $debts_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?date=<?php echo urlencode($date_filter); ?>&debts_sort=<?php echo $debts_sort_column; ?>&debts_order=<?php echo $debts_sort_order; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&status_per_page=<?php echo urlencode($status_per_page); ?>&debts_per_page=<?php echo urlencode($debts_per_page); ?>&debts_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<script>
function togglePaymentForm() {
    const form = document.getElementById('paymentForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function toggleMpesaField() {
    const paymentMode = document.getElementById('payment_mode').value;
    const mpesaField = document.getElementById('mpesa_field');
    mpesaField.style.display = paymentMode === 'MPESA' ? 'block' : 'none';
}

function toggleOtherPurposeField() {
    const purpose = document.getElementById('payment_purpose').value;
    const otherPurposeField = document.getElementById('other_purpose_field');
    otherPurposeField.style.display = purpose === 'Any Other' ? 'block' : 'none';
}

function filterStudents() {
    <?php if ($make_payment): ?>
        // Skip dynamic fetching if make_payment is set
        return;
    <?php endif; ?>
    const levelId = document.getElementById('level_id').value;
    const studentSelect = document.getElementById('student_id');
    if (levelId) {
        fetch(`get_students.php?level_id=${levelId}`, {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            studentSelect.innerHTML = '<option value="">Select Student</option>';
            data.forEach(student => {
                const option = document.createElement('option');
                option.value = student.id;
                option.text = `${student.reg_number} - ${student.first_name} ${student.other_name || ''} ${student.surname}`;
                studentSelect.appendChild(option);
            });
            filterSessions(); // Reset session dropdown when student list changes
        })
        .catch(error => console.error('Error fetching students:', error));
    } else {
        studentSelect.innerHTML = '<option value="">Select Student</option>';
        filterSessions();
    }
}

function filterSessions() {
    const studentId = document.getElementById('student_id').value;
    const sessionSelect = document.getElementById('session_id');
    if (studentId) {
        fetch(`get_unpaid_sessions.php?student_id=${studentId}&with_attendance=1`, {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            sessionSelect.innerHTML = '<option value="">Select Session (if applicable)</option>';
            data.forEach(session => {
                const option = document.createElement('option');
                option.value = session.id;
                option.text = `${session.session_date} - ${session.unit_name} (Unpaid: KES ${session.unpaid_amount})`;
                sessionSelect.appendChild(option);
            });
        })
        .catch(error => console.error('Error fetching sessions:', error));
    } else {
        sessionSelect.innerHTML = '<option value="">Select Session (if applicable)</option>';
    }
}

window.onload = function() {
    filterStudents();
    toggleOtherPurposeField();
    toggleMpesaField();
    <?php if ($make_payment): ?>
        filterSessions(); // Load sessions for pre-selected student
    <?php endif; ?>
};
</script>

<style>
/* Align with assets/css/style.css */
.table-striped thead tr th {
    background-color: #2980b9;
    color: white; /* Ensure header text is white */
}
.table-striped th a {
    color: white;
    text-decoration: none;
}
.table-striped th a:hover {
    text-decoration: underline;
}
.action-link {
    color: #2980b9;
    text-decoration: none;
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
form select, form input[type="date"] {
    margin-right: 20px;
    padding: 5px;
}
.text-danger {
    color: red;
}
.text-success {
    color: green;
}
</style>