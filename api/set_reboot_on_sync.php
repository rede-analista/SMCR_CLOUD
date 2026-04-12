<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth.php';
session_init();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$device_id = isset($body['device_id']) ? (int)$body['device_id'] : 0;
$enable    = isset($body['enable']) ? (bool)$body['enable'] : true;

if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'device_id inválido']);
    exit;
}

$db = getDB();

$stmt = $db->prepare('SELECT id FROM devices WHERE id = ?');
$stmt->execute([$device_id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Dispositivo não encontrado']);
    exit;
}

try {
    $db->prepare('UPDATE device_config SET reboot_on_sync = ? WHERE device_id = ?')
       ->execute([$enable ? 1 : 0, $device_id]);

    echo json_encode([
        'ok'             => true,
        'device_id'      => $device_id,
        'reboot_on_sync' => $enable,
    ]);

} catch (PDOException $e) {
    error_log('[SMCR API] DB error in set_reboot_on_sync: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
