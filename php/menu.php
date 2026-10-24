<?php
// Suppress PHP notices/warnings that would corrupt JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'categories': getCategories(); break;
    case 'items':      getMenuItems();  break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function getCategories() {
    $conn = getDB();
    $result = $conn->query("SELECT * FROM categories ORDER BY id");
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $conn->close();
    jsonResponse($rows);
}

function getMenuItems() {
    $conn = getDB();
    $category_id = $_GET['category_id'] ?? null;
    if ($category_id && $category_id != 1) {
        $stmt = $conn->prepare("SELECT mi.*, c.name as category_name FROM menu_items mi LEFT JOIN categories c ON mi.category_id = c.id WHERE mi.is_available = 1 AND mi.category_id = ? ORDER BY mi.name");
        $stmt->bind_param('i', $category_id);
    } else {
        $stmt = $conn->prepare("SELECT mi.*, c.name as category_name FROM menu_items mi LEFT JOIN categories c ON mi.category_id = c.id WHERE mi.is_available = 1 ORDER BY mi.name");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $conn->close();
    jsonResponse($rows);
}
?>
