<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header("Location: ../login.php");
    exit;
}

$student_count = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$session_count = $pdo->query("SELECT COUNT(*) FROM class_sessions WHERE academic_year = YEAR(CURDATE())")->fetchColumn();
$payment_total = $pdo->query("SELECT SUM(amount_paid) FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())")->fetchColumn();
?>

<?php include '../includes/header.php'; ?>
<h2>Admin Dashboard</h2>
<div>
    <h3>Statistics</h3>
    <p>Total Students: <?php echo $student_count; ?></p>
    <p>Class Sessions This Year: <?php echo $session_count; ?></p>
    <p>Total Payments This Year: KES <?php echo number_format($payment_total, 2); ?></p>
</div>
<?php include '../includes/footer.php'; ?>