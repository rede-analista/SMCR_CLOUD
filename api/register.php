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

// Identification fields
$hostname      = isset($data['hostname'])         ? substr(trim((string)$data['hostname']),         0, 64) : '';
$ip            = isset($data['ip'])               ? substr(trim((string)$data['ip']),               0, 45) : '';
$port          = isset($data['port'])             ? (int)$data['port']                                     : 8080;
$firmware      = isset($data['firmware_version']) ? substr(trim((string)$data['firmware_version']), 0, 20) : '';
$wifi_ssid     = isset($data['wifi_ssid'])        ? substr(trim((string)$data['wifi_ssid']),        0, 64) : '';
$wifi_attempts = isset($data['wifi_attempts'])    ? max(1, (int)$data['wifi_attempts'])                    : 4;
$qtd_pinos     = isset($data['qtd_pinos'])        ? max(1, min(255, (int)$data['qtd_pinos']))              : 25;

// Cloud connection fields (ESP is source of truth for these)
$cloud_port                   = isset($data['cloud_port'])                   ? max(1, min(65535, (int)$data['cloud_port'])) : 443;
$cloud_use_https              = isset($data['cloud_use_https'])              ? (int)(bool)$data['cloud_use_https']          : 0;
$cloud_sync_enabled           = isset($data['cloud_sync_enabled'])           ? (int)(bool)$data['cloud_sync_enabled']       : 1;
$cloud_sync_interval_min      = isset($data['cloud_sync_interval_min'])      ? max(1, (int)$data['cloud_sync_interval_min']) : 5;
$cloud_heartbeat_enabled      = isset($data['cloud_heartbeat_enabled'])      ? (int)(bool)$data['cloud_heartbeat_enabled']  : 1;
$cloud_heartbeat_interval_min = isset($data['cloud_heartbeat_interval_min']) ? max(1, (int)$data['cloud_heartbeat_interval_min']) : 5;

// Config arrays from ESP
$pins             = isset($data['pins'])             && is_array($data['pins'])             ? $data['pins']             : [];
$actions          = isset($data['actions'])          && is_array($data['actions'])          ? $data['actions']          : [];
$intermod_modules = isset($data['intermod_modules']) && is_array($data['intermod_modules']) ? $data['intermod_modules'] : [];

try {
    $db = getDB();

    // Validate register_token
    $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'register_token'");
    $stmt->execute();
    $setting = $stmt->fetch();

    if (!$setting || !hash_equals($setting['value'], $register_token)) {
        json_error('Invalid register_token', 401);
    }

    // Check if device already exists
    $stmt = $db->prepare('SELECT id, api_token, ativo FROM devices WHERE unique_id = ?');
    $stmt->execute([$unique_id]);
    $device = $stmt->fetch();

    $db->beginTransaction();

    if ($device) {
        $device_id = (int)$device['id'];

        // Gera novo token apenas se o dispositivo não tinha token (ex: descoberto via mDNS)
        $api_token = $device['api_token'];
        if (empty($api_token)) {
            $api_token = bin2hex(random_bytes(32));
            $db->prepare("UPDATE devices SET api_token = ?, last_seen = NOW(), online = 1 WHERE id = ?")
               ->execute([$api_token, $device_id]);
        } else {
            $db->prepare("UPDATE devices SET last_seen = NOW(), online = 1 WHERE id = ?")
               ->execute([$device_id]);
        }

        // Atualiza device_status
        $db->prepare('
            INSERT INTO device_status (device_id, ip, hostname, firmware_version)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ip               = VALUES(ip),
                hostname         = VALUES(hostname),
                firmware_version = VALUES(firmware_version),
                updated_at       = CURRENT_TIMESTAMP
        ')->execute([$device_id, $ip, $hostname, $firmware]);

        // Atualiza campos de configuração cloud no device_config
        $db->prepare("
            UPDATE device_config SET
                web_server_port              = ?,
                cloud_port                   = ?,
                cloud_use_https              = ?,
                cloud_sync_enabled           = ?,
                cloud_sync_interval_min      = ?,
                cloud_heartbeat_enabled      = ?,
                cloud_heartbeat_interval_min = ?
            WHERE device_id = ?
        ")->execute([
            $port, $cloud_port, $cloud_use_https,
            $cloud_sync_enabled, $cloud_sync_interval_min,
            $cloud_heartbeat_enabled, $cloud_heartbeat_interval_min,
            $device_id,
        ]);

        $registered = false;
        $message    = 'Device already registered';
    } else {
        // Novo dispositivo
        $api_token = bin2hex(random_bytes(32));
        $name      = $hostname ?: $unique_id;

        $db->prepare('INSERT INTO devices (unique_id, name, api_token, last_seen, online, ativo) VALUES (?, ?, ?, NOW(), 1, 0)')
           ->execute([$unique_id, $name, $api_token]);
        $device_id = (int)$db->lastInsertId();

        $db->prepare('
            INSERT INTO device_config
                (device_id, hostname, wifi_ssid, wifi_attempts, qtd_pinos, web_server_port,
                 cloud_port, cloud_use_https, cloud_sync_enabled, cloud_sync_interval_min,
                 cloud_heartbeat_enabled, cloud_heartbeat_interval_min)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $device_id, $hostname ?: 'esp32modularx', $wifi_ssid, $wifi_attempts, $qtd_pinos, $port,
            $cloud_port, $cloud_use_https, $cloud_sync_enabled, $cloud_sync_interval_min,
            $cloud_heartbeat_enabled, $cloud_heartbeat_interval_min,
        ]);

        $db->prepare('INSERT INTO device_status (device_id, ip, hostname, firmware_version) VALUES (?, ?, ?, ?)')
           ->execute([$device_id, $ip, $hostname, $firmware]);

        $registered = true;
        $message    = 'Device registered successfully';
    }

    // Importa pinos (substitui todos)
    if (!empty($pins)) {
        $db->prepare('DELETE FROM device_pins WHERE device_id = ?')->execute([$device_id]);
        $stmt = $db->prepare("
            INSERT INTO device_pins
                (device_id, nome, pino, tipo, modo, xor_logic, tempo_retencao,
                 nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt)
            VALUES (:did, :nome, :pino, :tipo, :modo, :xor, :ret, :nmin, :nmax, :cmqtt, :imqtt)
        ");
        foreach ($pins as $p) {
            if (!isset($p['pino'])) continue;
            $stmt->execute([
                ':did'   => $device_id,
                ':nome'  => substr($p['nome']       ?? '', 0, 64),
                ':pino'  => (int)$p['pino'],
                ':tipo'  => (int)($p['tipo']        ?? 0),
                ':modo'  => (int)($p['modo']        ?? 0),
                ':xor'   => (int)($p['xor_logic']   ?? 0),
                ':ret'   => (int)($p['tempo_retencao'] ?? 0),
                ':nmin'  => (int)($p['nivel_acionamento_min'] ?? 0),
                ':nmax'  => (int)($p['nivel_acionamento_max'] ?? 1),
                ':cmqtt' => substr($p['classe_mqtt'] ?? '', 0, 50),
                ':imqtt' => substr($p['icone_mqtt']  ?? '', 0, 50),
            ]);
        }
    }

    // Importa ações (substitui todas)
    if (!empty($actions)) {
        $db->prepare('DELETE FROM device_actions WHERE device_id = ?')->execute([$device_id]);
        $stmt = $db->prepare("
            INSERT INTO device_actions
                (device_id, pino_origem, numero_acao, pino_destino, acao,
                 tempo_on, tempo_off, pino_remoto, envia_modulo, telegram, assistente,
                 hora_agendada, minuto_agendado, duracao_agendada_s)
            VALUES (:did, :orig, :num, :dest, :acao, :ton, :toff, :prem, :mod, :tg, :ass, :hora, :min, :dur)
        ");
        foreach ($actions as $a) {
            if (!isset($a['pino_origem'])) continue;
            $stmt->execute([
                ':did'  => $device_id,
                ':orig' => (int)$a['pino_origem'],
                ':num'  => (int)($a['numero_acao']       ?? 1),
                ':dest' => (int)($a['pino_destino']      ?? 0),
                ':acao' => (int)($a['acao']              ?? 0),
                ':ton'  => (int)($a['tempo_on']          ?? 0),
                ':toff' => (int)($a['tempo_off']         ?? 0),
                ':prem' => (int)($a['pino_remoto']       ?? 0),
                ':mod'  => substr($a['envia_modulo'] ?? '', 0, 64),
                ':tg'   => (int)($a['telegram']          ?? 0),
                ':ass'  => (int)($a['assistente']        ?? 0),
                ':hora' => (int)($a['hora_agendada']     ?? 255),
                ':min'  => (int)($a['minuto_agendado']   ?? 0),
                ':dur'  => (int)($a['duracao_agendada_s']?? 0),
            ]);
        }
    }

    // Importa inter-módulos (substitui todos)
    if (!empty($intermod_modules)) {
        $db->prepare('DELETE FROM device_intermod WHERE device_id = ?')->execute([$device_id]);
        $stmt = $db->prepare("
            INSERT INTO device_intermod
                (device_id, module_id, hostname, ip, porta, ativo,
                 pins_offline, offline_alert_enabled, offline_flash_ms,
                 pins_healthcheck, healthcheck_alert_enabled, healthcheck_flash_ms)
            VALUES (:did, :mid, :host, :ip, :port, :ativo, :poff, :offen, :offms, :phc, :hcen, :hcms)
        ");
        foreach ($intermod_modules as $m) {
            $mid = $m['module_id'] ?? $m['id'] ?? '';
            if ($mid === '') continue;
            $stmt->execute([
                ':did'   => $device_id,
                ':mid'   => substr($mid, 0, 64),
                ':host'  => substr($m['hostname']                ?? '', 0, 64),
                ':ip'    => substr($m['ip']                      ?? '', 0, 45),
                ':port'  => (int)($m['porta']                    ?? 8080),
                ':ativo' => (int)($m['ativo']                    ?? 1),
                ':poff'  => substr($m['pins_offline']            ?? '', 0, 255),
                ':offen' => (int)($m['offline_alert_enabled']    ?? 0),
                ':offms' => (int)($m['offline_flash_ms']         ?? 200),
                ':phc'   => substr($m['pins_healthcheck']        ?? '', 0, 255),
                ':hcen'  => (int)($m['healthcheck_alert_enabled']?? 0),
                ':hcms'  => (int)($m['healthcheck_flash_ms']     ?? 500),
            ]);
        }
    }

    $db->commit();

    echo json_encode([
        'ok'         => true,
        'registered' => $registered,
        'api_token'  => $api_token,
        'device_id'  => $device_id,
        'message'    => $message,
    ]);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[SMCR register] DB error: ' . $e->getMessage());
    json_error('Database error', 500);
}
