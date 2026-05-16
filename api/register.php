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
$wifi_pass     = isset($data['wifi_pass'])        ? substr(trim((string)$data['wifi_pass']),        0, 128): '';
$wifi_attempts = isset($data['wifi_attempts'])    ? max(1, (int)$data['wifi_attempts'])                    : 4;
$wifi_check_interval      = isset($data['wifi_check_interval'])       ? max(1000, (int)$data['wifi_check_interval'])       : 15000;
$wifi_offline_restart_min = isset($data['wifi_offline_restart_min'])  ? max(0, (int)$data['wifi_offline_restart_min'])     : 30;
$ap_ssid            = isset($data['ap_ssid'])            ? substr(trim((string)$data['ap_ssid']),  0, 64)  : '';
$ap_pass            = isset($data['ap_pass'])            ? substr(trim((string)$data['ap_pass']),  0, 128) : '';
$ap_fallback_enabled= isset($data['ap_fallback_enabled'])? (int)(bool)$data['ap_fallback_enabled']         : 1;
$qtd_pinos     = isset($data['qtd_pinos'])        ? max(1, min(255, (int)$data['qtd_pinos']))              : 25;

// NTP
$ntp_server1      = isset($data['ntp_server1'])     ? substr(trim((string)$data['ntp_server1']), 0, 64) : 'pool.ntp.br';
$gmt_offset_sec   = isset($data['gmt_offset'])      ? (int)$data['gmt_offset']                          : -10800;
$daylight_offset_sec = isset($data['daylight_offset']) ? (int)$data['daylight_offset']                  : 0;

// Interface Web
$status_pinos_enabled  = isset($data['status_pinos_enabled'])  ? (int)(bool)$data['status_pinos_enabled']  : 1;
$inter_modulos_enabled = isset($data['inter_modulos_enabled']) ? (int)(bool)$data['inter_modulos_enabled'] : 0;
$cor_com_alerta        = isset($data['cor_com_alerta'])        ? substr(trim((string)$data['cor_com_alerta']),  0, 10) : '#ff0000';
$cor_sem_alerta        = isset($data['cor_sem_alerta'])        ? substr(trim((string)$data['cor_sem_alerta']),  0, 10) : '#00ff00';
$tempo_refresh         = isset($data['tempo_refresh'])         ? max(1, (int)$data['tempo_refresh'])           : 15;
$show_analog_history   = isset($data['show_analog_history'])   ? (int)(bool)$data['show_analog_history']       : 1;
$show_digital_history  = isset($data['show_digital_history'])  ? (int)(bool)$data['show_digital_history']      : 1;

// Sistema
$serial_debug_enabled = isset($data['serial_debug_enabled']) ? (int)(bool)$data['serial_debug_enabled'] : 0;
$log_flags            = isset($data['active_log_flags'])      ? (int)$data['active_log_flags']           : 0;
$watchdog_enabled     = isset($data['watchdog_enabled'])      ? (int)(bool)$data['watchdog_enabled']     : 0;
$tempo_watchdog_us    = isset($data['tempo_watchdog_us'])     ? max(1, (int)$data['tempo_watchdog_us'])  : 8000000;

// Servidor Web
$auth_enabled            = isset($data['auth_enabled'])            ? (int)(bool)$data['auth_enabled']            : 0;
$web_username            = isset($data['web_username'])            ? substr(trim((string)$data['web_username']),  0, 64)  : 'admin';
$web_password            = isset($data['web_password'])            ? substr(trim((string)$data['web_password']),  0, 128) : '';
$dashboard_auth_required = isset($data['dashboard_auth_required']) ? (int)(bool)$data['dashboard_auth_required'] : 0;

// MQTT
$mqtt_enabled          = isset($data['mqtt_enabled'])          ? (int)(bool)$data['mqtt_enabled']                    : 0;
$mqtt_server           = isset($data['mqtt_server'])           ? substr(trim((string)$data['mqtt_server']),   0, 128) : '';
$mqtt_port             = isset($data['mqtt_port'])             ? max(1, min(65535, (int)$data['mqtt_port']))          : 1883;
$mqtt_user             = isset($data['mqtt_user'])             ? substr(trim((string)$data['mqtt_user']),     0, 64)  : '';
$mqtt_password         = isset($data['mqtt_password'])         ? substr(trim((string)$data['mqtt_password']), 0, 128) : '';
$mqtt_topic_base       = isset($data['mqtt_topic_base'])       ? substr(trim((string)$data['mqtt_topic_base']),0, 64) : 'smcr';
$mqtt_publish_interval = isset($data['mqtt_publish_interval']) ? max(1, (int)$data['mqtt_publish_interval'])         : 60;
$mqtt_ha_discovery     = isset($data['mqtt_ha_discovery'])     ? (int)(bool)$data['mqtt_ha_discovery']               : 1;
$mqtt_ha_batch         = isset($data['mqtt_ha_batch'])         ? max(1, (int)$data['mqtt_ha_batch'])                  : 4;
$mqtt_ha_interval_ms   = isset($data['mqtt_ha_interval_ms'])   ? max(1, (int)$data['mqtt_ha_interval_ms'])            : 100;
$mqtt_ha_repeat_sec    = isset($data['mqtt_ha_repeat_sec'])    ? max(1, (int)$data['mqtt_ha_repeat_sec'])             : 900;

// Telegram
$telegram_enabled  = isset($data['telegram_enabled'])  ? (int)(bool)$data['telegram_enabled']                    : 0;
$telegram_token    = isset($data['telegram_token'])    ? substr(trim((string)$data['telegram_token']),    0, 128) : '';
$telegram_chatid   = isset($data['telegram_chatid'])   ? substr(trim((string)$data['telegram_chatid']),   0, 64)  : '';
$telegram_interval = isset($data['telegram_interval']) ? max(1, (int)$data['telegram_interval'])                  : 30;

// Cloud connection fields (ESP is source of truth for these)
$cloud_port                   = isset($data['cloud_port'])                   ? max(1, min(65535, (int)$data['cloud_port'])) : 443;
$cloud_use_https              = isset($data['cloud_use_https'])              ? (int)(bool)$data['cloud_use_https']          : 0;
$cloud_sync_enabled           = isset($data['cloud_sync_enabled'])           ? (int)(bool)$data['cloud_sync_enabled']       : 1;
$cloud_sync_interval_min      = isset($data['cloud_sync_interval_min'])      ? max(1, (int)$data['cloud_sync_interval_min']) : 5;
$cloud_heartbeat_enabled      = isset($data['cloud_heartbeat_enabled'])      ? (int)(bool)$data['cloud_heartbeat_enabled']  : 1;
$cloud_heartbeat_interval_min = isset($data['cloud_heartbeat_interval_min']) ? max(1, (int)$data['cloud_heartbeat_interval_min']) : 5;

// Inter-module fields (ESP is source of truth)
$intermod_enabled        = isset($data['intermod_enabled'])        ? (int)(bool)$data['intermod_enabled']       : 1;
$intermod_auto_discovery = isset($data['intermod_auto_discovery']) ? (int)(bool)$data['intermod_auto_discovery'] : 0;
$intermod_healthcheck    = isset($data['intermod_healthcheck'])    ? max(1, (int)$data['intermod_healthcheck'])   : 60;
$intermod_max_failures   = isset($data['intermod_max_failures'])   ? max(1, (int)$data['intermod_max_failures'])  : 3;

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

        // Atualiza config completa do ESP no device_config
        $db->prepare("
            UPDATE device_config SET
                hostname                     = ?,
                wifi_ssid                    = ?,
                wifi_pass                    = ?,
                wifi_attempts                = ?,
                wifi_check_interval          = ?,
                wifi_offline_restart_min     = ?,
                ap_ssid                      = ?,
                ap_pass                      = ?,
                ap_fallback_enabled          = ?,
                ntp_server1                  = ?,
                gmt_offset_sec               = ?,
                daylight_offset_sec          = ?,
                status_pinos_enabled         = ?,
                inter_modulos_enabled        = ?,
                cor_com_alerta               = ?,
                cor_sem_alerta               = ?,
                tempo_refresh                = ?,
                show_analog_history          = ?,
                show_digital_history         = ?,
                serial_debug_enabled         = ?,
                log_flags                    = ?,
                watchdog_enabled             = ?,
                tempo_watchdog_us            = ?,
                qtd_pinos                    = ?,
                web_server_port              = ?,
                auth_enabled                 = ?,
                web_username                 = ?,
                web_password                 = ?,
                dashboard_auth_required      = ?,
                mqtt_enabled                 = ?,
                mqtt_server                  = ?,
                mqtt_port                    = ?,
                mqtt_user                    = ?,
                mqtt_password                = ?,
                mqtt_topic_base              = ?,
                mqtt_publish_interval        = ?,
                mqtt_ha_discovery            = ?,
                mqtt_ha_batch                = ?,
                mqtt_ha_interval_ms          = ?,
                mqtt_ha_repeat_sec           = ?,
                intermod_enabled             = ?,
                intermod_auto_discovery      = ?,
                intermod_healthcheck         = ?,
                intermod_max_failures        = ?,
                telegram_enabled             = ?,
                telegram_token               = ?,
                telegram_chatid              = ?,
                telegram_interval            = ?,
                cloud_port                   = ?,
                cloud_use_https              = ?,
                cloud_sync_enabled           = ?,
                cloud_sync_interval_min      = ?,
                cloud_heartbeat_enabled      = ?,
                cloud_heartbeat_interval_min = ?
            WHERE device_id = ?
        ")->execute([
            $hostname, $wifi_ssid, $wifi_pass, $wifi_attempts,
            $wifi_check_interval, $wifi_offline_restart_min,
            $ap_ssid, $ap_pass, $ap_fallback_enabled,
            $ntp_server1, $gmt_offset_sec, $daylight_offset_sec,
            $status_pinos_enabled, $inter_modulos_enabled,
            $cor_com_alerta, $cor_sem_alerta, $tempo_refresh,
            $show_analog_history, $show_digital_history,
            $serial_debug_enabled, $log_flags, $watchdog_enabled, $tempo_watchdog_us,
            $qtd_pinos, $port,
            $auth_enabled, $web_username, $web_password, $dashboard_auth_required,
            $mqtt_enabled, $mqtt_server, $mqtt_port, $mqtt_user, $mqtt_password,
            $mqtt_topic_base, $mqtt_publish_interval, $mqtt_ha_discovery,
            $mqtt_ha_batch, $mqtt_ha_interval_ms, $mqtt_ha_repeat_sec,
            $intermod_enabled, $intermod_auto_discovery,
            $intermod_healthcheck, $intermod_max_failures,
            $telegram_enabled, $telegram_token, $telegram_chatid, $telegram_interval,
            $cloud_port, $cloud_use_https,
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
                (device_id, hostname, wifi_ssid, wifi_pass, wifi_attempts, wifi_check_interval,
                 wifi_offline_restart_min, ap_ssid, ap_pass, ap_fallback_enabled,
                 ntp_server1, gmt_offset_sec, daylight_offset_sec,
                 status_pinos_enabled, inter_modulos_enabled, cor_com_alerta, cor_sem_alerta,
                 tempo_refresh, show_analog_history, show_digital_history,
                 serial_debug_enabled, log_flags, watchdog_enabled, tempo_watchdog_us,
                 qtd_pinos, web_server_port, auth_enabled, web_username, web_password,
                 dashboard_auth_required,
                 mqtt_enabled, mqtt_server, mqtt_port, mqtt_user, mqtt_password,
                 mqtt_topic_base, mqtt_publish_interval, mqtt_ha_discovery,
                 mqtt_ha_batch, mqtt_ha_interval_ms, mqtt_ha_repeat_sec,
                 intermod_enabled, intermod_auto_discovery, intermod_healthcheck, intermod_max_failures,
                 telegram_enabled, telegram_token, telegram_chatid, telegram_interval,
                 cloud_port, cloud_use_https, cloud_sync_enabled, cloud_sync_interval_min,
                 cloud_heartbeat_enabled, cloud_heartbeat_interval_min)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $device_id, $hostname ?: 'esp32modularx', $wifi_ssid, $wifi_pass,
            $wifi_attempts, $wifi_check_interval, $wifi_offline_restart_min,
            $ap_ssid, $ap_pass, $ap_fallback_enabled,
            $ntp_server1, $gmt_offset_sec, $daylight_offset_sec,
            $status_pinos_enabled, $inter_modulos_enabled,
            $cor_com_alerta, $cor_sem_alerta, $tempo_refresh,
            $show_analog_history, $show_digital_history,
            $serial_debug_enabled, $log_flags, $watchdog_enabled, $tempo_watchdog_us,
            $qtd_pinos, $port,
            $auth_enabled, $web_username, $web_password, $dashboard_auth_required,
            $mqtt_enabled, $mqtt_server, $mqtt_port, $mqtt_user, $mqtt_password,
            $mqtt_topic_base, $mqtt_publish_interval, $mqtt_ha_discovery,
            $mqtt_ha_batch, $mqtt_ha_interval_ms, $mqtt_ha_repeat_sec,
            $intermod_enabled, $intermod_auto_discovery, $intermod_healthcheck, $intermod_max_failures,
            $telegram_enabled, $telegram_token, $telegram_chatid, $telegram_interval,
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
                 nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt, exibir_display)
            VALUES (:did, :nome, :pino, :tipo, :modo, :xor, :ret, :nmin, :nmax, :cmqtt, :imqtt, :exdisp)
        ");
        foreach ($pins as $p) {
            if (!isset($p['pino'])) continue;
            $stmt->execute([
                ':did'    => $device_id,
                ':nome'   => substr($p['nome']       ?? '', 0, 64),
                ':pino'   => (int)$p['pino'],
                ':tipo'   => (int)($p['tipo']        ?? 0),
                ':modo'   => (int)($p['modo']        ?? 0),
                ':xor'    => (int)($p['xor_logic']   ?? 0),
                ':ret'    => (int)($p['tempo_retencao'] ?? 0),
                ':nmin'   => (int)($p['nivel_acionamento_min'] ?? 0),
                ':nmax'   => (int)($p['nivel_acionamento_max'] ?? 1),
                ':cmqtt'  => substr($p['classe_mqtt'] ?? '', 0, 50),
                ':imqtt'  => substr($p['icone_mqtt']  ?? '', 0, 50),
                ':exdisp' => (int)(bool)($p['exibir_display'] ?? false),
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
