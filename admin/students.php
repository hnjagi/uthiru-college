<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk', 'Coordinator'])) {
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// Include libraries for PDF and Excel export
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Initialize messages
$error = '';
$success = '';
$form_data = $_POST ?: [];

// Handle sorting
$sort_column = $_GET['sort'] ?? 'reg_number';
$sort_order = $_GET['order'] ?? 'ASC';
$valid_columns = ['reg_number', 'first_name', 'phone_number', 'current_level', 'year_joined'];
$sort_column = in_array($sort_column, $valid_columns) ? $sort_column : 'reg_number';
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';
$next_order = $sort_order === 'ASC' ? 'DESC' : 'ASC';

// Handle search, pagination, and records per page
$search = $_GET['search'] ?? '';
$page = max(1, $_GET['page'] ?? 1);
$per_page_options = [10, 50, 100, 'All'];
$per_page = $_GET['per_page'] ?? 10;
if (!in_array($per_page, $per_page_options)) $per_page = 10;
$offset = ($page - 1) * $per_page;

// Handle new filters
$filter_level = $_GET['filter_level'] ?? '';
$filter_year = $_GET['filter_year'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';

// Handle form submission for adding/editing students
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['enroll']) && !isset($_POST['cancel_enroll'])) {
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
    $year_joined = $_POST['year_joined'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $student_type = $_POST['student_type'];
    $is_catchup = ($student_type == 'Catch Up Student') ? 1 : 0;
    $previous_center = $_POST['previous_center'] ?: null;

    if ($id) {
        if (!empty($password) && $password !== $confirm_password) {
            $error = "Passwords do not match.";
        }
    } else {
        if (empty($password)) {
            $error = "Password is required for new students.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        }
    }

    if (empty($error)) {
        $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
        if (!$id) {
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(reg_number, '/', -1), '/', 1) AS UNSIGNED)) AS max_seq FROM students WHERE year_joined = ?");
            $stmt->execute([$year_joined]);
            $max_seq = $stmt->fetchColumn();
            $count = $max_seq ? $max_seq + 1 : 1;
            $reg_number = sprintf("NPBC/IGACU/%d/%04d", $year_joined, $count);
        } else {
            $stmt = $pdo->prepare("SELECT reg_number FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $reg_number = $stmt->fetchColumn();
        }

        try {
            if ($id) {
                if ($hashed_password) {
                    $stmt = $pdo->prepare("UPDATE students SET first_name=?, other_name=?, surname=?, phone_number=?, email=?, date_of_birth=?, nationality=?, id_type=?, id_number=?, church_name=?, church_position=?, year_joined=?, username=?, password=?, is_catchup=?, catchup_center=?, updated_at=NOW(), updated_by=? WHERE id=?");
                    $stmt->execute([$first_name, $other_name, $surname, $phone_number, $email, $date_of_birth, $nationality, $id_type, $id_number, $church_name, $church_position, $year_joined, $username, $hashed_password, $is_catchup, $previous_center, $_SESSION['user_id'], $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE students SET first_name=?, other_name=?, surname=?, phone_number=?, email=?, date_of_birth=?, nationality=?, id_type=?, id_number=?, church_name=?, church_position=?, year_joined=?, username=?, is_catchup=?, catchup_center=?, updated_at=NOW(), updated_by=? WHERE id=?");
                    $stmt->execute([$first_name, $other_name, $surname, $phone_number, $email, $date_of_birth, $nationality, $id_type, $id_number, $church_name, $church_position, $year_joined, $username, $is_catchup, $previous_center, $_SESSION['user_id'], $id]);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO students (first_name, other_name, surname, phone_number, email, date_of_birth, nationality, id_type, id_number, church_name, church_position, year_joined, username, password, reg_number, is_catchup, catchup_center, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$first_name, $other_name, $surname, $phone_number, $email, $date_of_birth, $nationality, $id_type, $id_number, $church_name, $church_position, $year_joined, $username, $hashed_password, $reg_number, $is_catchup, $previous_center, $_SESSION['user_id']]);
            }
            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $id ? 'Updated Student' : 'Added Student', 'students', $id ?: $pdo->lastInsertId(), "Student: $reg_number"]);
            $success = $id ? "Student updated successfully." : "Student added successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                if (strpos($e->getMessage(), 'reg_number') !== false) {
                    $error = "Registration number '$reg_number' already exists.";
                } elseif (strpos($e->getMessage(), 'phone_number') !== false) {
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
        $error = "Invalid student ID.";
    } else {
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            try {
                $stmt = $pdo->prepare("UPDATE students SET deleted_at = NOW(), deleted_by = ?, updated_at = NOW(), updated_by = ? WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $delete_id]);
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Soft Deleted Student', 'students', $delete_id, "Student ID: $delete_id"]);
                    $success = "Student marked as deleted successfully.";
                } else {
                    $error = "Student not found or already deleted.";
                }
            } catch (PDOException $e) {
                $error = "Error deleting student: " . $e->getMessage();
            }
        } else {
            $stmt = $pdo->prepare("SELECT reg_number, first_name, surname FROM students WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$delete_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($student) {
                echo "<div style='color: red; padding: 1em;'>Are you sure you want to delete student {$student['reg_number']} ({$student['first_name']} {$student['surname']})?<br>";
                echo "<a href='students.php?delete=$delete_id&confirm=yes'>Yes</a> | <a href='students.php'>No</a></div>";
                exit;
            } else {
                $error = "Student not found or already deleted.";
            }
        }
    }
}

// Handle enrollment request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['enroll'])) {
    $student_id = $_POST['student_id'];
    $program_id = $_POST['program_id'];
    $level_id = $_POST['level_id'];
    $academic_year = $_POST['academic_year'];
    try {
        $pdo->beginTransaction();

        // Check for existing enrollment
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND academic_year = ?");
        $stmt->execute([$student_id, $academic_year]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Student is already enrolled for $academic_year.");
        }

        // Insert enrollment
        $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, academic_year, program_id, level_id, enrolled_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$student_id, $academic_year, $program_id, $level_id]);
        $enrollment_id = $pdo->lastInsertId();

        // Log enrollment in audit trail
        $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['user_id'], 'Enrolled Student', 'enrollments', $enrollment_id, "Student ID: $student_id, Level ID: $level_id, Academic Year: $academic_year"]);

        // Check if student is subject to registration fee (year_joined >= 2025)
        $stmt = $pdo->prepare("SELECT year_joined, reg_number FROM students WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            throw new Exception("Student not found.");
        }

        if ($student['year_joined'] >= 2025) {
            // Fetch registration fee from constants
            $stmt = $pdo->prepare("SELECT value FROM constants WHERE name = 'registration_fee' LIMIT 1");
            $stmt->execute();
            $registration_fee = $stmt->fetchColumn();
            if ($registration_fee === false) {
                throw new Exception("Registration fee not defined in constants table.");
            }

            // Check for existing registration fee invoice
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE student_id = ? AND session_id IS NULL AND amount_due = ?");
            $stmt->execute([$student_id, $registration_fee]);
            if ($stmt->fetchColumn() == 0) {
                // Create registration fee invoice
                $stmt = $pdo->prepare("INSERT INTO invoices (student_id, session_id, amount_due, invoice_date) VALUES (?, NULL, ?, NOW())");
                $stmt->execute([$student_id, $registration_fee]);
                $invoice_id = $pdo->lastInsertId();

                // Log invoice in audit trail
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], 'Created Invoice', 'invoices', $invoice_id, "Student ID: $student_id, Registration Fee, Amount: $registration_fee, Reg Number: {$student['reg_number']}"]);
            }
        }

        $pdo->commit();
        $success = "Student enrolled successfully.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error enrolling student: " . $e->getMessage();
    }
}

// Handle cancel enrollment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_enroll'])) {
    $student_id = $_POST['student_id'];
    $academic_year = date('Y');
    try {
        $stmt = $pdo->prepare("DELETE FROM enrollments WHERE student_id = ? AND academic_year = ?");
        $stmt->execute([$student_id, $academic_year]);
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], 'Cancelled Enrollment', 'enrollments', $student_id, "Student ID: $student_id, Academic Year: $academic_year"]);
            $success = "Enrollment cancelled successfully.";
        } else {
            $error = "No enrollment found for this year.";
        }
    } catch (PDOException $e) {
        $error = "Error cancelling enrollment: " . $e->getMessage();
    }
}

// Fetch levels for filter
$levels = $pdo->query("SELECT l.id, l.name, p.name AS program_name FROM levels l JOIN programs p ON l.program_id = p.id ORDER BY p.name, l.sequence")->fetchAll(PDO::FETCH_ASSOC);

// Fetch unique years for filter
$years = $pdo->query("SELECT DISTINCT year_joined FROM students WHERE deleted_at IS NULL ORDER BY year_joined DESC")->fetchAll(PDO::FETCH_COLUMN);

// Base SQL for students with level_id
$base_sql = "
    SELECT s.*, 
           COALESCE((
               SELECT l.name 
               FROM enrollments e 
               JOIN levels l ON e.level_id = l.id 
               WHERE e.student_id = s.id 
               AND e.academic_year = YEAR(CURDATE())
               ORDER BY e.enrolled_at DESC 
               LIMIT 1
           ), 'Not Enrolled') AS current_level,
           COALESCE((
               SELECT e.level_id 
               FROM enrollments e 
               WHERE e.student_id = s.id 
               AND e.academic_year = YEAR(CURDATE())
               ORDER BY e.enrolled_at DESC 
               LIMIT 1
           ), '') AS current_level_id,
           EXISTS (
               SELECT 1 
               FROM enrollments e 
               WHERE e.student_id = s.id 
               AND e.academic_year = YEAR(CURDATE())
           ) AS is_enrolled
    FROM students s
    WHERE s.deleted_at IS NULL
      AND (s.reg_number LIKE :search OR s.first_name LIKE :search OR s.surname LIKE :search)
";
$params = [':search' => "%$search%"];

if ($filter_level) {
    $base_sql .= " AND EXISTS (SELECT 1 FROM enrollments e WHERE e.student_id = s.id AND e.level_id = :level_id AND e.academic_year = YEAR(CURDATE()))";
    $params[':level_id'] = $filter_level;
}
if ($filter_year) {
    $base_sql .= " AND s.year_joined = :year_joined";
    $params[':year_joined'] = $filter_year;
}
if ($filter_type !== '') {
    $base_sql .= " AND s.is_catchup = :is_catchup";
    $params[':is_catchup'] = $filter_type === 'catchup' ? 1 : 0;
}

// Fetch paginated students for display
$sql = $base_sql . " ORDER BY $sort_column $sort_order";
if ($per_page !== 'All') {
    $sql .= " LIMIT :offset, :per_page";
}
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
if ($per_page !== 'All') {
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
}
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch total students for pagination with filters
$total_sql = "SELECT COUNT(*) FROM students s WHERE s.deleted_at IS NULL AND (s.reg_number LIKE :search OR s.first_name LIKE :search OR s.surname LIKE :search)";
if ($filter_level) {
    $total_sql .= " AND EXISTS (SELECT 1 FROM enrollments e WHERE e.student_id = s.id AND e.level_id = :level_id AND e.academic_year = YEAR(CURDATE()))";
}
if ($filter_year) {
    $total_sql .= " AND s.year_joined = :year_joined";
}
if ($filter_type !== '') {
    $total_sql .= " AND s.is_catchup = :is_catchup";
}
$stmt = $pdo->prepare($total_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$total_students = $stmt->fetchColumn();
$total_pages = ($per_page === 'All') ? 1 : ceil($total_students / $per_page);

// Fetch all students for export (no LIMIT)
if (isset($_GET['export'])) {
    $export_sql = $base_sql . " ORDER BY $sort_column $sort_order";
    $stmt = $pdo->prepare($export_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $export_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle PDF export
    if ($_GET['export'] === 'pdf') {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/tcpdf/tcpdf.php';
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('NPBC IGAC Uthiru Center');
        $pdf->SetTitle('Student List');
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();

        $html = "<h1>NPBC IGAC Uthiru Center</h1>";
        $html .= "<h2>Student List</h2>";
        $html .= "<table border='1'>";
        $html .= "<tr><th>#</th><th>Reg Number</th><th>Name</th><th>Phone</th><th>Current Level</th><th>Year Joined</th><th>Catch-up</th></tr>";
        $counter = 1;
        foreach ($export_students as $student) {
            $html .= "<tr>";
            $html .= "<td>" . $counter++ . "</td>";
            $html .= "<td>" . htmlspecialchars($student['reg_number']) . "</td>";
            $html .= "<td>" . htmlspecialchars($student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']) . "</td>";
            $html .= "<td>" . htmlspecialchars($student['phone_number']) . "</td>";
            $html .= "<td>" . htmlspecialchars($student['current_level']) . "</td>";
            $html .= "<td>" . htmlspecialchars($student['year_joined']) . "</td>";
            $html .= "<td>" . ($student['is_catchup'] ? 'Yes (' . htmlspecialchars($student['catchup_center'] ?? 'N/A') . ')' : 'No') . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('student_list.pdf', 'I');
        exit;
    }

    // Handle Excel export
    if ($_GET['export'] === 'excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $row = 1;

        $sheet->setCellValue('A' . $row, 'NPBC IGAC Uthiru Center');
        $row++;
        $sheet->setCellValue('A' . $row, 'Student List');
        $row += 2;

        $sheet->setCellValue('A' . $row, '#');
        $sheet->setCellValue('B' . $row, 'Reg Number');
        $sheet->setCellValue('C' . $row, 'Name');
        $sheet->setCellValue('D' . $row, 'Phone');
        $sheet->setCellValue('E' . $row, 'Current Level');
        $sheet->setCellValue('F' . $row, 'Year Joined');
        $sheet->setCellValue('G' . $row, 'Catch-up');
        $row++;

        $counter = 1;
        foreach ($export_students as $student) {
            $sheet->setCellValue('A' . $row, $counter++);
            $sheet->setCellValue('B' . $row, $student['reg_number']);
            $sheet->setCellValue('C' . $row, $student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']);
            $sheet->setCellValue('D' . $row, $student['phone_number']);
            $sheet->setCellValue('E' . $row, $student['current_level']);
            $sheet->setCellValue('F' . $row, $student['year_joined']);
            $sheet->setCellValue('G' . $row, $student['is_catchup'] ? 'Yes (' . ($student['catchup_center'] ?? 'N/A') . ')' : 'No');
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="student_list.xlsx"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    }
}

// Fetch student for editing or enrollment
$edit_student = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_GET['edit']]);
    $edit_student = $stmt->fetch(PDO::FETCH_ASSOC);
}
$enroll_student = null;
if (isset($_GET['enroll'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               COALESCE((
                   SELECT l.name 
                   FROM enrollments e 
                   JOIN levels l ON e.level_id = l.id 
                   WHERE e.student_id = s.id 
                   AND e.academic_year = YEAR(CURDATE())
                   ORDER BY e.enrolled_at DESC 
                   LIMIT 1
               ), 'Not Enrolled') AS current_level
        FROM students s
        WHERE s.id = ? AND s.deleted_at IS NULL
    ");
    $stmt->execute([$_GET['enroll']]);
    $enroll_student = $stmt->fetch(PDO::FETCH_ASSOC);
}

$programs = $pdo->query("SELECT * FROM programs")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("SELECT l.*, p.name AS program_name FROM levels l JOIN programs p ON l.program_id = p.id")->fetchAll(PDO::FETCH_ASSOC);
$display_data = !empty($error) ? (array) $form_data : ($edit_student ? (array) $edit_student : []);
if (isset($_GET['delete']) && !isset($_GET['confirm'])) {
    $display_data = [];
}
?>

<?php include '../includes/header.php'; ?>
<h2>Student Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button id="toggleForm" onclick="toggleForm()">Add New Student</button>

<div id="studentForm" style="display: <?php echo $edit_student ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_student ? 'Edit Student' : 'Add New Student'; ?></h3>
    <form method="POST">
        <?php if ($edit_student): ?>
            <input type="hidden" name="id" value="<?php echo $edit_student['id']; ?>">
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
        <label>Year Joined:</label>
        <input type="number" name="year_joined" value="<?php echo htmlspecialchars($display_data['year_joined'] ?? date('Y')); ?>" min="2000" max="<?php echo date('Y'); ?>" required>
        <label>Username:</label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($display_data['username'] ?? ''); ?>" required>
        <label>Password:</label>
        <input type="password" name="password" <?php echo $edit_student ? '' : 'required'; ?>>
        <label>Confirm Password:</label>
        <input type="password" name="confirm_password" <?php echo $edit_student ? '' : 'required'; ?>>
        <label>Student Type:</label>
        <select name="student_type" required>
            <option value="Center Student" <?php echo isset($display_data['student_type']) && $display_data['student_type'] === 'Center Student' ? 'selected' : ''; ?>>Center Student</option>
            <option value="Catch Up Student" <?php echo isset($display_data['student_type']) && $display_data['student_type'] === 'Catch Up Student' ? 'selected' : ''; ?>>Catch Up Student</option>
        </select>
        <label>Previous Center (if applicable):</label>
        <input type="text" name="previous_center" value="<?php echo htmlspecialchars($display_data['previous_center'] ?? $edit_student['catchup_center'] ?? ''); ?>">
        <button type="submit"><?php echo $edit_student ? 'Update' : 'Add'; ?> Student</button>
        <?php if ($edit_student): ?>
            <a href="students.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<h3>Registered Students</h3>
<form method="GET">
    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by Reg Number or Name">
    <label>Level:</label>
    <select name="filter_level" onchange="this.form.submit()">
        <option value="">All Levels</option>
        <?php foreach ($levels as $level): ?>
            <option value="<?php echo $level['id']; ?>" <?php echo $filter_level == $level['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label>Year Joined:</label>
    <select name="filter_year" onchange="this.form.submit()">
        <option value="">All Years</option>
        <?php foreach ($years as $year): ?>
            <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($year); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label>Type:</label>
    <select name="filter_type" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="center" <?php echo $filter_type == 'center' ? 'selected' : ''; ?>>Center Students</option>
        <option value="catchup" <?php echo $filter_type == 'catchup' ? 'selected' : ''; ?>>Catch-up Students</option>
    </select>
    <label>Records per page:</label>
    <select name="per_page" onchange="this.form.submit()">
        <?php foreach ($per_page_options as $option): ?>
            <option value="<?php echo $option; ?>" <?php echo $per_page == $option ? 'selected' : ''; ?>>
                <?php echo $option; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
    <a href="students.php">Reset</a>
</form>

<?php if (empty($students)): ?>
    <p>No students found.</p>
<?php else: ?>
    <table class="table-striped">
        <thead>
            <tr>
                <th>#</th>
                <th><a href="?sort=reg_number&order=<?php echo $sort_column == 'reg_number' ? $next_order : 'ASC'; ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>">Reg Number <?php echo $sort_column == 'reg_number' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=first_name&order=<?php echo $sort_column == 'first_name' ? $next_order : 'ASC'; ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>">Name <?php echo $sort_column == 'first_name' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=phone_number&order=<?php echo $sort_column == 'phone_number' ? $next_order : 'ASC'; ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>">Phone <?php echo $sort_column == 'phone_number' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=current_level&order=<?php echo $sort_column == 'current_level' ? $next_order : 'ASC'; ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>">Current Level <?php echo $sort_column == 'current_level' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?sort=year_joined&order=<?php echo $sort_column == 'year_joined' ? $next_order : 'ASC'; ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>">Year Joined <?php echo $sort_column == 'year_joined' ? ($sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>Catch-up</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $counter = $offset + 1; foreach ($students as $student): ?>
            <tr>
                <td><?php echo $counter++; ?></td>
                <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']); ?></td>
                <td><?php echo htmlspecialchars($student['phone_number']); ?></td>
                <td><?php echo htmlspecialchars($student['current_level']); ?></td>
                <td><?php echo htmlspecialchars($student['year_joined']); ?></td>
                <td><?php echo $student['is_catchup'] ? 'Yes (' . htmlspecialchars($student['catchup_center'] ?? 'N/A') . ')' : 'No'; ?></td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['current_level_id']); ?>')">
                        <option value="">Select Action</option>
                        <option value="edit">Edit</option>
                        <option value="delete">Delete</option>
                        <option value="<?php echo $student['is_enrolled'] ? 'edit_enroll' : 'enroll'; ?>">
                            <?php echo $student['is_enrolled'] ? 'Edit Enrollment' : 'Enroll'; ?>
                        </option>
                        <?php if ($student['is_enrolled']): ?>
                            <option value="cancel_enroll">Cancel Enrollment</option>
                        <?php endif; ?>
                        <option value="view_statement">View Statement</option>
                        <option value="mark_attendance">Mark Attendance</option>
                        <option value="make_payment">Make Payment</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin-top: 20px;">
        <a href="?sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>&search=<?php echo urlencode($search); ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>&page=<?php echo $page; ?>&export=pdf"><button>Export to PDF</button></a>
        <a href="?sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>&search=<?php echo urlencode($search); ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>&page=<?php echo $page; ?>&export=excel"><button>Export to Excel</button></a>
    </div>
<?php endif; ?>

<!-- Pagination -->
<?php if ($per_page !== 'All'): ?>
    <nav aria-label="Page navigation">
        <ul class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="students.php?sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>&search=<?php echo urlencode($search); ?>&filter_level=<?php echo urlencode($filter_level); ?>&filter_year=<?php echo urlencode($filter_year); ?>&filter_type=<?php echo urlencode($filter_type); ?>&per_page=<?php echo urlencode($per_page); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php if ($enroll_student): ?>
    <h3><?php echo $enroll_student['current_level'] !== 'Not Enrolled' ? 'Edit Enrollment for' : 'Enroll'; ?> <?php echo htmlspecialchars($enroll_student['reg_number'] . ' (' . $enroll_student['first_name'] . ' ' . $enroll_student['surname'] . ')'); ?></h3>
    <form method="POST">
        <input type="hidden" name="enroll" value="1">
        <input type="hidden" name="student_id" value="<?php echo $enroll_student['id']; ?>">
        <label>Program:</label>
        <select name="program_id" id="program_id" required onchange="filterLevels()">
            <option value="">Select Program</option>
            <?php foreach ($programs as $program): ?>
                <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <label>Level:</label>
        <select name="level_id" id="level_id" required>
            <option value="">Select Level</option>
            <?php foreach ($levels as $level): ?>
                <option value="<?php echo $level['id']; ?>" data-program="<?php echo $level['program_id']; ?>">
                    <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Academic Year:</label>
        <input type="number" name="academic_year" value="<?php echo date('Y'); ?>" min="2000" max="<?php echo date('Y') + 1; ?>" required>
        <button type="submit"><?php echo $enroll_student['current_level'] !== 'Not Enrolled' ? 'Update Enrollment' : 'Enroll Student'; ?></button>
        <?php if ($enroll_student['current_level'] !== 'Not Enrolled'): ?>
            <button type="submit" name="cancel_enroll" value="1" onclick="return confirm('Are you sure you want to cancel this enrollment?')">Cancel Enrollment</button>
        <?php endif; ?>
        <a href="students.php">Cancel</a>
    </form>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<script>
function toggleForm() {
    const form = document.getElementById('studentForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function handleAction(select, studentId, levelId) {
    const action = select.value;
    if (action) {
        if (action === 'delete') {
            if (confirm('Are you sure you want to delete this student?')) {
                window.location.href = `students.php?delete=${studentId}`;
            }
        } else if (action === 'edit') {
            window.location.href = `students.php?edit=${studentId}`;
        } else if (action === 'enroll' || action === 'edit_enroll') {
            window.location.href = `students.php?enroll=${studentId}`;
        } else if (action === 'cancel_enroll') {
            if (confirm('Are you sure you want to cancel this enrollment?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'students.php';
                const input1 = document.createElement('input');
                input1.type = 'hidden';
                input1.name = 'cancel_enroll';
                input1.value = '1';
                const input2 = document.createElement('input');
                input2.type = 'hidden';
                input2.name = 'student_id';
                input2.value = studentId;
                form.appendChild(input1);
                form.appendChild(input2);
                document.body.appendChild(form);
                form.submit();
            }
        } else if (action === 'view_statement') {
            window.location.href = `<?php echo BASE_URL; ?>student/statement.php?level_id=${levelId}&selected_student_id=${studentId}`;
        } else if (action === 'mark_attendance') {
            window.location.href = `attendance.php?student_id=${studentId}`;
        } else if (action === 'make_payment') {
            if (levelId) {
                window.location.href = `payments.php?make_payment=1&student_id=${studentId}&level_id=${levelId}`;
            } else {
                alert('Student is not enrolled. Please enroll the student first.');
            }
        }
        select.value = ''; // Reset dropdown
    }
}

function filterLevels() {
    const programId = document.getElementById('program_id').value;
    const levelSelect = document.getElementById('level_id');
    if (levelSelect) {
        const options = levelSelect.getElementsByTagName('option');
        for (let i = 0; i < options.length; i++) {
            const optionProgram = options[i].getAttribute('data-program');
            options[i].style.display = (programId === '' || optionProgram === programId) ? '' : 'none';
        }
        levelSelect.value = '';
    }
}

window.onload = function() {
    <?php if ($edit_student): ?>
        document.getElementById('studentForm').style.display = 'block';
    <?php endif; ?>
    filterLevels();
};
</script>

<style>
/* Match the table header color to assignments.php */
table thead tr th {
    background-color: #2980b9;
}

/* Style sorting arrows */
th a {
    color: white;
    text-decoration: none;
}
th a:hover {
    text-decoration: underline;
}

/* Style the action dropdown to match styles.css button */
.action-dropdown {
    padding: 0.5em;
    background-color: #2980b9;
    color: white;
    border: none;
    cursor: pointer;
}
.action-dropdown:hover {
    background-color: #3498db;
}

/* Add zebra striping to table rows */
.table-striped tbody tr:nth-child(odd) {
    background-color: #f9f9f9;
}
.table-striped tbody tr:nth-child(even) {
    background-color: #ffffff;
}

/* Adjust table column widths */
.table-striped th:nth-child(1), .table-striped td:nth-child(1) { width: 5%; } /* # */
.table-striped th:nth-child(2), .table-striped td:nth-child(2) { width: 15%; } /* Reg Number */
.table-striped th:nth-child(3), .table-striped td:nth-child(3) { width: 30%; } /* Name - widened */
.table-striped th:nth-child(4), .table-striped td:nth-child(4) { width: 15%; } /* Phone */
.table-striped th:nth-child(5), .table-striped td:nth-child(5) { width: 15%; } /* Current Level */
.table-striped th:nth-child(6), .table-striped td:nth-child(6) { width: 10%; } /* Year Joined */
.table-striped th:nth-child(7), .table-striped td:nth-child(7) { width: 10%; } /* Catch-up */
.table-striped th:nth-child(8), .table-striped td:nth-child(8) { width: 10%; } /* Actions */

/* Style filter form */
form label {
    margin-right: 10px;
}
form select {
    margin-right: 20px;
    padding: 5px;
}
</style>