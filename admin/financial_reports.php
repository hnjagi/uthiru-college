<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk', 'Coordinator'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$error = '';
$report_type = $_GET['report_type'] ?? 'cashflow';
$class_day = $_GET['class_day'] ?? '';
$report_data = [];

// Fetch distinct class dates
$class_dates = $pdo->query("SELECT DISTINCT session_date FROM class_sessions ORDER BY session_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch unit cost from constants
$unit_cost_stmt = $pdo->prepare("SELECT value FROM constants WHERE name = 'Unit fees'");
$unit_cost_stmt->execute();
$unit_cost = $unit_cost_stmt->fetchColumn() ?: 1600;

// Handle initial balances input for expenses report
$initial_balances = [];
if ($report_type === 'expenses' && isset($_POST['initial_cash']) && isset($_POST['initial_mpesa'])) {
    $initial_balances['cash'] = floatval($_POST['initial_cash']);
    $initial_balances['mpesa'] = floatval($_POST['initial_mpesa']);
    // Store in session for future use
    $_SESSION['initial_balances'] = $initial_balances;
} elseif (isset($_SESSION['initial_balances'])) {
    $initial_balances = $_SESSION['initial_balances'];
}

// Generate report based on type
if ($report_type === 'cashflow' && $class_day) {
    $stmt = $pdo->prepare("
        SELECT cs.id, l.name AS level_name, u.name AS unit_name,
               COUNT(DISTINCT a.student_id) AS students_present
        FROM class_sessions cs
        JOIN levels l ON cs.level_id = l.id
        JOIN units u ON cs.unit_id = u.id
        LEFT JOIN attendance a ON cs.id = a.session_id
        WHERE cs.session_date = ?
        GROUP BY cs.id, l.name, u.name
        HAVING COUNT(DISTINCT a.student_id) > 0
        ORDER BY l.name, u.name
    ");
    $stmt->execute([$class_day]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process each session
    foreach ($sessions as &$session) {
        $session_id = $session['id'];

        // Cash and MPesa received
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN p.payment_mode = 'Cash' THEN p.amount_paid ELSE 0 END) AS cash_received,
                SUM(CASE WHEN p.payment_mode = 'MPESA' THEN p.amount_paid ELSE 0 END) AS mpesa_received,
                SUM(p.amount_paid) AS total_received,
                SUM(CASE WHEN p.from_balance = 1 THEN p.amount_paid ELSE 0 END) AS prepaid_redeemed,
                SUM(CASE WHEN p.is_prepayment = 1 THEN p.amount_paid ELSE 0 END) AS prepayments,
                SUM(CASE WHEN p.from_balance = 0 AND p.is_prepayment = 0 AND p.payment_date < ? THEN p.amount_paid ELSE 0 END) AS debts_paid
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            WHERE i.session_id = ? AND DATE(p.payment_date) = ?
        ");
        $stmt->execute([$class_day, $session_id, $class_day]);
        $payment_data = $stmt->fetch(PDO::FETCH_ASSOC);

        // Debts incurred
        $stmt = $pdo->prepare("
            SELECT SUM(d.amount_due - d.amount_paid) AS debts_incurred
            FROM debts d
            JOIN invoices i ON d.invoice_id = i.id
            WHERE i.session_id = ? AND d.debt_date = ? AND d.cleared = 0
        ");
        $stmt->execute([$session_id, $class_day]);
        $debt_data = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ensure students_present is set
        $students_present = isset($session['students_present']) ? $session['students_present'] : 0;
        $session['students_present'] = $students_present;
        $session['cash_received'] = $payment_data['cash_received'] ?? 0;
        $session['mpesa_received'] = $payment_data['mpesa_received'] ?? 0;
        $session['total_received'] = $payment_data['total_received'] ?? 0;
        $session['expected_income'] = $students_present * $unit_cost;
        $session['actual_fees'] = $session['total_received'] - ($payment_data['prepayments'] ?? 0) - ($payment_data['debts_paid'] ?? 0);
        $session['debts_incurred'] = $debt_data['debts_incurred'] ?? 0;
        $session['prepaid_redeemed'] = $payment_data['prepaid_redeemed'] ?? 0;
        $session['balance_check'] = $session['actual_fees'] + $session['debts_incurred'] + $session['prepaid_redeemed'];
    }

    // Remove duplicates by creating a unique key based on level_name and unit_name
    $unique_sessions = [];
    $template = [
        'id' => 0,
        'level_name' => '',
        'unit_name' => '',
        'students_present' => 0,
        'cash_received' => 0,
        'mpesa_received' => 0,
        'total_received' => 0,
        'expected_income' => 0,
        'actual_fees' => 0,
        'debts_incurred' => 0,
        'prepaid_redeemed' => 0,
        'balance_check' => 0
    ];

    foreach ($sessions as $session) {
        $key = $session['level_name'] . ' - ' . $session['unit_name'];
        if (!isset($unique_sessions[$key])) {
            $unique_sessions[$key] = $template;
            $unique_sessions[$key]['id'] = $session['id'];
            $unique_sessions[$key]['level_name'] = $session['level_name'];
            $unique_sessions[$key]['unit_name'] = $session['unit_name'];
        }
        $unique_sessions[$key]['students_present'] += $session['students_present'] ?? 0;
        $unique_sessions[$key]['cash_received'] += $session['cash_received'] ?? 0;
        $unique_sessions[$key]['mpesa_received'] += $session['mpesa_received'] ?? 0;
        $unique_sessions[$key]['total_received'] += $session['total_received'] ?? 0;
        $unique_sessions[$key]['expected_income'] += $session['expected_income'] ?? 0;
        $unique_sessions[$key]['actual_fees'] += $session['actual_fees'] ?? 0;
        $unique_sessions[$key]['debts_incurred'] += $session['debts_incurred'] ?? 0;
        $unique_sessions[$key]['prepaid_redeemed'] += $session['prepaid_redeemed'] ?? 0;
        $unique_sessions[$key]['balance_check'] += $session['balance_check'] ?? 0;
    }
    $sessions = array_values($unique_sessions);

    // Calculate totals after deduplication
    $totals = [
        'students_present' => 0,
        'cash_received' => 0,
        'mpesa_received' => 0,
        'total_received' => 0,
        'expected_income' => 0,
        'actual_fees' => 0,
        'debts_incurred' => 0,
        'prepaid_redeemed' => 0,
        'balance_check' => 0
    ];

    foreach ($sessions as $session) {
        $totals['students_present'] += $session['students_present'];
        $totals['cash_received'] += $session['cash_received'];
        $totals['mpesa_received'] += $session['mpesa_received'];
        $totals['total_received'] += $session['total_received'];
        $totals['expected_income'] += $session['expected_income'];
        $totals['actual_fees'] += $session['actual_fees'];
        $totals['debts_incurred'] += $session['debts_incurred'];
        $totals['prepaid_redeemed'] += $session['prepaid_redeemed'];
        $totals['balance_check'] += $session['balance_check'];
    }

    $report_data['cashflow']['sessions'] = $sessions;
    $report_data['cashflow']['totals'] = $totals;

    // Additional totals
    $stmt = $pdo->prepare("SELECT SUM(amount_paid) AS prepayments FROM payments WHERE DATE(payment_date) = ? AND is_prepayment = 1");
    $stmt->execute([$class_day]);
    $report_data['cashflow']['prepayments_made'] = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(amount_paid) AS debts_paid FROM payments WHERE DATE(payment_date) = ? AND from_balance = 0 AND payment_date < ?");
    $stmt->execute([$class_day, $class_day]);
    $report_data['cashflow']['debts_paid'] = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(amount_paid) AS prepaid_redeemed FROM payments WHERE DATE(payment_date) = ? AND from_balance = 1");
    $stmt->execute([$class_day]);
    $report_data['cashflow']['prepaid_redeemed_total'] = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(amount_due - amount_paid) AS debts_incurred FROM debts WHERE debt_date = ? AND cleared = 0");
    $stmt->execute([$class_day]);
    $report_data['cashflow']['debts_incurred_total'] = $stmt->fetchColumn() ?: 0;
} elseif ($report_type === 'expenses' && $class_day) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.category AS item, e.amount, e.payment_mode, e.payee
        FROM expenses e
        WHERE e.expense_date = ?
    ");
    $stmt->execute([$class_day]);
    $report_data['expenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate received amounts
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN p.payment_mode = 'Cash' THEN p.amount_paid ELSE 0 END) AS cash_received,
            SUM(CASE WHEN p.payment_mode = 'MPESA' THEN p.amount_paid ELSE 0 END) AS mpesa_received
        FROM payments p
        WHERE DATE(p.payment_date) = ?
    ");
    $stmt->execute([$class_day]);
    $received = $stmt->fetch(PDO::FETCH_ASSOC);

    $cash_expenses = array_sum(array_column(array_filter($report_data['expenses'], fn($e) => $e['payment_mode'] === 'Cash'), 'amount'));
    $mpesa_expenses = array_sum(array_column(array_filter($report_data['expenses'], fn($e) => $e['payment_mode'] === 'MPESA'), 'amount'));

    $report_data['expenses_summary'] = [
        'cash_received' => $received['cash_received'] ?? 0,
        'mpesa_received' => $received['mpesa_received'] ?? 0,
        'cash_expenses' => $cash_expenses,
        'mpesa_expenses' => $mpesa_expenses,
        'expected_cash_balance' => ($received['cash_received'] ?? 0) - $cash_expenses,
        'expected_mpesa_balance' => ($received['mpesa_received'] ?? 0) - $mpesa_expenses
    ];
} elseif ($report_type === 'debts' && $class_day) {
    $stmt = $pdo->prepare("
        SELECT cs.id AS session_id, l.name AS level_name, u.name AS unit_name
        FROM class_sessions cs
        JOIN levels l ON cs.level_id = l.id
        JOIN units u ON cs.unit_id = u.id
        WHERE cs.session_date = ?
        ORDER BY l.name, u.name
    ");
    $stmt->execute([$class_day]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report_data['debts'] = [];
    foreach ($sessions as $session) {
        $stmt = $pdo->prepare("
            SELECT s.reg_number, s.first_name, s.other_name, s.surname,
                   COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
                   d.amount_due - COALESCE(SUM(p.amount_paid), 0) AS debt_incurred,
                   (SELECT SUM(d2.amount_due - d2.amount_paid) FROM debts d2 WHERE d2.student_id = s.id AND d2.cleared = 0) AS cumulative_debt
            FROM debts d
            JOIN students s ON d.student_id = s.id
            JOIN invoices i ON d.invoice_id = i.id
            LEFT JOIN payments p ON d.invoice_id = p.invoice_id
            WHERE i.session_id = ? AND d.debt_date = ? AND d.cleared = 0
            GROUP BY s.id
            ORDER BY s.first_name, s.other_name, s.surname
        ");
        $stmt->execute([$session['session_id'], $class_day]);
        $session['students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_data['debts'][] = $session;
    }
} elseif ($report_type === 'college_report' && $class_day) {
    $stmt = $pdo->prepare("
        SELECT cs.id AS session_id, l.name AS level_name, u.name AS unit_name,
               lec.title, lec.full_name AS lecturer_name,
               COUNT(DISTINCT a.student_id) AS students_present
        FROM class_sessions cs
        JOIN levels l ON cs.level_id = l.id
        JOIN units u ON cs.unit_id = u.id
        JOIN lecturers lec ON cs.lecturer_id = lec.id
        LEFT JOIN attendance a ON cs.id = a.session_id
        WHERE cs.session_date = ?
        GROUP BY cs.id
        ORDER BY l.name, u.name
    ");
    $stmt->execute([$class_day]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_students = array_sum(array_column($sessions, 'students_present'));
    $expenses = [
        'food' => 0,
        'center' => 0,
        'coordinator' => 0,
        'lecturer' => 0
    ];

    if ($total_students >= 30) {
        $expenses = ['food' => 4000, 'center' => 4000, 'coordinator' => 1000, 'lecturer' => 5000];
    } elseif ($total_students >= 25) {
        $expenses = ['food' => 3500, 'center' => 3500, 'coordinator' => 1000, 'lecturer' => 5000];
    } elseif ($total_students >= 20) {
        $expenses = ['food' => 3000, 'center' => 3000, 'coordinator' => 500, 'lecturer' => 4000];
    } elseif ($total_students >= 15) {
        $expenses = ['food' => 2500, 'center' => 1500, 'coordinator' => 500, 'lecturer' => 3000];
    } elseif ($total_students >= 10) {
        $expenses = ['food' => 2000, 'center' => 1000, 'coordinator' => 500, 'lecturer' => 3000];
    }

    $report_data['college_report'] = [];
    $totals = [
        'students' => $total_students,
        'income' => 0,
        'food' => $expenses['food'],
        'center' => $expenses['center'],
        'coordinator' => $expenses['coordinator'],
        'lecturer' => $expenses['lecturer'],
        'total_expenses' => 0,
        'balance' => 0
    ];

    foreach ($sessions as &$session) {
        $session['income'] = $session['students_present'] * $unit_cost;
        $totals['income'] += $session['income'];
    }
    $totals['total_expenses'] = $totals['food'] + $totals['center'] + $totals['coordinator'] + $totals['lecturer'];

    // Fetch actual expenses
    $stmt = $pdo->prepare("SELECT category, SUM(amount) AS amount FROM expenses WHERE expense_date = ? GROUP BY category");
    $stmt->execute([$class_day]);
    $actual_expenses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $totals['actual_expenses'] = array_sum($actual_expenses);
    $totals['balance'] = $totals['income'] - $totals['actual_expenses'];

    $report_data['college_report']['sessions'] = $sessions;
    $report_data['college_report']['totals'] = $totals;
    $report_data['college_report']['actual_expenses'] = $actual_expenses;
}

// Export to PDF
if (isset($_GET['export']) && $_GET['export'] === 'pdf' && !empty($report_data)) {
    require_once '../vendor/tcpdf/tcpdf.php';
    $pdf = new TCPDF();
    $pdf->SetMargins(10, 10, 10);

    if ($report_type === 'cashflow') {
        $pdf->AddPage();
        $html = "<h1>Cashflow Report - $class_day</h1>";
        $html .= "<table border='1'><tr><th></th>";
        foreach ($report_data['cashflow']['sessions'] as $session) {
            $html .= "<th>" . htmlspecialchars($session['level_name'] . ' - ' . $session['unit_name']) . "</th>";
        }
        $html .= "<th>Totals</th></tr>";

        $rows = [
            'No of Students Present' => 'students_present',
            'Cash Received' => 'cash_received',
            'MPesa Received' => 'mpesa_received',
            'Total Received' => 'total_received',
            'Expected Income' => 'expected_income',
            'Actual Fees Paid' => 'actual_fees',
            'Debts Incurred' => 'debts_incurred',
            'Prepaid Redeemed' => 'prepaid_redeemed',
            'Balance Check' => 'balance_check'
        ];

        foreach ($rows as $label => $key) {
            $html .= "<tr><td>$label</td>";
            foreach ($report_data['cashflow']['sessions'] as $session) {
                $html .= "<td>" . number_format($session[$key] ?? 0, $key === 'students_present' ? 0 : 2) . "</td>";
            }
            $html .= "<td>" . number_format($report_data['cashflow']['totals'][$key] ?? 0, $key === 'students_present' ? 0 : 2) . "</td></tr>";
        }
        $html .= "</table>";
        $html .= "<h2>Summary</h2>";
        $html .= "<p>Prepayments Made: " . number_format($report_data['cashflow']['prepayments_made'], 2) . "</p>";
        $html .= "<p>Debts Paid: " . number_format($report_data['cashflow']['debts_paid'], 2) . "</p>";
        $html .= "<p>Prepaid Amounts Redeemed: " . number_format($report_data['cashflow']['prepaid_redeemed_total'], 2) . "</p>";
        $html .= "<p>Debts Incurred: " . number_format($report_data['cashflow']['debts_incurred_total'], 2) . "</p>";
        $pdf->writeHTML($html);
    } elseif ($report_type === 'expenses') {
        $pdf->AddPage();
        $html = "<h1>Expenses Report - $class_day</h1>";
        $html .= "<table border='1'><tr><th>Item</th><th>Amount</th><th>Mode of Payment</th><th>Payee</th></tr>";
        foreach ($report_data['expenses'] as $expense) {
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($expense['item']) . "</td>";
            $html .= "<td>" . number_format($expense['amount'], 2) . "</td>";
            $html .= "<td>" . htmlspecialchars($expense['payment_mode']) . "</td>";
            $html .= "<td>" . htmlspecialchars($expense['payee'] ?? 'N/A') . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
        $html .= "<h2>Summary</h2>";
        $html .= "<p>Cash Received: " . number_format($report_data['expenses_summary']['cash_received'], 2) . "</p>";
        $html .= "<p>MPesa Received: " . number_format($report_data['expenses_summary']['mpesa_received'], 2) . "</p>";
        $html .= "<p>Expected Cash Balance: " . number_format($report_data['expenses_summary']['expected_cash_balance'], 2) . "</p>";
        $html .= "<p>Expected MPesa Balance: " . number_format($report_data['expenses_summary']['expected_mpesa_balance'], 2) . "</p>";
        if (!empty($initial_balances)) {
            $html .= "<h2>Balance Comparison</h2>";
            $html .= "<p>Actual Cash Balance: " . number_format($initial_balances['cash'], 2) . " (Difference: " . number_format($initial_balances['cash'] - $report_data['expenses_summary']['expected_cash_balance'], 2) . ")</p>";
            $html .= "<p>Actual MPesa Balance: " . number_format($initial_balances['mpesa'], 2) . " (Difference: " . number_format($initial_balances['mpesa'] - $report_data['expenses_summary']['expected_mpesa_balance'], 2) . ")</p>";
        }
        $pdf->writeHTML($html);
    } elseif ($report_type === 'debts') {
        $pdf->AddPage();
        $html = "<h1>Debts Report - $class_day</h1>";
        foreach ($report_data['debts'] as $session) {
            if (empty($session['students'])) continue;
            $html .= "<h2>" . htmlspecialchars($session['level_name'] . ' - ' . $session['unit_name']) . "</h2>";
            $html .= "<table border='1'><tr><th>Reg Number</th><th>Name</th><th>Amount Paid</th><th>Debt Incurred</th><th>Cumulative Debt</th></tr>";
            foreach ($session['students'] as $student) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($student['reg_number']) . "</td>";
                $html .= "<td>" . htmlspecialchars($student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']) . "</td>";
                $html .= "<td>" . number_format($student['amount_paid'], 2) . "</td>";
                $html .= "<td>" . number_format($student['debt_incurred'], 2) . "</td>";
                $html .= "<td>" . number_format($student['cumulative_debt'], 2) . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
        }
        $pdf->writeHTML($html);
    } elseif ($report_type === 'college_report') {
        $pdf->AddPage();
        $html = "<h1>Report to College - $class_day</h1>";
        $html .= "<table border='1'><tr><th></th>";
        foreach ($report_data['college_report']['sessions'] as $session) {
            $html .= "<th>" . htmlspecialchars($session['unit_name']) . "<br>" . htmlspecialchars($session['title'] . ' ' . $session['lecturer_name']) . "</th>";
        }
        $html .= "<th>TOTALS</th></tr>";

        $rows = [
            'No of Students' => 'students_present',
            'Income' => 'income'
        ];
        foreach ($rows as $label => $key) {
            $html .= "<tr><td>$label</td>";
            foreach ($report_data['college_report']['sessions'] as $session) {
                $html .= "<td>" . number_format($session[$key], 0) . "</td>";
            }
            $html .= "<td>" . number_format($report_data['college_report']['totals'][$key], 0) . "</td></tr>";
        }

        $html .= "<tr><td colspan='".(count($report_data['college_report']['sessions']) + 2)."'><strong>College Calculations</strong></td></tr>";
        $calc_rows = ['Food' => 'food', 'Center' => 'center', 'Coordinator' => 'coordinator', 'Lecturer' => 'lecturer'];
        foreach ($calc_rows as $label => $key) {
            $html .= "<tr><td>$label</td>";
            foreach ($report_data['college_report']['sessions'] as $session) {
                $html .= "<td>-</td>";
            }
            $html .= "<td>" . number_format($report_data['college_report']['totals'][$key], 0) . "</td></tr>";
        }

        $html .= "<tr><td>Sponsored Students</td>";
        foreach ($report_data['college_report']['sessions'] as $session) {
            $html .= "<td>-</td>";
        }
        $html .= "<td>-</td></tr>";

        $html .= "<tr><td><strong>Total Expenses</strong></td>";
        foreach ($report_data['college_report']['sessions'] as $session) {
            $html .= "<td>-</td>";
        }
        $html .= "<td>" . number_format($report_data['college_report']['totals']['total_expenses'], 0) . "</td></tr>";

        $html .= "<tr><td><strong>Balance to College</strong></td>";
        foreach ($report_data['college_report']['sessions'] as $session) {
            $html .= "<td>-</td>";
        }
        $html .= "<td>" . number_format($report_data['college_report']['totals']['balance'], 0) . "</td></tr>";

        $html .= "</table>";
        $pdf->writeHTML($html);
    }

    $pdf->Output("report_$report_type.pdf", 'I');
    exit;
}

// Export to Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel' && !empty($report_data)) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Add Excel export logic for financial reports if needed
    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="report_' . $report_type . '.xlsx"');
    $writer->save('php://output');
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<h2>Financial Reports</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="GET">
    <label>Report Type:</label>
    <select name="report_type" onchange="this.form.submit()">
        <option value="cashflow" <?php echo $report_type === 'cashflow' ? 'selected' : ''; ?>>Cashflow Report</option>
        <option value="expenses" <?php echo $report_type === 'expenses' ? 'selected' : ''; ?>>Expenses Report</option>
        <option value="debts" <?php echo $report_type === 'debts' ? 'selected' : ''; ?>>Debts Report</option>
        <option value="college_report" <?php echo $report_type === 'college_report' ? 'selected' : ''; ?>>Report to College</option>
    </select>

    <label>Class Day:</label>
    <select name="class_day" onchange="this.form.submit()">
        <option value="">Select Date</option>
        <?php foreach ($class_dates as $date): ?>
            <option value="<?php echo $date; ?>" <?php echo ($class_day == $date ? 'selected' : ''); ?>>
                <?php echo htmlspecialchars($date); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (!empty($report_data)): ?>
        <a href="?report_type=<?php echo $report_type; ?>&class_day=<?php echo $class_day; ?>&export=pdf"><button type="button">Export to PDF</button></a>
        <a href="?report_type=<?php echo $report_type; ?>&class_day=<?php echo $class_day; ?>&export=excel"><button type="button">Export to Excel</button></a>
    <?php endif; ?>
</form>

<?php if ($report_type === 'cashflow' && !empty($report_data['cashflow'])): ?>
    <h3>Cashflow Report - <?php echo htmlspecialchars($class_day); ?></h3>
    <table class="standard-table">
        <thead>
            <tr>
                <th></th>
                <?php foreach ($report_data['cashflow']['sessions'] as $session): ?>
                    <th><?php echo htmlspecialchars($session['level_name'] . ' - ' . $session['unit_name']); ?></th>
                <?php endforeach; ?>
                <th>Totals</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $rows = [
                'No of Students Present' => 'students_present',
                'Cash Received' => 'cash_received',
                'MPesa Received' => 'mpesa_received',
                'Total Received' => 'total_received',
                'Expected Income' => 'expected_income',
                'Actual Fees Paid' => 'actual_fees',
                'Debts Incurred' => 'debts_incurred',
                'Prepaid Redeemed' => 'prepaid_redeemed',
                'Balance Check' => 'balance_check'
            ];
            foreach ($rows as $label => $key): ?>
                <tr>
                    <td><?php echo $label; ?></td>
                    <?php foreach ($report_data['cashflow']['sessions'] as $session): ?>
                        <td><?php echo number_format($session[$key] ?? 0, $key === 'students_present' ? 0 : 2); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo number_format($report_data['cashflow']['totals'][$key] ?? 0, $key === 'students_present' ? 0 : 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <h4>Summary</h4>
    <p>Prepayments Made: <?php echo number_format($report_data['cashflow']['prepayments_made'], 2); ?></p>
    <p>Debts Paid: <?php echo number_format($report_data['cashflow']['debts_paid'], 2); ?></p>
    <p>Prepaid Amounts Redeemed: <?php echo number_format($report_data['cashflow']['prepaid_redeemed_total'], 2); ?></p>
    <p>Debts Incurred: <?php echo number_format($report_data['cashflow']['debts_incurred_total'], 2); ?></p>
<?php elseif ($report_type === 'expenses' && $class_day): ?>
    <?php if (empty($initial_balances) && !isset($_POST['initial_cash'])): ?>
        <h3>Enter Initial Balances</h3>
        <form method="POST">
            <label>Initial Cash Balance:</label>
            <input type="number" step="0.01" name="initial_cash" required>
            <label>Initial MPesa Balance:</label>
            <input type="number" step="0.01" name="initial_mpesa" required>
            <button type="submit">Submit</button>
        </form>
    <?php elseif (!empty($report_data['expenses'])): ?>
        <h3>Expenses Report - <?php echo htmlspecialchars($class_day); ?></h3>
        <table class="standard-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Amount</th>
                    <th>Mode of Payment</th>
                    <th>Payee</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_data['expenses'] as $expense): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($expense['item']); ?></td>
                        <td><?php echo number_format($expense['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($expense['payment_mode']); ?></td>
                        <td><?php echo htmlspecialchars($expense['payee'] ?? 'N/A'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <h4>Summary</h4>
        <p>Cash Received: <?php echo number_format($report_data['expenses_summary']['cash_received'], 2); ?></p>
        <p>MPesa Received: <?php echo number_format($report_data['expenses_summary']['mpesa_received'], 2); ?></p>
        <p>Expected Cash Balance: <?php echo number_format($report_data['expenses_summary']['expected_cash_balance'], 2); ?></p>
        <p>Expected MPesa Balance: <?php echo number_format($report_data['expenses_summary']['expected_mpesa_balance'], 2); ?></p>
        <?php if (!empty($initial_balances)): ?>
            <h4>Balance Comparison</h4>
            <p>Actual Cash Balance: <?php echo number_format($initial_balances['cash'], 2); ?>
                (Difference: <?php echo number_format($initial_balances['cash'] - $report_data['expenses_summary']['expected_cash_balance'], 2); ?>)</p>
            <p>Actual MPesa Balance: <?php echo number_format($initial_balances['mpesa'], 2); ?>
                (Difference: <?php echo number_format($initial_balances['mpesa'] - $report_data['expenses_summary']['expected_mpesa_balance'], 2); ?>)</p>
        <?php endif; ?>
    <?php endif; ?>
<?php elseif ($report_type === 'debts' && !empty($report_data['debts'])): ?>
    <h3>Debts Report - <?php echo htmlspecialchars($class_day); ?></h3>
    <?php foreach ($report_data['debts'] as $session): ?>
        <?php if (empty($session['students'])) continue; ?>
        <h4><?php echo htmlspecialchars($session['level_name'] . ' - ' . $session['unit_name']); ?></h4>
        <table class="standard-table">
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Name</th>
                    <th>Amount Paid</th>
                    <th>Debt Incurred</th>
                    <th>Cumulative Debt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($session['students'] as $student): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                        <td><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']); ?></td>
                        <td><?php echo number_format($student['amount_paid'], 2); ?></td>
                        <td><?php echo number_format($student['debt_incurred'], 2); ?></td>
                        <td><?php echo number_format($student['cumulative_debt'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
<?php elseif ($report_type === 'college_report' && !empty($report_data['college_report'])): ?>
    <h3>Report to College - <?php echo htmlspecialchars($class_day); ?></h3>
    <table class="standard-table">
        <thead>
            <tr>
                <th></th>
                <?php foreach ($report_data['college_report']['sessions'] as $session): ?>
                    <th><?php echo htmlspecialchars($session['unit_name']); ?><br><?php echo htmlspecialchars($session['title'] . ' ' . $session['lecturer_name']); ?></th>
                <?php endforeach; ?>
                <th>TOTALS</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $rows = [
                'No of Students' => 'students_present',
                'Income' => 'income'
            ];
            foreach ($rows as $label => $key): ?>
                <tr>
                    <td><?php echo $label; ?></td>
                    <?php foreach ($report_data['college_report']['sessions'] as $session): ?>
                        <td><?php echo number_format($session[$key], 0); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo number_format($report_data['college_report']['totals'][$key], 0); ?></td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td colspan="<?php echo count($report_data['college_report']['sessions']) + 2; ?>"><strong>College Calculations</strong></td>
            </tr>

            <?php
            $calc_rows = ['Food' => 'food', 'Center' => 'center', 'Coordinator' => 'coordinator', 'Lecturer' => 'lecturer'];
            foreach ($calc_rows as $label => $key): ?>
                <tr>
                    <td><?php echo $label; ?></td>
                    <?php foreach ($report_data['college_report']['sessions'] as $session): ?>
                        <td>-</td>
                    <?php endforeach; ?>
                    <td><?php echo number_format($report_data['college_report']['totals'][$key], 0); ?></td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td>Sponsored Students</td>
                <?php foreach ($report_data['college_report']['sessions'] as $session): ?>
                    <td>-</td>
                <?php endforeach; ?>
                <td>-</td>
            </tr>

            <tr>
                <td><strong>Total Expenses</strong></td>
                <?php foreach ($report_data['college_report']['sessions'] as $session): ?>
                    <td>-</td>
                <?php endforeach; ?>
                <td><?php echo number_format($report_data['college_report']['totals']['total_expenses'], 0); ?></td>
            </tr>

            <tr>
                <td><strong>Balance to College</strong></td>
                <?php foreach ($report_data['college_report']['sessions'] as $session): ?>
                    <td>-</td>
                <?php endforeach; ?>
                <td><?php echo number_format($report_data['college_report']['totals']['balance'], 0); ?></td>
            </tr>
        </tbody>
    </table>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
.standard-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.standard-table th, .standard-table td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
}
.standard-table th {
    background-color: #2980b9;
    color: white;
}
</style>