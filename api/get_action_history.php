<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
session_init();

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$device_id = (int)($_GET['device_id'] ?? 0);
if (!$device_id) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

$de = trim(str_replace('T', ' ', $_GET['de'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $de)) $de = '';

$ate = trim(str_replace('T', ' ', $_GET['ate'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $ate)) $ate = '';
if ($ate !== '') $ate = substr($ate, 0, 16) . ':59';

$where  = 'WHERE device_id = ?';
$params = [$device_id];
if ($de  !== '') { $where .= ' AND ocorrido_em >= ?'; $params[] = $de; }
if ($ate !== '') { $where .= ' AND ocorrido_em <= ?'; $params[] = $ate; }
$limit = ($de === '' && $ate === '') ? 200 : 1000;

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            gpio_origem,
            gpio_destino,
            tipo,
            valor_pino,
            DATE_FORMAT(ocorrido_em, '%d/%m/%Y %H:%i:%S') AS ts
        FROM device_action_events
        $where
        ORDER BY ocorrido_em DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'events' => $rows]);

} catch (PDOException $e) {
    error_log('[SMCR] get_action_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
