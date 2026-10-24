<?php
// Suppress PHP notices/warnings that would corrupt JSON output
error_reporting(0);
ini_set("display_errors", 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

// Safe migration — add customer_id to orders if not yet present
function ensureOrdersSchema($conn) {
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_id INT DEFAULT NULL");
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'POST': if ($action === 'create') createOrder(); break;
    case 'GET':
        if ($action === 'list') listOrders();
        elseif ($action === 'get') getOrder();
        break;
    case 'PUT': if ($action === 'status') updateStatus(); break;
    default: jsonResponse(['error' => 'Invalid request'], 400);
}

// ── CREATE ORDER ──────────────────────────────────────────────────
function createOrder() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['items'])) { jsonResponse(['error' => 'No items in order'], 400); }

    $conn = getDB();
    ensureOrdersSchema($conn);

    $customer_name = trim($data['customer_name'] ?? 'Guest');
    $table_number  = trim($data['table_number']  ?? '');
    $order_type    = trim($data['order_type']    ?? 'dine-in');
    $notes         = trim($data['notes']         ?? '');
    $customer_id   = intval($data['customer_id'] ?? 0); // 0 = walk-in, no account

    $subtotal = 0;
    foreach ($data['items'] as $item) $subtotal += $item['price'] * $item['qty'];
    $tax   = round($subtotal * 0.12, 2);
    $total = $subtotal + $tax;

    // Insert the order row
    $stmt = $conn->prepare(
        "INSERT INTO orders
           (customer_name, table_number, order_type, subtotal, tax, total, notes, customer_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('sssdddsi',
        $customer_name, $table_number, $order_type,
        $subtotal, $tax, $total, $notes, $customer_id
    );
    $stmt->execute();
    $order_id = $conn->insert_id;
    $stmt->close();

    // Insert each order item
    $item_stmt = $conn->prepare(
        "INSERT INTO order_items (order_id, menu_item_id, item_name, qty, unit_price, subtotal)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($data['items'] as $item) {
        $item_subtotal = $item['price'] * $item['qty'];
        $item_stmt->bind_param(
            'iisidd',
            $order_id, $item['id'], $item['name'],
            $item['qty'], $item['price'], $item_subtotal
        );
        $item_stmt->execute();
    }
    $item_stmt->close();

    // ── DEDUCT STOCK ON ORDER CREATION ────────────────────────────
    // Only runs if the stock column exists (safe for fresh installs that
    // haven't run the inventory migration yet).
    $hasStock = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'stock'");
    if ($hasStock && $hasStock->num_rows > 0) {
        $stockStmt = $conn->prepare(
            "UPDATE menu_items SET stock = GREATEST(0, stock - ?) WHERE id = ?"
        );
        foreach ($data['items'] as $item) {
            $qty    = intval($item['qty']);
            $itemId = intval($item['id']);
            $stockStmt->bind_param('ii', $qty, $itemId);
            $stockStmt->execute();
        }
        $stockStmt->close();
    }

    $conn->close();

    jsonResponse([
        'success'  => true,
        'order_id' => $order_id,
        'subtotal' => $subtotal,
        'tax'      => $tax,
        'total'    => $total
    ]);
}

// ── LIST ORDERS ───────────────────────────────────────────────────
function listOrders() {
    $conn   = getDB();
    $status = $_GET['status'] ?? null;

    if ($status) {
        $stmt = $conn->prepare(
            "SELECT o.*, COUNT(oi.id) AS item_count
             FROM orders o
             LEFT JOIN order_items oi ON o.id = oi.order_id
             WHERE o.status = ?
             GROUP BY o.id
             ORDER BY o.created_at DESC LIMIT 100"
        );
        $stmt->bind_param('s', $status);
    } else {
        $stmt = $conn->prepare(
            "SELECT o.*, COUNT(oi.id) AS item_count
             FROM orders o
             LEFT JOIN order_items oi ON o.id = oi.order_id
             GROUP BY o.id
             ORDER BY o.created_at DESC LIMIT 100"
        );
    }
    $stmt->execute();
    $rows = [];
    $res  = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $conn->close();
    jsonResponse($rows);
}

// ── GET SINGLE ORDER ──────────────────────────────────────────────
function getOrder() {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Invalid order ID'], 400);

    $conn = getDB();
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) jsonResponse(['error' => 'Order not found'], 404);

    $stmt2 = $conn->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    $items = [];
    $res   = $stmt2->get_result();
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt2->close();
    $conn->close();

    $order['items'] = $items;
    jsonResponse($order);
}

// ── UPDATE ORDER STATUS ───────────────────────────────────────────
function updateStatus() {
    $data    = json_decode(file_get_contents('php://input'), true);
    $id      = intval($data['id']     ?? 0);
    $status  = $data['status']        ?? '';
    $allowed = ['pending','preparing','ready','completed','cancelled'];

    if (!$id || !in_array($status, $allowed))
        jsonResponse(['error' => 'Invalid data'], 400);

    $conn = getDB();
    ensureOrdersSchema($conn);

    // Update the order status
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();

    // ── SYNC TO CUSTOMER DIRECTORY ON COMPLETION ──────────────────
    // When an order is marked COMPLETED and has a linked customer_id,
    // update: visit_count +1, last_visit = NOW(),
    //         total_spent += subtotal, loyalty_pts += floor(subtotal/50)
    if ($status === 'completed') {
        $oStmt = $conn->prepare(
            "SELECT customer_id, subtotal
             FROM orders
             WHERE id = ?
               AND customer_id IS NOT NULL
               AND customer_id > 0"
        );
        $oStmt->bind_param('i', $id);
        $oStmt->execute();
        $order = $oStmt->get_result()->fetch_assoc();
        $oStmt->close();

        if ($order) {
            $cid        = intval($order['customer_id']);
            $spent      = floatval($order['subtotal']);       // pre-tax subtotal
            $pts_earned = intval(floor($spent / 50));         // 1 point per ₱50

            // Guard: only run if customers table exists
            $tbl = $conn->query("SHOW TABLES LIKE 'customers'");
            if ($tbl && $tbl->num_rows > 0) {
                $cStmt = $conn->prepare(
                    "UPDATE customers
                     SET visit_count = visit_count + 1,
                         last_visit  = NOW(),
                         total_spent = total_spent + ?,
                         loyalty_pts = loyalty_pts + ?
                     WHERE id = ?"
                );
                $cStmt->bind_param('dii', $spent, $pts_earned, $cid);
                $cStmt->execute();
                $cStmt->close();
            }
        }
    }

    // ── RESTORE STOCK ON CANCELLATION ────────────────────────────
    // If order is CANCELLED, add back the qty to menu_items.stock
    if ($status === 'cancelled') {
        $tbl = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'stock'");
        if ($tbl && $tbl->num_rows > 0) {
            $iStmt = $conn->prepare(
                "SELECT menu_item_id, qty FROM order_items WHERE order_id = ?"
            );
            $iStmt->bind_param('i', $id);
            $iStmt->execute();
            $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $iStmt->close();

            $rStmt = $conn->prepare(
                "UPDATE menu_items SET stock = stock + ? WHERE id = ?"
            );
            foreach ($items as $item) {
                $rStmt->bind_param('ii', $item['qty'], $item['menu_item_id']);
                $rStmt->execute();
            }
            $rStmt->close();
        }
    }

    $conn->close();
    jsonResponse(['success' => true]);
}
?>
