<?php
/*
 * Padayon Kapetid POS — Settings API
 * Place in: php/settings.php
 *
 * GET  ?action=load           → returns all settings from DB as flat fields
 * POST ?action=save           → saves all settings to DB as flat fields
 * POST ?action=upload_banner  → uploads hero/promo image to disk + DB
 * POST ?action=delete_banner  → removes banner from disk + DB
 */

error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

// Auto-create table on every request (fast no-op if already exists)
getDB()->query("CREATE TABLE IF NOT EXISTS store_settings (
    setting_key   VARCHAR(120) NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT   NOT NULL DEFAULT '',
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? '';
switch ($action) {
    case 'load':          loadSettings();  break;
    case 'save':          saveSettings();  break;
    case 'upload_banner': uploadBanner();  break;
    case 'delete_banner': deleteBanner();  break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

// ── LOAD — returns every row as a flat key→value map ────────────────────────
function loadSettings() {
    $conn   = getDB();
    $result = $conn->query("SELECT setting_key, setting_value FROM store_settings");
    $data   = [];
    while ($row = $result->fetch_assoc()) {
        $v = $row['setting_value'];
        // Try JSON decode; if it fails keep it as a plain string
        $decoded = json_decode($v, true);
        $data[$row['setting_key']] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $v;
    }
    $conn->close();
    jsonResponse(['success' => true, 'settings' => $data]);
}

// ── SAVE — accepts flat key→value pairs, each saved as its own DB row ────────
// Frontend sends: { store_name:"...", store_city:"...", tax_rate:"12", ... }
function saveSettings() {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) jsonResponse(['error' => 'Invalid JSON body'], 400);

    $conn = getDB();
    $stmt = $conn->prepare(
        "INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );

    $saved = 0;
    foreach ($body as $key => $value) {
        // Sanitize key — only letters, numbers, underscore
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
        if (!$key || strlen($key) > 120) continue;
        // Arrays/bools stored as JSON, everything else as string
        if (is_array($value) || is_bool($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        } else {
            $encoded = (string)$value;
        }
        $stmt->bind_param('ss', $key, $encoded);
        $stmt->execute();
        $saved++;
    }
    $stmt->close();
    $conn->close();
    jsonResponse(['success' => true, 'saved' => $saved, 'message' => 'Settings saved to database!']);
}

// ── UPLOAD BANNER ────────────────────────────────────────────────────────────
function uploadBanner() {
    $slot = $_POST['slot'] ?? '';
    if (!in_array($slot, ['hero', 'promo']))
        jsonResponse(['error' => 'Invalid slot — use hero or promo'], 400);

    if (empty($_FILES['image']))
        jsonResponse(['error' => 'No image file received'], 400);

    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory on server.',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
        ];
        jsonResponse(['error' => $msgs[$file['error']] ?? 'Upload error code '.$file['error']], 400);
    }

    // Validate via finfo — don't trust browser-supplied MIME
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mime     = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extMap   = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($extMap[$mime]))
        jsonResponse(['error' => 'Invalid type. Only JPG, PNG, WEBP, GIF allowed.'], 400);
    if ($file['size'] > 5 * 1024 * 1024)
        jsonResponse(['error' => 'File too large — max 5 MB.'], 400);

    // Save to images/banners/ (relative to project root, one level up from php/)
    $bannerDir = dirname(__DIR__) . '/images/banners/';
    if (!is_dir($bannerDir) && !mkdir($bannerDir, 0755, true))
        jsonResponse(['error' => 'Cannot create banners directory — check permissions.'], 500);

    // Remove any previous banner for this slot (all extensions)
    foreach ($extMap as $e) {
        $old = $bannerDir . 'banner_' . $slot . '.' . $e;
        if (file_exists($old)) @unlink($old);
    }

    $filename = 'banner_' . $slot . '.' . $extMap[$mime];
    if (!move_uploaded_file($file['tmp_name'], $bannerDir . $filename))
        jsonResponse(['error' => 'Failed to save file — check folder write permissions.'], 500);

    // Persist URL in DB with cache-bust
    $url  = 'images/banners/' . $filename . '?v=' . time();
    $conn = getDB();
    $stmt = $conn->prepare(
        "INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );
    $key  = 'banner_' . $slot;
    $stmt->bind_param('ss', $key, $url);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    jsonResponse(['success' => true, 'slot' => $slot, 'url' => $url, 'message' => ucfirst($slot).' banner uploaded!']);
}

// ── DELETE BANNER ────────────────────────────────────────────────────────────
function deleteBanner() {
    $body = json_decode(file_get_contents('php://input'), true);
    $slot = $body['slot'] ?? '';
    if (!in_array($slot, ['hero', 'promo']))
        jsonResponse(['error' => 'Invalid slot'], 400);

    $bannerDir = dirname(__DIR__) . '/images/banners/';
    foreach (['jpg','png','webp','gif'] as $e) {
        $f = $bannerDir . 'banner_' . $slot . '.' . $e;
        if (file_exists($f)) @unlink($f);
    }

    $conn = getDB();
    $key  = 'banner_' . $slot;
    $stmt = $conn->prepare("DELETE FROM store_settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    jsonResponse(['success' => true, 'message' => ucfirst($slot).' banner removed.']);
}
?>
