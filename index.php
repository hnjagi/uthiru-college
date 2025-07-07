<?php
session_start();

// Check if user is logged in and has the correct role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// Log the session details for debugging
error_log("User accessing index.php: user_id=" . ($_SESSION['user_id'] ?? 'not set') . ", role=" . ($_SESSION['role'] ?? 'not set'));

// Restrict access to Admin, Clerk, and Coordinator roles
$allowed_roles = ['Admin', 'Clerk', 'Coordinator'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    if ($_SESSION['role'] === 'Student') {
        header("Location: student/statement.php");
        exit;
    } else {
        // For any other role, redirect to login or a safe page
        header("Location: login.php");
        exit;
    }
}

require 'includes/db_connect.php';

// Fetch stats for all roles
$student_count = $pdo->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL")->fetchColumn();
$session_count = $pdo->query("SELECT COUNT(*) FROM class_sessions WHERE academic_year = YEAR(CURDATE())")->fetchColumn();
$payment_total = $pdo->query("SELECT SUM(amount_paid) FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())")->fetchColumn();

// Admin-only stats
$lecturer_count = 0;
if (in_array($_SESSION['role'], ['Admin'])) {
    $lecturer_count = $pdo->query("SELECT COUNT(*) FROM lecturers")->fetchColumn();
}

// Fetch recent payments (last 5)
$stmt = $pdo->query("
    SELECT p.amount_paid, p.payment_date, s.reg_number, s.first_name, s.surname
    FROM payments p
    JOIN students s ON p.student_id = s.id
    ORDER BY p.payment_date DESC
    LIMIT 5
");
$recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch upcoming classes (next 7 days)
$stmt = $pdo->prepare("
    SELECT cs.session_date, cs.academic_year, u.name AS unit_name, l.full_name AS lecturer_name
    FROM class_sessions cs
    JOIN units u ON cs.unit_id = u.id
    JOIN lecturers l ON cs.lecturer_id = l.id
    WHERE cs.session_date >= CURDATE() AND cs.session_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY cs.session_date ASC
    LIMIT 5
");
$stmt->execute();
$upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'includes/header.php'; ?>
<main>
    <h2>Dashboard</h2>

    <div class="dashboard-stats">
        <div class="stat-box">
            <h3>Total Students</h3>
            <p><?php echo $student_count; ?></p>
        </div>
        <?php if (in_array($_SESSION['role'], ['Admin'])): ?>
            <div class="stat-box">
                <h3>Total Lecturers</h3>
                <p><?php echo $lecturer_count; ?></p>
            </div>
        <?php endif; ?>
        <div class="stat-box">
            <h3>Class Sessions This Year</h3>
            <p><?php echo $session_count; ?></p>
        </div>
        <div class="stat-box">
            <h3>Total Payments This Year</h3>
            <p>KES <?php echo number_format($payment_total ?: 0, 2); ?></p>
        </div>
    </div>

    <h3>Recent Payments</h3>
    <?php if (empty($recent_payments)): ?>
        <p>No recent payments.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Student</th>
                <th>Reg Number</th>
                <th>Amount</th>
                <th>Payment Date</th>
            </tr>
            <?php foreach ($recent_payments as $payment): ?>
                <tr>
                    <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['surname']); ?></td>
                    <td><?php echo htmlspecialchars($payment['reg_number']); ?></td>
                    <td>KES <?php echo number_format($payment['amount_paid'], 2); ?></td>
                    <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h3>Upcoming Classes (Next 7 Days)</h3>
    <?php if (empty($upcoming_classes)): ?>
        <p>No upcoming classes in the next 7 days.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Session Date</th>
                <th>Unit</th>
                <th>Lecturer</th>
                <th>Academic Year</th>
            </tr>
            <?php foreach ($upcoming_classes as $class): ?>
                <tr>
                    <td><?php echo htmlspecialchars($class['session_date']); ?></td>
                    <td><?php echo htmlspecialchars($class['unit_name']); ?></td>
                    <td><?php echo htmlspecialchars($class['lecturer_name']); ?></td>
                    <td><?php echo htmlspecialchars($class['academic_year']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>

<style>
.dashboard-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
}
.stat-box {
    background-color: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    flex: 1;
    text-align: center;
}
.stat-box h3 {
    margin: 0 0 10px;
    font-size: 18px;
}
.stat-box p {
    font-size: 24px;
    margin: 0;
    color: #2980b9;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
table, th, td {
    border: 1px solid #ddd;
}
th, td {
    padding: 10px;
    text-align: left;
}
</style>