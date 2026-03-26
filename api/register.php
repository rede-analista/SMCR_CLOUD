<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    json_error('Invalid JSON body');
}

// Required fields
$unique_id      = isset($data['unique_id'])      ? trim((string)$data['unique_id'])      : '';
$register_token = isset($data['register_token']) ? trim((string)$data['register_token']) : '';

if ($unique_id === '') {
    json_error('unique_id is required');
}
if ($register_token === '') {
    json_error('register_token is required', 401);
}
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $unique_id)) {
    json_error('unique_id contains invalid characters');
}

// Optional fields
$hostname = isset($data['hostname']) ? substr(trim((string)$data['hostname']), 0, 64) : '';
$ip       = isset($data['ip'])       ? substr(trim((string)$data['ip']),       0, 45) : '';
$port     = isset($data['port'])     ? (int)$data['port']                             : 8080;
$firmware = isset($data['firmware_version']) ? substr(trim((string)$data['firmware_version']), 0, 20) : '';

try {
    $db = getDB();

    // Validate register_token against cloud settings
    $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'register_token'");
    $stmt->execute();
    $setting = $stmt->fetch();

    if (!$setting || !hash_equals($setting['value'], $register_token)) {
        json_error('Invalid register_token', 401);
    }

    // Check if device already exists
    $stmt = $db->prepare('SELECT id, api_token FROM devices WHERE unique_id = ?');
    $stmt->execute([$unique_id]);
    $device = $stmt->fetch();

    if ($device) {
        // Device already registered — update status and return existing token
        $device_id = (int)$device['id'];

        // Update name if provided and empty
        if ($hostname !== '') {
            $stmt = $db->prepare("UPDATE devices SET last_seen = NOW(), online = 1 WHERE id = ?");
            $stmt->execute([$device_id]);
        }

        // UPSERT status
        $stmt = $db->prepare('
            INSERT INTO device_status (device_id, ip, hostname, firmware_version)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ip               = VALUES(ip),
                hostname         = VALUES(hostname),
                firmware_version = VALUES(firmware_version),
                updated_at       = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$device_id, $ip, $hostname, $firmware]);

        $stmt = $db->prepare('UPDATE devices SET last_seen = NOW(), online = 1 WHERE id = ?');
        $stmt->execute([$device_id]);

        echo json_encode([
            'ok'        => true,
            'registered' => false,
            'api_token' => $device['api_token'],
            'device_id' => $device_id,
            'message'   => 'Device already registered',
        ]);
        exit;
    }

    // New device — auto-register
    $api_token = bin2hex(random_bytes(32));
    $name      = $hostname ?: $unique_id;

    $db->beginTransaction();

    $stmt = $db->prepare('INSERT INTO devices (unique_id, name, api_token, last_seen, online) VALUES (?, ?, ?, NOW(), 1)');
    $stmt->execute([$unique_id, $name, $api_token]);
    $device_id = (int)$db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO device_config (device_id, hostname) VALUES (?, ?)');
    $stmt->execute([$device_id, $hostname ?: 'esp32modularx']);

    $stmt = $db->prepare('INSERT INTO device_status (device_id, ip, hostname, firmware_version) VALUES (?, ?, ?, ?)');
    $stmt->execute([$device_id, $ip, $hostname, $firmware]);

    $db->commit();

    echo json_encode([
        'ok'         => true,
        'registered' => true,
        'api_token'  => $api_token,
        'device_id'  => $device_id,
        'message'    => 'Device registered successfully',
    ]);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[SMCR register] DB error: ' . $e->getMessage());
    json_error('Database error', 500);
}
