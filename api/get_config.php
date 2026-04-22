<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$token = null;
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth_header, 'Bearer ')) {
    $token = trim(substr($auth_header, 7));
}
if (!$token && isset($_GET['token'])) {
    $token = trim($_GET['token']);
}
if (!$token) json_error('Missing authentication token', 401);
if (strlen($token) !== 64 || !ctype_xdigit($token)) json_error('Invalid token format', 401);

try {
    $db = getDB();

    $stmt = $db->prepare('SELECT id, unique_id, ativo FROM devices WHERE api_token = ?');
    $stmt->execute([$token]);
    $device = $stmt->fetch();
    if (!$device) json_error('Device not found or invalid token', 401);
    if (!(bool)$device['ativo']) json_error('Device is disabled', 403);

    $device_id = (int)$device['id'];

    $stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $cfg = $stmt->fetch();
    if (!$cfg) json_error('Device config not found', 404);

    // Pinos
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

    // Ações
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
        'telegram'     => (bool)$r['telegram'],
        'assistente'   => (bool)$r['assistente'],
    ], $stmt->fetchAll());

    // Inter-módulos cadastrados
    $stmt = $db->prepare('SELECT module_id, hostname, ip, porta, ativo,
        pins_offline, offline_alert_enabled, offline_flash_ms,
        pins_healthcheck, healthcheck_alert_enabled, healthcheck_flash_ms
        FROM device_intermod WHERE device_id = ? ORDER BY module_id ASC');
    $stmt->execute([$device_id]);
    $intermod_modules = array_map(fn($r) => [
        'module_id'                 => $r['module_id'],
        'hostname'                  => $r['hostname'],
        'ip'                        => $r['ip'],
        'porta'                     => (int)$r['porta'],
        'ativo'                     => (int)$r['ativo'],
        'pins_offline'              => $r['pins_offline'],
        'offline_alert_enabled'     => (bool)$r['offline_alert_enabled'],
        'offline_flash_ms'          => (int)$r['offline_flash_ms'],
        'pins_healthcheck'          => $r['pins_healthcheck'],
        'healthcheck_alert_enabled' => (bool)$r['healthcheck_alert_enabled'],
        'healthcheck_flash_ms'      => (int)$r['healthcheck_flash_ms'],
    ], $stmt->fetchAll());

    http_response_code(200);
    echo json_encode([
        'ok'        => true,
        'device_id' => $device_id,
        'unique_id' => $device['unique_id'],

        // Rede
        'hostname'              => $cfg['hostname'],
        'wifi_ssid'             => $cfg['wifi_ssid'],
        'wifi_pass'             => $cfg['wifi_pass'],
        'wifi_attempts'         => (int)$cfg['wifi_attempts'],
        'wifi_check_interval'   => (int)$cfg['wifi_check_interval'],
        'ap_ssid'               => $cfg['ap_ssid'],
        'ap_pass'               => $cfg['ap_pass'],
        'ap_fallback_enabled'   => (bool)$cfg['ap_fallback_enabled'],

        // NTP
        'ntp_server1'           => $cfg['ntp_server1'],
        'gmt_offset'            => (int)$cfg['gmt_offset_sec'],
        'daylight_offset'       => (int)$cfg['daylight_offset_sec'],

        // Interface Web
        'status_pinos_enabled'   => (bool)$cfg['status_pinos_enabled'],
        'inter_modulos_enabled'  => (bool)$cfg['inter_modulos_enabled'],
        'cor_com_alerta'         => $cfg['cor_com_alerta'],
        'cor_sem_alerta'         => $cfg['cor_sem_alerta'],
        'tempo_refresh'          => (int)$cfg['tempo_refresh'],
        'show_analog_history'    => (bool)$cfg['show_analog_history'],
        'show_digital_history'   => (bool)$cfg['show_digital_history'],

        // Sistema
        'qtd_pinos'              => (int)$cfg['qtd_pinos'],
        'serial_debug_enabled'   => (bool)$cfg['serial_debug_enabled'],
        'active_log_flags'       => (int)$cfg['log_flags'],
        'watchdog_enabled'       => (bool)$cfg['watchdog_enabled'],
        'tempo_watchdog_us'      => (int)$cfg['tempo_watchdog_us'],
        'clock_esp32_mhz'        => (int)$cfg['clock_esp32_mhz'],

        // Servidor Web
        'web_server_port'         => (int)$cfg['web_server_port'],
        'auth_enabled'            => (bool)$cfg['auth_enabled'],
        'web_username'            => $cfg['web_username'],
        'web_password'            => $cfg['web_password'],
        'dashboard_auth_required' => (bool)$cfg['dashboard_auth_required'],

        // MQTT
        'mqtt_enabled'            => (bool)$cfg['mqtt_enabled'],
        'mqtt_server'             => $cfg['mqtt_server'],
        'mqtt_port'               => (int)$cfg['mqtt_port'],
        'mqtt_user'               => $cfg['mqtt_user'],
        'mqtt_password'           => $cfg['mqtt_password'],
        'mqtt_topic_base'         => $cfg['mqtt_topic_base'],
        'mqtt_publish_interval'   => (int)$cfg['mqtt_publish_interval'],
        'mqtt_ha_discovery'       => (bool)$cfg['mqtt_ha_discovery'],
        'mqtt_ha_batch'           => (int)$cfg['mqtt_ha_batch'],
        'mqtt_ha_interval_ms'     => (int)$cfg['mqtt_ha_interval_ms'],
        'mqtt_ha_repeat_sec'      => (int)$cfg['mqtt_ha_repeat_sec'],

        // Inter-Módulos
        'intermod_enabled'        => (bool)$cfg['intermod_enabled'],
        'intermod_healthcheck'    => (int)$cfg['intermod_healthcheck'],
        'intermod_max_failures'   => (int)$cfg['intermod_max_failures'],
        'intermod_auto_discovery' => (bool)$cfg['intermod_auto_discovery'],

        // Telegram
        'telegram_enabled'        => (bool)$cfg['telegram_enabled'],
        'telegram_token'          => $cfg['telegram_token'],
        'telegram_chatid'         => $cfg['telegram_chatid'],
        'telegram_interval'       => (int)$cfg['telegram_interval'],

        // SMCR Cloud
        'cloud_url'                  => $cfg['cloud_url'] ?? 'smcr.pensenet.com.br',
        'cloud_port'                 => (int)($cfg['cloud_port'] ?? 8765),
        'cloud_use_https'            => (bool)($cfg['cloud_use_https'] ?? 0),
        'cloud_sync_enabled'         => (bool)($cfg['cloud_sync_enabled'] ?? 0),
        'cloud_sync_interval_min'    => (int)($cfg['cloud_sync_interval_min'] ?? 5),
        'cloud_heartbeat_enabled'    => (bool)($cfg['cloud_heartbeat_enabled'] ?? 0),
        'cloud_heartbeat_interval_min' => (int)($cfg['cloud_heartbeat_interval_min'] ?? 5),
        'reboot_on_sync'             => (bool)($cfg['reboot_on_sync'] ?? 0),
        'ota_update_on_sync'         => (bool)($cfg['ota_update_on_sync'] ?? 0),

        // Pinos, Ações e Inter-módulos
        'pins'             => $pins,
        'actions'          => $actions,
        'intermod_modules' => $intermod_modules,
    ]);

    // Atualiza last_seen e online ao sincronizar
    $db->prepare('UPDATE devices SET last_seen = NOW(), online = 1 WHERE id = ?')
       ->execute([$device_id]);

    // Auto-desativa as flags após enviar
    if (!empty($cfg['reboot_on_sync'])) {
        $db->prepare('UPDATE device_config SET reboot_on_sync = 0 WHERE device_id = ?')
           ->execute([$device_id]);
    }
    if (!empty($cfg['ota_update_on_sync'])) {
        $db->prepare('UPDATE device_config SET ota_update_on_sync = 0 WHERE device_id = ?')
           ->execute([$device_id]);
    }

} catch (PDOException $e) {
    error_log('[SMCR API] DB error in get_config: ' . $e->getMessage());
    json_error('Database error', 500);
} catch (Exception $e) {
    error_log('[SMCR API] Error in get_config: ' . $e->getMessage());
    json_error('Internal server error', 500);
}
