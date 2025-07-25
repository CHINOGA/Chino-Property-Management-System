<?php
require_once __DIR__ . '/../config/db.php';

$users = [
    ['username' => 'admin1', 'email' => 'admin1@example.com', 'password' => 'AdminPass123', 'role' => 'admin'],
    ['username' => 'manager1', 'email' => 'manager1@example.com', 'password' => 'ManagerPass123', 'role' => 'manager'],
    ['username' => 'tenant1', 'email' => 'tenant1@example.com', 'password' => 'TenantPass123', 'role' => 'tenant'],
    ['username' => 'tenant2', 'email' => 'tenant2@example.com', 'password' => 'TenantPass123', 'role' => 'tenant'],
];

foreach ($users as $user) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$user['username'], $user['email']]);
    if (!$stmt->fetch()) {
        $password_hash = password_hash($user['password'], PASSWORD_DEFAULT);
        $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $insert->execute([$user['username'], $user['email'], $password_hash, $user['role']]);
        echo "Inserted user: {$user['username']}\n";
    } else {
        echo "User {$user['username']} already exists.\n";
    }
}
?>
