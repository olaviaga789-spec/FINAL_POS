<?php
// Suppress PHP notices/warnings that would corrupt JSON output
error_reporting(0);
ini_set("display_errors", 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_items':       getItems();       break;
    case 'get_item':        getItem();        break;
    case 'add_item':        addItem();        break;
    case 'update_item':     updateItem();     break;
    case 'delete_item':     deleteItem();     break;
    case 'toggle_item':     toggleItem();     break;
    case 'upload_image':    uploadImage();    break;
    case 'get_categories':  getCategories();  break;
    case 'add_category':    addCategory();    break;
    case 'update_category': updateCategory(); break;
    case 'delete_category': deleteCategory(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

// ── IMAGE UPLOAD ──────────────────────────────────────────────────────────────

function uploadImage() {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) jsonResponse(['error' => 'item_id required'], 400);
    if (empty($_FILES['image'])) jsonResponse(['error' => 'No image file received'], 400);

    $file    = $_FILES['image'];
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowed))
        jsonResponse(['error' => 'Invalid file type. Only JPG, PNG, WEBP, GIF allowed.'], 400);
    if ($file['size'] > $maxSize)
        jsonResponse(['error' => 'File too large. Max 5MB.'], 400);

    // Build save path — images/ folder is at project root (one level up from php/)
    $imgDir = dirname(__DIR__) . '/images/';
    if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);

    // Sanitize filename: item_<id>_<timestamp>.<ext>
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext      = strtolower($ext) ?: 'jpg';
    $filename = 'item_' . $item_id . '_' . time() . '.' . $ext;
    $savePath = $imgDir . $filename;

    // Remove old image for this item if exists
    $conn = getDB();
    $stmt = $conn->prepare("SELECT image_url FROM menu_items WHERE id=?");
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($old && $old['image_url']) {
        $oldFile = dirname(__DIR__) . '/' . ltrim($old['image_url'], './');
        if (file_exists($oldFile)) @unlink($oldFile);
    }

    if (!move_uploaded_file($file['tmp_name'], $savePath)) {
        $conn->close();
        jsonResponse(['error' => 'Failed to save image file. Check folder permissions.'], 500);
    }

    // Save relative path in DB (relative to project root)
    $relativePath = 'images/' . $filename;
    $stmt = $conn->prepare("UPDATE menu_items SET image_url=? WHERE id=?");
    $stmt->bind_param('si', $relativePath, $item_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    jsonResponse(['success' => true, 'image_url' => $relativePath, 'message' => 'Image saved!']);
}

// ── MENU ITEMS ────────────────────────────────────────────────────────────────

function getItems() {
    $conn = getDB();
    $cat  = $_GET['category_id'] ?? null;
    if ($cat && $cat != 1) {
        $stmt = $conn->prepare("SELECT mi.*, c.name AS category_name, c.icon AS category_icon FROM menu_items mi LEFT JOIN categories c ON c.id = mi.category_id WHERE mi.category_id = ? ORDER BY c.name, mi.name");
        $stmt->bind_param('i', $cat);
    } else {
        $stmt = $conn->prepare("SELECT mi.*, c.name AS category_name, c.icon AS category_icon FROM menu_items mi LEFT JOIN categories c ON c.id = mi.category_id ORDER BY c.name, mi.name");
    }
    $stmt->execute();
    $rows = [];
    $res  = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close(); $conn->close();
    jsonResponse($rows);
}

function getItem() {
    $id   = intval($_GET['id'] ?? 0);
    $conn = getDB();
    $stmt = $conn->prepare("SELECT mi.*, c.name AS category_name FROM menu_items mi LEFT JOIN categories c ON c.id = mi.category_id WHERE mi.id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $conn->close();
    if (!$row) jsonResponse(['error' => 'Item not found'], 404);
    jsonResponse($row);
}

function addItem() {
    $d     = json_decode(file_get_contents('php://input'), true);
    $name  = trim($d['name'] ?? '');
    $price = floatval($d['price'] ?? 0);
    $cat   = intval($d['category_id'] ?? 0);
    if (!$name || !$price || !$cat) jsonResponse(['error' => 'Name, price and category required'], 400);

    $desc  = trim($d['description'] ?? '');
    $avail = isset($d['is_available']) ? intval($d['is_available']) : 1;
    $conn  = getDB();
    $stmt  = $conn->prepare("INSERT INTO menu_items (category_id, name, description, price, is_available) VALUES (?,?,?,?,?)");
    $stmt->bind_param('issdi', $cat, $name, $desc, $price, $avail);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close(); $conn->close();
    jsonResponse(['success' => true, 'id' => $id, 'message' => "$name added!"]);
}

function updateItem() {
    $d     = json_decode(file_get_contents('php://input'), true);
    $id    = intval($d['id'] ?? 0);
    $name  = trim($d['name'] ?? '');
    $price = floatval($d['price'] ?? 0);
    $cat   = intval($d['category_id'] ?? 0);
    if (!$id || !$name || !$price || !$cat) jsonResponse(['error' => 'Missing required fields'], 400);

    $desc  = trim($d['description'] ?? '');
    $avail = isset($d['is_available']) ? intval($d['is_available']) : 1;
    $conn  = getDB();
    $stmt  = $conn->prepare("UPDATE menu_items SET category_id=?, name=?, description=?, price=?, is_available=? WHERE id=?");
    $stmt->bind_param('issdii', $cat, $name, $desc, $price, $avail, $id);
    $stmt->execute();
    $stmt->close(); $conn->close();
    jsonResponse(['success' => true, 'message' => "$name updated!"]);
}

function deleteItem() {
    $d   = json_decode(file_get_contents('php://input'), true);
    $id  = intval($d['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Invalid ID'], 400);
    $conn = getDB();
    // Delete image file if exists
    $stmt = $conn->prepare("SELECT image_url FROM menu_items WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['image_url']) {
        $file = dirname(__DIR__) . '/' . ltrim($row['image_url'], './');
        if (file_exists($file)) @unlink($file);
    }
    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close(); $conn->close();
    jsonResponse(['success' => true, 'message' => 'Item deleted']);
}

function toggleItem() {
    $d    = json_decode(file_get_contents('php://input'), true);
    $id   = intval($d['id'] ?? 0);
    $avail= intval($d['is_available'] ?? 0);
    $conn = getDB();
    $stmt = $conn->prepare("UPDATE menu_items SET is_available=? WHERE id=?");
    $stmt->bind_param('ii', $avail, $id);
    $stmt->execute();
    $stmt->close(); $conn->close();
    jsonResponse(['success' => true]);
}

// ── CATEGORIES ────────────────────────────────────────────────────────────────

function getCategories() {
    $conn = getDB();
    $res  = $conn->query("SELECT c.*, COUNT(mi.id) AS item_count FROM categories c LEFT JOIN menu_items mi ON mi.category_id = c.id GROUP BY c.id ORDER BY c.id");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $conn->close();
    jsonResponse($rows);
}

function addCategory() {
    $d    = json_decode(file_get_contents('php://input'), true);
    $name = trim($d['name'] ?? '');
    $icon = trim($d['icon'] ?? '🍽️');
    if (!$name) jsonResponse(['error' => 'Category name required'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("INSERT INTO categories (name, icon) VALUES (?,?)");
    $stmt->bind_param('ss', $name, $icon);
    $stmt->execute();
    $id   = $conn->insert_id;
    $stmt->close(); $conn->close();
    jsonResponse(['success' => true, 'id' => $id, 'message' => "$name added!"]);
}

function updateCategory() {
    $d    = json_decode(file_get_contents('php://input'), true);
    $id   = intval($d['id'] ?? 0);
    $name = trim($d['name'] ?? '');
    $icon = trim($d['icon'] ?? '🍽️');
    if (!$id || !$name) jsonResponse(['error' => 'Missing fields'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("UPDATE categories SET name=?, icon=? WHERE id=?");
    $stmt->bind_param('ssi', $name, $icon, $id);
    $stmt->execute();
    $stmt->close(); $conn->close();
    jsonResponse(['success' => true, 'message' => "$name updated!"]);
}

function deleteCategory() {
    $d   = json_decode(file_get_contents('php://input'), true);
    $id  = intval($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Invalid ID'], 400);
    $conn  = getDB();
    $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM menu_items WHERE category_id=?");
    $check->bind_param('i', $id);
    $check->execute();
    $cnt = $check->get_result()->fetch_assoc()['cnt'];
    $check->close();
    if ($cnt > 0) { $conn->close(); jsonResponse(['error' => "Cannot delete — category has $cnt items. Move or delete items first."], 400); }
    $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close(); $conn->close();
    jsonResponse(['success' => true, 'message' => 'Category deleted']);
}
?>
