<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

// Initialize messages
$error = '';
$success = '';
$form_data = $_POST ?: [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'];
    $full_name = $_POST['full_name'];
    $phone_number = $_POST['phone_number'];

    if (empty($title) || empty($full_name) || empty($phone_number)) {
        $error = "All fields are required.";
    } elseif (!preg_match("/^[0-9]{4} [0-9]{6}$/", $phone_number)) {
        $error = "Phone number must be in format 'xxxx xxxxxx' (e.g., 0723 456789).";
    }

    if (empty($error)) {
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE lecturers SET title = ?, full_name = ?, phone_number = ? WHERE id = ?");
                $stmt->execute([$title, $full_name, $phone_number, $id]);
                $action = 'Updated Lecturer';
            } else {
                $stmt = $pdo->prepare("INSERT INTO lecturers (title, full_name, phone_number) VALUES (?, ?, ?)");
                $stmt->execute([$title, $full_name, $phone_number]);
                $action = 'Added Lecturer';
                $id = $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $action, 'lecturers', $id, "Lecturer: $title $full_name"]);
            $success = $id ? "Lecturer updated successfully." : "Lecturer added successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "Phone number '$phone_number' is already in use.";
            } else {
                $error = "An error occurred: " . $e->getMessage();
            }
        }
    }
}

// Handle delete request (soft delete to be added later if needed; currently hard delete for simplicity)
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $delete_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($delete_id === false || $delete_id <= 0) {
        $error = "Invalid lecturer ID.";
    } else {
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            try {
                $stmt = $pdo->prepare("DELETE FROM lecturers WHERE id = ?");
                $stmt->execute([$delete_id]);
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Deleted Lecturer', 'lecturers', $delete_id, "Lecturer ID: $delete_id"]);
                    $success = "Lecturer deleted successfully.";
                } else {
                    $error = "Lecturer not found.";
                }
            } catch (PDOException $e) {
                $error = "Error deleting lecturer: " . $e->getMessage();
            }
        } else {
            $stmt = $pdo->prepare("SELECT title, full_name FROM lecturers WHERE id = ?");
            $stmt->execute([$delete_id]);
            $lecturer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lecturer) {
                echo "<div style='color: red; padding: 1em;'>Are you sure you want to delete lecturer {$lecturer['title']} {$lecturer['full_name']}?<br>";
                echo "<a href='lecturers.php?delete=$delete_id&confirm=yes'>Yes</a> | <a href='lecturers.php'>No</a></div>";
                exit;
            } else {
                $error = "Lecturer not found.";
            }
        }
    }
}

// Fetch all lecturers
$stmt = $pdo->query("SELECT * FROM lecturers ORDER BY full_name");
$lecturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch lecturer for editing
$edit_lecturer = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM lecturers WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_lecturer = $stmt->fetch(PDO::FETCH_ASSOC);
}
$display_data = !empty($error) ? $form_data : ($edit_lecturer ?: []);
?>

<?php include '../includes/header.php'; ?>
<h2>Lecturer Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button id="toggleForm" onclick="toggleForm()">Add New Lecturer</button>

<div id="lecturerForm" style="display: <?php echo $edit_lecturer ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_lecturer ? 'Edit Lecturer' : 'Add New Lecturer'; ?></h3>
    <form method="POST">
        <?php if ($edit_lecturer): ?>
            <input type="hidden" name="id" value="<?php echo $edit_lecturer['id']; ?>">
        <?php endif; ?>
        <label>Title:</label>
        <select name="title" required>
            <option value="Mr" <?php echo ($display_data['title'] ?? '') == 'Mr' ? 'selected' : ''; ?>>Mr</option>
            <option value="Mrs" <?php echo ($display_data['title'] ?? '') == 'Mrs' ? 'selected' : ''; ?>>Mrs</option>
            <option value="Ms" <?php echo ($display_data['title'] ?? '') == 'Ms' ? 'selected' : ''; ?>>Ms</option>
            <option value="Rev" <?php echo ($display_data['title'] ?? '') == 'Rev' ? 'selected' : ''; ?>>Rev</option>
            <option value="Pastor" <?php echo ($display_data['title'] ?? '') == 'Pastor' ? 'selected' : ''; ?>>Pastor</option>
            <option value="Bishop" <?php echo ($display_data['title'] ?? '') == 'Bishop' ? 'selected' : ''; ?>>Bishop</option>
            <option value="Dr" <?php echo ($display_data['title'] ?? '') == 'Dr' ? 'selected' : ''; ?>>Dr</option>
            <option value="Prof" <?php echo ($display_data['title'] ?? '') == 'Prof' ? 'selected' : ''; ?>>Prof</option>
        </select>
        <label>Full Name:</label>
        <input type="text" name="full_name" value="<?php echo htmlspecialchars($display_data['full_name'] ?? ''); ?>" required>
        <label>Phone Number (e.g., 0723 456789):</label>
        <input type="text" name="phone_number" value="<?php echo htmlspecialchars($display_data['phone_number'] ?? ''); ?>" pattern="[0-9]{4} [0-9]{6}" required>
        <button type="submit"><?php echo $edit_lecturer ? 'Update' : 'Add'; ?> Lecturer</button>
        <?php if ($edit_lecturer): ?>
            <a href="lecturers.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<h3>Registered Lecturers</h3>
<?php if (empty($lecturers)): ?>
    <p>No lecturers registered yet.</p>
<?php else: ?>
    <table>
        <tr>
            <th>#</th>
            <th>Title</th>
            <th>Full Name</th>
            <th>Phone Number</th>
            <th>Actions</th>
        </tr>
        <?php $counter = 1; foreach ($lecturers as $lecturer): ?>
        <tr>
            <td><?php echo $counter++; ?></td>
            <td><?php echo htmlspecialchars($lecturer['title']); ?></td>
            <td><?php echo htmlspecialchars($lecturer['full_name']); ?></td>
            <td><?php echo htmlspecialchars($lecturer['phone_number']); ?></td>
            <td>
                <select class="action-dropdown" onchange="handleAction(this, <?php echo $lecturer['id']; ?>)">
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
</style>

<script>
function toggleForm() {
    const form = document.getElementById('lecturerForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function handleAction(select, lecturerId) {
    const action = select.value;
    if (action) {
        if (action === 'delete') {
            if (confirm('Are you sure you want to delete this lecturer?')) {
                window.location.href = `lecturers.php?delete=${lecturerId}`;
            }
        } else if (action === 'edit') {
            window.location.href = `lecturers.php?edit=${lecturerId}`;
        }
        select.value = ''; // Reset dropdown
    }
}

window.onload = function() {
    <?php if ($edit_lecturer): ?>
        document.getElementById('lecturerForm').style.display = 'block';
    <?php endif; ?>
};
</script>