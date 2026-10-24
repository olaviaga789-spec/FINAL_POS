<?php
/*
 * Padayon Kapetid POS — Shifts & Cash Log API
 * Place this file in: php/shifts.php
 *
 * DB Tables created on first run (auto-migrate):
 *   shifts        — shift records (open/close, float, totals)
 *   cash_log      — individual cash movements per shift
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

// Auto-create tables if they don't exist
function ensureTables() {
    $conn = getDB();

    $conn->query("CREATE TABLE IF NOT EXISTS shifts (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        cashier_id    INT NOT NULL DEFAULT 0,
        cashier_name  VARCHAR(150) NOT NULL DEFAULT 'Unknown',
        opened_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at     DATETIME NULL,
        opening_float DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        closing_float DECIMAL(10,2) NULL,
        expected_cash DECIMAL(10,2) NULL,
        cash_variance DECIMAL(10,2) NULL,
        total_sales   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_orders  INT NOT NULL DEFAULT 0,
        notes         TEXT NULL,
        status        ENUM('open','closed') NOT NULL DEFAULT 'open',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS cash_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        shift_id    INT NOT NULL,
        type        ENUM('float_in','sale','cash_in','cash_out','refund','adjustment') NOT NULL,
        amount      DECIMAL(10,2) NOT NULL,
        description VARCHAR(255) NULL,
        recorded_by VARCHAR(150) NOT NULL DEFAULT 'System',
        order_id    INT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->close();
}

ensureTables();
ob_end_clean();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'open_shift':        openShift();        break;
    case 'close_shift':       closeShift();       break;
    case 'current_shift':     getCurrentShift();  break;
    case 'list_shifts':       listShifts();       break;
    case 'get_shift':         getShift();         break;
    case 'shift_sales':       getShiftSales();    break;  // live sales for a shift
    case 'add_cash_movement': addCashMovement();  break;
    case 'cash_log':          getCashLog();       break;
    case 'summary':           getShiftSummary();  break;
    default: jsonResponse(['error' => 'Unknown action: ' . htmlspecialchars($action)], 400);
}

// ── OPEN SHIFT ────────────────────────────────────────────────────
function openShift() {
    $d            = json_decode(file_get_contents('php://input'), true);
    $cashier_id   = intval($d['cashier_id']   ?? 0);
    $cashier_name = trim($d['cashier_name']   ?? 'Unknown');
    $opening_float = floatval($d['opening_float'] ?? 0);
    $notes        = trim($d['notes']          ?? '');

    // Check if this cashier already has an open shift
    $conn = getDB();
    $chk = $conn->prepare("SELECT id FROM shifts WHERE cashier_id=? AND status='open'");
    $chk->bind_param('i', $cashier_id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($existing) {
        $conn->close();
        jsonResponse(['error' => 'You already have an open shift. Close it first.', 'shift_id' => $existing['id']], 409);
    }

    $stmt = $conn->prepare("INSERT INTO shifts (cashier_id, cashier_name, opening_float, notes, status) VALUES (?,?,?,?,'open')");
    $stmt->bind_param('isds', $cashier_id, $cashier_name, $opening_float, $notes);
    $stmt->execute();
    $shift_id = $conn->insert_id;
    $stmt->close();

    // Log the opening float as cash_in
    if ($opening_float > 0) {
        $desc = 'Opening float';
        $by   = $cashier_name;
        $logStmt = $conn->prepare("INSERT INTO cash_log (shift_id, type, amount, description, recorded_by) VALUES (?,'float_in',?,?,?)");
        $logStmt->bind_param('idss', $shift_id, $opening_float, $desc, $by);
        $logStmt->execute();
        $logStmt->close();
    }

    $conn->close();
    jsonResponse(['success' => true, 'shift_id' => $shift_id, 'message' => 'Shift opened successfully!']);
}

// ── CLOSE SHIFT ───────────────────────────────────────────────────
function closeShift() {
    $d             = json_decode(file_get_contents('php://input'), true);
    $shift_id      = intval($d['shift_id']      ?? 0);
    $closing_float = floatval($d['closing_float'] ?? 0);
    $notes         = trim($d['notes']            ?? '');

    if (!$shift_id) jsonResponse(['error' => 'shift_id required'], 400);

    $conn = getDB();

    // Get the shift record
    $stmt = $conn->prepare("SELECT * FROM shifts WHERE id=? AND status='open'");
    $stmt->bind_param('i', $shift_id);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$shift) { $conn->close(); jsonResponse(['error' => 'Shift not found or already closed'], 404); }

    // Count completed orders since shift opened (consistent with getShiftSales)
    $sales_stmt = $conn->prepare(
        "SELECT COALESCE(SUM(subtotal),0) AS calc_sales, COUNT(*) AS calc_orders
         FROM orders
         WHERE status='completed' AND created_at >= ?"
    );
    $sales_stmt->bind_param('s', $shift['opened_at']);
    $sales_stmt->execute();
    $sales_row    = $sales_stmt->get_result()->fetch_assoc();
    $sales_stmt->close();

    $total_sales   = floatval($sales_row['calc_sales']);
    $total_orders  = intval($sales_row['calc_orders']);
    $expected_cash = floatval($shift['opening_float']) + $total_sales;
    $cash_variance = $closing_float - $expected_cash;

    $upd = $conn->prepare("UPDATE shifts SET status='closed', closed_at=NOW(), closing_float=?, expected_cash=?, cash_variance=?, total_sales=?, total_orders=?, notes=CASE WHEN ? != '' THEN CONCAT(TRIM(IFNULL(notes,'')), IF(notes IS NOT NULL AND notes != '', ' | ', ''), ?) ELSE notes END WHERE id=?");
    $upd->bind_param('ddddissi', $closing_float, $expected_cash, $cash_variance, $total_sales, $total_orders, $notes, $notes, $shift_id);
    $upd->execute();
    $upd->close();
    $conn->close();

    jsonResponse([
        'success'       => true,
        'total_sales'   => $total_sales,
        'total_orders'  => $total_orders,
        'expected_cash' => $expected_cash,
        'cash_variance' => $cash_variance,
        'message'       => 'Shift closed successfully!'
    ]);
}

// ── GET CURRENT OPEN SHIFT ────────────────────────────────────────
function getCurrentShift() {
    $cashier_id = intval($_GET['cashier_id'] ?? 0);
    $conn = getDB();
    $where = $cashier_id ? "WHERE status='open' AND cashier_id=?" : "WHERE status='open'";
    if ($cashier_id) {
        $stmt = $conn->prepare("SELECT * FROM shifts $where ORDER BY opened_at DESC LIMIT 1");
        $stmt->bind_param('i', $cashier_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM shifts $where ORDER BY opened_at DESC LIMIT 1");
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    jsonResponse($row ?: null);
}

// ── LIST SHIFTS ───────────────────────────────────────────────────
function listShifts() {
    $conn = getDB();
    $limit  = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null;

    if ($status) {
        $stmt = $conn->prepare("SELECT * FROM shifts WHERE status=? ORDER BY opened_at DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('sii', $status, $limit, $offset);
    } else {
        $stmt = $conn->prepare("SELECT * FROM shifts ORDER BY opened_at DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('ii', $limit, $offset);
    }
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    jsonResponse($rows);
}

// ── GET SINGLE SHIFT ──────────────────────────────────────────────
function getShift() {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'id required'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("SELECT * FROM shifts WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    if (!$row) jsonResponse(['error' => 'Shift not found'], 404);
    jsonResponse($row);
}

// ── ADD CASH MOVEMENT ─────────────────────────────────────────────
function addCashMovement() {
    $d           = json_decode(file_get_contents('php://input'), true);
    $shift_id    = intval($d['shift_id']    ?? 0);
    $type        = $d['type']        ?? '';
    $amount      = floatval($d['amount']    ?? 0);
    $description = trim($d['description']  ?? '');
    $recorded_by = trim($d['recorded_by']  ?? 'Admin');
    $order_id    = intval($d['order_id']   ?? 0) ?: null;

    $allowed = ['float_in','sale','cash_in','cash_out','refund','adjustment'];
    if (!$shift_id || !in_array($type, $allowed) || $amount == 0)
        jsonResponse(['error' => 'shift_id, valid type, and non-zero amount required'], 400);

    $conn = getDB();
    $stmt = $conn->prepare("INSERT INTO cash_log (shift_id, type, amount, description, recorded_by, order_id) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('isdssi', $shift_id, $type, $amount, $description, $recorded_by, $order_id);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'id' => $id, 'message' => 'Cash movement recorded!']);
}

// ── GET CASH LOG FOR A SHIFT ──────────────────────────────────────
function getCashLog() {
    $shift_id = intval($_GET['shift_id'] ?? 0);
    if (!$shift_id) jsonResponse(['error' => 'shift_id required'], 400);
    $conn = getDB();
    $stmt = $conn->prepare("SELECT * FROM cash_log WHERE shift_id=? ORDER BY created_at ASC");
    $stmt->bind_param('i', $shift_id);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    jsonResponse($rows);
}

// ── LIVE SALES TOTAL FOR A SHIFT (used by close-shift modal) ─────
function getShiftSales() {
    $shift_id = intval($_GET['shift_id'] ?? 0);
    if (!$shift_id) jsonResponse(['error' => 'shift_id required'], 400);

    $conn = getDB();

    // Get the shift open time
    $s = $conn->prepare("SELECT opened_at, cashier_id FROM shifts WHERE id=?");
    $s->bind_param('i', $shift_id);
    $s->execute();
    $shift = $s->get_result()->fetch_assoc();
    $s->close();

    if (!$shift) { $conn->close(); jsonResponse(['error' => 'Shift not found'], 404); }

    // Sum completed orders placed since the shift opened
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(subtotal),0) AS total_sales, COUNT(*) AS total_orders
         FROM orders
         WHERE status='completed'
           AND created_at >= ?"
    );
    $stmt->bind_param('s', $shift['opened_at']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    jsonResponse([
        'shift_id'     => $shift_id,
        'total_sales'  => floatval($row['total_sales']),
        'total_orders' => intval($row['total_orders']),
    ]);
}

// ── SHIFT SUMMARY (for dashboard widget) ─────────────────────────
function getShiftSummary() {
    $conn = getDB();

    // 1. Base shift counts and closed-shift stored totals (today)
    $stmt = $conn->query("SELECT
        COUNT(*)                          AS total_shifts,
        COALESCE(SUM(total_sales),0)      AS closed_sales,
        COALESCE(SUM(total_orders),0)     AS closed_orders,
        COALESCE(SUM(cash_variance),0)    AS total_variance,
        MIN(opened_at)                    AS earliest_open
        FROM shifts
        WHERE DATE(opened_at) = CURDATE()");
    $row = $stmt->fetch_assoc();

    // 2. Live sales from orders placed today (captures open-shift sales not yet stored)
    $live = $conn->query("SELECT
        COALESCE(SUM(subtotal),0) AS live_sales,
        COUNT(*)                  AS live_orders
        FROM orders
        WHERE status = 'completed'
          AND DATE(created_at) = CURDATE()")->fetch_assoc();

    // 3. Combine: use whichever is larger to avoid double-counting edge cases
    //    (closed shifts already wrote totals; open shifts need live query)
    $total_sales  = max(floatval($row['closed_sales']),  floatval($live['live_sales']));
    $total_orders = max(intval($row['closed_orders']),   intval($live['live_orders']));

    // 4. Open shift count
    $open = $conn->query("SELECT COUNT(*) AS cnt FROM shifts WHERE status='open'")->fetch_assoc();

    $conn->close();
    jsonResponse([
        'total_shifts'   => intval($row['total_shifts']),
        'total_sales'    => $total_sales,
        'total_orders'   => $total_orders,
        'total_variance' => floatval($row['total_variance']),
        'open_shifts'    => intval($open['cnt']),
    ]);
}
?>
