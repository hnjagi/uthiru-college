<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk', 'Coordinator'])) {
    header("Location: ../login.php");
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<h2>Reports</h2>

<p>Select the type of report you would like to view:</p>
<ul>
    <li><a href="student_reports.php">Student Reports (Attendance, Assignments, Marks)</a></li>
    <li><a href="financial_reports.php">Financial Reports (Cashflow, Expenses, Debts, College Report)</a></li>
</ul>

<?php include '../includes/footer.php'; ?>