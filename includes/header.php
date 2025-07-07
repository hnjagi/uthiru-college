<?php
// No need for session_start() or BASE_URL definition; handled in db_connect.php

require_once 'db_connect.php'; // Include db_connect.php to define BASE_URL and session

if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IGAC Uthiru Center - College Management System</title>
    <!-- Bootstrap CSS (needed for dropdown functionality) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom Styles -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>styles.css">
    <style>
        .navbar {
            background-color: #2980b9;
            padding: 15px;
        }
        .navbar-brand, .nav-link {
            color: white !important;
        }
        .nav-link:hover {
            color: #ddd !important;
        }
        .dropdown-menu {
            background-color: #2980b9;
        }
        .dropdown-item {
            color: white !important;
        }
        .dropdown-item:hover {
            background-color: #3498db;
            color: #ddd !important;
        }
        .container {
            padding: 20px;
        }
        h2 {
            color: #333;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>index.php">IGAC Uthiru Center</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <!-- Students Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="studentsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Students
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="studentsDropdown">
                            <?php if (in_array($_SESSION['role'], ['Admin', 'Clerk'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/students.php">Manage Students</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/graduation_clearance.php">Graduation</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>student/statement.php">Student Statement</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <!-- Classes Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="classesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Classes
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="classesDropdown">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/class_sessions.php">Class Sessions</a></li>
                            <?php if (in_array($_SESSION['role'], ['Admin', 'Clerk'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/attendance.php">Attendance</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/assignments.php">Assignments</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/marks.php">Marks</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/units.php">Units</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <!-- Administrators Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="administratorsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Administrators
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="administratorsDropdown">
                            <?php if ($_SESSION['role'] === 'Admin'): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/administrators.php">Administrators</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/lecturers.php">Lecturers</a></li>
                        </ul>
                    </li>
                    <!-- Finances Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="financesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Finances
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="financesDropdown">
                            <?php if (in_array($_SESSION['role'], ['Admin', 'Clerk'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/payments.php">Payments</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/expenses.php">Expenses</a></li>
                            <?php endif; ?>
                            <?php if (in_array($_SESSION['role'], ['Admin', 'Coordinator'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/remittances.php">Remittances</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <!-- Reports Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Reports
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="reportsDropdown">
                            <?php if (in_array($_SESSION['role'], ['Admin', 'Coordinator'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/programs.php">Programs</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/reports.php">Reports</a></li>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'Admin'): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/constants.php">Constants</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <main class="container">