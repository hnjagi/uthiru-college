<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    if ($role === 'Admin' || $role === 'Clerk' || $role === 'Coordinator') {
        header("Location: " . (defined('BASE_URL') ? BASE_URL : '/') . "index.php");
    }
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Log raw input
    error_log("Login attempt: username='$username', password='$password', raw_username=" . bin2hex($username) . ", raw_password=" . bin2hex($password));

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
        error_log("Login failed: Empty username or password");
    } else {
        try {
            // Database connection
            require 'includes/db_connect.php';

            // Log database connection details
            error_log("Connected to DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn());
            error_log("Querying for username: '$username'");

            // Prepare and execute query
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM administrators WHERE username = ? AND deleted_at IS NULL");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Log query result and raw password hash
            error_log("Query result: " . print_r($user, true));
            if ($user) {
                error_log("DB password hash: '" . $user['password'] . "', length: " . strlen($user['password']));
                error_log("Testing password_verify with input: '$password'");
                $password_match = password_verify($password, $user['password']);
                error_log("password_verify result: " . ($password_match ? "true" : "false"));

                if ($password_match) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['username'] = $user['username'];
                    error_log("Login successful for user ID: " . $user['id'] . ", role: " . $user['role']);
                    header("Location: " . (defined('BASE_URL') ? BASE_URL : '/') . "index.php");
                    exit;
                } else {
                    error_log("Password verification failed for username: $username");
                    $error = "Invalid username or password.";
                }
            } else {
                error_log("No user found for username: $username");
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
            error_log("Database error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Uthiru College</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            width: 300px;
        }
        .login-container h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        .login-container label {
            display: block;
            margin-bottom: 5px;
        }
        .login-container input {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            box-sizing: border-box;
        }
        .login-container button {
            width: 100%;
            padding: 10px;
            background-color: #2980b9;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .login-container button:hover {
            background-color: #3498db;
        }
        .error {
            color: red;
            text-align: center;
            margin-bottom: 10px;
        }
        .success {
            color: green;
            text-align: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Login</h2>
        <?php if (!empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>
        <form method="POST">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" value="" required>
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" value="" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>