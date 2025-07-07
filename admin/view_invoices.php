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
    SELECT i.id, i.amount_due, i.invoice_date, cs.session_date, u.name AS unit_name
    FROM invoices i
    JOIN class_sessions cs ON i.session_id = cs.id
    JOIN units u ON cs.unit_id = u.id
    WHERE i.student_id = ?
    ORDER BY i.invoice_date DESC
");
$stmt->execute([$student_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', COALESCE(other_name, ''), ' ', surname) AS full_name FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student_name = $stmt->fetchColumn();
?>

<?php include '../includes/header.php'; ?>
<h2>Invoices for <?php echo htmlspecialchars($student_name); ?></h2>
<table class="table-striped">
    <thead>
        <tr>
            <th>Invoice ID</th>
            <th>Date</th>
            <th>Unit</th>
            <th>Session Date</th>
            <th>Amount Due (KES)</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($invoices as $invoice): ?>
            <tr>
                <td><?php echo htmlspecialchars($invoice['id']); ?></td>
                <td><?php echo htmlspecialchars($invoice['invoice_date']); ?></td>
                <td><?php echo htmlspecialchars($invoice['unit_name']); ?></td>
                <td><?php echo htmlspecialchars($invoice['session_date']); ?></td>
                <td><?php echo number_format($invoice['amount_due'], 2); ?></td>
                <td><a href="invoices.php?invoice_id=<?php echo $invoice['id']; ?>" class="action-link">Print</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php include '../includes/footer.php'; ?>

<style>
.table-striped thead tr th { background-color: #2980b9; }
.action-link { color: #2980b9; text-decoration: none; }
.action-link:hover { text-decoration: underline; }
.table-striped tbody tr:nth-child(odd) { background-color: #f9f9f9; }
.table-striped tbody tr:nth-child(even) { background-color: #ffffff; }
</style>