<?php
include '../includes/header.php'; // Includes db_connect.php implicitly and checks session

if ($_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';
$form_data = [];
$edit_unit = null; // Initialize $edit_unit to avoid undefined variable warnings

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? null;
    $code = strtoupper(trim($_POST['code']));
    $name = trim($_POST['name']);
    $level_id = $_POST['level_id'];
    $start_year = $_POST['start_year'];
    $end_year = $_POST['end_year'] ?: null;

    if (empty($code) || empty($name) || empty($level_id) || empty($start_year)) {
        $error = "Code, Name, Level, and Start Year are required.";
    } elseif (!preg_match('/^[A-Z]{3,4}\d{4}$/', $code)) {
        $error = "Unit code must be in format 'XXX1234' or 'XXXX1234' (e.g., ABC1234 or ABCD1234).";
    } elseif ($start_year < 2000 || $start_year > date('Y') + 1) {
        $error = "Start year must be between 2000 and next year.";
    } elseif ($end_year && ($end_year < $start_year || $end_year > date('Y') + 1)) {
        $error = "End year must be after start year and not exceed next year.";
    } else {
        try {
            $pdo->beginTransaction();

            if (!$id) {
                $stmt = $pdo->prepare("SELECT id FROM units WHERE code = ? AND level_id = ? AND end_year IS NULL");
                $stmt->execute([$code, $level_id]);
                if ($stmt->fetchColumn()) {
                    $error = "An active unit with code '$code' already exists for this level.";
                    throw new Exception($error);
                }
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE units SET code = ?, name = ?, level_id = ?, start_year = ?, end_year = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$code, $name, $level_id, $start_year, $end_year, $_SESSION['user_id'], $id]);
                $action = 'Updated Unit';
                $unit_id = $id;
            } else {
                $stmt = $pdo->prepare("INSERT INTO units (code, name, level_id, start_year, end_year, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $level_id, $start_year, $end_year, $_SESSION['user_id']]);
                $unit_id = $pdo->lastInsertId();
                $action = 'Added Unit';
            }

            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $action, 'units', $unit_id, "Code: $code, Name: $name"]);
            $pdo->commit();
            $success = $id ? "Unit updated successfully." : "Unit added successfully.";
            $form_data = []; // Reset form data after success
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage() ?: "Error: " . $e->getMessage();
        }
    }
    // If there's an error, retain the submitted form data
    if (!empty($error)) {
        $form_data = $_POST;
    }
}

// Handle delete request
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $delete_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($delete_id === false || $delete_id <= 0) {
        $error = "Invalid unit ID.";
    } else {
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            try {
                $stmt = $pdo->prepare("DELETE FROM units WHERE id = ?");
                $stmt->execute([$delete_id]);
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Deleted Unit', 'units', $delete_id, "Unit ID: $delete_id"]);
                    $success = "Unit deleted successfully.";
                } else {
                    $error = "Unit not found.";
                }
            } catch (PDOException $e) {
                $error = "Error deleting unit: " . $e->getMessage();
            }
        } else {
            $stmt = $pdo->prepare("SELECT code, name FROM units WHERE id = ?");
            $stmt->execute([$delete_id]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($unit) {
                echo "<div style='color: red; padding: 1em;'>Are you sure you want to delete unit {$unit['code']} ({$unit['name']})?<br>";
                echo "<a href='units.php?delete=$delete_id&confirm=yes'>Yes</a> | <a href='units.php'>No</a></div>";
                exit;
            } else {
                $error = "Unit not found.";
            }
        }
    }
}

// Fetch all units
$stmt = $pdo->query("
    SELECT u.id, u.code, u.name, u.start_year, u.end_year, l.name AS level_name, p.name AS program_name
    FROM units u
    JOIN levels l ON u.level_id = l.id
    JOIN programs p ON l.program_id = p.id
    ORDER BY u.start_year DESC, p.name, l.sequence, u.code
");
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch dropdown data
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("SELECT l.*, p.name AS program_name FROM levels l JOIN programs p ON l.program_id = p.id ORDER BY p.name, l.sequence")->fetchAll(PDO::FETCH_ASSOC);

// Fetch unit for editing
if (isset($_GET['edit']) && empty($form_data)) { // Only fetch if not a failed submission
    $stmt = $pdo->prepare("SELECT * FROM units WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_unit = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_unit) {
        $form_data = $edit_unit;
        $stmt = $pdo->prepare("SELECT program_id FROM levels WHERE id = ?");
        $stmt->execute([$edit_unit['level_id']]);
        $form_data['program_id'] = $stmt->fetchColumn();
    } else {
        $error = "Unit not found.";
    }
}
$display_data = $form_data ?: [];
$is_editing = !empty($edit_unit); // Flag to determine if we're in edit mode
?>

<h2>Unit Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button onclick="toggleForm()">Add New Unit</button>

<div id="unitForm" style="display: <?php echo $is_editing || !empty($error) ? 'block' : 'none'; ?>;">
    <h3><?php echo $is_editing ? 'Edit Unit' : 'Add New Unit'; ?></h3>
    <form method="POST" onsubmit="return validateForm()">
        <?php if ($is_editing): ?>
            <input type="hidden" name="id" value="<?php echo $edit_unit['id'] ?? ''; ?>">
        <?php endif; ?>
        <label>Program:</label>
        <select name="program_id" id="program_id" onchange="filterLevels()" required>
            <option value="">Select Program</option>
            <?php foreach ($programs as $program): ?>
                <option value="<?php echo $program['id']; ?>" <?php echo ($display_data['program_id'] ?? '') == $program['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($program['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Level:</label>
        <select name="level_id" id="level_id" required>
            <option value="">Select Level</option>
            <?php foreach ($levels as $level): ?>
                <option value="<?php echo $level['id']; ?>" data-program="<?php echo $level['program_id']; ?>" <?php echo ($display_data['level_id'] ?? '') == $level['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Unit Code (e.g., ABC1234 or ABCD1234):</label>
        <input type="text" name="code" id="code" value="<?php echo htmlspecialchars($display_data['code'] ?? ''); ?>" pattern="[A-Z]{3,4}\d{4}" required>
        <label>Unit Name:</label>
        <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($display_data['name'] ?? ''); ?>" required>
        <label>Start Year:</label>
        <input type="number" name="start_year" id="start_year" value="<?php echo htmlspecialchars($display_data['start_year'] ?? date('Y')); ?>" min="2000" max="<?php echo date('Y') + 1; ?>" required onchange="updateEndYearMin()">
        <label>End Year (leave blank if ongoing):</label>
        <input type="number" name="end_year" id="end_year" value="<?php echo htmlspecialchars($display_data['end_year'] ?? ''); ?>" min="2000" max="<?php echo date('Y') + 1; ?>" placeholder="Optional">
        <button type="submit"><?php echo $is_editing ? 'Update' : 'Add'; ?> Unit</button>
        <?php if ($is_editing): ?>
            <a href="units.php" class="cancel-link">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<h3>Units</h3>
<?php if (empty($units)): ?>
    <p>No units defined yet.</p>
<?php else: ?>
    <table class="standard-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Program</th>
                <th>Level</th>
                <th>Valid From</th>
                <th>Valid Until</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($units as $unit): ?>
            <tr>
                <td><?php echo htmlspecialchars($unit['code']); ?></td>
                <td><?php echo htmlspecialchars($unit['name']); ?></td>
                <td><?php echo htmlspecialchars($unit['program_name']); ?></td>
                <td><?php echo htmlspecialchars($unit['level_name']); ?></td>
                <td><?php echo htmlspecialchars($unit['start_year']); ?></td>
                <td><?php echo htmlspecialchars($unit['end_year'] ?: 'Ongoing'); ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $unit['id']; ?>)">
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
.cancel-link {
    display: inline-block;
    padding: 8px 16px;
    background-color: #2980b9;
    color: white;
    text-decoration: none;
    border-radius: 3px;
    cursor: pointer;
    margin-left: 10px;
}
.cancel-link:hover {
    background-color: #3498db;
}
</style>

<script>
function toggleForm() {
    const form = document.getElementById('unitForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function filterLevels() {
    const programId = document.getElementById('program_id').value;
    const levelSelect = document.getElementById('level_id');
    Array.from(levelSelect.options).forEach(option => {
        option.style.display = option.getAttribute('data-program') === programId || option.value === '' ? '' : 'none';
    });
    levelSelect.value = levelId || '';
}

function updateEndYearMin() {
    const startYear = parseInt(document.getElementById('start_year').value);
    const endYearInput = document.getElementById('end_year');
    endYearInput.min = startYear;
}

function validateForm() {
    const startYear = parseInt(document.getElementById('start_year').value);
    const endYearInput = document.getElementById('end_year');
    const endYear = endYearInput.value ? parseInt(endYearInput.value) : null;
    const nextYear = <?php echo date('Y') + 1; ?>;

    if (endYear !== null) {
        if (endYear < startYear) {
            alert("End year must be after start year.");
            return false;
        }
        if (endYear > nextYear) {
            alert("End year must not exceed next year (" + nextYear + ").");
            return false;
        }
    }
    return true;
}

function handleAction(select, unitId) {
    const action = select.value;
    if (action) {
        if (action === 'edit') {
            window.location.href = `units.php?edit=${unitId}`;
        } else if (action === 'delete') {
            window.location.href = `units.php?delete=${unitId}`;
        }
        select.value = '';
    }
}

window.onload = function() {
    <?php if ($is_editing): ?>
        document.getElementById('unitForm').style.display = 'block';
        const programId = '<?php echo $display_data['program_id'] ?? ''; ?>';
        const levelId = '<?php echo $display_data['level_id'] ?? ''; ?>';
        document.getElementById('program_id').value = programId;
        filterLevels();
        document.getElementById('level_id').value = levelId;
        updateEndYearMin();
    <?php endif; ?>
};
</script>