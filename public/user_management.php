<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/db.php';


// Check if user is logged in and is admin or manager
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'manager'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

// Handle user deletion
if (isset($_GET['delete'])) {
    try {
        $delete_id = intval($_GET['delete']);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$delete_id])) {
            $success = "User deleted successfully.";
        } else {
            $error = "Failed to delete user.";
        }
    } catch (PDOException $e) {
        $error = "Error deleting user: " . $e->getMessage();
    }
}

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'tenant';
        $full_name = trim($_POST['full_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');

        if (!$username || !$email || !$password || !$full_name || !$phone_number) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = 'Username or email already exists.';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, full_name, phone_number) VALUES (?, ?, ?, ?, ?, ?)");
                if ($insert->execute([$username, $email, $password_hash, $role, $full_name, $phone_number])) {
                    $success = 'User created successfully.';
                } else {
                    $error = 'Failed to create user.';
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Error creating user: " . $e->getMessage();
    }
}

// Fetch all users including full_name and phone_number
try {
    $stmt = $pdo->query("SELECT id, username, email, role, full_name, phone_number FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
    $users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>User Management - Property Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<?php include '../templates/header.php'; ?>
<div class="container mt-5">
    <h1>User Management</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <h2>Add New User</h2>
    <form method="post" action="user_management.php" class="mb-4">
        <div class="row g-3">
            <div class="col-md-2">
                <input type="text" name="full_name" class="form-control" placeholder="Full Name" required />
            </div>
            <div class="col-md-2">
                <input type="text" name="phone_number" class="form-control" placeholder="Phone Number" required />
            </div>
            <div class="col-md-2">
                <input type="text" name="username" class="form-control" placeholder="Username" required />
            </div>
            <div class="col-md-2">
                <input type="email" name="email" class="form-control" placeholder="Email" required />
            </div>
            <div class="col-md-2">
                <input type="password" name="password" class="form-control" placeholder="Password" required minlength="6" />
            </div>
            <div class="col-md-1">
                <select name="role" class="form-select" required>
                    <option value="tenant" selected>Tenant</option>
                    <option value="user">User</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </div>
    </form>

    <h2>Existing Users</h2>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Phone Number</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['id']) ?></td>
                <td><?= htmlspecialchars($u['full_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($u['phone_number'] ?? '') ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['role']) ?></td>
                <td>
                    <?php if ($u['id'] !== $_SESSION['user']['id']): ?>
                    <a href="user_management.php?delete=<?= $u['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?');">Delete</a>
                    <?php else: ?>
                    <em>Current User</em>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include '../templates/footer.php'; ?>
</body>
</html>
