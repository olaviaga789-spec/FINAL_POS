<?php
// Suppress PHP notices/warnings that would corrupt JSON output
error_reporting(0);
ini_set("display_errors", 0);
ob_start(); // buffer any accidental output so JSON is never corrupted

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit; }

require_once 'db.php';

$action = $_GET['action'] ?? '';
ob_end_clean(); // discard any stray output before switch
switch ($action) {
    case 'summary':     getSummary();     break;
    case 'top_items':   getTopItems();    break;
    case 'by_category': getByCategory();  break;
    case 'by_hour':     getByHour();      break;
    case 'by_day':      getByDay();       break;
    case 'orders':      getOrders();      break;
    default: jsonResponse(['error' => 'Unknown action: ' . htmlspecialchars($action)], 400);
}

function getDateRange() {
    $period = $_GET['period'] ?? 'today';
    $now    = new DateTime();
    switch ($period) {
        case 'today':
            return [$now->format('Y-m-d').' 00:00:00', $now->format('Y-m-d').' 23:59:59'];
        case 'week':
            $start = clone $now; $start->modify('monday this week');
            return [$start->format('Y-m-d').' 00:00:00', $now->format('Y-m-d').' 23:59:59'];
        case 'month':
            return [$now->format('Y-m-01').' 00:00:00', $now->format('Y-m-d').' 23:59:59'];
        case 'custom':
            return [($_GET['from'] ?? $now->format('Y-m-d')).' 00:00:00', ($_GET['to'] ?? $now->format('Y-m-d')).' 23:59:59'];
        default:
            return [$now->format('Y-m-d').' 00:00:00', $now->format('Y-m-d').' 23:59:59'];
    }
}

function getSummary() {
    [$from, $to] = getDateRange();
    $conn = getDB();

    // Query 1: order-level aggregates (no JOIN so subtotals are never multiplied)
    $stmt = $conn->prepare("SELECT
        COUNT(CASE WHEN status != 'cancelled' THEN 1 END) AS total_orders,
        COUNT(CASE WHEN status = 'completed'  THEN 1 END) AS completed_orders,
        COUNT(CASE WHEN status = 'pending'    THEN 1 END) AS pending_orders,
        COUNT(CASE WHEN status = 'cancelled'  THEN 1 END) AS cancelled_orders,
        COALESCE(SUM(CASE WHEN status != 'cancelled' THEN subtotal END),0) AS total_subtotal,
        COALESCE(SUM(CASE WHEN status != 'cancelled' THEN tax END),0)      AS total_tax,
        COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total END),0)    AS total_revenue,
        COALESCE(AVG(CASE WHEN status != 'cancelled' THEN total END),0)    AS avg_order
        FROM orders
        WHERE created_at BETWEEN ? AND ?");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Query 2: items sold (separate so the JOIN doesn't corrupt order aggregates)
    $stmt2 = $conn->prepare("SELECT COALESCE(SUM(oi.qty),0) AS items_sold
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.created_at BETWEEN ? AND ? AND o.status != 'cancelled'");
    $stmt2->bind_param('ss', $from, $to);
    $stmt2->execute();
    $row2 = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    $conn->close();

    if ($row) $row['items_sold'] = $row2 ? (int)$row2['items_sold'] : 0;
    // Always return an object — never null (null causes JS to think PHP failed)
    if (!$row) {
        $row = ['total_orders'=>0,'completed_orders'=>0,'pending_orders'=>0,
                'cancelled_orders'=>0,'total_subtotal'=>0,'total_tax'=>0,
                'total_revenue'=>0,'avg_order'=>0,'items_sold'=>0];
    }
    jsonResponse($row);
}

function getTopItems() {
    [$from, $to] = getDateRange();
    $conn = getDB();
    $stmt = $conn->prepare("SELECT oi.item_name AS name, c.name AS category, mi.image_url, SUM(oi.qty) AS qty, SUM(oi.subtotal) AS revenue FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN menu_items mi ON mi.id=oi.menu_item_id LEFT JOIN categories c ON c.id=mi.category_id WHERE o.created_at BETWEEN ? AND ? AND o.status != 'cancelled' GROUP BY oi.item_name, c.name, mi.image_url ORDER BY qty DESC LIMIT 8");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result(); $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close(); $conn->close();
    jsonResponse($rows);
}

function getByCategory() {
    [$from, $to] = getDateRange();
    $conn = getDB();
    $stmt = $conn->prepare("SELECT c.name AS category, c.icon, SUM(oi.subtotal) AS revenue FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN menu_items mi ON mi.id=oi.menu_item_id LEFT JOIN categories c ON c.id=mi.category_id WHERE o.created_at BETWEEN ? AND ? AND o.status != 'cancelled' GROUP BY c.id, c.name, c.icon ORDER BY revenue DESC");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result(); $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close(); $conn->close();
    jsonResponse($rows);
}

function getByHour() {
    [$from, $to] = getDateRange();
    $conn = getDB();
    $stmt = $conn->prepare("SELECT HOUR(created_at) AS hour, SUM(subtotal) AS revenue, COUNT(id) AS orders FROM orders WHERE created_at BETWEEN ? AND ? AND status != 'cancelled' GROUP BY HOUR(created_at) ORDER BY hour ASC");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result(); $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close(); $conn->close();
    jsonResponse($rows);
}

function getByDay() {
    [$from, $to] = getDateRange();
    $conn = getDB();
    $stmt = $conn->prepare("SELECT DATE(created_at) AS day, DAYNAME(created_at) AS day_name, SUM(subtotal) AS revenue, COUNT(id) AS orders FROM orders WHERE created_at BETWEEN ? AND ? AND status != 'cancelled' GROUP BY DATE(created_at) ORDER BY day ASC");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result(); $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close(); $conn->close();
    jsonResponse($rows);
}

function getOrders() {
    [$from, $to] = getDateRange();
    $conn = getDB();
    $stmt = $conn->prepare("SELECT o.*, COUNT(oi.id) AS item_count FROM orders o LEFT JOIN order_items oi ON o.id=oi.order_id WHERE o.created_at BETWEEN ? AND ? GROUP BY o.id ORDER BY o.created_at DESC LIMIT 50");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result(); $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close(); $conn->close();
    jsonResponse($rows);
}
?>