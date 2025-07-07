<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';
$upload_dir = '../uploads/remittances/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $remittance_date = $_POST['remittance_date'];
    $amount = floatval($_POST['amount']);
    $mpesa_code = $_POST['mpesa_code'] ?: null;
    $session_ids = $_POST['session_ids'] ?? [];
    $description = $_POST['description'] ?: null;

    if (empty($remittance_date) || $amount <= 0 || empty($session_ids)) {
        $error = "Remittance date, amount, and at least one session are required.";
    } else {
        try {
            $file_path = null;
            if (!empty($_FILES['mpesa_receipt']['name'])) {
                $file = $_FILES['mpesa_receipt'];
                $file_name = $file['name'];
                $file_tmp = $file['tmp_name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                if ($file_ext !== 'pdf') {
                    throw new Exception("Only PDF files are allowed.");
                }
                $file_path = $upload_dir . "remittance_" . date('Ymd_His') . '.' . $file_ext;
                move_uploaded_file($file_tmp, $file_path);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO remittances (remittance_date, amount, mpesa_code, mpesa_receipt, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$remittance_date, $amount, $mpesa_code, $file_path, $description, $_SESSION['user_id']]);
            $remittance_id = $pdo->lastInsertId();

            foreach ($session_ids as $session_id) {
                $stmt = $pdo->prepare("INSERT INTO remittance_sessions (remittance_id, session_id) VALUES (?, ?)");
                $stmt->execute([$remittance_id, $session_id]);
            }

            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, 'Added Remittance', 'remittances', ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $remittance_id, "Date: $remittance_date, Amount: $amount"]);
            $pdo->commit();
            $success = "Remittance recorded successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            if ($file_path && file_exists($file_path)) unlink($file_path);
            $error = "Error: " . $e->getMessage();
        }
    }
}

$stmt = $pdo->query("SELECT r.*, GROUP_CONCAT(cs.session_date) AS session_dates FROM remittances r LEFT JOIN remittance_sessions rs ON r.id = rs.remittance_id LEFT JOIN class_sessions cs ON rs.session_id = cs.id GROUP BY r.id ORDER BY r.remittance_date DESC");
$remittances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT id, session_date, CONCAT(level_id, '-', unit_id) AS session_name FROM class_sessions WHERE is_closed = 1 ORDER BY session_date DESC");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<h2>Remittances Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button onclick="toggleForm()">Add New Remittance</button>

<div id="remittanceForm" style="display: none;">
    <h3>Add New Remittance</h3>
    <form method="POST" enctype="multipart/form-data">
        <label>Remittance Date:</label>
        <input type="date" name="remittance_date" required>
        <label>Amount (KES):</label>
        <input type="number" name="amount" step="0.01" min="0" required>
        <label>Class Sessions:</label>
        <select name="session_ids[]" multiple required>
            <?php foreach ($sessions as $session): ?>
                <option value="<?php echo $session['id']; ?>"><?php echo htmlspecialchars($session['session_date'] . ' - ' . $session['session_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <label>MPESA Code (if applicable):</label>
        <input type="text" name="mpesa_code">
        <label>MPESA Receipt (PDF, optional):</label>
        <input type="file" name="mpesa_receipt" accept="application/pdf">
        <label>Description:</label>
        <textarea name="description"></textarea>
        <button type="submit">Record Remittance</button>
    </form>
</div>

<h3>Remittances</h3>
<?php if (empty($remittances)): ?>
    <p>No remittances recorded yet.</p>
<?php else: ?>
    <table>
        <tr><th>Date</th><th>Amount (KES)</th><th>MPESA Code</th><th>Receipt</th><th>Sessions</th><th>Description</th></tr>
        <?php foreach ($remittances as $remittance): ?>
            <tr>
                <td><?php echo htmlspecialchars($remittance['remittance_date']); ?></td>
                <td><?php echo htmlspecialchars(number_format($remittance['amount'], 2)); ?></td>
                <td><?php echo htmlspecialchars($remittance['mpesa_code'] ?: 'N/A'); ?></td>
                <td><?php echo $remittance['mpesa_receipt'] ? "<a href='" . htmlspecialchars($remittance['mpesa_receipt']) . "' target='_blank'>View</a>" : 'N/A'; ?></td>
                <td><?php echo htmlspecialchars($remittance['session_dates']); ?></td>
                <td><?php echo htmlspecialchars($remittance['description'] ?: 'N/A'); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<script>
function toggleForm() {
    document.getElementById('remittanceForm').style.display = document.getElementById('remittanceForm').style.display === 'none' ? 'block' : 'none';
}
</script>