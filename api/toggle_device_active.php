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

if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'device_id inválido']);
    exit;
}

$db = getDB();

$stmt = $db->prepare('SELECT id, ativo FROM devices WHERE id = ?');
$stmt->execute([$device_id]);
$device = $stmt->fetch();

if (!$device) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Dispositivo não encontrado']);
    exit;
}

try {
    $novo = $device['ativo'] ? 0 : 1;
    $db->prepare('UPDATE devices SET ativo = ? WHERE id = ?')->execute([$novo, $device_id]);

    echo json_encode(['ok' => true, 'device_id' => $device_id, 'ativo' => (bool)$novo]);

} catch (PDOException $e) {
    error_log('[SMCR API] DB error in toggle_device_active: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
