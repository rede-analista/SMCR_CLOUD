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

$input     = json_decode(file_get_contents('php://input'), true);
$device_id = (int)($input['device_id'] ?? 0);

if (!$device_id) {
    echo json_encode(['ok' => false, 'error' => 'device_id obrigatório']);
    exit;
}

$db = getDB();

// Busca IP e porta do dispositivo
$stmt = $db->prepare('
    SELECT d.id, d.unique_id, ds.ip, ds.hostname,
           COALESCE(dc.web_server_port, 8080) AS port,
           COALESCE(dc.auth_enabled, 0) AS auth_enabled,
           COALESCE(dc.web_username, \'\') AS web_username,
           COALESCE(dc.web_password, \'\') AS web_password
    FROM devices d
    LEFT JOIN device_status ds ON ds.device_id = d.id
    LEFT JOIN device_config dc ON dc.device_id = d.id
    WHERE d.id = ?
');
$stmt->execute([$device_id]);
$device = $stmt->fetch();

if (!$device || empty($device['ip'])) {
    echo json_encode(['ok' => false, 'error' => 'Dispositivo não encontrado ou IP desconhecido']);
    exit;
}

$base    = "http://{$device['ip']}:{$device['port']}";
$user    = $device['web_username'];
$pass    = $device['web_password'];
$use_auth = (bool)$device['auth_enabled'];

// ─── Fetch paralelo de todos os endpoints ───
$endpoints = [
    'config'      => "$base/config/json",
    'pins'        => "$base/api/pins",
    'actions'     => "$base/api/actions",
    'mqtt'        => "$base/api/mqtt/config",
    'intermod'    => "$base/api/intermod/config",
    'modules'     => "$base/api/intermod/modules",
    'telegram'    => "$base/api/assistentes/config",
];

$mh      = curl_multi_init();
$handles = [];

foreach ($endpoints as $key => $url) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($use_auth && $user !== '') {
        $opts[CURLOPT_USERPWD]  = "{$user}:{$pass}";
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    curl_setopt_array($ch, $opts);
    curl_multi_add_handle($mh, $ch);
    $handles[$key] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
} while ($running > 0);

$raw = [];
foreach ($handles as $key => $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    $raw[$key] = ($code === 200 && $body) ? json_decode($body, true) : null;
}
curl_multi_close($mh);

// ─── Importação para o banco ───
$imported = [];
$errors   = [];

$db->beginTransaction();
try {

    // 1. Config geral (/config/json)
    if (is_array($raw['config'])) {
        $c = $raw['config'];
        $stmt = $db->prepare("
            UPDATE device_config SET
                hostname               = COALESCE(:hostname, hostname),
                wifi_ssid              = COALESCE(:wifi_ssid, wifi_ssid),
                wifi_pass              = COALESCE(:wifi_pass, wifi_pass),
                wifi_attempts          = COALESCE(:wifi_attempts, wifi_attempts),
                wifi_check_interval    = COALESCE(:wifi_check_interval, wifi_check_interval),
                ap_ssid                = COALESCE(:ap_ssid, ap_ssid),
                ap_pass                = COALESCE(:ap_pass, ap_pass),
                ap_fallback_enabled    = COALESCE(:ap_fallback, ap_fallback_enabled),
                ntp_server1            = COALESCE(:ntp_server1, ntp_server1),
                gmt_offset_sec         = COALESCE(:gmt_offset, gmt_offset_sec),
                daylight_offset_sec    = COALESCE(:daylight_offset, daylight_offset_sec),
                status_pinos_enabled   = COALESCE(:status_pinos, status_pinos_enabled),
                inter_modulos_enabled  = COALESCE(:inter_mod, inter_modulos_enabled),
                cor_com_alerta         = COALESCE(:cor_alerta, cor_com_alerta),
                cor_sem_alerta         = COALESCE(:cor_ok, cor_sem_alerta),
                tempo_refresh          = COALESCE(:refresh, tempo_refresh),
                show_analog_history    = COALESCE(:show_a, show_analog_history),
                show_digital_history   = COALESCE(:show_d, show_digital_history),
                watchdog_enabled       = COALESCE(:wdt_en, watchdog_enabled),
                clock_esp32_mhz        = COALESCE(:clk, clock_esp32_mhz),
                tempo_watchdog_us      = COALESCE(:wdt_t, tempo_watchdog_us),
                qtd_pinos              = COALESCE(:qtd, qtd_pinos),
                serial_debug_enabled   = COALESCE(:dbg, serial_debug_enabled),
                log_flags              = COALESCE(:log, log_flags),
                web_server_port        = COALESCE(:port, web_server_port),
                auth_enabled           = COALESCE(:auth, auth_enabled),
                web_username           = COALESCE(:wuser, web_username),
                web_password           = COALESCE(:wpass, web_password),
                dashboard_auth_required= COALESCE(:dash, dashboard_auth_required),
                cloud_url               = COALESCE(:cloud_url, cloud_url),
                cloud_port              = COALESCE(:cloud_port, cloud_port),
                cloud_sync_enabled      = COALESCE(:cloud_sync_en, cloud_sync_enabled),
                cloud_sync_interval_min = COALESCE(:cloud_sync_int, cloud_sync_interval_min)
            WHERE device_id = :device_id
        ");
        $stmt->execute([
            ':hostname'       => $c['hostname']              ?? null,
            ':wifi_ssid'      => $c['wifi_ssid']             ?? null,
            ':wifi_pass'      => $c['wifi_pass']             ?? null,
            ':wifi_attempts'  => isset($c['wifi_attempts'])  ? (int)$c['wifi_attempts']  : null,
            ':wifi_check_interval' => isset($c['wifi_check_interval']) ? (int)$c['wifi_check_interval'] : null,
            ':ap_ssid'        => $c['ap_ssid']               ?? null,
            ':ap_pass'        => $c['ap_pass']               ?? null,
            ':ap_fallback'    => isset($c['ap_fallback_enabled']) ? (int)$c['ap_fallback_enabled'] : null,
            ':ntp_server1'    => $c['ntp_server1']           ?? null,
            ':gmt_offset'     => isset($c['gmt_offset'])     ? (int)$c['gmt_offset']     : null,
            ':daylight_offset'=> isset($c['daylight_offset'])? (int)$c['daylight_offset']: null,
            ':status_pinos'   => isset($c['status_pinos_enabled']) ? (int)$c['status_pinos_enabled'] : null,
            ':inter_mod'      => isset($c['inter_modulos_enabled']) ? (int)$c['inter_modulos_enabled'] : null,
            ':cor_alerta'     => $c['cor_com_alerta']        ?? null,
            ':cor_ok'         => $c['cor_sem_alerta']        ?? null,
            ':refresh'        => isset($c['tempo_refresh'])  ? (int)$c['tempo_refresh']  : null,
            ':show_a'         => isset($c['show_analog_history'])  ? (int)$c['show_analog_history']  : null,
            ':show_d'         => isset($c['show_digital_history']) ? (int)$c['show_digital_history'] : null,
            ':wdt_en'         => isset($c['watchdog_enabled'])? (int)$c['watchdog_enabled'] : null,
            ':clk'            => isset($c['clock_esp32_mhz'])? (int)$c['clock_esp32_mhz'] : null,
            ':wdt_t'          => isset($c['tempo_watchdog_us']) ? (int)$c['tempo_watchdog_us'] : null,
            ':qtd'            => isset($c['qtd_pinos'])      ? (int)$c['qtd_pinos']      : null,
            ':dbg'            => isset($c['serial_debug_enabled']) ? (int)$c['serial_debug_enabled'] : null,
            ':log'            => isset($c['active_log_flags'])? (int)$c['active_log_flags'] : null,
            ':port'           => isset($c['web_server_port'])? (int)$c['web_server_port']: null,
            ':auth'           => isset($c['auth_enabled'])   ? (int)$c['auth_enabled']   : null,
            ':wuser'          => $c['web_username']          ?? null,
            ':wpass'          => $c['web_password']          ?? null,
            ':dash'           => isset($c['dashboard_auth_required']) ? (int)$c['dashboard_auth_required'] : null,
            ':cloud_url'      => $c['cloud_url']                    ?? null,
            ':cloud_port'     => isset($c['cloud_port'])             ? (int)$c['cloud_port']             : null,
            ':cloud_sync_en'  => isset($c['cloud_sync_enabled'])     ? (int)$c['cloud_sync_enabled']     : null,
            ':cloud_sync_int' => isset($c['cloud_sync_interval_min'])? (int)$c['cloud_sync_interval_min']: null,
            ':device_id'      => $device_id,
        ]);
        $imported[] = 'config_geral';
    } else {
        $errors[] = 'config_geral: sem resposta';
    }

    // 2. MQTT (/api/mqtt/config)
    if (is_array($raw['mqtt'])) {
        $m = $raw['mqtt'];
        $stmt = $db->prepare("
            UPDATE device_config SET
                mqtt_enabled          = COALESCE(:en,   mqtt_enabled),
                mqtt_server           = COALESCE(:srv,  mqtt_server),
                mqtt_port             = COALESCE(:port, mqtt_port),
                mqtt_user             = COALESCE(:usr,  mqtt_user),
                mqtt_password         = COALESCE(:pass, mqtt_password),
                mqtt_topic_base       = COALESCE(:topic,mqtt_topic_base),
                mqtt_publish_interval = COALESCE(:pint, mqtt_publish_interval),
                mqtt_ha_discovery     = COALESCE(:had,  mqtt_ha_discovery),
                mqtt_ha_batch         = COALESCE(:hab,  mqtt_ha_batch),
                mqtt_ha_interval_ms   = COALESCE(:haim, mqtt_ha_interval_ms),
                mqtt_ha_repeat_sec    = COALESCE(:harp, mqtt_ha_repeat_sec)
            WHERE device_id = :device_id
        ");
        $stmt->execute([
            ':en'        => isset($m['mqtt_enabled'])          ? (int)$m['mqtt_enabled']          : null,
            ':srv'       => $m['mqtt_server']                  ?? null,
            ':port'      => isset($m['mqtt_port'])             ? (int)$m['mqtt_port']             : null,
            ':usr'       => $m['mqtt_user']                    ?? null,
            ':pass'      => $m['mqtt_password']                ?? null,
            ':topic'     => $m['mqtt_topic_base']              ?? null,
            ':pint'      => isset($m['mqtt_publish_interval']) ? (int)$m['mqtt_publish_interval'] : null,
            ':had'       => isset($m['mqtt_ha_discovery'])     ? (int)$m['mqtt_ha_discovery']     : null,
            ':hab'       => isset($m['mqtt_ha_batch'])         ? (int)$m['mqtt_ha_batch']         : null,
            ':haim'      => isset($m['mqtt_ha_interval_ms'])   ? (int)$m['mqtt_ha_interval_ms']   : null,
            ':harp'      => isset($m['mqtt_ha_repeat_sec'])    ? (int)$m['mqtt_ha_repeat_sec']    : null,
            ':device_id' => $device_id,
        ]);
        $imported[] = 'mqtt';
    } else {
        $errors[] = 'mqtt: sem resposta';
    }

    // 3. Telegram (/api/assistentes/config)
    if (is_array($raw['telegram'])) {
        $t = $raw['telegram'];
        $stmt = $db->prepare("
            UPDATE device_config SET
                telegram_enabled  = COALESCE(:en,   telegram_enabled),
                telegram_token    = COALESCE(:tok,  telegram_token),
                telegram_chatid   = COALESCE(:chat, telegram_chatid),
                telegram_interval = COALESCE(:intv, telegram_interval)
            WHERE device_id = :device_id
        ");
        $stmt->execute([
            ':en'        => isset($t['telegram_enabled'])  ? (int)$t['telegram_enabled']  : null,
            ':tok'       => $t['telegram_token']           ?? null,
            ':chat'      => $t['telegram_chatid']          ?? null,
            ':intv'      => isset($t['telegram_interval']) ? (int)$t['telegram_interval'] : null,
            ':device_id' => $device_id,
        ]);
        $imported[] = 'telegram';
    } else {
        $errors[] = 'telegram: sem resposta';
    }

    // 4. Inter-módulos config (/api/intermod/config)
    if (is_array($raw['intermod'])) {
        $i = $raw['intermod'];
        $stmt = $db->prepare("
            UPDATE device_config SET
                intermod_enabled        = COALESCE(:en,   intermod_enabled),
                intermod_healthcheck    = COALESCE(:hchk, intermod_healthcheck),
                intermod_max_failures   = COALESCE(:mfail,intermod_max_failures),
                intermod_auto_discovery = COALESCE(:adisc,intermod_auto_discovery)
            WHERE device_id = :device_id
        ");
        $stmt->execute([
            ':en'        => isset($i['interModEnabled'])        ? (int)$i['interModEnabled']        : null,
            ':hchk'      => isset($i['healthCheckInterval'])    ? (int)$i['healthCheckInterval']    : null,
            ':mfail'     => isset($i['maxFailures'])            ? (int)$i['maxFailures']            : null,
            ':adisc'     => isset($i['autoDiscovery'])          ? (int)$i['autoDiscovery']          : null,
            ':device_id' => $device_id,
        ]);
        $imported[] = 'intermod_config';
    } else {
        $errors[] = 'intermod_config: sem resposta';
    }

    // 5. Pinos (/api/pins) — substitui todos
    if (is_array($raw['pins']) && isset($raw['pins']['pins'])) {
        $db->prepare('DELETE FROM device_pins WHERE device_id = ?')->execute([$device_id]);
        $count = 0;
        $stmt = $db->prepare("
            INSERT INTO device_pins
                (device_id, nome, pino, tipo, modo, xor_logic, tempo_retencao,
                 nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt)
            VALUES
                (:device_id, :nome, :pino, :tipo, :modo, :xor, :ret, :nmin, :nmax, :cmqtt, :imqtt)
        ");
        foreach ($raw['pins']['pins'] as $p) {
            if (!isset($p['pino'])) continue;
            $stmt->execute([
                ':device_id' => $device_id,
                ':nome'      => substr($p['nome']       ?? '', 0, 64),
                ':pino'      => (int)$p['pino'],
                ':tipo'      => (int)($p['tipo']        ?? 0),
                ':modo'      => (int)($p['modo']        ?? 0),
                ':xor'       => (int)($p['xor_logic']   ?? 0),
                ':ret'       => (int)($p['tempo_retencao'] ?? 0),
                ':nmin'      => (int)($p['nivel_acionamento_min'] ?? 0),
                ':nmax'      => (int)($p['nivel_acionamento_max'] ?? 1),
                ':cmqtt'     => substr($p['classe_mqtt'] ?? '', 0, 50),
                ':imqtt'     => substr($p['icone_mqtt']  ?? '', 0, 50),
            ]);
            $count++;
        }
        $imported[] = "pinos ($count)";
    } else {
        $errors[] = 'pinos: sem resposta';
    }

    // 6. Ações (/api/actions) — substitui todas
    if (is_array($raw['actions']) && isset($raw['actions']['actions'])) {
        $db->prepare('DELETE FROM device_actions WHERE device_id = ?')->execute([$device_id]);
        $count = 0;
        $stmt = $db->prepare("
            INSERT INTO device_actions
                (device_id, pino_origem, numero_acao, pino_destino, acao,
                 tempo_on, tempo_off, pino_remoto, envia_modulo, telegram, assistente)
            VALUES
                (:device_id, :orig, :num, :dest, :acao,
                 :ton, :toff, :premoto, :modulo, :tg, :ass)
        ");
        foreach ($raw['actions']['actions'] as $a) {
            if (!isset($a['pino_origem'])) continue;
            $stmt->execute([
                ':device_id' => $device_id,
                ':orig'      => (int)$a['pino_origem'],
                ':num'       => (int)($a['numero_acao']  ?? 1),
                ':dest'      => (int)($a['pino_destino'] ?? 0),
                ':acao'      => (int)($a['acao']         ?? 0),
                ':ton'       => (int)($a['tempo_on']     ?? 0),
                ':toff'      => (int)($a['tempo_off']    ?? 0),
                ':premoto'   => (int)($a['pino_remoto']  ?? 0),
                ':modulo'    => substr($a['envia_modulo'] ?? '', 0, 64),
                ':tg'        => (int)($a['telegram']     ?? 0),
                ':ass'       => (int)($a['assistente']   ?? 0),
            ]);
            $count++;
        }
        $imported[] = "acoes ($count)";
    } else {
        $errors[] = 'acoes: sem resposta';
    }

    // 7. Inter-módulos cadastrados (/api/intermod/modules)
    if (is_array($raw['modules'])) {
        $modules = $raw['modules']['modules'] ?? $raw['modules'];
        if (is_array($modules)) {
            $db->prepare('DELETE FROM device_intermod WHERE device_id = ?')->execute([$device_id]);
            $count = 0;
            $stmt = $db->prepare("
                INSERT INTO device_intermod (device_id, module_id, hostname, ip, porta,
                    pins_offline, offline_alert_enabled, offline_flash_ms,
                    pins_healthcheck, healthcheck_alert_enabled, healthcheck_flash_ms)
                VALUES (:device_id, :mid, :host, :ip, :port,
                    :pins_offline, :offline_en, :offline_ms,
                    :pins_hc, :hc_en, :hc_ms)
            ");
            foreach ($modules as $mod) {
                $mid = $mod['id'] ?? $mod['module_id'] ?? '';
                if ($mid === '') continue;
                $stmt->execute([
                    ':device_id'    => $device_id,
                    ':mid'          => substr($mid, 0, 64),
                    ':host'         => substr($mod['hostname']   ?? '', 0, 64),
                    ':ip'           => substr($mod['ip']         ?? '', 0, 45),
                    ':port'         => (int)($mod['porta']       ?? $mod['port'] ?? 8080),
                    ':pins_offline' => substr($mod['pins_offline']              ?? '', 0, 255),
                    ':offline_en'   => (int)($mod['offline_alert_enabled']      ?? 0),
                    ':offline_ms'   => (int)($mod['offline_flash_ms']           ?? 200),
                    ':pins_hc'      => substr($mod['pins_healthcheck']          ?? '', 0, 255),
                    ':hc_en'        => (int)($mod['healthcheck_alert_enabled']  ?? 0),
                    ':hc_ms'        => (int)($mod['healthcheck_flash_ms']       ?? 500),
                ]);
                $count++;
            }
            $imported[] = "intermod_modulos ($count)";
        }
    } else {
        $errors[] = 'intermod_modulos: sem resposta';
    }

    $db->commit();

} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Erro ao importar: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok'       => true,
    'imported' => $imported,
    'errors'   => $errors,
    'device'   => $device['unique_id'],
]);
