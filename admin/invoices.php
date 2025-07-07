<?php
require '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

$invoice_id = $_GET['invoice_id'] ?? null;

if (!$invoice_id) {
    die("No invoice ID provided.");
}

// Fetch invoice details
$stmt = $pdo->prepare("
    SELECT i.id AS invoice_id, i.amount_due, i.invoice_date,
           s.reg_number, s.first_name, s.surname, s.phone_number,
           cs.session_date, u.name AS unit_name, l.name AS level_name
    FROM invoices i
    JOIN students s ON i.student_id = s.id
    JOIN class_sessions cs ON i.session_id = cs.id
    JOIN units u ON cs.unit_id = u.id
    JOIN levels l ON cs.level_id = l.id
    WHERE i.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Invoice not found.");
}

// Fetch the name of the logged-in admin/clerk
$stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', COALESCE(other_name, ''), ' ', surname) AS served_by FROM administrators WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$served_by = $stmt->fetchColumn() ?: 'Unknown Staff';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo $invoice['invoice_id']; ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            width: 80mm; /* Suitable for thermal printers */
            margin: 0;
            padding: 5mm;
            font-size: 12px;
            line-height: 1.2;
        }
        .header {
            text-align: center;
            margin-bottom: 10px;
        }
        .details, .footer {
            margin-top: 10px;
        }
        .details p, .footer p {
            margin: 2px 0;
        }
        .line {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <strong>IGAC Uthiru Center for NPBC</strong><br>
        Invoice #<?php echo htmlspecialchars($invoice['invoice_id']); ?>
    </div>
    <div class="details">
        <p><strong>Student:</strong> <?php echo htmlspecialchars($invoice['reg_number'] . ' - ' . $invoice['first_name'] . ' ' . $invoice['surname']); ?></p>
        <p><strong>Phone:</strong> <?php echo htmlspecialchars($invoice['phone_number']); ?></p>
        <p><strong>Level:</strong> <?php echo htmlspecialchars($invoice['level_name']); ?></p>
        <p><strong>Unit:</strong> <?php echo htmlspecialchars($invoice['unit_name']); ?></p>
        <p><strong>Session Date:</strong> <?php echo htmlspecialchars($invoice['session_date']); ?></p>
        <p><strong>Invoice Date:</strong> <?php echo htmlspecialchars($invoice['invoice_date']); ?></p>
        <div class="line"></div>
        <p><strong>Amount Due:</strong> KES <?php echo htmlspecialchars(number_format($invoice['amount_due'], 2)); ?></p>
    </div>
    <div class="footer">
        <p>Kindly make prompt payment of this balance.</p>
        <p>You were served by: <?php echo htmlspecialchars($served_by); ?></p>
        <p>Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>
</body>
</html>