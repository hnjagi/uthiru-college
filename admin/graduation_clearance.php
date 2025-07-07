<?php
require_once '../includes/db_connect.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Check if user is an admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'Admin') {
    $_SESSION['error'] = "Access denied. Admins only.";
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Fetch admin name for audit logging
$admin_stmt = $pdo->prepare("SELECT first_name, surname FROM administrators WHERE id = ?");
$admin_stmt->execute([$_SESSION['user_id'] ?? 1]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);
$admin_name = $admin ? htmlspecialchars($admin['first_name'] . ' ' . $admin['surname']) : 'Unknown Admin';

// Initialize messages
$error = '';
$success = '';

// Fetch levels and graduation events
$levels = $pdo->query("SELECT id, name FROM levels ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC);
$events = $pdo->query("SELECT id, name FROM graduation_events ORDER BY event_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Detect clear_student, student_id, level_id, and event_id from URL
$clear_student = isset($_GET['clear_student']) && $_GET['clear_student'] == '1';
$student_id = isset($_GET['student_id']) ? filter_var($_GET['student_id'], FILTER_VALIDATE_INT) : null;
$level_id = isset($_GET['level_id']) ? filter_var($_GET['level_id'], FILTER_VALIDATE_INT) : null;
$event_id = isset($_GET['event_id']) ? filter_var($_GET['event_id'], FILTER_VALIDATE_INT) : null;

// Validate student_id, level_id, and event_id if clear_student is set
$selected_student = null;
if ($clear_student && $student_id && $level_id && $event_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.reg_number, s.first_name, s.other_name, s.surname,
                   EXISTS (
                       SELECT 1 
                       FROM enrollments e 
                       WHERE e.student_id = s.id 
                       AND e.level_id = :level_id 
                       AND e.academic_year = YEAR(CURDATE())
                   ) AS is_enrolled,
                   NOT EXISTS (
                       SELECT 1 
                       FROM graduation_clearance gc 
                       WHERE gc.student_id = s.id 
                       AND gc.level_id = :level_id 
                       AND gc.graduation_event_id = :event_id
                   ) AS not_cleared
            FROM students s 
            WHERE s.id = :student_id AND s.deleted_at IS NULL
        ");
        $stmt->execute([
            ':level_id' => $level_id,
            ':event_id' => $event_id,
            ':student_id' => $student_id
        ]);
        $selected_student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$selected_student) {
            $error = "Student not found or deleted.";
        } elseif (!$selected_student['is_enrolled']) {
            $error = "Student is not enrolled in the selected level for the current academic year.";
        } elseif (!$selected_student['not_cleared']) {
            $error = "Student is already cleared for this level and event.";
        }
    } catch (PDOException $e) {
        $error = "Error validating student: " . $e->getMessage();
    }
}

// Handle single-student clearance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clear_student'])) {
    $student_id = $_POST['student_id'] ?? '';
    $level_id = $_POST['level_id'] ?? '';
    $event_id = $_POST['event_id'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $admin_id = $_SESSION['user_id'] ?? 1;

    if (empty($student_id) || empty($level_id) || empty($event_id)) {
        $error = "Please select a student, level, and event.";
    } else {
        try {
            $pdo->beginTransaction();

            // Fetch student details
            $stmt = $pdo->prepare("SELECT reg_number, CONCAT(first_name, ' ', COALESCE(other_name, ''), ' ', surname) AS full_name, phone_number
                                   FROM students WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                throw new Exception("Student not found.");
            }

            // Check for existing clearance
            $stmt = $pdo->prepare("SELECT id FROM graduation_clearance WHERE student_id = ? AND level_id = ? AND graduation_event_id = ?");
            $stmt->execute([$student_id, $level_id, $event_id]);
            if ($stmt->fetch()) {
                throw new Exception("Student already cleared for this event.");
            }

            // Insert into graduation_clearance
            $stmt = $pdo->prepare("INSERT INTO graduation_clearance (student_id, reg_number, full_name, phone_number, level_id, graduation_event_id, cleared_by, notes)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $student_id,
                $student['reg_number'],
                $student['full_name'],
                $student['phone_number'],
                $level_id,
                $event_id,
                $admin_id,
                $notes
            ]);

            // Log in audit_trail
            $clearance_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details)
                                   VALUES (?, 'Cleared for Graduation', 'graduation_clearance', ?, ?)");
            $stmt->execute([$admin_id, $clearance_id, "Student ID: $student_id, Level ID: $level_id, Event ID: $event_id"]);

            $pdo->commit();
            $success = "Student cleared successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error clearing student: " . $e->getMessage();
        }
    }
}

// Handle export requests (only for cleared students)
if (isset($_GET['export']) && $_GET['export'] == 'cleared' && isset($_GET['event_id'])) {
    $event_id = $_GET['event_id'];

    // Fetch cleared students grouped by level
    $stmt = $pdo->prepare("
        SELECT l.id AS level_id, l.name AS level_name, gc.reg_number, gc.full_name, gc.phone_number, gc.notes, gc.cleared_at
        FROM graduation_clearance gc
        JOIN levels l ON gc.level_id = l.id
        WHERE gc.graduation_event_id = ?
        ORDER BY l.sequence, gc.reg_number
    ");
    $stmt->execute([$event_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group students by level
    $cleared_students_by_level = [];
    foreach ($students as $student) {
        $level_name = $student['level_name'];
        if (!isset($cleared_students_by_level[$level_name])) {
            $cleared_students_by_level[$level_name] = [];
        }
        $cleared_students_by_level[$level_name][] = $student;
    }

    if ($_GET['format'] == 'pdf') {
        $pdf = new TCPDF();
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Uthiru College');
        $pdf->SetTitle('Cleared Students');
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);

        $html = '<h1>Uthiru College - Cleared Students</h1>';
        foreach ($cleared_students_by_level as $level_name => $level_students) {
            $html .= '<h2>Level: ' . htmlspecialchars($level_name) . '</h2>';
            $html .= '<table border="1" cellpadding="5">
                        <thead>
                            <tr>
                                <th>Reg Number</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Notes</th>
                                <th>Cleared At</th>
                            </tr>
                        </thead>
                        <tbody>';
            foreach ($level_students as $student) {
                $html .= '<tr>
                            <td>' . htmlspecialchars($student['reg_number']) . '</td>
                            <td>' . htmlspecialchars($student['full_name']) . '</td>
                            <td>' . htmlspecialchars($student['phone_number']) . '</td>
                            <td>' . htmlspecialchars($student['notes'] ?: 'N/A') . '</td>
                            <td>' . date('Y-m-d', strtotime($student['cleared_at'])) . '</td>
                          </tr>';
            }
            $html .= '</tbody></table><br>';
        }
        $html .= '<p>Printed by: ' . $admin_name . '</p>';
        $pdf->writeHTML($html);
        $pdf->Output('cleared_students.pdf', 'D');
        exit;
    } elseif ($_GET['format'] == 'excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cleared Students');
        $row = 1;

        $sheet->setCellValue('A' . $row, 'Uthiru College - Cleared Students');
        $row += 2;

        foreach ($cleared_students_by_level as $level_name => $level_students) {
            $sheet->setCellValue('A' . $row, "Level: $level_name");
            $row++;
            $sheet->setCellValue('A' . $row, 'Reg Number');
            $sheet->setCellValue('B' . $row, 'Name');
            $sheet->setCellValue('C' . $row, 'Phone');
            $sheet->setCellValue('D' . $row, 'Notes');
            $sheet->setCellValue('E' . $row, 'Cleared At');
            $row++;

            foreach ($level_students as $student) {
                $sheet->setCellValue('A' . $row, $student['reg_number']);
                $sheet->setCellValue('B' . $row, $student['full_name']);
                $sheet->setCellValue('C' . $row, $student['phone_number']);
                $sheet->setCellValue('D' . $row, $student['notes'] ?: 'N/A');
                $sheet->setCellValue('E' . $row, date('Y-m-d', strtotime($student['cleared_at'])));
                $row++;
            }
            $row++; // Add space between levels
        }
        $sheet->setCellValue('A' . $row, 'Printed by: ' . $admin_name);

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="cleared_students.xlsx"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    }
}

// Cleared Students Section: Sorting and Pagination
$event_id = $_GET['event_id'] ?? (!empty($events) ? $events[0]['id'] : '');
$cleared_sort_column = $_GET['cleared_sort'] ?? 'reg_number';
$cleared_sort_order = $_GET['cleared_order'] ?? 'ASC';
$valid_cleared_columns = ['reg_number', 'full_name', 'cleared_at'];
$cleared_sort_column = in_array($cleared_sort_column, $valid_cleared_columns) ? $cleared_sort_column : 'reg_number';
$cleared_sort_order = strtoupper($cleared_sort_order) === 'ASC' ? 'ASC' : 'DESC';
$cleared_next_order = $cleared_sort_order === 'ASC' ? 'DESC' : 'ASC';

$cleared_page = max(1, $_GET['cleared_page'] ?? 1);
$cleared_per_page_options = [10, 50, 100, 'All'];
$cleared_per_page = $_GET['cleared_per_page'] ?? 10;
if (!in_array($cleared_per_page, $cleared_per_page_options)) $cleared_per_page = 10;
$cleared_offset = ($cleared_page - 1) * $cleared_per_page;

// Fetch cleared students grouped by level
$cleared_sql = "
    SELECT l.id AS level_id, l.name AS level_name, gc.reg_number, gc.full_name, gc.phone_number, gc.notes, gc.cleared_at
    FROM graduation_clearance gc
    JOIN levels l ON gc.level_id = l.id
    WHERE gc.graduation_event_id = ?
    ORDER BY l.sequence, gc.$cleared_sort_column $cleared_sort_order";
$cleared_total_sql = "SELECT COUNT(*) FROM graduation_clearance gc WHERE gc.graduation_event_id = ?";
$stmt = $pdo->prepare($cleared_total_sql);
$stmt->execute([$event_id]);
$total_cleared = $stmt->fetchColumn();
$cleared_total_pages = ($cleared_per_page === 'All') ? 1 : ceil($total_cleared / $cleared_per_page);

$display_cleared_sql = $cleared_sql;
if ($cleared_per_page !== 'All') {
    $display_cleared_sql .= " LIMIT ?, ?";
}
$stmt = $pdo->prepare($display_cleared_sql);
if ($cleared_per_page !== 'All') {
    $stmt->bindValue(1, $event_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $cleared_offset, PDO::PARAM_INT);
    $stmt->bindValue(3, $cleared_per_page, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $stmt->execute([$event_id]);
}
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group students by level
$cleared_students_by_level = [];
foreach ($students as $student) {
    $level_name = $student['level_name'];
    if (!isset($cleared_students_by_level[$level_name])) {
        $cleared_students_by_level[$level_name] = [];
    }
    $cleared_students_by_level[$level_name][] = $student;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graduation Clearance - Uthiru College</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <style>
        /* Override and enhance styles from styles.css for tables */
        .standard-table th, .standard-table td {
            padding: 12px 15px; /* Increase padding for better spacing */
            word-break: break-word; /* Prevent text overflow */
        }
        .standard-table th.reg-number, .standard-table td.reg-number {
            min-width: 150px; /* Ensure Reg Number column has enough space */
        }
        .standard-table th.name, .standard-table td.name {
            min-width: 200px; /* Wider column for names */
        }
        .standard-table th.units-attended, .standard-table td.units-attended,
        .standard-table th.assignments-submitted, .standard-table td.assignments-submitted,
        .standard-table th.balance, .standard-table td.balance,
        .standard-table th.ready, .standard-table td.ready {
            min-width: 120px; /* Numeric columns */
            text-align: center; /* Center-align numeric data */
        }
        .standard-table th.phone, .standard-table td.phone {
            min-width: 150px;
        }
        .standard-table th.notes, .standard-table td.notes {
            min-width: 200px;
        }
        .standard-table th.cleared-at, .standard-table td.cleared-at {
            min-width: 120px;
            text-align: center;
        }
        .table-responsive {
            margin-bottom: 20px; /* Space between tables */
        }
        .center-buttons {
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <div class="container mt-4">
        <h1>Graduation Clearance</h1>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Clear Student Button and Form -->
        <div class="center-buttons">
            <button id="toggleClearForm" onclick="toggleClearForm()" class="btn btn-primary">Clear Student</button>
        </div>
        <div id="clearForm" style="display: <?php echo $clear_student ? 'block' : 'none'; ?>;">
            <h3>Clear Student for Graduation</h3>
            <?php if ($clear_student && $selected_student): ?>
                <?php
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.id) AS units_attended,
                                              COUNT(DISTINCT ass.id) AS assignments_submitted,
                                              COALESCE(SUM(d.amount_due - d.amount_paid), 0) AS balance
                                       FROM students s
                                       LEFT JOIN attendance a ON s.id = a.student_id
                                       LEFT JOIN assignments ass ON s.id = ass.student_id AND ass.submission_date IS NOT NULL
                                       LEFT JOIN debts d ON s.id = d.student_id
                                       WHERE s.id = ?");
                $stmt->execute([$student_id]);
                $readiness = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("SELECT COUNT(*) AS total_units FROM units WHERE level_id = ?");
                $stmt->execute([$level_id]);
                $total_units = $stmt->fetchColumn();
                $is_ready = ($readiness['units_attended'] == $total_units &&
                             $readiness['assignments_submitted'] == $total_units &&
                             $readiness['balance'] <= 0) ? 'Yes' : 'No';
                ?>
                <form method="POST">
                    <input type="hidden" name="clear_student" value="1">
                    <input type="hidden" name="student_id" value="<?php echo $selected_student['id']; ?>">
                    <input type="hidden" name="level_id" value="<?php echo htmlspecialchars($level_id); ?>">
                    <input type="hidden" name="event_id" value="<?php echo htmlspecialchars($event_id); ?>">
                    <div class="table-responsive">
                        <table class="standard-table">
                            <thead>
                                <tr>
                                    <th class="reg-number">Reg Number</th>
                                    <th class="name">Name</th>
                                    <th class="units-attended">Units Attended</th>
                                    <th class="assignments-submitted">Assignments Submitted</th>
                                    <th class="balance">Balance (KSH)</th>
                                    <th class="ready">Ready</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="reg-number"><?php echo htmlspecialchars($selected_student['reg_number']); ?></td>
                                    <td class="name"><?php echo htmlspecialchars($selected_student['first_name'] . ' ' . ($selected_student['other_name'] ? $selected_student['other_name'] . ' ' : '') . $selected_student['surname']); ?></td>
                                    <td class="units-attended"><?php echo $readiness['units_attended'] . '/' . $total_units; ?></td>
                                    <td class="assignments-submitted"><?php echo $readiness['assignments_submitted'] . '/' . $total_units; ?></td>
                                    <td class="balance <?php echo $readiness['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo number_format($readiness['balance'], 2); ?>
                                    </td>
                                    <td class="ready"><?php echo $is_ready; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-group">
                        <label for="notes">Notes <?php echo $is_ready === 'No' ? '(Required for non-ready student)' : '(Optional)'; ?></label>
                        <textarea name="notes" id="notes" class="form-control" rows="4" <?php echo $is_ready === 'No' ? 'required' : ''; ?>></textarea>
                    </div>
                    <div class="center-buttons">
                        <button type="submit" class="btn btn-primary">Clear Student</button>
                        <a href="<?php echo BASE_URL; ?>admin/graduation_clearance.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <form method="GET">
                    <input type="hidden" name="clear_student" value="1">
                    <div class="form-group">
                        <label for="level_id">Level</label>
                        <select name="level_id" id="level_id" class="form-control" onchange="filterStudents()" required>
                            <option value="">Select Level</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?php echo $level['id']; ?>" <?php echo $level_id == $level['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($level['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="event_id">Graduation Event</label>
                        <select name="event_id" id="event_id" class="form-control" onchange="filterStudents()" required>
                            <option value="">Select Event</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo $event['id']; ?>" <?php echo $event_id == $event['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($event['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="student_id">Student</label>
                        <select name="student_id" id="student_id" class="form-control" required>
                            <option value="">Select Student</option>
                        </select>
                    </div>
                    <div class="center-buttons">
                        <button type="submit" class="btn btn-primary">Load Student</button>
                        <a href="#" onclick="toggleClearForm(); return false;" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Cleared Students Section -->
        <h3>Cleared Students</h3>
        <form method="GET">
            <div class="form-group">
                <label for="event_id">Graduation Event</label>
                <select name="event_id" id="event_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Select Event</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?php echo $event['id']; ?>" <?php echo $event_id == $event['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($event['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label>Records per page:</label>
            <select name="cleared_per_page" onchange="this.form.submit()">
                <?php foreach ($cleared_per_page_options as $option): ?>
                    <option value="<?php echo $option; ?>" <?php echo $cleared_per_page == $option ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="cleared_sort" value="<?php echo htmlspecialchars($cleared_sort_column); ?>">
            <input type="hidden" name="cleared_order" value="<?php echo htmlspecialchars($cleared_sort_order); ?>">
            <div class="center-buttons mt-2">
                <a href="?event_id=<?php echo urlencode($event_id); ?>&export=cleared&format=pdf" class="btn btn-success">Export to PDF</a>
                <a href="?event_id=<?php echo urlencode($event_id); ?>&export=cleared&format=excel" class="btn btn-success">Export to Excel</a>
            </div>
        </form>
        <?php if ($event_id): ?>
            <?php if (!empty($cleared_students_by_level)): ?>
                <?php foreach ($cleared_students_by_level as $level_name => $level_students): ?>
                    <h4>Level: <?php echo htmlspecialchars($level_name); ?></h4>
                    <div class="table-responsive">
                        <table class="standard-table">
                            <thead>
                                <tr>
                                    <th class="reg-number"><a href="?event_id=<?php echo urlencode($event_id); ?>&cleared_sort=reg_number&cleared_order=<?php echo $cleared_sort_column == 'reg_number' ? $cleared_next_order : 'ASC'; ?>&cleared_per_page=<?php echo urlencode($cleared_per_page); ?>">Reg Number <?php echo $cleared_sort_column == 'reg_number' ? ($cleared_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                                    <th class="name"><a href="?event_id=<?php echo urlencode($event_id); ?>&cleared_sort=full_name&cleared_order=<?php echo $cleared_sort_column == 'full_name' ? $cleared_next_order : 'ASC'; ?>&cleared_per_page=<?php echo urlencode($cleared_per_page); ?>">Name <?php echo $cleared_sort_column == 'full_name' ? ($cleared_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                                    <th class="phone">Phone</th>
                                    <th class="notes">Notes</th>
                                    <th class="cleared-at"><a href="?event_id=<?php echo urlencode($event_id); ?>&cleared_sort=cleared_at&cleared_order=<?php echo $cleared_sort_column == 'cleared_at' ? $cleared_next_order : 'ASC'; ?>&cleared_per_page=<?php echo urlencode($cleared_per_page); ?>">Cleared At <?php echo $cleared_sort_column == 'cleared_at' ? ($cleared_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($level_students as $student): ?>
                                    <tr>
                                        <td class="reg-number"><?php echo htmlspecialchars($student['reg_number']); ?></td>
                                        <td class="name"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td class="phone"><?php echo htmlspecialchars($student['phone_number']); ?></td>
                                        <td class="notes"><?php echo htmlspecialchars($student['notes'] ?: 'N/A'); ?></td>
                                        <td class="cleared-at"><?php echo date('Y-m-d', strtotime($student['cleared_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                <?php if ($cleared_per_page !== 'All'): ?>
                    <nav aria-label="Cleared Students pagination">
                        <ul class="pagination">
                            <?php for ($i = 1; $i <= $cleared_total_pages; $i++): ?>
                                <li class="page-item <?php echo $i === $cleared_page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?event_id=<?php echo urlencode($event_id); ?>&cleared_sort=<?php echo $cleared_sort_column; ?>&cleared_order=<?php echo $cleared_sort_order; ?>&cleared_per_page=<?php echo urlencode($cleared_per_page); ?>&cleared_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <p>No students cleared for this event.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php include '../includes/footer.php'; ?>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/scripts.js"></script>
    <script>
        function toggleClearForm() {
            const form = document.getElementById('clearForm');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        function filterStudents() {
            <?php if ($clear_student): ?>
                return;
            <?php endif; ?>
            const levelId = document.getElementById('level_id').value;
            const eventId = document.getElementById('event_id').value;
            const studentSelect = document.getElementById('student_id');
            if (levelId && eventId) {
                fetch(`get_students.php?level_id=${levelId}&event_id=${eventId}`, {
                    method: 'GET',
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(response => response.json())
                .then(data => {
                    studentSelect.innerHTML = '<option value="">Select Student</option>';
                    data.forEach(student => {
                        const option = document.createElement('option');
                        option.value = student.id;
                        option.text = `${student.reg_number} - ${student.first_name} ${student.other_name || ''} ${student.surname}`;
                        studentSelect.appendChild(option);
                    });
                })
                .catch(error => console.error('Error fetching students:', error));
            } else {
                studentSelect.innerHTML = '<option value="">Select Student</option>';
            }
        }

        window.onload = function() {
            <?php if ($clear_student): ?>
                // Form is already visible
            <?php else: ?>
                filterStudents();
            <?php endif; ?>
        };
    </script>
</body>
</html>