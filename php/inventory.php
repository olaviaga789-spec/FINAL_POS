<?php
/*
 * Padayon Kapetid POS — Inventory API
 * Place this file in: php/inventory.php
 *
 * Actions:
 *   GET  ?action=get_stock    → returns all items with stock levels
 *   POST ?action=update_stock → update stock qty + threshold for one item
 *   POST ?action=bulk_update  → update multiple items at once
 */

error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

// Ensure stock columns exist on menu_items
function ensureStockColumns() {
    $conn = getDB();
    $res = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'stock'");
    if ($res->num_rows === 0) {
        $conn->query("ALTER TABLE menu_items ADD COLUMN stock INT NOT NULL DEFAULT 0 AFTER price");
    }
    $res2 = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'stock_threshold'");
    if ($res2->num_rows === 0) {
        $conn->query("ALTER TABLE menu_items ADD COLUMN stock_threshold INT NOT NULL DEFAULT 10 AFTER stock");
    }
    $conn->close();
}
ensureStockColumns();

$action = $_GET['action'] ?? '';
switch ($action) {
    case 'get_stock':    getStock();    break;
    case 'update_stock': updateStock(); break;
    case 'bulk_update':  bulkUpdate();  break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function getStock() {
    $conn = getDB();
    $stmt = $conn->prepare(
        "SELECT mi.id, mi.name, mi.price, mi.is_available,
                mi.stock, mi.stock_threshold,
                mi.image_url,
                c.name AS category_name, c.icon AS category_icon
         FROM menu_items mi
         LEFT JOIN categories c ON c.id = mi.category_id
         ORDER BY c.name, mi.name"
    );
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['stock']           = (int)$row['stock'];
        $row['stock_threshold'] = (int)$row['stock_threshold'];
        $row['is_available']    = (int)$row['is_available'];
        $rows[] = $row;
    }
    $stmt->close();
    $conn->close();
    jsonResponse($rows);
}

function updateStock() {
    $body      = json_decode(file_get_contents('php://input'), true);
    $id        = intval($body['id']        ?? 0);
    $stock     = intval($body['stock']     ?? 0);
    $threshold = intval($body['threshold'] ?? 10);
    if (!$id) jsonResponse(['error' => 'Invalid item ID'], 400);
    $stock     = max(0, $stock);
    $threshold = max(0, $threshold);
    $conn = getDB();
    $stmt = $conn->prepare("UPDATE menu_items SET stock = ?, stock_threshold = ? WHERE id = ?");
    $stmt->bind_param('iii', $stock, $threshold, $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'message' => 'Stock updated!', 'id' => $id, 'stock' => $stock, 'threshold' => $threshold]);
}

function bulkUpdate() {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) jsonResponse(['error' => 'Expected array'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("UPDATE menu_items SET stock = ?, stock_threshold = ? WHERE id = ?");
    $updated = 0;
    foreach ($body as $item) {
        $id        = intval($item['id']        ?? 0);
        $stock     = max(0, intval($item['stock']     ?? 0));
        $threshold = max(0, intval($item['threshold'] ?? 10));
        if (!$id) continue;
        $stmt->bind_param('iii', $stock, $threshold, $id);
        $stmt->execute();
        $updated++;
    }
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'message' => "$updated items updated."]);
}
?>
