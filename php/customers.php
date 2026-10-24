<?php
/*
 * Padayon Kapetid POS — Customer Directory API
 * Place this file in: php/customers.php
 *
 * DB Tables created on first run (auto-migrate):
 *   customers     — customer profiles
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit; }

require_once 'db.php';

// Auto-create customers table
function ensureTables() {
    $conn = getDB();
    $conn->query("CREATE TABLE IF NOT EXISTS customers (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(150) NOT NULL,
        phone         VARCHAR(30)  NULL,
        email         VARCHAR(120) NULL,
        address       TEXT         NULL,
        birthday      DATE         NULL,
        notes         TEXT         NULL,
        loyalty_pts   INT          NOT NULL DEFAULT 0,
        total_spent   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        visit_count   INT          NOT NULL DEFAULT 0,
        last_visit    DATETIME     NULL,
        is_active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->close();
}

ensureTables();
ob_end_clean();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'list':        listCustomers();    break;
    case 'get':         getCustomer();      break;
    case 'search':      searchCustomers();  break;
    case 'create':      createCustomer();   break;
    case 'update':      updateCustomer();   break;
    case 'delete':      deleteCustomer();   break;
    case 'add_points':        addPoints();          break;
    case 'record_visit':      recordVisit();        break;
    case 'summary':           getSummary();         break;
    case 'import_from_orders':importFromOrders();   break;
    default: jsonResponse(['error' => 'Unknown action: ' . htmlspecialchars($action)], 400);
}

// ── LIST CUSTOMERS ────────────────────────────────────────────────
function listCustomers() {
    $conn   = getDB();
    $limit  = intval($_GET['limit']  ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $sort   = in_array($_GET['sort'] ?? '', ['name','total_spent','visit_count','last_visit','loyalty_pts','created_at'])
              ? $_GET['sort'] : 'name';
    $dir    = ($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    $active = isset($_GET['active']) ? intval($_GET['active']) : 1;

    $stmt = $conn->prepare("SELECT * FROM customers WHERE is_active=? ORDER BY $sort $dir LIMIT ? OFFSET ?");
    $stmt->bind_param('iii', $active, $limit, $offset);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    // Total count for pagination
    $cnt = $conn->prepare("SELECT COUNT(*) AS total FROM customers WHERE is_active=?");
    $cnt->bind_param('i', $active);
    $cnt->execute();
    $total = $cnt->get_result()->fetch_assoc()['total'];
    $cnt->close();
    $conn->close();

    jsonResponse(['customers' => $rows, 'total' => intval($total)]);
}

// ── SEARCH ───────────────────────────────────────────────────────
function searchCustomers() {
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $conn = getDB();
    $stmt = $conn->prepare("SELECT * FROM customers WHERE is_active=1 AND (name LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY name ASC LIMIT 30");
    $stmt->bind_param('sss', $q, $q, $q);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    jsonResponse($rows);
}

// ── GET SINGLE CUSTOMER ───────────────────────────────────────────
function getCustomer() {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'id required'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    if (!$row) jsonResponse(['error' => 'Customer not found'], 404);
    jsonResponse($row);
}

// ── CREATE CUSTOMER ───────────────────────────────────────────────
function createCustomer() {
    $d       = json_decode(file_get_contents('php://input'), true);
    $name    = trim($d['name']    ?? '');
    $phone   = trim($d['phone']   ?? '');
    $email   = trim($d['email']   ?? '');
    $address = trim($d['address'] ?? '');
    $birthday= trim($d['birthday']?? '');
    $notes   = trim($d['notes']   ?? '');

    if (!$name) jsonResponse(['error' => 'Customer name is required'], 400);

    // Validate email if provided
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
        jsonResponse(['error' => 'Invalid email format'], 400);

    $conn = getDB();

    // Check duplicate phone
    if ($phone) {
        $chk = $conn->prepare("SELECT id FROM customers WHERE phone=?");
        $chk->bind_param('s', $phone);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $conn->close();
            jsonResponse(['error' => 'Phone number already registered to another customer'], 409);
        }
        $chk->close();
    }

    $bday = $birthday ?: null;
    $stmt = $conn->prepare("INSERT INTO customers (name, phone, email, address, birthday, notes) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('ssssss', $name, $phone, $email, $address, $bday, $notes);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'id' => $id, 'message' => "$name added to directory!"]);
}

// ── UPDATE CUSTOMER ───────────────────────────────────────────────
function updateCustomer() {
    $d       = json_decode(file_get_contents('php://input'), true);
    $id      = intval($d['id']      ?? 0);
    $name    = trim($d['name']      ?? '');
    $phone   = trim($d['phone']     ?? '');
    $email   = trim($d['email']     ?? '');
    $address = trim($d['address']   ?? '');
    $birthday= trim($d['birthday']  ?? '');
    $notes   = trim($d['notes']     ?? '');
    $is_active = isset($d['is_active']) ? intval((bool)$d['is_active']) : 1;

    if (!$id || !$name) jsonResponse(['error' => 'id and name required'], 400);
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
        jsonResponse(['error' => 'Invalid email format'], 400);

    $conn = getDB();

    // Check duplicate phone (exclude self)
    if ($phone) {
        $chk = $conn->prepare("SELECT id FROM customers WHERE phone=? AND id!=?");
        $chk->bind_param('si', $phone, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $conn->close();
            jsonResponse(['error' => 'Phone number already registered to another customer'], 409);
        }
        $chk->close();
    }

    $bday = $birthday ?: null;
    $stmt = $conn->prepare("UPDATE customers SET name=?, phone=?, email=?, address=?, birthday=?, notes=?, is_active=? WHERE id=?");
    $stmt->bind_param('ssssssii', $name, $phone, $email, $address, $bday, $notes, $is_active, $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'message' => "$name updated!"]);
}

// ── DELETE CUSTOMER ───────────────────────────────────────────────
function deleteCustomer() {
    $d  = json_decode(file_get_contents('php://input'), true);
    $id = intval($d['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Invalid ID'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("DELETE FROM customers WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'message' => 'Customer deleted']);
}

// ── ADD LOYALTY POINTS ─────────────────────────────────────────────
function addPoints() {
    $d      = json_decode(file_get_contents('php://input'), true);
    $id     = intval($d['id']     ?? 0);
    $points = intval($d['points'] ?? 0);
    if (!$id || $points === 0) jsonResponse(['error' => 'id and points required'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("UPDATE customers SET loyalty_pts = loyalty_pts + ? WHERE id=?");
    $stmt->bind_param('ii', $points, $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'message' => 'Points updated']);
}

// ── RECORD VISIT ──────────────────────────────────────────────────
function recordVisit() {
    $d      = json_decode(file_get_contents('php://input'), true);
    $id     = intval($d['id']     ?? 0);
    $spent  = floatval($d['spent'] ?? 0);
    $pts    = intval(floor($spent / 50)); // 1 point per ₱50 spent
    if (!$id) jsonResponse(['error' => 'id required'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("UPDATE customers SET visit_count=visit_count+1, last_visit=NOW(), total_spent=total_spent+?, loyalty_pts=loyalty_pts+? WHERE id=?");
    $stmt->bind_param('dii', $spent, $pts, $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'points_earned' => $pts]);
}

// ── SUMMARY KPIs ──────────────────────────────────────────────────
function getSummary() {
    $conn = getDB();
    $row = $conn->query("SELECT
        COUNT(*) AS total_customers,
        SUM(is_active=1) AS active_customers,
        COALESCE(SUM(total_spent),0) AS total_revenue,
        COALESCE(SUM(loyalty_pts),0) AS total_pts,
        COALESCE(AVG(total_spent),0) AS avg_spent,
        SUM(last_visit >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent_visitors
        FROM customers")->fetch_assoc();
    $conn->close();
    jsonResponse($row);
}
// ── IMPORT CUSTOMERS FROM ORDER HISTORY ──────────────────────────
// Reads every distinct customer_name from orders, creates a customers
// row for each one that doesn't already exist, back-fills stats
// (total_spent, visit_count, loyalty_pts, last_visit) and links the
// orders rows to the new customer_id so future completions update stats.
function importFromOrders() {
    $conn = getDB();

    // Summarise each unique non-guest name from orders
    $res = $conn->query("
        SELECT
            customer_name,
            COUNT(*)                                             AS order_count,
            COALESCE(SUM(CASE WHEN status='completed' THEN subtotal ELSE 0 END), 0) AS total_spent,
            MAX(created_at)                                      AS last_visit
        FROM orders
        WHERE customer_name IS NOT NULL
          AND TRIM(customer_name) != ''
          AND LOWER(TRIM(customer_name)) NOT IN ('guest','unknown','-','n/a')
        GROUP BY customer_name
    ");

    if (!$res) {
        $conn->close();
        jsonResponse(['error' => 'Failed to query orders table'], 500);
    }

    $imported = 0;
    $skipped  = 0;
    $linked   = 0;

    while ($row = $res->fetch_assoc()) {
        $name      = trim($row['customer_name']);
        $spent     = floatval($row['total_spent']);
        $visits    = intval($row['order_count']);
        $pts       = intval(floor($spent / 50));   // 1 pt per ₱50 spent
        $lastVisit = $row['last_visit'];

        // Skip if a customer with this exact name already exists
        $chk = $conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $chk->bind_param('s', $name);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing) {
            $cid = intval($existing['id']);
            $skipped++;
        } else {
            // Insert new customer record
            $ins = $conn->prepare(
                "INSERT INTO customers (name, total_spent, visit_count, loyalty_pts, last_visit)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $ins->bind_param('sdii' . ($lastVisit ? 's' : 's'), $name, $spent, $visits, $pts, $lastVisit);
            $ins->execute();
            $cid = $conn->insert_id;
            $ins->close();
            $imported++;
        }

        // Back-link all orders that still have customer_id = 0 for this name
        $upd = $conn->prepare(
            "UPDATE orders SET customer_id = ?
             WHERE LOWER(TRIM(customer_name)) = LOWER(TRIM(?))
               AND (customer_id IS NULL OR customer_id = 0)"
        );
        $upd->bind_param('is', $cid, $name);
        $upd->execute();
        $linked += $upd->affected_rows;
        $upd->close();
    }

    $conn->close();
    jsonResponse([
        'success'  => true,
        'imported' => $imported,
        'skipped'  => $skipped,
        'linked'   => $linked,
        'message'  => "Done! {$imported} new customer(s) imported, {$skipped} already existed, {$linked} order(s) linked."
    ]);
}

?>
