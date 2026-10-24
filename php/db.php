<?php
error_reporting(0);
ini_set("display_errors", 0);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'restaurant_pos');

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
    }
    // ── CRITICAL: set utf8mb4 so emojis (☕🍵🌶️ etc.) store and retrieve correctly ──
    $conn->set_charset('utf8mb4');
    return $conn;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireAuth($allowed_roles = ['admin', 'cashier']) {
    session_start();
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Unauthorized', 'redirect' => 'login.html'], 401);
    }
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        jsonResponse(['error' => 'Forbidden — insufficient role'], 403);
    }
}
?>
