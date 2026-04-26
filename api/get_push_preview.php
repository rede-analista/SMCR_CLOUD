<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth.php';
session_init();

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

$input     = json_decode(file_get_contents('php://input'), true);
$device_id = (int)($input['device_id'] ?? 0);
if (!$device_id) { echo json_encode(['ok' => false, 'error' => 'device_id obrigatório']); exit; }

try {
    $db = getDB();

    $stmt = $db->prepare('SELECT d.*, ds.ip, ds.firmware_version, ds.free_heap, ds.sketch_size, ds.sketch_free FROM devices d LEFT JOIN device_status ds ON ds.device_id = d.id WHERE d.id = ?');
    $stmt->execute([$device_id]);
    $device = $stmt->fetch();
    if (!$device) { echo json_encode(['ok' => false, 'error' => 'Dispositivo não encontrado']); exit; }

    $stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $cfg = $stmt->fetch() ?: [];

    $stmt = $db->prepare('SELECT COUNT(*) FROM device_pins WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $pins_count = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM device_actions WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $actions_count = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM device_intermod WHERE device_id = ? AND ativo = 1');
    $stmt->execute([$device_id]);
    $intermod_count = (int)$stmt->fetchColumn();

    $sk_size  = (int)($device['sketch_size'] ?? 0);
    $sk_free  = (int)($device['sketch_free'] ?? 0);
    $sk_total = $sk_size + $sk_free;
    $sk_pct   = $sk_total > 0 ? round($sk_size / $sk_total * 100, 1) : null;

    echo json_encode([
        'ok' => true,
        'device' => [
            'name'             => $device['name'],
            'unique_id'        => $device['unique_id'],
            'ip'               => $device['ip'] ?? '',
            'online'           => (bool)$device['online'],
            'firmware_version' => $device['firmware_version'] ?? '',
            'free_heap_kb'     => $device['free_heap'] ? round((int)$device['free_heap'] / 1024, 1) : null,
            'flash_pct'        => $sk_pct,
        ],
        'config' => [
            'mqtt_enabled'   => (bool)($cfg['mqtt_enabled']   ?? false),
            'mqtt_server'    => $cfg['mqtt_server']            ?? '',
            'telegram_enabled' => (bool)($cfg['telegram_enabled'] ?? false),
            'cloud_url'      => ($cfg['cloud_url'] ?? '') . ':' . ($cfg['cloud_port'] ?? 80),
            'cloud_sync'     => (bool)($cfg['cloud_sync_enabled'] ?? false),
            'cloud_https'    => (bool)($cfg['cloud_use_https'] ?? false),
            'ota_enabled'    => (bool)($cfg['ota_update_on_sync'] ?? false),
            'reboot_on_sync' => (bool)($cfg['reboot_on_sync'] ?? false),
        ],
        'counts' => [
            'pins'    => $pins_count,
            'actions' => $actions_count,
            'intermod'=> $intermod_count,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
