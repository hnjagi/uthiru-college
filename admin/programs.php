<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';
$edit_program = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name']);

    if (empty($name)) {
        $error = "Program name is required.";
    } else {
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE programs SET name = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$name, $_SESSION['user_id'], $id]);
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, 'Updated Program', 'programs', ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], $id, "Name: $name"]);
                $success = "Program updated successfully.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO programs (name, created_by) VALUES (?, ?)");
                $stmt->execute([$name, $_SESSION['user_id']]);
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, 'Added Program', 'programs', ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], $pdo->lastInsertId(), "Name: $name"]);
                $success = "Program added successfully.";
            }
        } catch (PDOException $e) {
            $error = $e->getCode() == 23000 ? "Program name '$name' already exists." : "Error: " . $e->getMessage();
        }
    }
}

if (isset($_GET['delete']) && isset($_GET['id'])) {
    $delete_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($delete_id && isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
        try {
            $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$delete_id]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, 'Deleted Program', 'programs', ?, 'Program ID: ?', NOW())");
                $stmt->execute([$_SESSION['user_id'], $delete_id, $delete_id]);
                $success = "Program deleted successfully.";
            } else {
                $error = "Program not found.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting program: " . $e->getMessage();
        }
    } else {
        $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ?");
        $stmt->execute([$delete_id]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($program) {
            echo "<div style='color: red;'>Confirm deletion of '{$program['name']}'? <a href='?delete=$delete_id&confirm=yes'>Yes</a> | <a href='programs.php'>No</a></div>";
            exit;
        }
    }
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM programs WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_program = $stmt->fetch(PDO::FETCH_ASSOC);
}

$stmt = $pdo->query("SELECT * FROM programs ORDER BY name");
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$display_data = !empty($error) ? $_POST : ($edit_program ?: []);
?>

<?php include '../includes/header.php'; ?>
<h2>Programs Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button onclick="toggleForm()">Add New Program</button>

<div id="programForm" style="display: <?php echo $edit_program ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_program ? 'Edit Program' : 'Add New Program'; ?></h3>
    <form method="POST">
        <?php if ($edit_program): ?>
            <input type="hidden" name="id" value="<?php echo $edit_program['id']; ?>">
        <?php endif; ?>
        <label>Name:</label>
        <input type="text" name="name" value="<?php echo htmlspecialchars($display_data['name'] ?? ''); ?>" required>
        <button type="submit"><?php echo $edit_program ? 'Update' : 'Add'; ?> Program</button>
        <?php if ($edit_program): ?>
            <a href="programs.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<h3>Programs</h3>
<?php if (empty($programs)): ?>
    <p>No programs defined yet.</p>
<?php else: ?>
    <table>
        <tr><th>ID</th><th>Name</th><th>Actions</th></tr>
        <?php foreach ($programs as $program): ?>
            <tr>
                <td><?php echo htmlspecialchars($program['id']); ?></td>
                <td><?php echo htmlspecialchars($program['name']); ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $program['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="edit">Edit</option>
                        <option value="delete">Delete</option>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
.action-dropdown {
    padding: 5px; background-color: #2980b9; color: white; border: none; border-radius: 3px; cursor: pointer;
}
.action-dropdown:hover { background-color: #3498db; }
</style>

<script>
function toggleForm() {
    document.getElementById('programForm').style.display = document.getElementById('programForm').style.display === 'none' ? 'block' : 'none';
}

function handleAction(select, programId) {
    const action = select.value;
    if (action) {
        if (action === 'edit') {
            window.location.href = `programs.php?edit=${programId}`;
        } else if (action === 'delete') {
            window.location.href = `programs.php?delete=${programId}`;
        }
        select.value = '';
    }
}
</script>