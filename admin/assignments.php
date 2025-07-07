<?php
session_start();
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

// Include libraries for PDF and Excel export
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/tcpdf/tcpdf.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Fetch logged-in administrator's name for printing
$admin_stmt = $pdo->prepare("SELECT first_name, surname FROM administrators WHERE id = ?");
$admin_stmt->execute([$_SESSION['user_id']]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);
$admin_name = $admin ? htmlspecialchars($admin['first_name'] . ' ' . $admin['surname']) : 'Unknown Admin';

// Initialize variables
$error = '';
$success = '';
$form_data = $_POST ?: [];
$edit_assignment = null;

// Ensure upload directory exists
$upload_dir = '../Uploads/assignments/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Fetch form data (dropdowns) first
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("
    SELECT l.*, p.name AS program_name 
    FROM levels l 
    JOIN programs p ON l.program_id = p.id 
    ORDER BY p.name, l.sequence
")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("
    SELECT u.*, l.name AS level_name, p.name AS program_name
    FROM units u
    JOIN levels l ON u.level_id = l.id
    JOIN programs p ON l.program_id = p.id
    JOIN class_sessions cs ON u.id = cs.unit_id
    WHERE cs.academic_year = ? AND cs.is_closed = 1
    GROUP BY u.id
    ORDER BY p.name, l.sequence, u.name
");
$stmt->execute([date('Y')]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);
$valid_unit_ids = array_column($units, 'id') ?: [];

// Create a mapping of unit_id to level_id
$unit_to_level_map = [];
foreach ($units as $unit) {
    $unit_to_level_map[$unit['id']] = $unit['level_id'];
}

// Fetch distinct submission dates for dropdown
$stmt = $pdo->query("SELECT DISTINCT DATE(submission_date) AS submission_date FROM assignments WHERE deleted_at IS NULL ORDER BY submission_date DESC");
$submission_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle date and unit filter (default to most recent submission date or today)
$date_filter = isset($_GET['date']) ? $_GET['date'] : (!empty($submission_dates) ? $submission_dates[0] : date('Y-m-d'));
$unit_filter = isset($_GET['unit_id']) ? filter_var($_GET['unit_id'], FILTER_VALIDATE_INT) : null;

// Sorting and Pagination for Daily Assignments
$daily_sort_column = $_GET['daily_sort'] ?? 'student_name';
$daily_sort_order = $_GET['daily_order'] ?? 'ASC';
$valid_daily_columns = ['reg_number', 'student_name', 'submission_date', 'submitted_to_college_date'];
$daily_sort_column = in_array($daily_sort_column, $valid_daily_columns) ? $daily_sort_column : 'student_name';
$daily_sort_order = strtoupper($daily_sort_order) === 'ASC' ? 'ASC' : 'DESC';
$daily_next_order = $daily_sort_order === 'ASC' ? 'DESC' : 'ASC';

$daily_page = max(1, $_GET['daily_page'] ?? 1);
$daily_per_page_options = [10, 50, 100, 'All'];
$daily_per_page = $_GET['daily_per_page'] ?? 10;
if (!in_array($daily_per_page, $daily_per_page_options)) $daily_per_page = 10;
$daily_offset = ($daily_page - 1) * $daily_per_page;

// Fetch daily assignments
$daily_sql = "
    SELECT a.id, a.submission_date, a.submitted_to_college_date, a.file_path, a.created_at, a.updated_at, 
           s.reg_number, s.first_name, s.other_name, s.surname, 
           u.name AS unit_name, l.name AS level_name, p.name AS program_name,
           CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS student_name
    FROM assignments a
    JOIN students s ON a.student_id = s.id
    JOIN units u ON a.unit_id = u.id
    JOIN levels l ON u.level_id = l.id
    JOIN programs p ON l.program_id = p.id
    WHERE DATE(a.submission_date) = :date AND s.deleted_at IS NULL AND a.deleted_at IS NULL
";
$daily_params = [':date' => $date_filter];
if ($unit_filter) {
    $daily_sql .= " AND a.unit_id = :unit_id";
    $daily_params[':unit_id'] = $unit_filter;
}
$daily_order_by = '';
switch ($daily_sort_column) {
    case 'reg_number':
        $daily_order_by = "s.reg_number $daily_sort_order";
        break;
    case 'student_name':
        $daily_order_by = "student_name $daily_sort_order";
        break;
    case 'submission_date':
        $daily_order_by = "a.submission_date $daily_sort_order";
        break;
    case 'submitted_to_college_date':
        $daily_order_by = "a.submitted_to_college_date $daily_sort_order";
        break;
}
$daily_sql .= " ORDER BY $daily_order_by";

// Fetch total daily assignments for pagination
$total_daily_sql = "SELECT COUNT(*) FROM assignments a JOIN students s ON a.student_id = s.id JOIN units u ON a.unit_id = u.id WHERE DATE(a.submission_date) = :date AND s.deleted_at IS NULL AND a.deleted_at IS NULL";
if ($unit_filter) {
    $total_daily_sql .= " AND a.unit_id = :unit_id";
}
$stmt = $pdo->prepare($total_daily_sql);
$stmt->execute($daily_params);
$total_daily = $stmt->fetchColumn();
$daily_total_pages = ($daily_per_page === 'All') ? 1 : ceil($total_daily / $daily_per_page);

// Fetch paginated daily assignments
$display_daily_sql = $daily_sql;
if ($daily_per_page !== 'All') {
    $display_daily_sql .= " LIMIT :offset, :per_page";
}
$stmt = $pdo->prepare($display_daily_sql);
foreach ($daily_params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
if ($daily_per_page !== 'All') {
    $stmt->bindValue(':offset', $daily_offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $daily_per_page, PDO::PARAM_INT);
}
$stmt->execute();
$daily_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle delete (soft delete)
if (isset($_GET['delete'])) {
    $assignment_id = filter_var($_GET['delete'], FILTER_VALIDATE_INT);
    if ($assignment_id === false || $assignment_id <= 0) {
        $error = "Invalid assignment ID.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$assignment_id]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($assignment) {
                if ($assignment['file_path'] && file_exists($assignment['file_path'])) {
                    unlink($assignment['file_path']);
                }
                $stmt = $pdo->prepare("UPDATE assignments SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $assignment_id]);
                $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $_SESSION['user_id'],
                    'Deleted Assignment',
                    'assignments',
                    $assignment_id,
                    "Soft deleted assignment for Student ID: {$assignment['student_id']}, Unit ID: {$assignment['unit_id']}"
                ]);
                $success = "Assignment deleted successfully.";
            } else {
                $error = "Assignment not found.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting assignment: " . $e->getMessage();
            file_put_contents('../debug.log', "Delete Error - Assignment ID: $assignment_id, Error: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
}

// Handle edit (fetch assignment data)
if (isset($_GET['edit'])) {
    $assignment_id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($assignment_id === false || $assignment_id <= 0) {
        $error = "Invalid assignment ID.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$assignment_id]);
        $edit_assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($edit_assignment) {
            $form_data = $edit_assignment;
            $stmt = $pdo->prepare("
                SELECT p.id AS program_id, l.id AS level_id
                FROM units u
                JOIN levels l ON u.level_id = l.id
                JOIN programs p ON l.program_id = p.id
                WHERE u.id = ?
            ");
            $stmt->execute([$edit_assignment['unit_id']]);
            $unit_details = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($unit_details) {
                $form_data['program_id'] = $unit_details['program_id'];
                $form_data['level_id'] = $unit_details['level_id'];
            } else {
                $error = "Unit for assignment not found or invalid.";
            }
        } else {
            $error = "Assignment not found.";
        }
    }
}

// Handle assignment submission (add or edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = filter_var($_POST['student_id'], FILTER_VALIDATE_INT);
    $unit_id = filter_var($_POST['unit_id'], FILTER_VALIDATE_INT);
    $submission_date = trim($_POST['submission_date']);
    $submitted_to_college_date = trim($_POST['submitted_to_college_date']) ?: null;
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
    $file_path = null;

    // Debug logging with form inputs
    $form_inputs = json_encode($_POST);
    file_put_contents('../debug.log', "Assignment Submission - Unit ID: $unit_id, Student ID: $student_id, Valid Unit IDs: " . json_encode($valid_unit_ids) . ", Form Inputs: $form_inputs, Time: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

    // Validate inputs
    if (!$student_id || $student_id <= 0 || !$unit_id || $unit_id <= 0) {
        $error = "Invalid student or unit selected.";
        file_put_contents('../debug.log', "Invalid input - Unit ID: $unit_id, Student ID: $student_id\n", FILE_APPEND);
    } elseif (empty($submission_date)) {
        $error = "Submission date is required.";
        file_put_contents('../debug.log', "Missing submission date\n", FILE_APPEND);
    } elseif (empty($valid_unit_ids) || !in_array($unit_id, $valid_unit_ids)) {
        $error = "Selected unit is not valid or not available for assignment submission.";
        file_put_contents('../debug.log', "Invalid unit_id not in dropdown - Unit ID: $unit_id, Valid Unit IDs: " . json_encode($valid_unit_ids) . "\n", FILE_APPEND);
    } else {
        try {
            // Validate student
            $stmt = $pdo->prepare("SELECT first_name, surname FROM students WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                $error = "Student not found or has been deleted.";
                file_put_contents('../debug.log', "Student not found - Student ID: $student_id\n", FILE_APPEND);
            }

            // Get the level_id from the mapping
            if (!isset($unit_to_level_map[$unit_id])) {
                $error = "Invalid unit selected - no associated level found.";
                file_put_contents('../debug.log', "No level_id for unit - Unit ID: $unit_id\n", FILE_APPEND);
            }

            // Validate unit name (still needed for file naming)
            $stmt = $pdo->prepare("SELECT u.name AS unit_name FROM units u WHERE u.id = ?");
            $stmt->execute([$unit_id]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unit) {
                $error = "Invalid unit selected.";
                file_put_contents('../debug.log', "Invalid unit - Unit ID: $unit_id\n", FILE_APPEND);
            }

            // If any validation errors occurred, skip further processing
            if ($error) {
                // Allow form to redisplay with error message
                $form_data = $_POST; // Preserve form inputs
            } else {
                $level_id = $unit_to_level_map[$unit_id];

                // Validate class session or catch-up
                $current_year = date('Y');
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM class_sessions cs
                    WHERE cs.unit_id = ? AND cs.academic_year = ? AND cs.is_closed = 1
                ");
                $stmt->execute([$unit_id, $current_year]);
                $session_exists = $stmt->fetchColumn() > 0;

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM attendance a
                    JOIN class_sessions cs ON a.session_id = cs.id
                    WHERE a.student_id = ? AND cs.unit_id = ? AND cs.academic_year = ?
                ");
                $stmt->execute([$student_id, $unit_id, $current_year]);
                $attended = $stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM catchups c
                    WHERE c.student_id = ? AND c.unit_id = ? AND YEAR(c.catchup_date) = ?
                ");
                $stmt->execute([$student_id, $unit_id, $current_year]);
                $caught_up = $stmt->fetchColumn();

                if (!$session_exists) {
                    $error = "Unit must be completed in the current year to submit an assignment.";
                    file_put_contents('../debug.log', "Unit not completed - Unit ID: $unit_id\n", FILE_APPEND);
                    $form_data = $_POST; // Preserve form inputs
                } elseif ($attended == 0 && $caught_up == 0) {
                    $error = "Student must have attended or completed this unit as a catch-up to submit an assignment.";
                    file_put_contents('../debug.log', "No attendance or catch-up - Student ID: $student_id, Unit ID: $unit_id\n", FILE_APPEND);
                    $form_data = $_POST; // Preserve form inputs
                } else {
                    // Validate unique assignment
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE student_id = ? AND unit_id = ? AND id != ? AND deleted_at IS NULL");
                    $stmt->execute([$student_id, $unit_id, $id ?? 0]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = "Assignment already submitted for this student and unit.";
                        file_put_contents('../debug.log', "Duplicate assignment - Student ID: $student_id, Unit ID: $unit_id\n", FILE_APPEND);
                        $form_data = $_POST; // Preserve form inputs
                    } else {
                        // Handle file upload
                        if (!empty($_FILES['assignment_file']['name'])) {
                            $file = $_FILES['assignment_file'];
                            $file_name = $file['name'];
                            $file_tmp = $file['tmp_name'];
                            $file_error = $file['error'];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            if ($file_ext !== 'pdf') {
                                $error = "Only PDF files are allowed.";
                                file_put_contents('../debug.log', "Invalid file type - File: $file_name\n", FILE_APPEND);
                                $form_data = $_POST; // Preserve form inputs
                            } elseif ($file_error !== UPLOAD_ERR_OK) {
                                $error = "Error uploading file.";
                                file_put_contents('../debug.log', "File upload error - Error: $file_error\n", FILE_APPEND);
                                $form_data = $_POST; // Preserve form inputs
                            } else {
                                $unit_name_clean = preg_replace('/[^a-zA-Z0-9]/', '_', $unit['unit_name']);
                                $firstname_clean = preg_replace('/[^a-zA-Z0-9]/', '_', $student['first_name']);
                                $surname_clean = preg_replace('/[^a-zA-Z0-9]/', '_', $student['surname']);
                                $base_file_name = "{$unit_name_clean}-{$firstname_clean}_{$surname_clean}";
                                $file_path = $upload_dir . $base_file_name . '.' . $file_ext;
                                $counter = 1;
                                while (file_exists($file_path)) {
                                    $file_path = $upload_dir . $base_file_name . '_' . $counter . '.' . $file_ext;
                                    $counter++;
                                }
                                if (!move_uploaded_file($file_tmp, $file_path)) {
                                    $error = "Failed to move uploaded file.";
                                    file_put_contents('../debug.log', "Failed to move file - Path: $file_path\n", FILE_APPEND);
                                    $form_data = $_POST; // Preserve form inputs
                                }
                            }
                        }

                        // Process submission only if no errors
                        if (!$error) {
                            $pdo->beginTransaction();
                            try {
                                if ($id) {
                                    $stmt = $pdo->prepare("SELECT file_path FROM assignments WHERE id = ? AND deleted_at IS NULL");
                                    $stmt->execute([$id]);
                                    $existing_assignment = $stmt->fetch(PDO::FETCH_ASSOC);
                                    if (!$existing_assignment) {
                                        $error = "Assignment not found for update.";
                                        file_put_contents('../debug.log', "Assignment not found - ID: $id\n", FILE_APPEND);
                                        $form_data = $_POST; // Preserve form inputs
                                    } else {
                                        if ($file_path && $existing_assignment['file_path'] && file_exists($existing_assignment['file_path'])) {
                                            unlink($existing_assignment['file_path']);
                                        }
                                        $stmt = $pdo->prepare("
                                            UPDATE assignments 
                                            SET student_id = ?, unit_id = ?, submission_date = ?, submitted_to_college_date = ?, 
                                                file_path = ?, updated_at = NOW(), updated_by = ? 
                                            WHERE id = ?
                                        ");
                                        $stmt->execute([
                                            $student_id, $unit_id, $submission_date, $submitted_to_college_date,
                                            $file_path ?: $existing_assignment['file_path'], $_SESSION['user_id'], $id
                                        ]);
                                        $action = 'Updated Assignment';
                                        $record_id = $id;
                                    }
                                } else {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO assignments (student_id, unit_id, submission_date, submitted_to_college_date, file_path, created_by)
                                        VALUES (?, ?, ?, ?, ?, ?)
                                    ");
                                    $stmt->execute([$student_id, $unit_id, $submission_date, $submitted_to_college_date, $file_path, $_SESSION['user_id']]);
                                    $record_id = $pdo->lastInsertId();
                                    $action = 'Added Assignment';
                                }

                                // Log audit trail
                                if (!$error) {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time)
                                        VALUES (?, ?, ?, ?, ?, NOW())
                                    ");
                                    $stmt->execute([
                                        $_SESSION['user_id'], $action, 'assignments', $record_id,
                                        "Student ID: $student_id, Unit ID: $unit_id"
                                    ]);

                                    $pdo->commit();
                                    $success = $id ? "Assignment updated successfully." : "Assignment recorded successfully.";
                                    $form_data = []; // Reset form after success
                                }
                            } catch (PDOException $e) {
                                $pdo->rollBack();
                                $error = "Database error: " . $e->getMessage();
                                file_put_contents('../debug.log', "Database Error - Unit ID: $unit_id, Student ID: $student_id, Error: " . $e->getMessage() . "\n", FILE_APPEND);
                                $form_data = $_POST; // Preserve form inputs
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
            file_put_contents('../debug.log', "Database Error - Unit ID: $unit_id, Student ID: $student_id, Error: " . $e->getMessage() . "\n", FILE_APPEND);
            $form_data = $_POST; // Preserve form inputs
        }
    }
}

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    class CustomTCPDF extends TCPDF {
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(0, 10, 'Generated on: ' . date('Y-m-d'), 0, 0, 'L');
            $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    }

    $pdf = new CustomTCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('IGAC Uthiru Center');
    $pdf->SetTitle('Assignments Report - ' . $date_filter);
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();

    $html = "<h1>IGAC Uthiru Center for NPBC</h1>";
    $html .= "<h2>Assignments Report - " . htmlspecialchars($date_filter) . "</h2>";

    // Daily Assignments
    $html .= "<h3>Daily Assignments";
    if ($unit_filter) {
        $stmt = $pdo->prepare("SELECT name FROM units WHERE id = ?");
        $stmt->execute([$unit_filter]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        $html .= " for " . htmlspecialchars($unit['name']);
    }
    $html .= "</h3>";
    if (empty($daily_assignments)) {
        $html .= "<p>No assignments submitted on this date" . ($unit_filter ? " for the selected unit" : "") . ".</p>";
    } else {
        $html .= "<table border='1'>";
        $html .= "<tr><th>Reg Number</th><th>Student</th><th>Level</th><th>Unit</th><th>Submission Date</th><th>Submitted to College</th><th>File</th></tr>";
        foreach ($daily_assignments as $assignment) {
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($assignment['reg_number']) . "</td>";
            $html .= "<td>" . htmlspecialchars($assignment['first_name'] . ' ' . ($assignment['other_name'] ?? '') . ' ' . $assignment['surname']) . "</td>";
            $html .= "<td>" . htmlspecialchars($assignment['level_name']) . "</td>";
            $html .= "<td>" . htmlspecialchars($assignment['unit_name']) . "</td>";
            $html .= "<td>" . htmlspecialchars($assignment['submission_date']) . "</td>";
            $html .= "<td>" . htmlspecialchars($assignment['submitted_to_college_date'] ?: 'Not yet') . "</td>";
            $html .= "<td>" . ($assignment['file_path'] ? 'Uploaded' : 'Not uploaded') . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
    }

    // Assignments by Level/Unit
    $html .= "<h3>Assignments by Level and Unit</h3>";
    if (empty($grouped_assignments)) {
        $html .= "<p>No assignments submitted.</p>";
    } else {
        foreach ($grouped_assignments as $level => $units) {
            $html .= "<h4>" . htmlspecialchars($level) . "</h4>";
            foreach ($units as $unit => $assignments) {
                $html .= "<h5>" . htmlspecialchars($unit) . "</h5>";
                $html .= "<table border='1'>";
                $html .= "<tr><th>Reg Number</th><th>Student</th><th>Submission Date</th><th>Submitted to College</th><th>File</th></tr>";
                foreach ($assignments as $assignment) {
                    $html .= "<tr>";
                    $html .= "<td>" . htmlspecialchars($assignment['reg_number']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($assignment['first_name'] . ' ' . ($assignment['other_name'] ?? '') . ' ' . $assignment['surname']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($assignment['submission_date']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($assignment['submitted_to_college_date'] ?: 'Not yet') . "</td>";
                    $html .= "<td>" . ($assignment['file_path'] ? 'Uploaded' : 'Not uploaded') . "</td>";
                    $html .= "</tr>";
                }
                $html .= "</table>";
            }
        }
    }

    $html .= "<p>Printed by: " . htmlspecialchars($admin_name) . "</p>";
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('assignments_report_' . $date_filter . '.pdf', 'I');
    exit;
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $row = 1;

    $sheet->setCellValue('A' . $row, 'IGAC Uthiru Center for NPBC');
    $row++;
    $sheet->setCellValue('A' . $row, 'Assignments Report - ' . $date_filter);
    $row += 2;

    // Daily Assignments
    $sheet->setCellValue('A' . $row, 'Daily Assignments');
    if ($unit_filter) {
        $stmt = $pdo->prepare("SELECT name FROM units WHERE id = ?");
        $stmt->execute([$unit_filter]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        $sheet->setCellValue('A' . $row, 'Daily Assignments for ' . $unit['name']);
    }
    $row++;
    $sheet->setCellValue('A' . $row, 'Reg Number');
    $sheet->setCellValue('B' . $row, 'Student');
    $sheet->setCellValue('C' . $row, 'Level');
    $sheet->setCellValue('D' . $row, 'Unit');
    $sheet->setCellValue('E' . $row, 'Submission Date');
    $sheet->setCellValue('F' . $row, 'Submitted to College');
    $sheet->setCellValue('G' . $row, 'File');
    $row++;
    if (!empty($daily_assignments)) {
        foreach ($daily_assignments as $assignment) {
            $sheet->setCellValue('A' . $row, $assignment['reg_number']);
            $sheet->setCellValue('B' . $row, $assignment['first_name'] . ' ' . ($assignment['other_name'] ?? '') . ' ' . $assignment['surname']);
            $sheet->setCellValue('C' . $row, $assignment['level_name']);
            $sheet->setCellValue('D' . $row, $assignment['unit_name']);
            $sheet->setCellValue('E' . $row, $assignment['submission_date']);
            $sheet->setCellValue('F' . $row, $assignment['submitted_to_college_date'] ?: 'Not yet');
            $sheet->setCellValue('G' . $row, $assignment['file_path'] ? 'Uploaded' : 'Not uploaded');
            $row++;
        }
    } else {
        $sheet->setCellValue('A' . $row, 'No assignments submitted on this date' . ($unit_filter ? ' for the selected unit' : '.'));
        $row++;
    }
    $row++;

    // Assignments by Level/Unit
    $sheet->setCellValue('A' . $row, 'Assignments by Level and Unit');
    $row++;
    foreach ($grouped_assignments as $level => $units) {
        $sheet->setCellValue('A' . $row, $level);
        $row++;
        foreach ($units as $unit => $assignments) {
            $sheet->setCellValue('A' . $row, $unit);
            $row++;
            $sheet->setCellValue('A' . $row, 'Reg Number');
            $sheet->setCellValue('B' . $row, 'Student');
            $sheet->setCellValue('C' . $row, 'Submission Date');
            $sheet->setCellValue('D' . $row, 'Submitted to College');
            $sheet->setCellValue('E' . $row, 'File');
            $row++;
            foreach ($assignments as $assignment) {
                $sheet->setCellValue('A' . $row, $assignment['reg_number']);
                $sheet->setCellValue('B' . $row, $assignment['first_name'] . ' ' . ($assignment['other_name'] ?? '') . ' ' . $assignment['surname']);
                $sheet->setCellValue('C' . $row, $assignment['submission_date']);
                $sheet->setCellValue('D' . $row, $assignment['submitted_to_college_date'] ?: 'Not yet');
                $sheet->setCellValue('E' . $row, $assignment['file_path'] ? 'Uploaded' : 'Not uploaded');
                $row++;
            }
            $row++;
        }
    }

    // Add Printed by
    $sheet->setCellValue('A' . $row, 'Printed by: ' . $admin_name);

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="assignments_report_' . $date_filter . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}

// Fetch all assignments, clustered by level and unit, sorted by student name
$stmt = $pdo->query("
    SELECT a.id, a.submission_date, a.submitted_to_college_date, a.file_path, a.created_at, a.updated_at, 
           s.reg_number, s.first_name, s.other_name, s.surname, 
           u.name AS unit_name, l.name AS level_name, p.name AS program_name,
           CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS student_name
    FROM assignments a
    JOIN students s ON a.student_id = s.id
    JOIN units u ON a.unit_id = u.id
    JOIN levels l ON u.level_id = l.id
    JOIN programs p ON l.program_id = p.id
    WHERE s.deleted_at IS NULL AND a.deleted_at IS NULL
    ORDER BY l.name, u.name, student_name
");
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group assignments by level and unit
$grouped_assignments = [];
foreach ($assignments as $assignment) {
    $level = $assignment['level_name'];
    $unit = $assignment['unit_name'];
    if (!isset($grouped_assignments[$level])) {
        $grouped_assignments[$level] = [];
    }
    if (!isset($grouped_assignments[$level][$unit])) {
        $grouped_assignments[$level][$unit] = [];
    }
    $grouped_assignments[$level][$unit][] = $assignment;
}
?>

<?php include '../includes/header.php'; ?>
<h2>Assignments Overview - <?php echo htmlspecialchars(date('Y-m-d', strtotime($date_filter))); ?></h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<!-- Date and Unit Filter Form -->
<form method="GET" action="">
    <label>Date:</label>
    <select name="date" onchange="this.form.submit()" required>
        <?php if (empty($submission_dates)): ?>
            <option value="<?php echo date('Y-m-d'); ?>">No assignments submitted</option>
        <?php else: ?>
            <?php foreach ($submission_dates as $date): ?>
                <option value="<?php echo $date; ?>" <?php echo $date_filter === $date ? 'selected' : ''; ?>>
                    <?php echo date('m/d/Y', strtotime($date)); ?>
                </option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <label>Unit (optional):</label>
    <select name="unit_id" onchange="this.form.submit()">
        <option value="">All Units</option>
        <?php foreach ($units as $unit): ?>
            <option value="<?php echo $unit['id']; ?>" <?php echo $unit_filter == $unit['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($unit['program_name'] . ' - ' . $unit['level_name'] . ' - ' . $unit['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
    <a href="?export=pdf&date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>"><button type="button">Export to PDF</button></a>
    <a href="?export=excel&date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>"><button type="button">Export to Excel</button></a>
</form>

<!-- Add New Assignment Button -->
<button id="toggleFormBtn" onclick="toggleForm()">Add New Assignment</button>

<div id="assignmentForm" style="display: <?php echo $edit_assignment || !empty($error) ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_assignment ? 'Edit Assignment' : 'Add New Assignment'; ?></h3>
    <form method="POST" id="assignmentFormElement" enctype="multipart/form-data" onsubmit="return validateForm()">
        <?php if ($edit_assignment): ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_assignment['id']); ?>">
        <?php endif; ?>
        <label>Program:</label>
        <select name="program_id" id="program_id" required onchange="filterLevels()">
            <option value="">Select Program</option>
            <?php foreach ($programs as $program): ?>
                <option value="<?php echo $program['id']; ?>" <?php echo ($form_data['program_id'] ?? '') == $program['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($program['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Level:</label>
        <select name="level_id" id="level_id" required onchange="filterUnits()">
            <option value="">Select Level</option>
            <?php foreach ($levels as $level): ?>
                <option value="<?php echo $level['id']; ?>" data-program="<?php echo $level['program_id']; ?>" <?php echo ($form_data['level_id'] ?? '') == $level['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Unit:</label>
        <select name="unit_id" id="unit_id" required onchange="filterStudents()">
            <option value="">Select Unit</option>
            <?php foreach ($units as $unit): ?>
                <option value="<?php echo $unit['id']; ?>" data-level="<?php echo $unit['level_id']; ?>" <?php echo ($form_data['unit_id'] ?? '') == $unit['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($unit['program_name'] . ' - ' . $unit['level_name'] . ' - ' . $unit['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Student:</label>
        <select name="student_id" id="student_id" required>
            <option value="">Select Student</option>
            <?php foreach ($students as $student): ?>
                <option value="<?php echo $student['id']; ?>" <?php echo ($form_data['student_id'] ?? '') == $student['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($student['reg_number'] . ' - ' . $student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Submission Date:</label>
        <input type="date" name="submission_date" value="<?php echo htmlspecialchars($form_data['submission_date'] ?? date('Y-m-d')); ?>" required>
        <label>Submitted to College Date (Optional):</label>
        <input type="date" name="submitted_to_college_date" value="<?php echo htmlspecialchars($form_data['submitted_to_college_date'] ?? ''); ?>">
        <label>Assignment File (PDF, Optional):</label>
        <input type="file" name="assignment_file" accept="application/pdf">
        <?php if ($edit_assignment && $edit_assignment['file_path']): ?>
            <p>Current file: <a href="<?php echo htmlspecialchars($edit_assignment['file_path']); ?>" target="_blank"><?php echo htmlspecialchars(basename($edit_assignment['file_path'])); ?></a></p>
        <?php endif; ?>
        <button type="submit"><?php echo $edit_assignment ? 'Update Assignment' : 'Record Assignment'; ?></button>
        <?php if ($edit_assignment): ?>
            <a href="assignments.php" class="cancel-link">Cancel</a>
        <?php else: ?>
            <a href="#" onclick="toggleForm(); return false;" class="cancel-link">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<!-- Daily Assignments -->
<h3>Daily Assignments</h3>
<form method="GET">
    <label>Records per page:</label>
    <select name="daily_per_page" onchange="this.form.submit()">
        <?php foreach ($daily_per_page_options as $option): ?>
            <option value="<?php echo $option; ?>" <?php echo $daily_per_page == $option ? 'selected' : ''; ?>>
                <?php echo $option; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
    <input type="hidden" name="unit_id" value="<?php echo htmlspecialchars($unit_filter); ?>">
    <input type="hidden" name="daily_sort" value="<?php echo htmlspecialchars($daily_sort_column); ?>">
    <input type="hidden" name="daily_order" value="<?php echo htmlspecialchars($daily_sort_order); ?>">
</form>
<?php if (empty($daily_assignments)): ?>
    <p>No assignments submitted on this date<?php echo $unit_filter ? ' for the selected unit' : ''; ?>.</p>
<?php else: ?>
    <table class="table-striped" id="dailyAssignmentsTable">
        <thead>
            <tr>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_sort=reg_number&daily_order=<?php echo $daily_sort_column == 'reg_number' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>">Reg Number <?php echo $daily_sort_column == 'reg_number' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_sort=student_name&daily_order=<?php echo $daily_sort_column == 'student_name' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>">Student <?php echo $daily_sort_column == 'student_name' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>Level</th>
                <th>Unit</th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_sort=submission_date&daily_order=<?php echo $daily_sort_column == 'submission_date' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>">Submission Date <?php echo $daily_sort_column == 'submission_date' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th><a href="?date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_sort=submitted_to_college_date&daily_order=<?php echo $daily_sort_column == 'submitted_to_college_date' ? $daily_next_order : 'ASC'; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>">Submitted to College <?php echo $daily_sort_column == 'submitted_to_college_date' ? ($daily_sort_order == 'ASC' ? '↑' : '↓') : '↕'; ?></a></th>
                <th>File</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($daily_assignments as $assignment): 
                $student_name = htmlspecialchars($assignment['first_name'] . ' ' . ($assignment['other_name'] ?? '') . ' ' . $assignment['surname']);
            ?>
            <tr>
                <td><?php echo htmlspecialchars($assignment['reg_number']); ?></td>
                <td><?php echo $student_name; ?></td>
                <td><?php echo htmlspecialchars($assignment['level_name']); ?></td>
                <td><?php echo htmlspecialchars($assignment['unit_name']); ?></td>
                <td><?php echo htmlspecialchars($assignment['submission_date']); ?></td>
                <td><?php echo htmlspecialchars($assignment['submitted_to_college_date'] ?: 'Not yet'); ?></td>
                <td>
                    <?php if ($assignment['file_path']): ?>
                        <a href="<?php echo htmlspecialchars($assignment['file_path']); ?>" target="_blank">View PDF</a>
                    <?php else: ?>
                        Not uploaded
                    <?php endif; ?>
                </td>
                <td>
                    <select class="action-dropdown" onchange="handleAction(this, <?php echo $assignment['id']; ?>)">
                        <option value="">Select Action</option>
                        <option value="edit">Edit</option>
                        <option value="delete">Delete</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($daily_per_page !== 'All'): ?>
        <nav aria-label="Daily Assignments pagination">
            <ul class="pagination">
                <?php for ($i = 1; $i <= $daily_total_pages; $i++): ?>
                    <li class="page-item <?php echo $i === $daily_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_sort=<?php echo $daily_sort_column; ?>&daily_order=<?php echo $daily_sort_order; ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>&daily_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- Assignments by Level/Unit -->
<h3>Assignments by Level and Unit</h3>
<?php if (empty($grouped_assignments)): ?>
    <p>No assignments submitted yet.</p>
<?php else: ?>
    <input type="text" id="filterInput" placeholder="Filter by unit or student name..." onkeyup="filterTable()">
    <?php foreach ($grouped_assignments as $level => $units): ?>
        <h4><?php echo htmlspecialchars($level); ?></h4>
        <?php foreach ($units as $unit => $assignments): ?>
            <h5><?php echo htmlspecialchars($unit); ?></h5>
            <table class="table-striped" id="assignmentsTable_<?php echo htmlspecialchars(str_replace(' ', '_', $level . '_' . $unit)); ?>">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Reg Number</th>
                        <th>Student</th>
                        <th>Submission Date</th>
                        <th>Submitted to College</th>
                        <th>File</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($assignments as $assignment): 
                        $student_name = htmlspecialchars($assignment['first_name'] . ' ' . ($assignment['other_name'] ?? '') . ' ' . $assignment['surname']);
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo htmlspecialchars($assignment['reg_number']); ?></td>
                        <td><?php echo $student_name; ?></td>
                        <td><?php echo htmlspecialchars($assignment['submission_date']); ?></td>
                        <td><?php echo htmlspecialchars($assignment['submitted_to_college_date'] ?: 'Not yet'); ?></td>
                        <td>
                            <?php if ($assignment['file_path']): ?>
                                <a href="<?php echo htmlspecialchars($assignment['file_path']); ?>" target="_blank">View PDF</a>
                            <?php else: ?>
                                Not uploaded
                            <?php endif; ?>
                        </td>
                        <td>
                            <select class="action-dropdown" onchange="handleAction(this, <?php echo $assignment['id']; ?>)">
                                <option value="">Select Action</option>
                                <option value="edit">Edit</option>
                                <option value="delete">Delete</option>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
/* Align with payments.php styling */
.table-striped thead tr th {
    background-color: #2980b9;
    color: white;
}
.table-striped th a {
    color: white;
    text-decoration: none;
}
.table-striped th a:hover {
    text-decoration: underline;
}
.action-dropdown, .action-link {
    color: #2980b9;
}
.action-link:hover {
    text-decoration: underline;
}
.table-striped tbody tr:nth-child(odd) {
    background-color: #f9f9f9;
}
.table-striped tbody tr:nth-child(even) {
    background-color: #ffffff;
}
form label {
    margin-right: 10px;
}
form select, form input[type="text"], form input[type="date"] {
    margin-right: 20px;
    padding: 5px;
}
</style>

<script>
// Client-side validation
const validUnitIds = <?php echo json_encode($valid_unit_ids); ?>;

function validateForm() {
    const unitId = document.getElementById('unit_id').value;
    if (!unitId || !validUnitIds.includes(parseInt(unitId))) {
        alert('Please select a valid unit from the dropdown.');
        return false;
    }
    return true;
}

function toggleForm() {
    const form = document.getElementById('assignmentForm');
    form.style.display = form.style.display === 'none' || form.style.display === '' ? 'block' : 'none';
}

function filterLevels() {
    const programId = document.getElementById('program_id').value;
    const levelSelect = document.getElementById('level_id');
    const options = levelSelect.getElementsByTagName('option');
    for (let i = 0; i < options.length; i++) {
        const optionProgram = options[i].getAttribute('data-program');
        options[i].style.display = (programId === '' || optionProgram === programId) ? '' : 'none';
    }
    levelSelect.value = '';
    filterUnits();
}

function filterUnits() {
    const levelId = document.getElementById('level_id').value;
    const unitSelect = document.getElementById('unit_id');
    const options = unitSelect.getElementsByTagName('option');
    for (let i = 0; i < options.length; i++) {
        const optionLevel = options[i].getAttribute('data-level');
        options[i].style.display = (levelId === '' || optionLevel === levelId) ? '' : 'none';
    }
    unitSelect.value = '';
    filterStudents();
}

function filterStudents() {
    const unitId = document.getElementById('unit_id').value;
    const studentSelect = document.getElementById('student_id');
    if (unitId && !isNaN(unitId) && validUnitIds.includes(parseInt(unitId))) {
        fetch(`get_students.php?unit_id=${unitId}`, {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            studentSelect.innerHTML = '<option value="">Select Student</option>';
            data.forEach(student => {
                const option = document.createElement('option');
                const fullName = `${student.first_name} ${student.other_name || ''} ${student.surname}`.trim();
                option.value = student.id;
                option.text = `${student.reg_number} - ${fullName}`;
                if (<?php echo json_encode($form_data['student_id'] ?? ''); ?> == student.id) option.selected = true;
                studentSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error fetching students:', error);
            studentSelect.innerHTML = '<option value="">Select Student</option>';
        });
    } else {
        studentSelect.innerHTML = '<option value="">Select Student</option>';
    }
}

function filterTable() {
    const input = document.getElementById('filterInput').value.toLowerCase();
    const tables = document.querySelectorAll('.table-striped');
    tables.forEach(table => {
        const tr = table.getElementsByTagName('tr');
        let tableVisible = false;
        for (let i = 1; i < tr.length; i++) { // Skip header row
            const td = tr[i].getElementsByTagName('td');
            let found = false;
            for (let j = 0; j < td.length; j++) {
                if (td[j] && td[j].textContent.toLowerCase().indexOf(input) > -1) {
                    found = true;
                    break;
                }
            }
            tr[i].style.display = found ? '' : 'none';
            if (found) tableVisible = true;
        }
        const parent = table.closest('div');
        if (parent) parent.style.display = tableVisible ? '' : 'none';
    });
}

function handleAction(select, assignmentId) {
    const action = select.value;
    if (action) {
        if (action === 'edit') {
            window.location.href = `assignments.php?edit=${assignmentId}&date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>`;
        } else if (action === 'delete') {
            if (confirm('Are you sure you want to delete this assignment?')) {
                window.location.href = `assignments.php?delete=${assignmentId}&date=<?php echo urlencode($date_filter); ?>&unit_id=<?php echo urlencode($unit_filter); ?>&daily_per_page=<?php echo urlencode($daily_per_page); ?>`;
            }
        }
        select.value = '';
    }
}

window.onload = function() {
    filterLevels();
    filterUnits();
    filterStudents();
    <?php if ($edit_assignment): ?>
        document.getElementById('assignmentForm').style.display = 'block';
        document.getElementById('program_id').value = <?php echo json_encode($form_data['program_id'] ?? ''); ?>;
        filterLevels();
        document.getElementById('level_id').value = <?php echo json_encode($form_data['level_id'] ?? ''); ?>;
        filterUnits();
        document.getElementById('unit_id').value = <?php echo json_encode($form_data['unit_id'] ?? ''); ?>;
        filterStudents();
        document.getElementById('student_id').value = <?php echo json_encode($form_data['student_id'] ?? ''); ?>;
    <?php endif; ?>
};
</script>