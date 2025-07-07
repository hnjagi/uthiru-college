<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$unit_id = $_GET['unit_id'] ?? null;
$level_id = $_GET['level_id'] ?? null;
$current_year = date('Y');
$students = [];

if ($unit_id) {
    // Original logic: Fetch students who have attended or caught up on a specific unit
    $stmt = $pdo->prepare("
        SELECT s.id, s.reg_number, s.first_name, s.other_name, s.surname
        FROM students s
        JOIN enrollments e ON s.id = e.student_id
        WHERE s.deleted_at IS NULL AND (
            EXISTS (
                SELECT 1 FROM attendance a
                JOIN class_sessions cs ON a.session_id = cs.id
                WHERE a.student_id = s.id AND cs.unit_id = ? AND cs.academic_year = ?
            ) OR EXISTS (
                SELECT 1 FROM catchups c
                WHERE c.student_id = s.id AND c.unit_id = ? AND YEAR(c.catchup_date) = ?
            )
        )
        ORDER BY s.reg_number
    ");
    $stmt->execute([$unit_id, $current_year, $unit_id, $current_year]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($level_id) {
    // New logic: Fetch students enrolled in a specific level
    $stmt = $pdo->prepare("
        SELECT s.id, s.reg_number, s.first_name, s.other_name, s.surname
        FROM students s
        JOIN enrollments e ON s.id = e.student_id
        WHERE e.level_id = ? AND e.academic_year = ? AND s.deleted_at IS NULL
        ORDER BY s.reg_number
    ");
    $stmt->execute([$level_id, $current_year]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

header('Content-Type: application/json');
echo json_encode($students);
?>