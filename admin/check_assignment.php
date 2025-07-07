<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$student_id = $_GET['student_id'] ?? '';
$unit_id = $_GET['unit_id'] ?? '';

if (empty($student_id) || empty($unit_id)) {
    echo json_encode(['has_assignment' => false]);
    exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE student_id = ? AND unit_id = ? AND deleted_at IS NULL");
$stmt->execute([$student_id, $unit_id]);
$has_assignment = $stmt->fetchColumn() > 0;

header('Content-Type: application/json');
echo json_encode(['has_assignment' => $has_assignment]);
?>