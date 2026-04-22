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

$db = getDB();

try {
    $stmt = $db->prepare('UPDATE device_config SET ota_update_on_sync = 1');
    $stmt->execute();

    echo json_encode([
        'ok'      => true,
        'updated' => $stmt->rowCount(),
    ]);

} catch (PDOException $e) {
    error_log('[SMCR API] DB error in set_ota_all: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
