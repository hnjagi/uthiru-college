<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

// Initialize messages
$error = '';
$success = '';

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $session_id = $_POST['session_id'];
    $student_ids = $_POST['student_ids'] ?? [];

    try {
        $pdo->beginTransaction();

        // Fetch unit fee from constants table (default to 1600.00 if not found)
        $stmt = $pdo->prepare("SELECT value FROM constants WHERE name = 'Unit fees' LIMIT 1");
        $stmt->execute();
        $unit_fee = $stmt->fetchColumn() ?: 1600.00;

        foreach ($student_ids as $student_id) {
            // Check for existing attendance
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE session_id = ? AND student_id = ?");
            $stmt->execute([$session_id, $student_id]);
            if ($stmt->fetchColumn() == 0) {
                // Insert attendance record
                $stmt = $pdo->prepare("INSERT INTO attendance (session_id, student_id, attended_at, recorded_by) VALUES (?, ?, NOW(), ?)");
                $stmt->execute([$session_id, $student_id, $_SESSION['user_id']]);
                $attendance_id = $pdo->lastInsertId();

                // Log attendance in audit trail
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], 'Marked Attendance', 'attendance', $attendance_id, "Session ID: $session_id, Student ID: $student_id"]);

                // Check for existing invoice
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE session_id = ? AND student_id = ?");
                $stmt->execute([$session_id, $student_id]);
                if ($stmt->fetchColumn() == 0) {
                    // Insert invoice
                    $stmt = $pdo->prepare("INSERT INTO invoices (student_id, session_id, amount_due, invoice_date) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$student_id, $session_id, $unit_fee]);

                    // Log invoice creation in audit trail
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Created Invoice', 'invoices', $pdo->lastInsertId(), "Student ID: $student_id, Session ID: $session_id, Amount: $unit_fee"]);
                }
            }
        }
        $pdo->commit();
        $success = "Attendance and invoices recorded successfully.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error recording attendance or invoices: " . $e->getMessage();
    }
}

// Fetch open class sessions
$stmt = $pdo->query("
    SELECT cs.id, cs.session_date, p.name AS program_name, l.name AS level_name, u.name AS unit_name, lec.title, lec.full_name AS lecturer_name
    FROM class_sessions cs
    LEFT JOIN programs p ON cs.program_id = p.id
    JOIN levels l ON cs.level_id = l.id
    JOIN units u ON cs.unit_id = u.id
    JOIN lecturers lec ON cs.lecturer_id = lec.id
    WHERE cs.is_closed = 0
    ORDER BY cs.session_date DESC
");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle student-specific redirect
if (isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];
    $stmt = $pdo->prepare("SELECT level_id FROM enrollments WHERE student_id = ? AND academic_year = YEAR(CURDATE())");
    $stmt->execute([$student_id]);
    $level_id = $stmt->fetchColumn();
    if ($level_id) {
        $stmt = $pdo->prepare("SELECT id FROM class_sessions WHERE level_id = ? AND is_closed = 0 ORDER BY session_date DESC LIMIT 1");
        $stmt->execute([$level_id]);
        $session_id = $stmt->fetchColumn();
        if ($session_id) {
            header("Location: attendance.php?session_id=$session_id");
            exit;
        } else {
            $error = "No open sessions available for this student’s level.";
        }
    } else {
        $error = "Student is not enrolled this year.";
    }
}

// Fetch students for selected session
$selected_session = null;
$students = [];
if (isset($_GET['session_id'])) {
    $session_id = $_GET['session_id'];
    $stmt = $pdo->prepare("
        SELECT cs.*, p.name AS program_name, l.name AS level_name, u.name AS unit_name
        FROM class_sessions cs
        LEFT JOIN programs p ON cs.program_id = p.id
        JOIN levels l ON cs.level_id = l.id
        JOIN units u ON cs.unit_id = u.id
        WHERE cs.id = ? AND cs.is_closed = 0
    ");
    $stmt->execute([$session_id]);
    $selected_session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_session) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.reg_number, s.first_name, s.other_name, s.surname, 
                   IFNULL((SELECT 1 FROM attendance a WHERE a.session_id = ? AND a.student_id = s.id), 0) AS attended
            FROM students s
            JOIN enrollments e ON s.id = e.student_id
            WHERE e.level_id = ? AND e.academic_year = ? AND s.deleted_at IS NULL
            ORDER BY s.reg_number
        ");
        $stmt->execute([$session_id, $selected_session['level_id'], $selected_session['academic_year']]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error = "Selected session is closed or not found.";
    }
}
?>

<?php include '../includes/header.php'; ?>
<h2>Attendance Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<h3>Active Class Sessions</h3>
<?php if (empty($sessions)): ?>
    <p>No open sessions available.</p>
<?php else: ?>
    <table class="standard-table">
        <thead>
            <tr>
                <th>#</th>
                <th><a href="?sort=session_date&order=asc">Date <?php echo isset($_GET['sort']) && $_GET['sort'] == 'session_date' ? ($_GET['order'] == 'asc' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=program_name&order=asc">Program <?php echo isset($_GET['sort']) && $_GET['sort'] == 'program_name' ? ($_GET['order'] == 'asc' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=level_name&order=asc">Level <?php echo isset($_GET['sort']) && $_GET['sort'] == 'level_name' ? ($_GET['order'] == 'asc' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=unit_name&order=asc">Unit <?php echo isset($_GET['sort']) && $_GET['sort'] == 'unit_name' ? ($_GET['order'] == 'asc' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=lecturer_name&order=asc">Lecturer <?php echo isset($_GET['sort']) && $_GET['sort'] == 'lecturer_name' ? ($_GET['order'] == 'asc' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sort_column = $_GET['sort'] ?? 'session_date';
            $sort_order = $_GET['order'] ?? 'asc';
            $counter = 1;
            usort($sessions, function($a, $b) use ($sort_column, $sort_order) {
                $a_val = $sort_column == 'lecturer_name' ? ($a['title'] . ' ' . $a['lecturer_name']) : $a[$sort_column];
                $b_val = $sort_column == 'lecturer_name' ? ($b['title'] . ' ' . $b['lecturer_name']) : $b[$sort_column];
                return $sort_order === 'asc' ? strnatcmp($a_val, $b_val) : strnatcmp($b_val, $a_val);
            });
            foreach ($sessions as $session): 
            ?>
            <tr>
                <td><?php echo $counter++; ?></td>
                <td><?php echo htmlspecialchars($session['session_date']); ?></td>
                <td><?php echo htmlspecialchars($session['program_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($session['level_name']); ?></td>
                <td><?php echo htmlspecialchars($session['unit_name']); ?></td>
                <td><?php echo htmlspecialchars($session['title'] . ' ' . $session['lecturer_name']); ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $session['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="mark_attendance">Mark Attendance</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($selected_session): ?>
    <h3>Mark Attendance for <?php echo htmlspecialchars($selected_session['program_name'] . ' - ' . $selected_session['level_name'] . ' - ' . $selected_session['unit_name'] . ' (' . $selected_session['session_date'] . ')'); ?></h3>
    <form method="POST">
        <input type="hidden" name="session_id" value="<?php echo $selected_session['id']; ?>">
        <table class="standard-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><a href="?session_id=<?php echo $selected_session['id']; ?>&sort=reg_number&order=asc">Reg Number <?php echo isset($_GET['sort']) && $_GET['sort'] == 'reg_number' ? ($_GET['order'] == 'asc' ? '↑' : '↓') : '↕'; ?></a></th>
                    <th><a href="?session_id=<?php echo $selected_session['id']; ?>&sort=full_name&order=asc">Name <?php echo isset($_GET['sort']) && $_GET['sort'] == 'full_name' ? ($_GET['order'] == 'asc' ? '↑' : '↓') : '↕'; ?></a></th>
                    <th>Present</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sort_column = $_GET['sort'] ?? 'reg_number';
                $sort_order = $_GET['order'] ?? 'asc';
                $counter = 1;
                usort($students, function($a, $b) use ($sort_column, $sort_order) {
                    $a_val = $sort_column === 'full_name' ? ($a['first_name'] . ' ' . ($a['other_name'] ?? '') . ' ' . $a['surname']) : $a[$sort_column];
                    $b_val = $sort_column === 'full_name' ? ($b['first_name'] . ' ' . ($b['other_name'] ?? '') . ' ' . $b['surname']) : $b[$sort_column];
                    return $sort_order === 'asc' ? strnatcmp($a_val, $b_val) : strnatcmp($b_val, $a_val);
                });
                foreach ($students as $student): 
                ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                    <td><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']); ?></td>
                    <td>
                        <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>" <?php echo $student['attended'] ? 'checked disabled' : ''; ?>>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit">Record Attendance</button>
        <a href="attendance.php" class="cancel-link">Cancel</a>
    </form>
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
function handleAction(select, sessionId) {
    const action = select.value;
    if (action) {
        if (action === 'mark_attendance') {
            window.location.href = `attendance.php?session_id=${sessionId}`;
        }
        select.value = ''; // Reset dropdown
    }
}
</script>