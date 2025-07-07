<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';
$form_data = $_POST ?: [];
$edit_mark = null;

// Ensure the upload directory exists
$upload_dir = '../uploads/marks/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle marks list upload (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['marks_file']) && $_SESSION['role'] === 'Admin') {
    $file = $_FILES['marks_file'];
    $file_name = $file['name'];
    $file_tmp = $file['tmp_name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed_exts = ['pdf', 'csv'];

    if (!in_array($file_ext, $allowed_exts)) {
        $error = "Only PDF or CSV files are allowed.";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Error uploading file.";
    } else {
        $file_content = file_get_contents($file_tmp);
        $file_hash = hash('sha1', $file_content);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM marks_upload WHERE file_hash = ?");
        $stmt->execute([$file_hash]);
        if ($stmt->fetchColumn() > 0) {
            $error = "This document has already been uploaded.";
        } else {
            $custom_filename = trim($_POST['custom_filename'] ?? '');
            if (!empty($custom_filename)) {
                $base_file_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $custom_filename);
                $file_path = $upload_dir . $base_file_name . '.' . $file_ext;
                $counter = 1;
                while (file_exists($file_path)) {
                    $file_path = $upload_dir . $base_file_name . '_' . $counter . '.' . $file_ext;
                    $counter++;
                }
            } else {
                $base_file_name = 'marks_list_' . date('Ymd_His');
                $file_path = $upload_dir . $base_file_name . '.' . $file_ext;
            }

            if (move_uploaded_file($file_tmp, $file_path)) {
                $received_date = $_POST['received_date'] ?? date('Y-m-d');
                $sent_by_college = $_POST['sent_by_college'] ?? 'Unknown';
                $received_method = $_POST['received_method'] ?? 'email';
                $stmt = $pdo->prepare("INSERT INTO marks_upload (file_path, file_hash, received_date, sent_by_college, received_method, description, upload_date, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([$file_path, $file_hash, $received_date, $sent_by_college, $received_method, $_POST['description'] ?? null, $_SESSION['user_id']]);
                $success = "Marks list uploaded successfully as " . basename($file_path) . ".";
            } else {
                $error = "Failed to move uploaded file.";
            }
        }
    }
}

// Handle edit (fetch mark data)
if (isset($_GET['edit']) && $_SESSION['role'] === 'Admin') {
    $stmt = $pdo->prepare("SELECT * FROM marks WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_GET['edit']]);
    $edit_mark = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_mark) {
        $form_data = $edit_mark;
        $stmt = $pdo->prepare("SELECT p.id AS program_id, l.id AS level_id FROM units u JOIN levels l ON u.level_id = l.id JOIN programs p ON l.program_id = p.id WHERE u.id = ?");
        $stmt->execute([$edit_mark['unit_id']]);
        $unit_details = $stmt->fetch(PDO::FETCH_ASSOC);
        $form_data['program_id'] = $unit_details['program_id'];
        $form_data['level_id'] = $unit_details['level_id'];
    } else {
        $error = "Mark not found.";
    }
}

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['marks_file']) && $_SESSION['role'] === 'Admin') {
    $student_id = $_POST['student_id'] ?? '';
    $unit_id = $_POST['unit_id'] ?? '';
    $mark = $_POST['mark'] ?? '';
    $recorded_date = $_POST['recorded_date'] ?? '';
    $marks_upload_id = $_POST['marks_upload_id'] ?? null;
    $id = $_POST['id'] ?? null;

    error_log("Form submitted: student_id=$student_id, unit_id=$unit_id, mark=$mark, recorded_date=$recorded_date, marks_upload_id=$marks_upload_id, id=$id");

    if (empty($student_id) || empty($unit_id) || empty($mark) || empty($recorded_date)) {
        $error = "All fields are required.";
    } elseif ($mark < 0 || $mark > 100) {
        $error = "Mark must be between 0 and 100.";
    } else {
        $current_year = date('Y');
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN class_sessions cs ON a.session_id = cs.id WHERE a.student_id = ? AND cs.unit_id = ? AND cs.academic_year = ? AND cs.is_closed = 1");
            $stmt->execute([$student_id, $unit_id, $current_year]);
            $attended = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM catchups c WHERE c.student_id = ? AND c.unit_id = ? AND YEAR(c.catchup_date) = ?");
            $stmt->execute([$student_id, $unit_id, $current_year]);
            $caught_up = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE student_id = ? AND unit_id = ? AND deleted_at IS NULL");
            $stmt->execute([$student_id, $unit_id]);
            $submitted_assignment = $stmt->fetchColumn();

            if ($attended == 0 && $caught_up == 0) {
                throw new Exception("Student must have attended or caught up this unit in $current_year.");
            } elseif ($submitted_assignment == 0) {
                throw new Exception("Student must have submitted an assignment for this unit.");
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE marks SET student_id = ?, unit_id = ?, mark = ?, recorded_date = ?, marks_upload_id = ?, updated_at = NOW(), updated_by = ? WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$student_id, $unit_id, $mark, $recorded_date, $marks_upload_id, $_SESSION['user_id'], $id]);
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], 'Updated Mark', 'marks', $id, "Student ID: $student_id, Unit ID: $unit_id"]);
                $success = "Mark updated successfully.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO marks (student_id, unit_id, mark, recorded_date, marks_upload_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$student_id, $unit_id, $mark, $recorded_date, $marks_upload_id, $_SESSION['user_id']]);
                $mark_id = $pdo->lastInsertId();
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], 'Added Mark', 'marks', $mark_id, "Student ID: $student_id, Unit ID: $unit_id"]);
                $success = "Mark recorded successfully.";
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
            error_log("Mark insertion failed: " . $e->getMessage());
        }
    }
}

// Handle delete (Admin only)
if (isset($_GET['delete']) && $_SESSION['role'] === 'Admin') {
    $stmt = $pdo->prepare("UPDATE marks SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_SESSION['user_id'], $_GET['delete']]);
    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$_SESSION['user_id'], 'Deleted Mark', 'marks', $_GET['delete'], "Mark ID: " . $_GET['delete']]);
    $success = "Mark deleted successfully.";
}

// Fetch dropdown data
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("SELECT l.*, p.name AS program_name FROM levels l JOIN programs p ON l.program_id = p.id ORDER BY p.name, l.sequence")->fetchAll(PDO::FETCH_ASSOC);
$current_year = date('Y');
$stmt = $pdo->prepare("SELECT u.*, l.name AS level_name, p.name AS program_name FROM units u JOIN levels l ON u.level_id = l.id JOIN programs p ON l.program_id = p.id WHERE EXISTS (SELECT 1 FROM class_sessions cs WHERE cs.unit_id = u.id AND cs.academic_year = ? AND cs.is_closed = 1) ORDER BY p.name, l.sequence, u.name");
$stmt->execute([$current_year]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

$students = [];
if (!empty($form_data['unit_id'])) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.reg_number, s.first_name, s.surname
        FROM students s
        WHERE s.deleted_at IS NULL 
        AND EXISTS (
            SELECT 1 FROM attendance a 
            JOIN class_sessions cs ON a.session_id = cs.id 
            WHERE a.student_id = s.id AND cs.unit_id = ? AND cs.academic_year = ? AND cs.is_closed = 1
        ) 
        AND EXISTS (
            SELECT 1 FROM assignments a 
            WHERE a.student_id = s.id AND a.unit_id = ? AND a.deleted_at IS NULL
        )
        ORDER BY s.reg_number
    ");
    $stmt->execute([$form_data['unit_id'], $current_year, $form_data['unit_id']]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$marks_uploads = $pdo->query("SELECT id, file_path, received_date, sent_by_college FROM marks_upload ORDER BY received_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch marks with unit filter
$unit_filter = $_GET['unit_filter'] ?? '';
$query = "
    SELECT m.id, m.mark, m.recorded_date, mu.file_path AS upload_path, s.reg_number, s.first_name, s.surname, u.name AS unit_name
    FROM marks m
    JOIN students s ON m.student_id = s.id
    JOIN units u ON m.unit_id = u.id
    LEFT JOIN marks_upload mu ON m.marks_upload_id = mu.id
    WHERE m.deleted_at IS NULL
";
$params = [];
if ($unit_filter) {
    $query .= " AND m.unit_id = ?";
    $params[] = $unit_filter;
}
$query .= " ORDER BY m.recorded_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once '../vendor/tcpdf/tcpdf.php';
    $pdf = new TCPDF();
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('IGAC Uthiru Center');
    $pdf->SetTitle('Marks Report');
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();

    $html = '<h1>Marks Report</h1>';
    $html .= '<table border="1"><tr><th>Reg Number</th><th>Name</th><th>Unit</th><th>Mark</th><th>Recorded Date</th></tr>';
    foreach ($marks as $mark) {
        $html .= '<tr><td>' . htmlspecialchars($mark['reg_number']) . '</td><td>' . htmlspecialchars($mark['first_name'] . ' ' . $mark['surname']) . '</td><td>' . htmlspecialchars($mark['unit_name']) . '</td><td>' . htmlspecialchars($mark['mark']) . '</td><td>' . htmlspecialchars($mark['recorded_date']) . '</td></tr>';
    }
    $html .= '</table>';

    $pdf->writeHTML($html);
    $pdf->Output('marks_report.pdf', 'I');
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<h2>Marks Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<?php if ($_SESSION['role'] === 'Admin'): ?>
    <button onclick="toggleUploadForm()">Upload Marks List</button>
    <div id="uploadForm" style="display: none;">
        <h3>Upload Marks List</h3>
        <form method="POST" enctype="multipart/form-data">
            <label>File (PDF/CSV):</label><input type="file" name="marks_file" accept=".pdf,.csv" required>
            <label>Custom File Name (optional):</label><input type="text" name="custom_filename" placeholder="e.g., Certificate_Marks_Jan2025">
            <label>Received Date:</label><input type="date" name="received_date" required>
            <label>Sent By:</label><input type="text" name="sent_by_college" required>
            <label>Method:</label>
            <select name="received_method" required>
                <option value="email">Email</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="sms">SMS</option>
                <option value="hardcopy">Hardcopy</option>
            </select>
            <label>Description:</label><input type="text" name="description">
            <button type="submit">Upload</button>
        </form>
    </div>

    <button onclick="toggleForm()">Add New Mark</button>
    <div id="markForm" style="display: <?php echo $edit_mark ? 'block' : 'none'; ?>;">
        <h3><?php echo $edit_mark ? 'Edit Mark' : 'Add New Mark'; ?></h3>
        <form method="POST" id="markFormElement">
            <?php if ($edit_mark): ?>
                <input type="hidden" name="id" value="<?php echo $edit_mark['id']; ?>">
            <?php endif; ?>
            <label>Program:</label>
            <select name="program_id" id="program_id" onchange="filterLevels()" required>
                <option value="">Select Program</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?php echo $program['id']; ?>" <?php echo ($form_data['program_id'] ?? '') == $program['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($program['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Level:</label>
            <select name="level_id" id="level_id" onchange="filterUnits()" required>
                <option value="">Select Level</option>
                <?php foreach ($levels as $level): ?>
                    <option value="<?php echo $level['id']; ?>" data-program="<?php echo $level['program_id']; ?>" <?php echo ($form_data['level_id'] ?? '') == $level['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Unit:</label>
            <select name="unit_id" id="unit_id" onchange="filterStudents()" required>
                <option value="">Select Unit</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?php echo $unit['id']; ?>" data-level="<?php echo $unit['level_id']; ?>" <?php echo ($form_data['unit_id'] ?? '') == $unit['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Student:</label>
            <select name="student_id" id="student_id" required onchange="checkStudentAssignment()">
                <option value="">Select Student</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?php echo $student['id']; ?>" <?php echo ($form_data['student_id'] ?? '') == $student['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($student['reg_number'] . ' - ' . $student['first_name'] . ' ' . $student['surname']); ?></option>
                <?php endforeach; ?>
            </select>
            <span id="assignment_validation" style="color: red;"></span>
            <label>Mark (0-100):</label><input type="number" name="mark" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($form_data['mark'] ?? ''); ?>" required>
            <label>Recorded Date:</label><input type="date" name="recorded_date" value="<?php echo htmlspecialchars($form_data['recorded_date'] ?? ''); ?>" required>
            <label>Marks List:</label>
            <select name="marks_upload_id">
                <option value="">None</option>
                <?php foreach ($marks_uploads as $upload): ?>
                    <option value="<?php echo $upload['id']; ?>" <?php echo ($form_data['marks_upload_id'] ?? '') == $upload['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(basename($upload['file_path']) . ' - ' . $upload['received_date']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?php echo $edit_mark ? 'Update Mark' : 'Add Mark'; ?></button>
        </form>
    </div>
<?php endif; ?>

<h3>Filter Marks</h3>
<form method="GET">
    <label>Unit:</label>
    <select name="unit_filter" onchange="this.form.submit()">
        <option value="">All Units</option>
        <?php foreach ($units as $unit): ?>
            <option value="<?php echo $unit['id']; ?>" <?php echo $unit_filter == $unit['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <a href="marks.php?export=pdf"><button type="button">Export to PDF</button></a>
</form>

<h3>Recorded Marks</h3>
<?php if (empty($marks)): ?>
    <p>No marks recorded yet.</p>
<?php else: ?>
    <table>
        <tr>
            <th>Reg Number</th>
            <th>Name</th>
            <th>Unit</th>
            <th>Mark</th>
            <th>Recorded Date</th>
            <th>Reference</th>
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <th>Actions</th>
            <?php endif; ?>
        </tr>
        <?php foreach ($marks as $mark): ?>
            <tr>
                <td><?php echo htmlspecialchars($mark['reg_number']); ?></td>
                <td><?php echo htmlspecialchars($mark['first_name'] . ' ' . $mark['surname']); ?></td>
                <td><?php echo htmlspecialchars($mark['unit_name']); ?></td>
                <td><?php echo htmlspecialchars($mark['mark']); ?></td>
                <td><?php echo htmlspecialchars($mark['recorded_date']); ?></td>
                <td><?php echo $mark['upload_path'] ? '<a href="' . htmlspecialchars($mark['upload_path']) . '" target="_blank">View</a>' : 'None'; ?></td>
                <?php if ($_SESSION['role'] === 'Admin'): ?>
                    <td>
                        <a href="marks.php?edit=<?php echo $mark['id']; ?>">Edit</a> |
                        <a href="marks.php?delete=<?php echo $mark['id']; ?>" onclick="return confirm('Are you sure?');">Delete</a>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
    table tr:nth-child(even) { background-color: #f2f2f2; }
</style>

<script>
function toggleUploadForm() {
    document.getElementById('uploadForm').style.display = document.getElementById('uploadForm').style.display === 'none' ? 'block' : 'none';
}

function toggleForm() {
    document.getElementById('markForm').style.display = document.getElementById('markForm').style.display === 'none' ? 'block' : 'none';
}

function filterLevels() {
    const programId = document.getElementById('program_id').value;
    const levelSelect = document.getElementById('level_id');
    Array.from(levelSelect.options).forEach(option => {
        option.style.display = option.getAttribute('data-program') === programId || option.value === '' ? '' : 'none';
    });
    levelSelect.value = '';
    filterUnits();
}

function filterUnits() {
    const levelId = document.getElementById('level_id').value;
    const unitSelect = document.getElementById('unit_id');
    Array.from(unitSelect.options).forEach(option => {
        option.style.display = option.getAttribute('data-level') === levelId || option.value === '' ? '' : 'none';
    });
    unitSelect.value = '';
    filterStudents();
}

async function filterStudents() {
    const unitId = document.getElementById('unit_id').value;
    const studentSelect = document.getElementById('student_id');
    if (unitId) {
        try {
            const response = await fetch('get_students.php?unit_id=' + unitId);
            const data = await response.json();
            console.log('Fetched students:', data); // Debug: Log fetched students
            studentSelect.innerHTML = '<option value="">Select Student</option>';
            data.forEach(student => {
                const option = document.createElement('option');
                option.value = student.id;
                option.text = student.reg_number + ' - ' + student.first_name + ' ' + student.surname;
                studentSelect.appendChild(option);
            });
            // Set the selected student after population if editing
            <?php if ($edit_mark): ?>
                const studentIdToSelect = '<?php echo $edit_mark['student_id']; ?>';
                console.log('Attempting to select student ID:', studentIdToSelect); // Debug
                studentSelect.value = studentIdToSelect;
                if (studentSelect.value !== studentIdToSelect) {
                    console.error('Failed to select student ID:', studentIdToSelect);
                }
            <?php endif; ?>
            checkStudentAssignment();
        } catch (error) {
            console.error('Error fetching students:', error);
        }
    } else {
        studentSelect.innerHTML = '<option value="">Select Student</option>';
        document.getElementById('assignment_validation').textContent = '';
    }
}

function checkStudentAssignment() {
    const studentId = document.getElementById('student_id').value;
    const unitId = document.getElementById('unit_id').value;
    const validationSpan = document.getElementById('assignment_validation');
    if (studentId && unitId) {
        fetch(`check_assignment.php?student_id=${studentId}&unit_id=${unitId}`)
            .then(response => response.json())
            .then(data => {
                if (!data.has_assignment) {
                    validationSpan.textContent = 'This student has not submitted an assignment for this unit.';
                } else {
                    validationSpan.textContent = '';
                }
            })
            .catch(error => console.error('Error checking assignment:', error));
    } else {
        validationSpan.textContent = '';
    }
}

window.onload = async function() {
    <?php if ($edit_mark): ?>
        console.log('Starting edit mode...');
        document.getElementById('program_id').value = '<?php echo $form_data['program_id']; ?>';
        filterLevels();
        document.getElementById('level_id').value = '<?php echo $form_data['level_id']; ?>';
        filterUnits();
        document.getElementById('unit_id').value = '<?php echo $form_data['unit_id']; ?>';
        // Wait for filterStudents to complete before proceeding
        await filterStudents();
        console.log('Finished setting dropdowns');
    <?php endif; ?>
};
</script>