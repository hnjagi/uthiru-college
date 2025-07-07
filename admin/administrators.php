<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';
$edit_admin = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['delete'])) {
    $id = $_POST['id'] ?? null;
    $first_name = $_POST['first_name'];
    $other_name = $_POST['other_name'] ?: null;
    $surname = $_POST['surname'];
    $phone_number = $_POST['phone_number'];
    $email = $_POST['email'] ?: null;
    $date_of_birth = $_POST['date_of_birth'];
    $nationality = $_POST['nationality'];
    $id_type = $_POST['id_type'];
    $id_number = $_POST['id_number'];
    $church_name = $_POST['church_name'];
    $church_position = $_POST['church_position'] ?: null;
    $role = $_POST['role'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($id) {
        if (!empty($password) && $password !== $confirm_password) {
            $error = "Passwords do not match.";
        }
    } else {
        if (empty($password)) {
            $error = "Password is required for new administrators.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        }
    }

    if (empty($error)) {
        $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
        try {
            if ($id) {
                if ($hashed_password) {
                    $stmt = $pdo->prepare("UPDATE administrators SET first_name=?, other_name=?, surname=?, phone_number=?, email=?, date_of_birth=?, nationality=?, id_type=?, id_number=?, church_name=?, church_position=?, role=?, username=?, password=?, updated_at=NOW(), updated_by=? WHERE id=?");
                    $stmt->execute([$first_name, $other_name, $surname, $phone_number, $email, $date_of_birth, $nationality, $id_type, $id_number, $church_name, $church_position, $role, $username, $hashed_password, $_SESSION['user_id'], $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE administrators SET first_name=?, other_name=?, surname=?, phone_number=?, email=?, date_of_birth=?, nationality=?, id_type=?, id_number=?, church_name=?, church_position=?, role=?, username=?, updated_at=NOW(), updated_by=? WHERE id=?");
                    $stmt->execute([$first_name, $other_name, $surname, $phone_number, $email, $date_of_birth, $nationality, $id_type, $id_number, $church_name, $church_position, $role, $username, $_SESSION['user_id'], $id]);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO administrators (first_name, other_name, surname, phone_number, email, date_of_birth, nationality, id_type, id_number, church_name, church_position, role, username, password, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$first_name, $other_name, $surname, $phone_number, $email, $date_of_birth, $nationality, $id_type, $id_number, $church_name, $church_position, $role, $username, $hashed_password, $_SESSION['user_id']]);
            }
            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $id ? 'Updated Administrator' : 'Added Administrator', 'administrators', $id ?: $pdo->lastInsertId(), "Username: $username"]);
            $success = $id ? "Administrator updated successfully." : "Administrator added successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                if (strpos($e->getMessage(), 'phone_number') !== false) {
                    $error = "Phone number '$phone_number' is already in use.";
                } elseif (strpos($e->getMessage(), 'id_number') !== false) {
                    $error = "ID/Passport number '$id_number' is already in use.";
                } elseif (strpos($e->getMessage(), 'username') !== false) {
                    $error = "Username '$username' is already taken.";
                } else {
                    $error = "A duplicate entry error occurred.";
                }
            } else {
                $error = "An error occurred: " . $e->getMessage();
            }
        }
    }
}

// Handle delete request
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $delete_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($delete_id === false || $delete_id <= 0) {
        $error = "Invalid administrator ID.";
    } else {
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            try {
                $stmt = $pdo->prepare("UPDATE administrators SET deleted_at = NOW(), deleted_by = ?, updated_at = NOW(), updated_by = ? WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $delete_id]);
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Soft Deleted Administrator', 'administrators', $delete_id, "Administrator ID: $delete_id"]);
                    $success = "Administrator marked as deleted successfully.";
                } else {
                    $error = "Administrator not found or already deleted.";
                }
            } catch (PDOException $e) {
                $error = "Error deleting administrator: " . $e->getMessage();
            }
        } else {
            $stmt = $pdo->prepare("SELECT username, first_name, surname FROM administrators WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$delete_id]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($admin) {
                echo "<div style='color: red; padding: 1em;'>Are you sure you want to delete administrator {$admin['username']} ({$admin['first_name']} {$admin['surname']})?<br>";
                echo "<a href='administrators.php?delete=$delete_id&confirm=yes'>Yes</a> | <a href='administrators.php'>No</a></div>";
                exit;
            } else {
                $error = "Administrator not found or already deleted.";
            }
        }
    }
}

// Fetch all administrators
$stmt = $pdo->query("SELECT * FROM administrators WHERE deleted_at IS NULL ORDER BY username");
$administrators = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch administrator for editing
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM administrators WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_GET['edit']]);
    $edit_admin = $stmt->fetch(PDO::FETCH_ASSOC);
}

$display_data = !empty($error) ? $_POST : ($edit_admin ?: []);
if (isset($_GET['delete']) && !isset($_GET['confirm'])) {
    $display_data = [];
}
?>

<?php include '../includes/header.php'; ?>
<h2>Administrator Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button id="toggleForm" onclick="toggleForm()">Add New Administrator</button>

<div id="adminForm" style="display: <?php echo $edit_admin ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_admin ? 'Edit Administrator' : 'Add New Administrator'; ?></h3>
    <form method="POST">
        <?php if ($edit_admin): ?>
            <input type="hidden" name="id" value="<?php echo $edit_admin['id']; ?>">
        <?php endif; ?>
        <label>First Name:</label>
        <input type="text" name="first_name" value="<?php echo htmlspecialchars($display_data['first_name'] ?? ''); ?>" required>
        <label>Other Name:</label>
        <input type="text" name="other_name" value="<?php echo htmlspecialchars($display_data['other_name'] ?? ''); ?>">
        <label>Surname:</label>
        <input type="text" name="surname" value="<?php echo htmlspecialchars($display_data['surname'] ?? ''); ?>" required>
        <label>Phone Number (e.g., 0723 456789):</label>
        <input type="text" name="phone_number" value="<?php echo htmlspecialchars($display_data['phone_number'] ?? ''); ?>" pattern="[0-9]{4} [0-9]{6}" required>
        <label>Email:</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($display_data['email'] ?? ''); ?>">
        <label>Date of Birth:</label>
        <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($display_data['date_of_birth'] ?? ''); ?>" required>
        <label>Nationality:</label>
        <input type="text" name="nationality" value="<?php echo htmlspecialchars($display_data['nationality'] ?? 'Kenyan'); ?>" required>
        <label>ID Type:</label>
        <select name="id_type" required>
            <option value="National ID" <?php echo ($display_data['id_type'] ?? '') == 'National ID' ? 'selected' : ''; ?>>National ID</option>
            <option value="Passport" <?php echo ($display_data['id_type'] ?? '') == 'Passport' ? 'selected' : ''; ?>>Passport</option>
        </select>
        <label>ID/Passport Number:</label>
        <input type="text" name="id_number" value="<?php echo htmlspecialchars($display_data['id_number'] ?? ''); ?>" required>
        <label>Church Name:</label>
        <input type="text" name="church_name" value="<?php echo htmlspecialchars($display_data['church_name'] ?? ''); ?>" required>
        <label>Position in Church:</label>
        <input type="text" name="church_position" value="<?php echo htmlspecialchars($display_data['church_position'] ?? ''); ?>">
        <label>Role:</label>
        <select name="role" required>
            <option value="Admin" <?php echo ($display_data['role'] ?? '') == 'Admin' ? 'selected' : ''; ?>>Admin</option>
            <option value="Clerk" <?php echo ($display_data['role'] ?? '') == 'Clerk' ? 'selected' : ''; ?>>Clerk</option>
            <option value="Coordinator" <?php echo ($display_data['role'] ?? '') == 'Coordinator' ? 'selected' : ''; ?>>Coordinator</option>
        </select>
        <label>Username:</label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($display_data['username'] ?? ''); ?>" required>
        <label>Password:</label>
        <input type="password" name="password" <?php echo $edit_admin ? '' : 'required'; ?>>
        <label>Confirm Password:</label>
        <input type="password" name="confirm_password" <?php echo $edit_admin ? '' : 'required'; ?>>
        <button type="submit"><?php echo $edit_admin ? 'Update' : 'Add'; ?> Administrator</button>
        <?php if ($edit_admin): ?>
            <a href="administrators.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<h3>Administrators</h3>
<?php if (empty($administrators)): ?>
    <p>No administrators registered yet.</p>
<?php else: ?>
    <table class="standard-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($administrators as $admin): ?>
            <tr>
                <td><?php echo htmlspecialchars($admin['username']); ?></td>
                <td><?php echo htmlspecialchars($admin['first_name'] . ' ' . ($admin['other_name'] ?? '') . ' ' . $admin['surname']); ?></td>
                <td><?php echo htmlspecialchars($admin['phone_number']); ?></td>
                <td><?php echo htmlspecialchars($admin['role']); ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $admin['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="edit">Edit</option>
                        <option value="delete">Delete</option>
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
</style>

<script>
function toggleForm() {
    const form = document.getElementById('adminForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function handleAction(select, adminId) {
    const action = select.value;
    if (action) {
        if (action === 'edit') {
            window.location.href = `administrators.php?edit=${adminId}`;
        } else if (action === 'delete') {
            window.location.href = `administrators.php?delete=${adminId}`;
        }
        select.value = '';
    }
}
</script>