<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: " . BASE_URL . "login.php");
    exit;
}

$student_id = $_GET['student_id'] ?? null;
if (!$student_id) {
    die("No student ID provided.");
}

$stmt = $pdo->prepare("
    SELECT p.id, p.amount_paid, p.payment_mode, p.payment_purpose, p.is_prepayment, p.from_balance, p.payment_date, r.receipt_number
    FROM payments p
    LEFT JOIN receipts r ON p.id = r.payment_id
    WHERE p.student_id = ?
    ORDER BY p.payment_date DESC
");
$stmt->execute([$student_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', COALESCE(other_name, ''), ' ', surname) AS full_name FROM students s WHERE s.id = ?");
$stmt->execute([$student_id]);
$student_name = $stmt->fetchColumn();
?>

<?php include '../includes/header.php'; ?>
<h2>Payments for <?php echo htmlspecialchars($student_name); ?></h2>
<table class="table-striped">
    <thead>
        <tr>
            <th>Date</th>
            <th>Amount (KES)</th>
            <th>Mode</th>
            <th>Purpose</th>
            <th>Receipt</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($payments as $payment): ?>
            <tr>
                <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                <td><?php echo number_format($payment['amount_paid'], 2); ?></td>
                <td><?php echo $payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : htmlspecialchars($payment['payment_mode'])); ?></td>
                <td><?php echo htmlspecialchars($payment['payment_purpose'] ?: 'N/A'); ?></td>
                <td>
                    <?php if ($payment['receipt_number']): ?>
                        <a href="print_receipt.php?payment_id=<?php echo $payment['id']; ?>" class="action-link"><?php echo htmlspecialchars($payment['receipt_number']); ?></a>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </td>
                <td>
                    <a href="print_receipt.php?payment_id=<?php echo $payment['id']; ?>" class="action-link">Print Receipt</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php include '../includes/footer.php'; ?>

<style>
.table-striped thead tr th { background-color: #2980b9; color: white; }
.action-link { color: #2980b9; text-decoration: none; }
.action-link:hover { text-decoration: underline; }
.table-striped tbody tr:nth-child(odd) { background-color: #f9f9f9; }
.table-striped tbody tr:nth-child(even) { background-color: #ffffff; }
</style>