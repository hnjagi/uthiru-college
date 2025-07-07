<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

$payment_id = $_GET['payment_id'] ?? null;

if (!$payment_id) {
    die("No payment ID provided.");
}

// Fetch payment and receipt details
$stmt = $pdo->prepare("
    SELECT p.id, p.student_id, p.amount_paid, p.payment_mode, p.payment_purpose, p.is_prepayment, p.from_balance, p.payment_date,
           s.reg_number, CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS student_name,
           r.receipt_number, r.balance_bf, r.balance_cf, r.issued_at
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN receipts r ON p.id = r.payment_id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    die("Receipt not found for this payment.");
}

// Fetch the name of the logged-in admin/clerk
$stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', COALESCE(other_name, ''), ' ', surname) AS served_by FROM administrators WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$served_by = $stmt->fetchColumn() ?: 'Unknown Staff';
?>

<?php include '../includes/header.php'; ?>
<h2>Receipt Details</h2>
<table class="table-striped">
    <tr>
        <th>Receipt Number</th>
        <td><?php echo htmlspecialchars($receipt['receipt_number']); ?></td>
    </tr>
    <tr>
        <th>Student</th>
        <td><?php echo htmlspecialchars($receipt['student_name']); ?></td>
    </tr>
    <tr>
        <th>Reg Number</th>
        <td><?php echo htmlspecialchars($receipt['reg_number']); ?></td>
    </tr>
    <tr>
        <th>Amount Paid</th>
        <td>KES <?php echo number_format($receipt['amount_paid'], 2); ?></td>
    </tr>
    <tr>
        <th>Mode</th>
        <td><?php echo $receipt['from_balance'] ? 'Redeemed from Prepayment' : ($receipt['is_prepayment'] ? 'Prepayment' : htmlspecialchars($receipt['payment_mode'])); ?></td>
    </tr>
    <tr>
        <th>Purpose</th>
        <td><?php echo htmlspecialchars($receipt['payment_purpose'] ?: 'N/A'); ?></td>
    </tr>
    <tr>
        <th>Payment Date</th>
        <td><?php echo date('Y-m-d H:i', strtotime($receipt['payment_date'])); ?></td>
    </tr>
    <tr>
        <th>Balance B/F</th>
        <td>KES <?php echo number_format($receipt['balance_bf'], 2); ?></td>
    </tr>
    <tr>
        <th>Balance C/F</th>
        <td>KES <?php echo number_format($receipt['balance_cf'], 2); ?></td>
    </tr>
    <tr>
        <th>Served By</th>
        <td><?php echo htmlspecialchars($served_by); ?></td>
    </tr>
    <tr>
        <th>Actions</th>
        <td>
            <a href="print_receipt.php?payment_id=<?php echo $receipt['id']; ?>" class="action-link">Print Receipt</a>
        </td>
    </tr>
</table>

<?php include '../includes/footer.php'; ?>

<style>
.table-striped th {
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
</style>