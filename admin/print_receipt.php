<?php
require '../includes/db_connect.php';
require '../vendor/autoload.php'; // Include the ESC/POS library

use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

// Check if payment_id is provided
if (!isset($_GET['payment_id']) || !is_numeric($_GET['payment_id'])) {
    die("Invalid payment ID.");
}

$payment_id = (int)$_GET['payment_id'];

// Fetch payment details
$stmt = $pdo->prepare("
    SELECT p.id, p.student_id, p.amount_paid, p.payment_mode, p.payment_purpose, p.is_prepayment, p.from_balance, p.payment_date,
           s.reg_number, CONCAT(s.first_name, ' ', COALESCE(s.other_name, ''), ' ', s.surname) AS student_name,
           r.receipt_number, r.balance_bf, r.balance_cf, r.issued_at
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN receipts r ON p.id = r.payment_id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die("Payment not found.");
}

// Generate the receipt content using ESC/POS
try {
    // Use a file connector to generate the receipt as a file
    $connector = new FilePrintConnector("php://output");
    $printer = new Printer($connector);

    // Initialize printer
    $printer->initialize();

    // Center-align text
    $printer->setJustification(Printer::JUSTIFY_CENTER);

    // Print header
    $printer->text("IGAC Uthiru Center\n");
    $printer->text("--------------------------------\n");
    $printer->text("PAYMENT RECEIPT\n");
    $printer->text("--------------------------------\n");
    $printer->setJustification(Printer::JUSTIFY_LEFT);

    // Print receipt details
    $printer->text("Receipt No: " . $payment['receipt_number'] . "\n");
    $printer->text("Date: " . date('Y-m-d H:i', strtotime($payment['issued_at'])) . "\n");
    $printer->text("--------------------------------\n");
    $printer->text("Student: " . $payment['student_name'] . "\n");
    $printer->text("Reg No: " . $payment['reg_number'] . "\n");
    $printer->text("Amount Paid: KES " . number_format($payment['amount_paid'], 2) . "\n");
    $printer->text("Purpose: " . ($payment['payment_purpose'] ?: 'N/A') . "\n");
    $printer->text("Mode: " . ($payment['from_balance'] ? 'Redeemed from Prepayment' : ($payment['is_prepayment'] ? 'Prepayment' : $payment['payment_mode'])) . "\n");
    $printer->text("--------------------------------\n");
    $printer->text("Balance B/F: KES " . number_format($payment['balance_bf'], 2) . "\n");
    $printer->text("Balance C/F: KES " . number_format($payment['balance_cf'], 2) . "\n");
    $printer->text("--------------------------------\n");

    // Footer
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("Thank you!\n");
    $printer->text("--------------------------------\n");

    // Cut the paper
    $printer->cut();

    // Close the printer
    $printer->close();

    // Set headers to download the file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="receipt_' . $payment['receipt_number'] . '.bin"');
    header('Cache-Control: no-cache');
    exit;

} catch (Exception $e) {
    die("Error generating receipt: " . $e->getMessage());
}
?>