<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';
$edit_constant = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? null;
    $name = $_POST['name'];
    $value = $_POST['value'];

    if (empty($name) || empty($value)) {
        $error = "Name and value are required.";
    } else {
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE constants SET name = ?, value = ? WHERE id = ?");
                $stmt->execute([$name, $value, $id]);
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], 'Updated Constant', 'constants', $id, "Name: $name"]);
                $success = "Constant updated successfully.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO constants (name, value, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$name, $value, $_SESSION['user_id']]);
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], 'Added Constant', 'constants', $pdo->lastInsertId(), "Name: $name"]);
                $success = "Constant added successfully.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "Constant name '$name' already exists.";
            } else {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all constants
$stmt = $pdo->query("SELECT * FROM constants ORDER BY name");
$constants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle edit request
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM constants WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_constant = $stmt->fetch(PDO::FETCH_ASSOC);
}

$display_data = !empty($error) ? $_POST : ($edit_constant ?: []);
?>

<?php include '../includes/header.php'; ?>
<h2>Constants Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button onclick="toggleForm()">Add New Constant</button>

<div id="constantForm" style="display: <?php echo $edit_constant ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_constant ? 'Edit Constant' : 'Add New Constant'; ?></h3>
    <form method="POST">
        <?php if ($edit_constant): ?>
            <input type="hidden" name="id" value="<?php echo $edit_constant['id']; ?>">
        <?php endif; ?>
        <label>Name:</label>
        <input type="text" name="name" value="<?php echo htmlspecialchars($display_data['name'] ?? ''); ?>" required>
        <label>Value:</label>
        <textarea name="value" required><?php echo htmlspecialchars($display_data['value'] ?? ''); ?></textarea>
        <button type="submit"><?php echo $edit_constant ? 'Update' : 'Add'; ?> Constant</button>
        <?php if ($edit_constant): ?>
            <a href="constants.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<h3>Constants</h3>
<?php if (empty($constants)): ?>
    <p>No constants defined yet.</p>
<?php else: ?>
    <table class="standard-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Value</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($constants as $constant): ?>
            <tr>
                <td><?php echo htmlspecialchars($constant['name']); ?></td>
                <td><?php echo htmlspecialchars($constant['value']); ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $constant['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="edit">Edit</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
.action-dropdown {
    padding: 5px;
    background-color: #2980b9;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
}
.action-dropdown:hover {
    background-color: #3498db;
}
textarea {
    width: 100%;
    height: 100px;
    padding: 8px;
    margin-bottom: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    box-sizing: border-box;
}
</style>

<script>
function toggleForm() {
    const form = document.getElementById('constantForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function handleAction(select, constantId) {
    const action = select.value;
    if (action) {
        if (action === 'edit') {
            window.location.href = `constants.php?edit=${constantId}`;
        }
        select.value = '';
    }
}
</script>