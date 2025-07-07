<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk', 'Coordinator'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$error = '';
$report_type = $_GET['report_type'] ?? 'class_session';
$session_id = $_GET['session_id'] ?? '';
$level_id = $_GET['level_id'] ?? '';
$unit_id = $_GET['unit_id'] ?? '';
$class_day = $_GET['class_day'] ?? '';
$report_data = [];

// Fetch levels for dropdown
$levels = $pdo->query("SELECT l.*, p.name AS program_name FROM levels l JOIN programs p ON l.program_id = p.id ORDER BY p.name, l.sequence")->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct class dates
$class_dates = $pdo->query("SELECT DISTINCT session_date FROM class_sessions ORDER BY session_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch units for selected level (only units that have had sessions)
$units = [];
if ($level_id) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.*
        FROM units u
        JOIN class_sessions cs ON u.id = cs.unit_id
        WHERE u.level_id = ?
        ORDER BY u.name
    ");
    $stmt->execute([$level_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch sessions for selected unit
$sessions = [];
if ($unit_id) {
    $stmt = $pdo->prepare("SELECT cs.* FROM class_sessions cs WHERE cs.unit_id = ? ORDER BY cs.session_date DESC");
    $stmt->execute([$unit_id]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Generate report based on type
if ($report_type === 'class_session' && $session_id) {
    $stmt = $pdo->prepare("
        SELECT cs.session_date, prog.name AS program_name, l.name AS level_name, u.name AS unit_name, 
               lec.title, lec.full_name AS lecturer_name,
               s.reg_number, s.first_name, s.other_name, s.surname, s.phone_number,
               COALESCE(SUM(pay.amount_paid), 0) AS fees_paid
        FROM class_sessions cs
        JOIN programs prog ON cs.program_id = prog.id
        JOIN levels l ON cs.level_id = l.id
        JOIN units u ON cs.unit_id = u.id
        JOIN lecturers lec ON cs.lecturer_id = lec.id
        LEFT JOIN attendance a ON cs.id = a.session_id
        LEFT JOIN students s ON a.student_id = s.id AND s.deleted_at IS NULL
        LEFT JOIN payments pay ON s.id = pay.student_id AND DATE(pay.payment_date) = cs.session_date AND pay.is_prepayment = 0
        WHERE cs.id = ?
        GROUP BY s.id
        ORDER BY s.first_name, s.other_name, s.surname
    ");
    $stmt->execute([$session_id]);
    $report_data['class_session'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($report_data['class_session'])) {
        $error = "No data found for the selected session.";
    } else {
        $session_info = $report_data['class_session'][0];
        $session_info['student_count'] = count(array_filter($report_data['class_session'], fn($row) => !empty($row['reg_number'])));
    }
} elseif ($report_type === 'assignments_submitted' && $level_id && $class_day) {
    $stmt = $pdo->prepare("
        SELECT cs.session_date, cs.id AS session_id, l.name AS level_name, u.id AS unit_id, u.name AS unit_name, 
               lec.title, lec.full_name AS lecturer_name
        FROM class_sessions cs
        JOIN levels l ON cs.level_id = l.id
        JOIN units u ON cs.unit_id = u.id
        JOIN lecturers lec ON cs.lecturer_id = lec.id
        WHERE cs.level_id = ? AND cs.session_date = ?
        ORDER BY u.name
    ");
    $stmt->execute([$level_id, $class_day]);
    $units_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report_data['assignments_submitted'] = [];
    foreach ($units_data as $unit) {
        $stmt = $pdo->prepare("
            SELECT s.reg_number, s.first_name, s.other_name, s.surname, s.phone_number
            FROM assignments a
            JOIN students s ON a.student_id = s.id AND s.deleted_at IS NULL
            JOIN units u ON a.unit_id = u.id
            WHERE a.unit_id = ? AND a.submission_date = ? AND a.deleted_at IS NULL
            ORDER BY s.first_name, s.other_name, s.surname
        ");
        $stmt->execute([$unit['unit_id'], $class_day]);
        $unit['students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_data['assignments_submitted'][] = $unit;
    }
}

// Export to PDF
if (isset($_GET['export']) && $_GET['export'] === 'pdf' && !empty($report_data)) {
    require_once '../vendor/tcpdf/tcpdf.php';
    $pdf = new TCPDF();
    $pdf->SetMargins(10, 10, 10);

    if ($report_type === 'class_session' && $session_id) {
        $pdf->AddPage();
        $html = "<h1>Class Session Register</h1>";
        $html .= "<p><strong>Date:</strong> " . htmlspecialchars($session_info['session_date']) . "</p>";
        $html .= "<p><strong>Program:</strong> " . htmlspecialchars($session_info['program_name'] ?? 'N/A') . "</p>";
        $html .= "<p><strong>Level:</strong> " . htmlspecialchars($session_info['level_name']) . "</p>";
        $html .= "<p><strong>Unit:</strong> " . htmlspecialchars($session_info['unit_name']) . "</p>";
        $html .= "<p><strong>Lecturer:</strong> " . htmlspecialchars($session_info['title'] . ' ' . $session_info['lecturer_name']) . "</p>";
        $html .= "<p><strong>No of Students Present:</strong> " . htmlspecialchars($session_info['student_count']) . "</p>";
        $html .= "<h2>Attendees</h2>";
        $html .= "<table border='1'><tr><th>Reg Number</th><th>Name</th><th>Phone Number</th><th>Class Fees Paid</th></tr>";
        foreach ($report_data['class_session'] as $row) {
            if (empty($row['reg_number'])) continue;
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($row['reg_number']) . "</td>";
            $html .= "<td>" . htmlspecialchars($row['first_name'] . ' ' . ($row['other_name'] ?? '') . ' ' . $row['surname']) . "</td>";
            $html .= "<td>" . htmlspecialchars($row['phone_number']) . "</td>";
            $html .= "<td>" . number_format($row['fees_paid'], 2) . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
        $pdf->writeHTML($html);
    } elseif ($report_type === 'assignments_submitted') {
        foreach ($report_data['assignments_submitted'] as $unit) {
            $pdf->AddPage();
            $html = "<h1>Assignments Submitted</h1>";
            $html .= "<p><strong>Date:</strong> " . htmlspecialchars($unit['session_date']) . "</p>";
            $html .= "<p><strong>Level:</strong> " . htmlspecialchars($unit['level_name']) . "</p>";
            $html .= "<p><strong>Unit:</strong> " . htmlspecialchars($unit['unit_name']) . "</p>";
            $html .= "<p><strong>Lecturer:</strong> " . htmlspecialchars($unit['title'] . ' ' . $unit['lecturer_name']) . "</p>";
            $html .= "<h2>Students</h2>";
            $html .= "<table border='1'><tr><th>Reg Number</th><th>Name</th><th>Phone Number</th></tr>";
            foreach ($unit['students'] as $student) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($student['reg_number']) . "</td>";
                $html .= "<td>" . htmlspecialchars($student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']) . "</td>";
                $html .= "<td>" . htmlspecialchars($student['phone_number']) . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
            $pdf->writeHTML($html);
        }
    }

    $pdf->Output("report_$report_type.pdf", 'I');
    exit;
}

// Export to Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel' && !empty($report_data)) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    if ($report_type === 'class_session' && $session_id) {
        $row = 1;
        $sheet->setCellValue('A' . $row, 'Class Session Register'); $row++;
        $sheet->setCellValue('A' . $row, 'Date: ' . $session_info['session_date']); $row++;
        $sheet->setCellValue('A' . $row, 'Program: ' . ($session_info['program_name'] ?? 'N/A')); $row++;
        $sheet->setCellValue('A' . $row, 'Level: ' . $session_info['level_name']); $row++;
        $sheet->setCellValue('A' . $row, 'Unit: ' . $session_info['unit_name']); $row++;
        $sheet->setCellValue('A' . $row, 'Lecturer: ' . $session_info['title'] . ' ' . $session_info['lecturer_name']); $row++;
        $sheet->setCellValue('A' . $row, 'No of Students Present: ' . $session_info['student_count']); $row++;
        $row++;
        $sheet->setCellValue('A' . $row, 'Reg Number');
        $sheet->setCellValue('B' . $row, 'Name');
        $sheet->setCellValue('C' . $row, 'Phone Number');
        $sheet->setCellValue('D' . $row, 'Class Fees Paid'); $row++;
        foreach ($report_data['class_session'] as $data) {
            if (empty($data['reg_number'])) continue;
            $sheet->setCellValue('A' . $row, $data['reg_number']);
            $sheet->setCellValue('B' . $row, $data['first_name'] . ' ' . ($data['other_name'] ?? '') . ' ' . $data['surname']);
            $sheet->setCellValue('C' . $row, $data['phone_number']);
            $sheet->setCellValue('D' . $row, $data['fees_paid']);
            $row++;
        }
    }

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="report_' . $report_type . '.xlsx"');
    $writer->save('php://output');
    exit;
}
?>

<?php include '../includes/header.php'; ?>
<h2>Student Reports</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="GET">
    <label>Report Type:</label>
    <select name="report_type" onchange="this.form.submit()">
        <option value="class_session" <?php echo $report_type === 'class_session' ? 'selected' : ''; ?>>Class Session Register</option>
        <option value="assignments_submitted" <?php echo $report_type === 'assignments_submitted' ? 'selected' : ''; ?>>Assignments Submitted</option>
    </select>

    <?php if (in_array($report_type, ['class_session'])): ?>
        <label>Level:</label>
        <select name="level_id" onchange="this.form.submit()">
            <option value="">Select Level</option>
            <?php foreach ($levels as $level): ?>
                <option value="<?php echo $level['id']; ?>" <?php echo ($level_id == $level['id'] ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($level_id): ?>
            <label>Unit:</label>
            <select name="unit_id" onchange="this.form.submit()">
                <option value="">Select Unit</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?php echo $unit['id']; ?>" <?php echo ($unit_id == $unit['id'] ? 'selected' : ''); ?>>
                        <?php echo htmlspecialchars($unit['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ($unit_id): ?>
            <label>Session:</label>
            <select name="session_id" onchange="this.form.submit()">
                <option value="">Select Session</option>
                <?php foreach ($sessions as $session): ?>
                    <option value="<?php echo $session['id']; ?>" <?php echo ($session_id == $session['id'] ? 'selected' : ''); ?>>
                        <?php echo htmlspecialchars($session['session_date']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($report_type, ['assignments_submitted'])): ?>
        <label>Level:</label>
        <select name="level_id" onchange="this.form.submit()">
            <option value="">Select Level</option>
            <?php foreach ($levels as $level): ?>
                <option value="<?php echo $level['id']; ?>" <?php echo ($level_id == $level['id'] ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars($level['program_name'] . ' - ' . $level['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($level_id): ?>
            <label>Class Day:</label>
            <select name="class_day" onchange="this.form.submit()">
                <option value="">Select Date</option>
                <?php foreach ($class_dates as $date): ?>
                    <option value="<?php echo $date; ?>" <?php echo ($class_day == $date ? 'selected' : ''); ?>>
                        <?php echo htmlspecialchars($date); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($report_data)): ?>
        <a href="?report_type=<?php echo $report_type; ?>&session_id=<?php echo $session_id; ?>&level_id=<?php echo $level_id; ?>&unit_id=<?php echo $unit_id; ?>&class_day=<?php echo $class_day; ?>&export=pdf"><button type="button">Export to PDF</button></a>
        <a href="?report_type=<?php echo $report_type; ?>&session_id=<?php echo $session_id; ?>&level_id=<?php echo $level_id; ?>&unit_id=<?php echo $unit_id; ?>&class_day=<?php echo $class_day; ?>&export=excel"><button type="button">Export to Excel</button></a>
    <?php endif; ?>
</form>

<?php if ($report_type === 'class_session' && !empty($report_data['class_session'])): ?>
    <h3>Class Session Register</h3>
    <p><strong>Date:</strong> <?php echo htmlspecialchars($session_info['session_date']); ?></p>
    <p><strong>Program:</strong> <?php echo htmlspecialchars($session_info['program_name'] ?? 'N/A'); ?></p>
    <p><strong>Level:</strong> <?php echo htmlspecialchars($session_info['level_name']); ?></p>
    <p><strong>Unit:</strong> <?php echo htmlspecialchars($session_info['unit_name']); ?></p>
    <p><strong>Lecturer:</strong> <?php echo htmlspecialchars($session_info['title'] . ' ' . $session_info['lecturer_name']); ?></p>
    <p><strong>No of Students Present:</strong> <?php echo htmlspecialchars($session_info['student_count']); ?></p>
    <table class="standard-table">
        <thead>
            <tr>
                <th>Reg Number</th>
                <th>Name</th>
                <th>Phone Number</th>
                <th>Class Fees Paid</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report_data['class_session'] as $row): ?>
                <?php if (empty($row['reg_number'])) continue; ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['reg_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . ($row['other_name'] ?? '') . ' ' . $row['surname']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone_number']); ?></td>
                    <td><?php echo number_format($row['fees_paid'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php elseif ($report_type === 'assignments_submitted' && !empty($report_data['assignments_submitted'])): ?>
    <?php foreach ($report_data['assignments_submitted'] as $unit): ?>
        <h3>Assignments Submitted - <?php echo htmlspecialchars($unit['unit_name']); ?></h3>
        <p><strong>Date:</strong> <?php echo htmlspecialchars($unit['session_date']); ?></p>
        <p><strong>Level:</strong> <?php echo htmlspecialchars($unit['level_name']); ?></p>
        <p><strong>Unit:</strong> <?php echo htmlspecialchars($unit['unit_name']); ?></p>
        <p><strong>Lecturer:</strong> <?php echo htmlspecialchars($unit['title'] . ' ' . $unit['lecturer_name']); ?></p>
        <table class="standard-table">
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Name</th>
                    <th>Phone Number</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unit['students'] as $student): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($student['reg_number']); ?></td>
                        <td><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['other_name'] ?? '') . ' ' . $student['surname']); ?></td>
                        <td><?php echo htmlspecialchars($student['phone_number']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="page-break-after: always;"></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<style>
.standard-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
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