<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_URL', '/'); // Adjust if deployed to a subdirectory

$host = 'localhost';
$dbname = 'igacorke_npbc';
$db_user = 'igacorke_npbc'; 
$db_pass = 'Uthiru@2025';   

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>