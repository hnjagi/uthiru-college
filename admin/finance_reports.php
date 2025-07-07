<?php
require '../includes/db_connect.php';
if (!in_array($_SESSION['role'], ['Admin', 'Coordinator'])) {
    header("Location: ../login.php");
    exit;
}

$report_type = $_GET['report_type'] ?? 'class_day';
$class_day = $_GET['class_day'] ?? date('Y-m-d');

if ($report_type === 'class_day') {
    $stmt = $pdo->prepare("
        SELECT cs.id, u.name AS unit_name, l.full_name AS lecturer_name,
               SUM(p.amount_paid) AS total_paid,
               SUM(CASE WHEN p.payment_mode = 'Cash' THEN p.amount_paid ELSE 0 END) AS cash_paid,
               SUM(CASE WHEN p.payment_mode = 'MPESA' THEN p.amount_paid ELSE 0 END) AS mpesa_paid,
               COUNT(DISTINCT p.student_id) AS student_count
        FROM class_sessions cs
        JOIN units u ON cs.unit_id = u.id
        JOIN lecturers l ON cs.lecturer_id = l.id
        LEFT JOIN invoices i ON i.session_id = cs.id
        LEFT JOIN payments p ON p.invoice_id = i.id
        WHERE cs.session_date = ?
        GROUP BY cs.id
    ");
    $stmt->execute([$class_day]);
    $class_day_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT SUM(amount) AS total_expenses FROM expenses WHERE expense_date = ? AND deleted_at IS NULL");
    $stmt->execute([$class_day]);
    $total_expenses = $stmt->fetchColumn() ?? 0;

    $stmt = $pdo->prepare("SELECT SUM(p.amount_paid) AS redeemed FROM payments p WHERE p.from_balance = 1 AND DATE(p.payment_date) = ?");
    $stmt->execute([$class_day]);
    $redeemed_prepaid = $stmt->fetchColumn() ?? 0;

    $stmt = $pdo->prepare("SELECT SUM(r.balance_cf) AS prepaid FROM receipts r JOIN payments p ON r.payment_id = p.id WHERE DATE(p.payment_date) = ?");
    $stmt->execute([$class_day]);
    $prepaid = $stmt->fetchColumn() ?? 0;

    $stmt = $pdo->prepare("SELECT SUM(d.amount_due - d.amount_paid) AS debts FROM debts d JOIN invoices i ON d.invoice_id = i.id JOIN class_sessions cs ON i.session_id = cs.id WHERE cs.session_date = ? AND d.cleared = 0");
    $stmt->execute([$class_day]);
    $debts = $stmt->fetchColumn() ?? 0;

    $total_income = array_sum(array_column($class_day_payments, 'total_paid'));
    $cash_at_hand = $total_income - $total_expenses - $redeemed_prepaid + $prepaid - $debts;
}

// Add PDF export logic
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once '../vendor/tcpdf/tcpdf.php';
    $pdf = new TCPDF();
    $pdf->AddPage();
    $html = "<h1>Class Day Finance Report - $class_day</h1>";

    $html .= "<h2>Income Received</h2>";
    $html .= "<table border='1'><tr><th>Unit</th><th>Lecturer</th><th>Cash</th><th>MPESA</th><th>Total</th></tr>";
    foreach ($class_day_payments as $payment) {
        $html .= "<tr>";
        $html .= "<td>" . htmlspecialchars($payment['unit_name']) . "</td>";
        $html .= "<td>" . htmlspecialchars($payment['lecturer_name']) . "</td>";
        $html .= "<td>" . number_format($payment['cash_paid'], 2) . "</td>";
        $html .= "<td>" . number_format($payment['mpesa_paid'], 2) . "</td>";
        $html .= "<td>" . number_format($payment['total_paid'], 2) . "</td>";
        $html .= "</tr>";
    }
    $html .= "</table>";
    $html .= "<p><strong>Total Income:</strong> KES " . number_format($total_income, 2) . "</p>";

    $html .= "<h2>Cashflow Report</h2>";
    $html .= "<table border='1'><tr><th>Description</th><th>Amount (KES)</th></tr>";
    $html .= "<tr><td>Total Income</td><td>" . number_format($total_income, 2) . "</td></tr>";
    $html .= "<tr><td>Prepayments Redeemed</td><td>" . number_format($redeemed_prepaid, 2) . "</td></tr>";
    $html .= "<tr><td>Debts Incurred</td><td>" . number_format($debts, 2) . "</td></tr>";
    $html .= "<tr><td>Prepayments Made</td><td>" . number_format($prepaid, 2) . "</td></tr>";
    $html .= "<tr><td>Expenses</td><td>" . number_format($total_expenses, 2) . "</td></tr>";
    $html .= "<tr><td><strong>Cash at Hand</strong></td><td><strong>" . number_format($cash_at_hand, 2) . "</strong></td></tr>";
    $html .= "</table>";

    $html .= "<h2>NPBC Report</h2>";
    $html .= "<table border='1'><tr><th>Unit</th><th>Lecturer</th><th>Students</th><th>Amount Paid</th></tr>";
    foreach ($class_day_payments as $payment) {
        $html .= "<tr>";
        $html .= "<td>" . htmlspecialchars($payment['unit_name']) . "</td>";
        $html .= "<td>" . htmlspecialchars($payment['lecturer_name']) . "</td>";
        $html .= "<td>" . $payment['student_count'] . "</td>";
        $html .= "<td>" . number_format($payment['total_paid'], 2) . "</td>";
        $html .= "</tr>";
    }
    $html .= "<tr><td colspan='3'><strong>Total Expenses</strong></td><td>" . number_format($total_expenses, 2) . "</td></tr>";
    $html .= "<tr><td colspan='3'><strong>Amount Due to NPBC</strong></td><td>" . number_format($total_income - $total_expenses, 2) . "</td></tr>";
    $html .= "</table>";

    $pdf->writeHTML($html);
    $pdf->Output("finance_report_$class_day.pdf", 'I');
    exit;
}

include '../includes/header.php';
?>
<h2>Finance Reports</h2>
<form method="GET">
    <label>Class Day:</label>
    <input type="date" name="class_day" value="<?php echo $class_day; ?>">
    <button type="submit">Generate</button>
    <a href="?export=pdf&class_day=<?php echo $class_day; ?>"><button type="button">Export to PDF</button></a>
</form>

<?php if ($report_type === 'class_day'): ?>
    <h3>Income Received - <?php echo $class_day; ?></h3>
    <table class="standard-table">
        <thead>
            <tr>
                <th>Unit</th>
                <th>Lecturer</th>
                <th>Cash (KES)</th>
                <th>MPESA (KES)</th>
                <th>Total (KES)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($class_day_payments as $payment): ?>
                <tr>
                    <td><?php echo htmlspecialchars($payment['unit_name']); ?></td>
                    <td><?php echo htmlspecialchars($payment['lecturer_name']); ?></td>
                    <td><?php echo number_format($payment['cash_paid'], 2); ?></td>
                    <td><?php echo number_format($payment['mpesa_paid'], 2); ?></td>
                    <td><?php echo number_format($payment['total_paid'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p><strong>Total Income:</strong> KES <?php echo number_format($total_income, 2); ?></p>

    <h3>Cashflow Report</h3>
    <table class="standard-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Amount (KES)</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Total Income</td><td><?php echo number_format($total_income, 2); ?></td></tr>
            <tr><td>Prepayments Redeemed</td><td><?php echo number_format($redeemed_prepaid, 2); ?></td></tr>
            <tr><td>Debts Incurred</td><td><?php echo number_format($debts, 2); ?></td></tr>
            <tr><td>Prepayments Made</td><td><?php echo number_format($prepaid, 2); ?></td></tr>
            <tr><td>Expenses</td><td><?php echo number_format($total_expenses, 2); ?></td></tr>
            <tr><td><strong>Cash at Hand</strong></td><td><strong><?php echo number_format($cash_at_hand, 2); ?></strong></td></tr>
        </tbody>
    </table>

    <h3>NPBC Report</h3>
    <table class="standard-table">
        <thead>
            <tr>
                <th>Unit</th>
                <th>Lecturer</th>
                <th>Students</th>
                <th>Amount Paid (KES)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($class_day_payments as $payment): ?>
                <tr>
                    <td><?php echo htmlspecialchars($payment['unit_name']); ?></td>
                    <td><?php echo htmlspecialchars($payment['lecturer_name']); ?></td>
                    <td><?php echo $payment['student_count']; ?></td>
                    <td><?php echo number_format($payment['total_paid'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><td colspan="3"><strong>Total Expenses</strong></td><td><?php echo number_format($total_expenses, 2); ?></td></tr>
            <tr><td colspan="3"><strong>Amount Due to NPBC</strong></td><td><?php echo number_format($total_income - $total_expenses, 2); ?></td></tr>
        </tbody>
    </table>
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
    padding: 8px;
    text-align: left;
}
.standard-table th {
    background-color: #2980b9;
    color: white;
}
</style>