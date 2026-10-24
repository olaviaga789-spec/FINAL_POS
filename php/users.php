<?php
/*
 * Padayon Kapetid POS — Users API
 * Handles: list, create, update, delete users
 * Place this file in: php/users.php
 */

error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

// Only admins can manage accounts
session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorized — please log in'], 401);
}
if ($_SESSION['role'] !== 'admin') {
    jsonResponse(['error' => 'Forbidden — admin only'], 403);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'list':   listUsers();   break;
    case 'create': createUser();  break;
    case 'update': updateUser();  break;
    case 'delete': deleteUser();  break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

// ── LIST ALL USERS ────────────────────────────────────────────────
function listUsers() {
    $conn = getDB();
    $result = $conn->query(
        "SELECT id, username, full_name, role,
                is_active,
                DATE_FORMAT(last_login, '%Y-%m-%d %H:%i') AS last_login,
                DATE_FORMAT(created_at, '%Y-%m-%d')        AS created_at
         FROM users
         ORDER BY role DESC, full_name ASC"
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['is_active'] = (bool) $row['is_active'];
        $rows[] = $row;
    }
    $conn->close();
    jsonResponse($rows);
}

// ── CREATE USER ───────────────────────────────────────────────────
function createUser() {
    $d         = json_decode(file_get_contents('php://input'), true);
    $username  = trim($d['username']  ?? '');
    $full_name = trim($d['full_name'] ?? '');
    $role      = $d['role']      ?? 'cashier';
    $password  = $d['password']  ?? '';
    $is_active = isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1;

    // Validate
    if (!$username || !$full_name)
        jsonResponse(['error' => 'Username and full name are required.'], 400);
    if (!in_array($role, ['admin', 'cashier']))
        jsonResponse(['error' => 'Invalid role.'], 400);
    if (strlen($password) < 6)
        jsonResponse(['error' => 'Password must be at least 6 characters.'], 400);

    $conn = getDB();

    // Check duplicate username
    $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $chk->bind_param('s', $username);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $conn->close();
        jsonResponse(['error' => 'Username already exists.'], 409);
    }
    $chk->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO users (username, password, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssssi', $username, $hash, $full_name, $role, $is_active);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();
    $conn->close();

    jsonResponse(['success' => true, 'id' => $new_id, 'message' => "$full_name created successfully!"]);
}

// ── UPDATE USER ───────────────────────────────────────────────────
function updateUser() {
    $d         = json_decode(file_get_contents('php://input'), true);
    $id        = intval($d['id']        ?? 0);
    $username  = trim($d['username']    ?? '');
    $full_name = trim($d['full_name']   ?? '');
    $role      = $d['role']      ?? 'cashier';
    $password  = $d['password']  ?? '';   // empty = don't change
    $is_active = isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1;

    if (!$id || !$username || !$full_name)
        jsonResponse(['error' => 'ID, username, and full name are required.'], 400);
    if (!in_array($role, ['admin', 'cashier']))
        jsonResponse(['error' => 'Invalid role.'], 400);
    if ($password && strlen($password) < 6)
        jsonResponse(['error' => 'Password must be at least 6 characters.'], 400);

    $conn = getDB();

    // Prevent editing your own account's role/status (safety guard)
    $me = $_SESSION['user_id'];
    if ((int)$me === $id) {
        // Allow name/username changes but lock role + active status to current values
        $self = $conn->prepare("SELECT role, is_active FROM users WHERE id = ?");
        $self->bind_param('i', $id);
        $self->execute();
        $selfRow = $self->get_result()->fetch_assoc();
        $self->close();
        $role      = $selfRow['role'];
        $is_active = $selfRow['is_active'];
    }

    // Check duplicate username (excluding self)
    $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $chk->bind_param('si', $username, $id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $conn->close();
        jsonResponse(['error' => 'Username already taken by another account.'], 409);
    }
    $chk->close();

    if ($password) {
        // Update everything including password
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "UPDATE users SET username=?, full_name=?, role=?, is_active=?, password=? WHERE id=?"
        );
        $stmt->bind_param('sssisi', $username, $full_name, $role, $is_active, $hash, $id);
    } else {
        // Update everything except password
        $stmt = $conn->prepare(
            "UPDATE users SET username=?, full_name=?, role=?, is_active=? WHERE id=?"
        );
        $stmt->bind_param('sssii', $username, $full_name, $role, $is_active, $id);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    if ($affected === 0 && !$password) {
        // Could just mean nothing changed — still success
    }

    jsonResponse(['success' => true, 'message' => "$full_name updated successfully!"]);
}

// ── DELETE USER ───────────────────────────────────────────────────
function deleteUser() {
    $d  = json_decode(file_get_contents('php://input'), true);
    $id = intval($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Invalid user ID.'], 400);

    // Cannot delete yourself
    if ((int)$_SESSION['user_id'] === $id)
        jsonResponse(['error' => 'You cannot delete your own account.'], 400);

    $conn = getDB();

    // Get name for confirmation message
    $chk = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $chk->bind_param('i', $id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) { $conn->close(); jsonResponse(['error' => 'User not found.'], 404); }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    jsonResponse(['success' => true, 'message' => "{$row['full_name']} deleted."]);
}
?>
