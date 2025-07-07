<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$student_id = $_GET['student_id'] ?? '';
$sessions = [];

if ($student_id) {
    $stmt = $pdo->prepare("
        SELECT cs.id, cs.session_date, u.name AS unit_name, 
               (i.amount_due - COALESCE(SUM(p.amount_paid), 0)) AS unpaid_amount
        FROM class_sessions cs
        JOIN units u ON cs.unit_id = u.id
        JOIN invoices i ON i.session_id = cs.id
        LEFT JOIN payments p ON p.invoice_id = i.id
        WHERE i.student_id = ?
        GROUP BY cs.id, i.id
        HAVING unpaid_amount > 0
        ORDER BY cs.session_date DESC
    ");
    $stmt->execute([$student_id]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

header('Content-Type: application/json');
echo json_encode($sessions);
?>