<?php
session_start();
require_once '../config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$username || !$email || !$password || !$confirm_password) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Check if username or email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = 'Username or email already exists.';
        } else {
            // Insert new user with default role 'tenant'
            try {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'tenant')");
                if ($insert->execute([$username, $email, $password_hash])) {
                    $success = 'Registration successful. You can now <a href="login.php">login</a>.';
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            } catch (PDOException $e) {
                // Check for duplicate entry
                if ($e->getCode() == 23000) {
                    $error = 'Username or email already exists.';
                } else {
                    $error = 'Error fetching data: ' . $e->getMessage();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Register - Property Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <script>
        // Client-side validation for better UX
        function validateForm() {
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            let error = '';

            if (!username || !email || !password || !confirmPassword) {
                error = 'Please fill in all fields.';
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                error = 'Invalid email address.';
            } else if (password !== confirmPassword) {
                error = 'Passwords do not match.';
            }

            if (error) {
                const errorDiv = document.getElementById('clientError');
                errorDiv.textContent = error;
                errorDiv.style.display = 'block';
                return false;
            }
            return true;
        }
    </script>
</head>
<body>
<?php include '../templates/header.php'; ?>
<div class="container mt-5">
    <h1>Register</h1>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <div id="clientError" class="alert alert-danger" style="display:none;"></div>
    <form method="post" action="register.php" onsubmit="return validateForm();">
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" required autofocus />
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" required />
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required minlength="6" />
        </div>
        <div class="mb-3">
            <label for="confirm_password" class="form-label">Confirm Password</label>
            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6" />
        </div>
        <button type="submit" class="btn btn-primary">Register</button>
        <a href="login.php" class="btn btn-link">Back to Login</a>
    </form>
</div>
<?php include '../templates/footer.php'; ?>
</body>
</html>
