<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
session_init();

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$device_id = (int)($_GET['device_id'] ?? 0);
if (!$device_id) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

try {
    $db = getDB();

    $stmt = $db->prepare('
        SELECT
            gpio_origem,
            gpio_destino,
            tipo,
            valor_pino,
            DATE_FORMAT(ocorrido_em, \'%d/%m/%Y %H:%i:%S\') AS ts
        FROM device_action_events
        WHERE device_id = ?
        ORDER BY ocorrido_em DESC
        LIMIT 200
    ');
    $stmt->execute([$device_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'events' => $rows]);

} catch (PDOException $e) {
    error_log('[SMCR] get_action_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
