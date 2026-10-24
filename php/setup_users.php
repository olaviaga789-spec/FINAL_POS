<?php
// Run this ONCE to set up default users
// Visit: http://localhost/pos/php/setup_users.php
// Then DELETE this file for security!

require_once 'db.php';
$conn = getDB();

$users = [
    ['admin',   password_hash('admin123',   PASSWORD_DEFAULT), 'Administrator', 'admin'],
    ['cashier', password_hash('cashier123', PASSWORD_DEFAULT), 'Juan Abubakar', 'cashier'],
];

// Create users table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('admin','cashier') DEFAULT 'cashier',
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Insert or update users
foreach ($users as [$username, $hash, $full_name, $role]) {
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password=VALUES(password), full_name=VALUES(full_name), role=VALUES(role)");
    $stmt->bind_param('ssss', $username, $hash, $full_name, $role);
    $stmt->execute();
    $stmt->close();
}

$conn->close();
echo '<h2 style="font-family:sans-serif;color:green">✅ Users created successfully!</h2>';
echo '<p style="font-family:sans-serif">Default accounts:</p>';
echo '<ul style="font-family:sans-serif">';
echo '<li><strong>Admin</strong> — username: <code>admin</code> | password: <code>admin123</code></li>';
echo '<li><strong>Cashier</strong> — username: <code>cashier</code> | password: <code>cashier123</code></li>';
echo '</ul>';
echo '<p style="font-family:sans-serif;color:red">⚠ Delete this file after running it!</p>';
echo '<p style="font-family:sans-serif"><a href="../login.html">→ Go to Login</a></p>';
?>
