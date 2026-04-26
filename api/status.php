<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Parse JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    json_error('Invalid JSON body');
}

// Extract token: Authorization: Bearer <token> OR token field in JSON
$token = null;

$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($auth_header) && function_exists('apache_request_headers')) {
    $hdrs = apache_request_headers();
    $auth_header = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}
if (str_starts_with($auth_header, 'Bearer ')) {
    $token = trim(substr($auth_header, 7));
}

if (!$token && isset($data['token'])) {
    $token = trim($data['token']);
}

if (!$token) {
    json_error('Missing authentication token', 401);
}

// Validate token length (64 hex chars = 32 bytes)
if (strlen($token) !== 64 || !ctype_xdigit($token)) {
    json_error('Invalid token format', 401);
}

try {
    $db = getDB();

    // Find device by api_token
    $stmt = $db->prepare('SELECT id, unique_id, ativo FROM devices WHERE api_token = ?');
    $stmt->execute([$token]);
    $device = $stmt->fetch();

    if (!$device) {
        json_error('Device not found or invalid token', 401);
    }

    if (!(bool)$device['ativo']) {
        echo json_encode(['ok' => true, 'ignored' => true]);
        exit;
    }

    $device_id = (int)$device['id'];

    // Extract status fields with safe defaults
    $ip               = isset($data['ip'])               ? substr(trim((string)$data['ip']), 0, 45)        : '';
    $hostname         = isset($data['hostname'])         ? substr(trim((string)$data['hostname']), 0, 64)   : '';
    $firmware_version = isset($data['firmware_version']) ? substr(trim((string)$data['firmware_version']), 0, 20) : '';
    $free_heap        = isset($data['free_heap'])        ? (int)$data['free_heap']                         : 0;
    $uptime_ms        = isset($data['uptime_ms'])        ? (int)$data['uptime_ms']                         : 0;
    $wifi_rssi        = isset($data['wifi_rssi'])        ? (int)$data['wifi_rssi']                         : 0;
    $sketch_size      = isset($data['sketch_size'])      ? (int)$data['sketch_size']                       : 0;
    $sketch_free      = isset($data['sketch_free'])      ? (int)$data['sketch_free']                       : 0;

    // UPSERT device_status
    $stmt = $db->prepare('
        INSERT INTO device_status (device_id, ip, hostname, firmware_version, free_heap, uptime_ms, wifi_rssi, sketch_size, sketch_free)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ip               = VALUES(ip),
            hostname         = VALUES(hostname),
            firmware_version = VALUES(firmware_version),
            free_heap        = VALUES(free_heap),
            uptime_ms        = VALUES(uptime_ms),
            wifi_rssi        = VALUES(wifi_rssi),
            sketch_size      = VALUES(sketch_size),
            sketch_free      = VALUES(sketch_free),
            updated_at       = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$device_id, $ip, $hostname, $firmware_version, $free_heap, $uptime_ms, $wifi_rssi, $sketch_size, $sketch_free]);

    // Check previous online state to detect offline→online transition
    $stmt = $db->prepare('SELECT online FROM devices WHERE id = ?');
    $stmt->execute([$device_id]);
    $prev_online = (bool)$stmt->fetchColumn();

    // Update device last_seen and online flag
    $stmt = $db->prepare('UPDATE devices SET last_seen = NOW(), online = 1 WHERE id = ?');
    $stmt->execute([$device_id]);

    // Log online transition event
    if (!$prev_online) {
        $stmt = $db->prepare('INSERT INTO device_events (device_id, event) VALUES (?, \'online\')');
        $stmt->execute([$device_id]);
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'device_id' => $device_id,
        'unique_id' => $device['unique_id'],
    ]);

} catch (PDOException $e) {
    error_log('[SMCR API] DB error: ' . $e->getMessage());
    json_error('Database error', 500);
} catch (Exception $e) {
    error_log('[SMCR API] Error: ' . $e->getMessage());
    json_error('Internal server error', 500);
}
