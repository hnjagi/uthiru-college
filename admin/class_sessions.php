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

// Handle session form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['add_lecturer'])) {
    $id = $_POST['id'] ?? null;
    $program_id = $_POST['program_id'];
    $level_id = $_POST['level_id'];
    $unit_id = $_POST['unit_id'];
    $session_date = $_POST['session_date'];
    $lecturer_id = $_POST['lecturer_id'];
    $academic_year = date('Y', strtotime($session_date));
    $is_closed = isset($_POST['is_closed']) ? 1 : 0;

    // Check for open sessions for the same level, excluding the session being edited
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_sessions WHERE level_id = ? AND is_closed = 0" . ($id ? " AND id != ?" : ""));
    $params = [$level_id];
    if ($id) {
        $params[] = $id;
    }
    $stmt->execute($params);
    if ($stmt->fetchColumn() > 0 && !$is_closed) {
        $error = "An open session already exists for this level.";
    } else {
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE class_sessions SET program_id = ?, level_id = ?, unit_id = ?, session_date = ?, lecturer_id = ?, academic_year = ?, is_closed = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$program_id, $level_id, $unit_id, $session_date, $lecturer_id, $academic_year, $is_closed, $_SESSION['user_id'], $id]);
                $action = 'Updated Class Session';
                $record_id = $id;
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_sessions WHERE unit_id = ? AND academic_year = ? AND is_closed = 1");
                $stmt->execute([$unit_id, $academic_year]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("This unit has already been completed this academic year.");
                }
                $stmt = $pdo->prepare("INSERT INTO class_sessions (program_id, level_id, unit_id, session_date, lecturer_id, academic_year, is_closed, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$program_id, $level_id, $unit_id, $session_date, $lecturer_id, $academic_year, $is_closed, $_SESSION['user_id']]);
                $action = 'Added Class Session';
                $record_id = $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $action, 'class_sessions', $record_id, "Session Date: $session_date"]);
            $success = $id ? "Class session updated successfully." : "Class session added successfully.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle lecturer addition
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_lecturer'])) {
    $title = $_POST['title'];
    $full_name = $_POST['full_name'];
    $phone_number = $_POST['phone_number'];

    if (empty($title) || empty($full_name) || empty($phone_number)) {
        $error = "All lecturer fields are required.";
    } elseif (!preg_match("/^[0-9]{4} [0-9]{6}$/", $phone_number)) {
        $error = "Phone number must be in format 'xxxx xxxxxx' (e.g., 0723 456789).";
    }

    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO lecturers (title, full_name, phone_number) VALUES (?, ?, ?)");
            $stmt->execute([$title, $full_name, $phone_number]);
            $new_lecturer_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], 'Added Lecturer', 'lecturers', $new_lecturer_id, "Lecturer: $title $full_name"]);
            $success = "Lecturer added successfully.";
            $form_data['lecturer_id'] = $new_lecturer_id; // Pre-select new lecturer
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "Phone number '$phone_number' is already in use.";
            } else {
                $error = "Error adding lecturer: " . $e->getMessage();
            }
        }
    }
}

// Fetch dropdown data
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("SELECT l.*, p.name AS program_name FROM levels l JOIN programs p ON l.program_id = p.id ORDER BY p.name, l.sequence")->fetchAll(PDO::FETCH_ASSOC);
$units = $pdo->query("SELECT u.*, l.name AS level_name FROM units u JOIN levels l ON u.level_id = l.id ORDER BY l.sequence, u.name")->fetchAll(PDO::FETCH_ASSOC);
$lecturers = $pdo->query("SELECT * FROM lecturers ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all class sessions
$sessions = [];
try {
    $stmt = $pdo->query("SELECT cs.*, p.name AS program_name, l.name AS level_name, u.name AS unit_name, lec.title, lec.full_name AS lecturer_name 
                         FROM class_sessions cs 
                         LEFT JOIN programs p ON cs.program_id = p.id 
                         JOIN levels l ON cs.level_id = l.id 
                         JOIN units u ON cs.unit_id = u.id 
                         JOIN lecturers lec ON cs.lecturer_id = lec.id 
                         ORDER BY cs.session_date DESC");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching sessions: " . $e->getMessage();
    $stmt = $pdo->query("SELECT cs.*, l.name AS level_name, u.name AS unit_name, lec.title, lec.full_name AS lecturer_name 
                         FROM class_sessions cs 
                         JOIN levels l ON cs.level_id = l.id 
                         JOIN units u ON cs.unit_id = u.id 
                         JOIN lecturers lec ON cs.lecturer_id = lec.id 
                         ORDER BY cs.session_date DESC");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Separate open and closed sessions
$open_sessions = array_filter($sessions, function($session) { return !$session['is_closed']; });
$closed_sessions = array_filter($sessions, function($session) { return $session['is_closed']; });

// Fetch session for editing
$edit_session = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM class_sessions WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_session = $stmt->fetch(PDO::FETCH_ASSOC);
}
$display_data = !empty($error) ? $form_data : ($edit_session ?: []);
?>

<?php include '../includes/header.php'; ?>
<h2>Class Session Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button id="toggleForm" onclick="toggleForm()"><?php echo $edit_session ? 'Edit Class Session' : 'Add New Class Session'; ?></button>

<div id="sessionForm" style="display: <?php echo $edit_session ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_session ? 'Edit Class Session' : 'Add New Class Session'; ?></h3>

    <form method="POST">
        <?php if ($edit_session): ?>
            <input type="hidden" name="id" value="<?php echo $edit_session['id']; ?>">
        <?php endif; ?>
        <label>Program:</label>
        <select name="program_id" id="program_id" required onchange="filterLevels()">
            <option value="">Select Program</option>
            <?php foreach ($programs as $program): ?>
                <option value="<?php echo $program['id']; ?>" <?php echo ($display_data['program_id'] ?? '') == $program['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($program['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Level:</label>
        <select name="level_id" id="level_id" required onchange="filterUnits()">
            <option value="">Select Level</option>
            <?php foreach ($levels as $level): ?>
                <option value="<?php echo $level['id']; ?>" data-program="<?php echo $level['program_id']; ?>" <?php echo ($display_data['level_id'] ?? '') == $level['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Unit:</label>
        <select name="unit_id" id="unit_id" required>
            <option value="">Select Unit</option>
            <?php foreach ($units as $unit): ?>
                <option value="<?php echo $unit['id']; ?>" data-level="<?php echo $unit['level_id']; ?>" <?php echo ($display_data['unit_id'] ?? '') == $unit['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($unit['level_name'] . ' - ' . $unit['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Session Date:</label>
        <input type="date" name="session_date" value="<?php echo htmlspecialchars($display_data['session_date'] ?? date('Y-m-d')); ?>" required>
        <label>Lecturer:</label>
        <div style="display: flex; align-items: center;">
            <select name="lecturer_id" id="lecturer_id" required>
                <option value="">Select Lecturer</option>
                <?php foreach ($lecturers as $lecturer): ?>
                    <option value="<?php echo $lecturer['id']; ?>" <?php echo ($display_data['lecturer_id'] ?? '') == $lecturer['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($lecturer['title'] . ' ' . $lecturer['full_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="toggleLecturerForm()" style="margin-left: 10px;">Add New Lecturer</button>
        </div>
        <label>Closed:</label>
        <input type="checkbox" name="is_closed" value="1" <?php echo ($display_data['is_closed'] ?? 0) ? 'checked' : ''; ?>>
        <button type="submit"><?php echo $edit_session ? 'Update' : 'Add'; ?> Session</button>
        <?php if ($edit_session): ?>
            <a href="class_sessions.php">Cancel</a>
        <?php endif; ?>
    </form>

    <div id="lecturerForm" style="display: none; margin-top: 20px;">
        <h3>Add New Lecturer</h3>
        <form method="POST">
            <input type="hidden" name="add_lecturer" value="1">
            <?php foreach ($form_data as $key => $value): if ($key !== 'add_lecturer'): ?>
                <input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($value); ?>">
            <?php endif; endforeach; ?>
            <label>Title:</label>
            <select name="title" required>
                <option value="Mr">Mr</option>
                <option value="Mrs">Mrs</option>
                <option value="Ms">Ms</option>
                <option value="Rev">Rev</option>
                <option value="Pastor">Pastor</option>
                <option value="Bishop">Bishop</option>
                <option value="Dr">Dr</option>
                <option value="Prof">Prof</option>
            </select>
            <label>Full Name:</label>
            <input type="text" name="full_name" required>
            <label>Phone Number (e.g., 0723 456789):</label>
            <input type="text" name="phone_number" pattern="[0-9]{4} [0-9]{6}" required>
            <button type="submit">Add Lecturer</button>
            <button type="button" onclick="toggleLecturerForm()">Cancel</button>
        </form>
    </div>
</div>

<h3>Open Class Sessions</h3>
<?php if (empty($open_sessions)): ?>
    <p>No open sessions scheduled yet.</p>
<?php else: ?>
    <table class="standard-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Program</th>
                <th>Level</th>
                <th>Unit</th>
                <th>Lecturer</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $counter = 1; foreach ($open_sessions as $session): ?>
            <tr>
                <td><?php echo $counter++; ?></td>
                <td><?php echo htmlspecialchars($session['session_date']); ?></td>
                <td><?php echo htmlspecialchars($session['program_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($session['level_name']); ?></td>
                <td><?php echo htmlspecialchars($session['unit_name']); ?></td>
                <td><?php echo htmlspecialchars($session['title'] . ' ' . $session['lecturer_name']); ?></td>
                <td><?php echo $session['is_closed'] ? 'Closed' : 'Open'; ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $session['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="edit">Edit</option>
                        <option value="delete">Delete</option>
                        <option value="mark_attendance">Mark Attendance</option>
                        <option value="view_register">View Register</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h3>Closed Class Sessions</h3>
<?php if (empty($closed_sessions)): ?>
    <p>No closed sessions scheduled yet.</p>
<?php else: ?>
    <table class="standard-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Program</th>
                <th>Level</th>
                <th>Unit</th>
                <th>Lecturer</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $counter = 1; foreach ($closed_sessions as $session): ?>
            <tr style="background-color: #ddd;">
                <td><?php echo $counter++; ?></td>
                <td><?php echo htmlspecialchars($session['session_date']); ?></td>
                <td><?php echo htmlspecialchars($session['program_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($session['level_name']); ?></td>
                <td><?php echo htmlspecialchars($session['unit_name']); ?></td>
                <td><?php echo htmlspecialchars($session['title'] . ' ' . $session['lecturer_name']); ?></td>
                <td><?php echo $session['is_closed'] ? 'Closed' : 'Open'; ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $session['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="edit">Edit</option>
                        <option value="delete">Delete</option>
                        <option value="mark_attendance">Mark Attendance</option>
                        <option value="view_register">View Register</option>
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
.standard-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.standard-table th, .standard-table td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
}
.standard-table th {
    background-color: #2980b9;
    color: white;
}
</style>

<script>
function toggleForm() {
    const form = document.getElementById('sessionForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function toggleLecturerForm() {
    const form = document.getElementById('lecturerForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function filterLevels() {
    const programId = document.getElementById('program_id').value;
    const levelSelect = document.getElementById('level_id');
    const unitSelect = document.getElementById('unit_id');
    const editSession = <?php echo json_encode($edit_session ?? null); ?>;
    const selectedLevel = editSession ? editSession.level_id : '';

    const levelOptions = levelSelect.getElementsByTagName('option');
    for (let i = 0; i < levelOptions.length; i++) {
        const optionProgram = levelOptions[i].getAttribute('data-program');
        levelOptions[i].style.display = (programId === '' || optionProgram === programId) ? '' : 'none';
    }
    if (!editSession) levelSelect.value = '';
    filterUnits();
    if (editSession) levelSelect.value = selectedLevel;
}

function filterUnits() {
    const levelId = document.getElementById('level_id').value;
    const unitSelect = document.getElementById('unit_id');
    const editSession = <?php echo json_encode($edit_session ?? null); ?>;
    const selectedUnit = editSession ? editSession.unit_id : '';

    const unitOptions = unitSelect.getElementsByTagName('option');
    for (let i = 0; i < unitOptions.length; i++) {
        const optionLevel = unitOptions[i].getAttribute('data-level');
        unitOptions[i].style.display = (levelId === '' || optionLevel === levelId) ? '' : 'none';
    }
    if (!editSession) unitSelect.value = '';
    if (editSession) unitSelect.value = selectedUnit;
}

function handleAction(select, sessionId) {
    const action = select.value;
    if (action) {
        if (action === 'edit') {
            window.location.href = `class_sessions.php?edit=${sessionId}`;
        } else if (action === 'delete') {
            if (confirm('Are you sure you want to delete this session?')) {
                window.location.href = `class_sessions.php?delete=${sessionId}`;
            }
        } else if (action === 'mark_attendance') {
            window.location.href = `attendance.php?session_id=${sessionId}`;
        } else if (action === 'view_register') {
            window.location.href = `reports.php?report_type=class_session&session_id=${sessionId}`;
        }
        select.value = '';
    }
}

window.onload = function() {
    <?php if ($edit_session): ?>
        document.getElementById('sessionForm').style.display = 'block';
        const editSession = <?php echo json_encode($edit_session); ?>;
        document.getElementById('program_id').value = editSession.program_id || '';
        filterLevels();
        document.getElementById('level_id').value = editSession.level_id || '';
        filterUnits();
        document.getElementById('unit_id').value = editSession.unit_id || '';
    <?php else: ?>
        filterLevels();
        filterUnits();
    <?php endif; ?>
};
</script>