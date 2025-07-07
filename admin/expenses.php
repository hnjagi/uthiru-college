<?php
require '../includes/db_connect.php';

// Include libraries for PDF and Excel export
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Clerk'])) {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';
$edit_expense = null;

// AJAX handler to fetch payees based on category and date
if (isset($_GET['action']) && $_GET['action'] === 'get_payees') {
    $category = $_GET['category'] ?? '';
    $expense_date = $_GET['expense_date'] ?? '';

    $payees = [];
    if ($category === 'Lecturer Allowance' && $expense_date) {
        // Fetch lecturers who taught on the selected date (removed deleted_at check)
        $stmt = $pdo->prepare("
            SELECT l.id, l.full_name 
            FROM lecturers l
            JOIN class_sessions cs ON l.id = cs.lecturer_id
            WHERE cs.session_date = ?
        ");
        $stmt->execute([$expense_date]);
        $payees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($category === 'Center Clerk') {
        // Fetch administrators with role 'Clerk'
        $stmt = $pdo->prepare("
            SELECT id, CONCAT(first_name, ' ', COALESCE(other_name, ''), ' ', surname) AS full_name 
            FROM administrators 
            WHERE role = 'Clerk' AND deleted_at IS NULL
        ");
        $stmt->execute();
        $payees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Return payees as JSON
    header('Content-Type: application/json');
    echo json_encode($payees);
    exit;
}

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? null;
    $expense_date = $_POST['expense_date'];
    $amount = floatval($_POST['amount']);
    $category = $_POST['category'];
    $description = $_POST['description'] ?: null;
    $payee = $_POST['payee'] ?: null;
    $payment_mode = $_POST['payment_mode'];
    $mpesa_code = ($payment_mode === 'MPESA') ? ($_POST['mpesa_code'] ?: null) : null;

    // Validate inputs
    if (empty($expense_date) || $amount <= 0 || empty($category) || empty($payment_mode)) {
        $error = "Expense date, amount, category, and payment mode are required.";
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_sessions WHERE session_date = ?");
        $stmt->execute([$expense_date]);
        if ($stmt->fetchColumn() == 0) {
            $error = "Expense date must correspond to a class day.";
        } else {
            try {
                if ($id) {
                    // Update existing expense
                    $stmt = $pdo->prepare("UPDATE expenses SET expense_date = ?, amount = ?, category = ?, description = ?, payee = ?, payment_mode = ?, mpesa_code = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    $stmt->execute([$expense_date, $amount, $category, $description, $payee, $payment_mode, $mpesa_code, $_SESSION['user_id'], $id]);
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Updated Expense', 'expenses', $id, "Expense Date: $expense_date, Amount: $amount"]);
                    $success = "Expense updated successfully.";
                } else {
                    // Add new expense
                    $stmt = $pdo->prepare("INSERT INTO expenses (expense_date, amount, category, description, payee, payment_mode, mpesa_code, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$expense_date, $amount, $category, $description, $payee, $payment_mode, $mpesa_code, $_SESSION['user_id']]);
                    $expense_id = $pdo->lastInsertId();
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Added Expense', 'expenses', $expense_id, "Expense Date: $expense_date, Amount: $amount"]);
                    $success = "Expense recorded successfully.";
                }
            } catch (PDOException $e) {
                $error = "Error recording expense: " . $e->getMessage();
            }
        }
    }
}

// Handle delete request (soft delete)
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $delete_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($delete_id !== false && $delete_id > 0) {
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            try {
                $stmt = $pdo->prepare("UPDATE expenses SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$_SESSION['user_id'], $delete_id]);
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, table_name, record_id, details, action_time) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], 'Soft Deleted Expense', 'expenses', $delete_id, "Expense ID: $delete_id"]);
                    $success = "Expense marked as deleted successfully.";
                } else {
                    $error = "Expense not found or already deleted.";
                }
            } catch (PDOException $e) {
                $error = "Error deleting expense: " . $e->getMessage();
            }
        } else {
            $stmt = $pdo->prepare("SELECT expense_date, amount FROM expenses WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$delete_id]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($expense) {
                echo "<div style='color: red; padding: 1em;'>Are you sure you want to delete the expense on {$expense['expense_date']} for KES " . number_format($expense['amount'], 2) . "?<br>";
                echo "<a href='expenses.php?delete=$delete_id&confirm=yes'>Yes</a> | <a href='expenses.php'>No</a></div>";
                exit;
            } else {
                $error = "Expense not found or already deleted.";
            }
        }
    } else {
        $error = "Invalid expense ID.";
    }
}

// Fetch all expenses (excluding soft-deleted ones), grouped by date
$stmt = $pdo->query("SELECT * FROM expenses WHERE deleted_at IS NULL ORDER BY expense_date DESC, id DESC");
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group expenses by date for display
$expenses_by_date = [];
foreach ($expenses as $expense) {
    $date = $expense['expense_date'];
    if (!isset($expenses_by_date[$date])) {
        $expenses_by_date[$date] = [];
    }
    $expenses_by_date[$date][] = $expense;
}

// Fetch class session dates for the dropdown
$stmt = $pdo->query("SELECT DISTINCT session_date FROM class_sessions ORDER BY session_date DESC");
$session_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle edit request
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_GET['edit']]);
    $edit_expense = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/tcpdf/tcpdf.php';
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('IGAC Uthiru Center');
    $pdf->SetTitle('Expenses Report');
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();

    $html = "<h1>Expenses Report</h1>";

    foreach ($expenses_by_date as $date => $expense_list) {
        $html .= "<h2>" . htmlspecialchars($date) . "</h2>";
        $html .= "<table border='1'>";
        $html .= "<tr><th>Amount (KES)</th><th>Category</th><th>Description</th><th>Payee</th><th>Payment Mode</th><th>MPESA Code</th></tr>";
        foreach ($expense_list as $expense) {
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars(number_format($expense['amount'], 2)) . "</td>";
            $html .= "<td>" . htmlspecialchars($expense['category']) . "</td>";
            $html .= "<td>" . htmlspecialchars($expense['description'] ?: 'N/A') . "</td>";
            $html .= "<td>" . htmlspecialchars($expense['payee'] ?: 'N/A') . "</td>";
            $html .= "<td>" . htmlspecialchars($expense['payment_mode']) . "</td>";
            $html .= "<td>" . htmlspecialchars($expense['mpesa_code'] ?: 'N/A') . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table><br>";
    }

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('expenses_report.pdf', 'I');
    exit;
}

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $row = 1;

    $sheet->setCellValue('A' . $row, 'Expenses Report');
    $row += 2;

    foreach ($expenses_by_date as $date => $expense_list) {
        $sheet->setCellValue('A' . $row, $date);
        $row++;
        $sheet->setCellValue('A' . $row, 'Amount (KES)');
        $sheet->setCellValue('B' . $row, 'Category');
        $sheet->setCellValue('C' . $row, 'Description');
        $sheet->setCellValue('D' . $row, 'Payee');
        $sheet->setCellValue('E' . $row, 'Payment Mode');
        $sheet->setCellValue('F' . $row, 'MPESA Code');
        $row++;
        foreach ($expense_list as $expense) {
            $sheet->setCellValue('A' . $row, number_format($expense['amount'], 2));
            $sheet->setCellValue('B' . $row, $expense['category']);
            $sheet->setCellValue('C' . $row, $expense['description'] ?: 'N/A');
            $sheet->setCellValue('D' . $row, $expense['payee'] ?: 'N/A');
            $sheet->setCellValue('E' . $row, $expense['payment_mode']);
            $sheet->setCellValue('F' . $row, $expense['mpesa_code'] ?: 'N/A');
            $row++;
        }
        $row++;
    }

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="expenses_report.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}

$display_data = !empty($error) ? $_POST : ($edit_expense ?: []);
?>

<?php include '../includes/header.php'; ?>
<h2>Expense Management</h2>

<?php if (!empty($error)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
<?php endif; ?>

<button onclick="toggleForm()"><?php echo $edit_expense ? 'Edit Expense' : 'Add New Expense'; ?></button>

<div id="expenseForm" style="display: <?php echo $edit_expense ? 'block' : 'none'; ?>;">
    <h3><?php echo $edit_expense ? 'Edit Expense' : 'Add New Expense'; ?></h3>
    <form method="POST">
        <?php if ($edit_expense): ?>
            <input type="hidden" name="id" value="<?php echo $edit_expense['id']; ?>">
        <?php endif; ?>
        <label>Expense Date:</label>
        <select name="expense_date" id="expense_date" required onchange="updatePayees()">
            <option value="">Select Date</option>
            <?php foreach ($session_dates as $date): ?>
                <option value="<?php echo $date; ?>" <?php echo ($display_data['expense_date'] ?? '') == $date ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($date); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Amount (KES):</label>
        <input type="number" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($display_data['amount'] ?? ''); ?>" required>
        <label>Category:</label>
        <select name="category" id="category" required onchange="updatePayees()">
            <option value="">Select Category</option>
            <option value="Meals" <?php echo ($display_data['category'] ?? '') == 'Meals' ? 'selected' : ''; ?>>Meals</option>
            <option value="Stationery" <?php echo ($display_data['category'] ?? '') == 'Stationery' ? 'selected' : ''; ?>>Stationery</option>
            <option value="Lecturer Allowance" <?php echo ($display_data['category'] ?? '') == 'Lecturer Allowance' ? 'selected' : ''; ?>>Lecturer Allowance</option>
            <option value="Fuel" <?php echo ($display_data['category'] ?? '') == 'Fuel' ? 'selected' : ''; ?>>Fuel</option>
            <option value="Center Clerk" <?php echo ($display_data['category'] ?? '') == 'Center Clerk' ? 'selected' : ''; ?>>Center Clerk</option>
            <option value="Airtime" <?php echo ($display_data['category'] ?? '') == 'Airtime' ? 'selected' : ''; ?>>Airtime</option>
            <option value="Other" <?php echo ($display_data['category'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
        </select>
        <label>Description:</label>
        <textarea name="description"><?php echo htmlspecialchars($display_data['description'] ?? ''); ?></textarea>
        <label>Payee:</label>
        <select name="payee" id="payee_select">
            <option value="">Select Payee (if applicable)</option>
            <?php if ($edit_expense && $edit_expense['payee']): ?>
                <option value="<?php echo htmlspecialchars($edit_expense['payee']); ?>" selected><?php echo htmlspecialchars($edit_expense['payee']); ?></option>
            <?php endif; ?>
        </select>
        <label>Payment Mode:</label>
        <select name="payment_mode" id="payment_mode" required onchange="toggleMpesaField()">
            <option value="Cash" <?php echo ($display_data['payment_mode'] ?? '') == 'Cash' ? 'selected' : ''; ?>>Cash</option>
            <option value="MPESA" <?php echo ($display_data['payment_mode'] ?? '') == 'MPESA' ? 'selected' : ''; ?>>MPESA</option>
        </select>
        <div id="mpesa_field" style="display: <?php echo ($display_data['payment_mode'] ?? '') == 'MPESA' ? 'block' : 'none'; ?>;">
            <label>MPESA Code:</label>
            <input type="text" name="mpesa_code" value="<?php echo htmlspecialchars($display_data['mpesa_code'] ?? ''); ?>">
        </div>
        <button type="submit"><?php echo $edit_expense ? 'Update' : 'Add'; ?> Expense</button>
        <?php if ($edit_expense): ?>
            <a href="expenses.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<h3>Recorded Expenses</h3>
<div style="margin-bottom: 10px;">
    <a href="expenses.php?export=pdf"><button>Export to PDF</button></a>
    <a href="expenses.php?export=excel"><button>Export to Excel</button></a>
</div>

<?php if (empty($expenses_by_date)): ?>
    <p>No expenses recorded yet.</p>
<?php else: ?>
    <?php foreach ($expenses_by_date as $date => $expense_list): ?>
        <h4><?php echo htmlspecialchars($date); ?></h4>
        <table class="standard-table">
            <thead>
                <tr>
                    <th>Amount (KES)</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Payee</th>
                    <th>Payment Mode</th>
                    <th>MPESA Code</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expense_list as $expense): ?>
                <tr>
                    <td><?php echo htmlspecialchars(number_format($expense['amount'], 2)); ?></td>
                    <td><?php echo htmlspecialchars($expense['category']); ?></td>
                    <td><?php echo htmlspecialchars($expense['description'] ?: 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($expense['payee'] ?: 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($expense['payment_mode']); ?></td>
                    <td><?php echo htmlspecialchars($expense['mpesa_code'] ?: 'N/A'); ?></td>
                    <td>
                        <select class="action-dropdown" onchange="handleAction(this, <?php echo $expense['id']; ?>)">
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
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<script>
function toggleForm() {
    const form = document.getElementById('expenseForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function toggleMpesaField() {
    const paymentMode = document.getElementById('payment_mode').value;
    const mpesaField = document.getElementById('mpesa_field');
    mpesaField.style.display = paymentMode === 'MPESA' ? 'block' : 'none';
}

function updatePayees() {
    const category = document.getElementById('category').value;
    const expenseDate = document.getElementById('expense_date').value;
    const payeeSelect = document.getElementById('payee_select');

    if (!category || !expenseDate) {
        payeeSelect.innerHTML = '<option value="">Select Payee (if applicable)</option>';
        return;
    }

    fetch(`expenses.php?action=get_payees&category=${encodeURIComponent(category)}&expense_date=${encodeURIComponent(expenseDate)}`)
        .then(response => response.json())
        .then(data => {
            payeeSelect.innerHTML = '<option value="">Select Payee (if applicable)</option>';
            data.forEach(payee => {
                const option = document.createElement('option');
                option.value = payee.full_name;
                option.textContent = payee.full_name;
                payeeSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error fetching payees:', error);
            payeeSelect.innerHTML = '<option value="">Error loading payees</option>';
        });
}

function handleAction(select, expenseId) {
    const action = select.value;
    if (action) {
        if (action === 'edit') {
            window.location.href = `expenses.php?edit=${expenseId}`;
        } else if (action === 'delete') {
            if (confirm('Are you sure you want to delete this expense?')) {
                window.location.href = `expenses.php?delete=${expenseId}`;
            }
        }
        select.value = '';
    }
}

window.onload = function() {
    updatePayees();
};
</script>

<style>
.action-dropdown {
    padding: 5px;
    background-color: #2980b9;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
}
.action-dropdown:hover {
    background-color: #3498db;
}
.standard-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
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