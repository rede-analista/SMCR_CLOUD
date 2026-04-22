<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

require_once __DIR__ . '/../config/db.php';

$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
if (!$device_id) {
    http_response_code(400);
    exit('device_id obrigatório');
}

$db = getDB();

$stmt = $db->prepare('SELECT id, unique_id, name, api_token FROM devices WHERE id = ?');
$stmt->execute([$device_id]);
$device = $stmt->fetch();
if (!$device) {
    http_response_code(404);
    exit('Dispositivo não encontrado');
}

$stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
$stmt->execute([$device_id]);
$cfg = $stmt->fetch() ?: [];
unset($cfg['device_id']);

$stmt = $db->prepare('SELECT nome, pino, tipo, modo, xor_logic, tempo_retencao,
    nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt
    FROM device_pins WHERE device_id = ? ORDER BY pino ASC');
$stmt->execute([$device_id]);
$pins = array_map(fn($r) => [
    'nome'                  => $r['nome'],
    'pino'                  => (int)$r['pino'],
    'tipo'                  => (int)$r['tipo'],
    'modo'                  => (int)$r['modo'],
    'xor_logic'             => (int)$r['xor_logic'],
    'tempo_retencao'        => (int)$r['tempo_retencao'],
    'nivel_acionamento_min' => (int)$r['nivel_acionamento_min'],
    'nivel_acionamento_max' => (int)$r['nivel_acionamento_max'],
    'classe_mqtt'           => $r['classe_mqtt'],
    'icone_mqtt'            => $r['icone_mqtt'],
], $stmt->fetchAll());

$stmt = $db->prepare('SELECT pino_origem, numero_acao, pino_destino, acao,
    tempo_on, tempo_off, pino_remoto, envia_modulo, telegram, assistente
    FROM device_actions WHERE device_id = ? ORDER BY pino_origem, numero_acao ASC');
$stmt->execute([$device_id]);
$actions = array_map(fn($r) => [
    'pino_origem'  => (int)$r['pino_origem'],
    'numero_acao'  => (int)$r['numero_acao'],
    'pino_destino' => (int)$r['pino_destino'],
    'acao'         => (int)$r['acao'],
    'tempo_on'     => (int)$r['tempo_on'],
    'tempo_off'    => (int)$r['tempo_off'],
    'pino_remoto'  => (int)$r['pino_remoto'],
    'envia_modulo' => $r['envia_modulo'],
    'telegram'     => (int)$r['telegram'],
    'assistente'   => (int)$r['assistente'],
], $stmt->fetchAll());

$stmt = $db->prepare('SELECT module_id, hostname, ip, porta, ativo,
    pins_offline, offline_alert_enabled, offline_flash_ms,
    pins_healthcheck, healthcheck_alert_enabled, healthcheck_flash_ms
    FROM device_intermod WHERE device_id = ? ORDER BY module_id ASC');
$stmt->execute([$device_id]);
$intermod = array_map(fn($r) => [
    'module_id'                 => $r['module_id'],
    'hostname'                  => $r['hostname'],
    'ip'                        => $r['ip'],
    'porta'                     => (int)$r['porta'],
    'ativo'                     => (int)$r['ativo'],
    'pins_offline'              => $r['pins_offline'],
    'offline_alert_enabled'     => (int)$r['offline_alert_enabled'],
    'offline_flash_ms'          => (int)$r['offline_flash_ms'],
    'pins_healthcheck'          => $r['pins_healthcheck'],
    'healthcheck_alert_enabled' => (int)$r['healthcheck_alert_enabled'],
    'healthcheck_flash_ms'      => (int)$r['healthcheck_flash_ms'],
], $stmt->fetchAll());

$export = [
    'smcr_backup'  => true,
    'version'      => 1,
    'exported_at'  => date('Y-m-d H:i:s'),
    'unique_id'    => $device['unique_id'],
    'name'         => $device['name'],
    'api_token'    => $device['api_token'],
    'config'       => $cfg,
    'pins'         => $pins,
    'actions'      => $actions,
    'intermod'     => $intermod,
];

$filename = 'smcr_backup_' . $device['unique_id'] . '_' . date('Ymd_His') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
