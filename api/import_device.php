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

$device_id      = (int)($_POST['device_id'] ?? 0);
$update_token   = !empty($_POST['update_token']);

if (!$device_id) {
    echo json_encode(['ok' => false, 'error' => 'device_id obrigatório']);
    exit;
}

if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Arquivo de backup não enviado ou inválido']);
    exit;
}

$raw = file_get_contents($_FILES['backup_file']['tmp_name']);
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['smcr_backup']) || ($data['version'] ?? 0) < 1) {
    echo json_encode(['ok' => false, 'error' => 'Arquivo inválido: não é um backup SMCR']);
    exit;
}

$db = getDB();

$stmt = $db->prepare('SELECT id FROM devices WHERE id = ?');
$stmt->execute([$device_id]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Dispositivo não encontrado']);
    exit;
}

$db->beginTransaction();
try {
    // Config geral
    if (is_array($data['config'] ?? null)) {
        $c = $data['config'];
        $stmt = $db->prepare("
            INSERT INTO device_config (device_id,
                hostname, wifi_ssid, wifi_pass, wifi_attempts, wifi_check_interval,
                ap_ssid, ap_pass, ap_fallback_enabled,
                ntp_server1, gmt_offset_sec, daylight_offset_sec,
                status_pinos_enabled, inter_modulos_enabled,
                cor_com_alerta, cor_sem_alerta, tempo_refresh,
                show_analog_history, show_digital_history,
                serial_debug_enabled, log_flags,
                watchdog_enabled, tempo_watchdog_us, clock_esp32_mhz, qtd_pinos,
                web_server_port, auth_enabled, web_username, web_password, dashboard_auth_required,
                mqtt_enabled, mqtt_server, mqtt_port, mqtt_user, mqtt_password,
                mqtt_topic_base, mqtt_publish_interval,
                mqtt_ha_discovery, mqtt_ha_batch, mqtt_ha_interval_ms, mqtt_ha_repeat_sec,
                intermod_enabled, intermod_healthcheck, intermod_max_failures, intermod_auto_discovery,
                telegram_enabled, telegram_token, telegram_chatid, telegram_interval,
                cloud_url, cloud_port, cloud_sync_enabled, cloud_sync_interval_min,
                cloud_heartbeat_enabled, cloud_heartbeat_interval_min,
                reboot_on_sync, ota_update_on_sync
            ) VALUES (
                :device_id,
                :hostname, :wifi_ssid, :wifi_pass, :wifi_attempts, :wifi_check_interval,
                :ap_ssid, :ap_pass, :ap_fallback,
                :ntp_server1, :gmt_offset, :daylight_offset,
                :status_pinos, :inter_mod,
                :cor_alerta, :cor_ok, :refresh,
                :show_a, :show_d,
                :dbg, :log,
                :wdt_en, :wdt_t, :clk, :qtd,
                :port, :auth, :wuser, :wpass, :dash,
                :mqtt_en, :mqtt_srv, :mqtt_port, :mqtt_usr, :mqtt_pass,
                :mqtt_topic, :mqtt_pint,
                :mqtt_had, :mqtt_hab, :mqtt_haim, :mqtt_harp,
                :imod_en, :imod_hchk, :imod_mfail, :imod_adisc,
                :tg_en, :tg_tok, :tg_chat, :tg_intv,
                :cloud_url, :cloud_port, :cloud_sync_en, :cloud_sync_int,
                :cloud_hb_en, :cloud_hb_int,
                0, 0
            )
            ON DUPLICATE KEY UPDATE
                hostname=VALUES(hostname), wifi_ssid=VALUES(wifi_ssid), wifi_pass=VALUES(wifi_pass),
                wifi_attempts=VALUES(wifi_attempts), wifi_check_interval=VALUES(wifi_check_interval),
                ap_ssid=VALUES(ap_ssid), ap_pass=VALUES(ap_pass), ap_fallback_enabled=VALUES(ap_fallback_enabled),
                ntp_server1=VALUES(ntp_server1), gmt_offset_sec=VALUES(gmt_offset_sec),
                daylight_offset_sec=VALUES(daylight_offset_sec),
                status_pinos_enabled=VALUES(status_pinos_enabled), inter_modulos_enabled=VALUES(inter_modulos_enabled),
                cor_com_alerta=VALUES(cor_com_alerta), cor_sem_alerta=VALUES(cor_sem_alerta),
                tempo_refresh=VALUES(tempo_refresh),
                show_analog_history=VALUES(show_analog_history), show_digital_history=VALUES(show_digital_history),
                serial_debug_enabled=VALUES(serial_debug_enabled), log_flags=VALUES(log_flags),
                watchdog_enabled=VALUES(watchdog_enabled), tempo_watchdog_us=VALUES(tempo_watchdog_us),
                clock_esp32_mhz=VALUES(clock_esp32_mhz), qtd_pinos=VALUES(qtd_pinos),
                web_server_port=VALUES(web_server_port), auth_enabled=VALUES(auth_enabled),
                web_username=VALUES(web_username), web_password=VALUES(web_password),
                dashboard_auth_required=VALUES(dashboard_auth_required),
                mqtt_enabled=VALUES(mqtt_enabled), mqtt_server=VALUES(mqtt_server),
                mqtt_port=VALUES(mqtt_port), mqtt_user=VALUES(mqtt_user), mqtt_password=VALUES(mqtt_password),
                mqtt_topic_base=VALUES(mqtt_topic_base), mqtt_publish_interval=VALUES(mqtt_publish_interval),
                mqtt_ha_discovery=VALUES(mqtt_ha_discovery), mqtt_ha_batch=VALUES(mqtt_ha_batch),
                mqtt_ha_interval_ms=VALUES(mqtt_ha_interval_ms), mqtt_ha_repeat_sec=VALUES(mqtt_ha_repeat_sec),
                intermod_enabled=VALUES(intermod_enabled), intermod_healthcheck=VALUES(intermod_healthcheck),
                intermod_max_failures=VALUES(intermod_max_failures), intermod_auto_discovery=VALUES(intermod_auto_discovery),
                telegram_enabled=VALUES(telegram_enabled), telegram_token=VALUES(telegram_token),
                telegram_chatid=VALUES(telegram_chatid), telegram_interval=VALUES(telegram_interval),
                cloud_url=VALUES(cloud_url), cloud_port=VALUES(cloud_port),
                cloud_sync_enabled=VALUES(cloud_sync_enabled), cloud_sync_interval_min=VALUES(cloud_sync_interval_min),
                cloud_heartbeat_enabled=VALUES(cloud_heartbeat_enabled),
                cloud_heartbeat_interval_min=VALUES(cloud_heartbeat_interval_min)
        ");
        $stmt->execute([
            ':device_id'     => $device_id,
            ':hostname'      => $c['hostname']                   ?? 'esp32modularx',
            ':wifi_ssid'     => $c['wifi_ssid']                  ?? '',
            ':wifi_pass'     => $c['wifi_pass']                  ?? '',
            ':wifi_attempts' => (int)($c['wifi_attempts']        ?? 15),
            ':wifi_check_interval' => (int)($c['wifi_check_interval'] ?? 15000),
            ':ap_ssid'       => $c['ap_ssid']                    ?? 'SMCR_AP_SETUP',
            ':ap_pass'       => $c['ap_pass']                    ?? 'senha1234',
            ':ap_fallback'   => (int)($c['ap_fallback_enabled']  ?? 1),
            ':ntp_server1'   => $c['ntp_server1']                ?? 'pool.ntp.br',
            ':gmt_offset'    => (int)($c['gmt_offset_sec']       ?? -10800),
            ':daylight_offset' => (int)($c['daylight_offset_sec'] ?? 0),
            ':status_pinos'  => (int)($c['status_pinos_enabled'] ?? 1),
            ':inter_mod'     => (int)($c['inter_modulos_enabled'] ?? 0),
            ':cor_alerta'    => $c['cor_com_alerta']             ?? '#ff0000',
            ':cor_ok'        => $c['cor_sem_alerta']             ?? '#00ff00',
            ':refresh'       => (int)($c['tempo_refresh']        ?? 15),
            ':show_a'        => (int)($c['show_analog_history']  ?? 1),
            ':show_d'        => (int)($c['show_digital_history'] ?? 1),
            ':dbg'           => (int)($c['serial_debug_enabled'] ?? 0),
            ':log'           => (int)($c['log_flags']            ?? 0),
            ':wdt_en'        => (int)($c['watchdog_enabled']     ?? 0),
            ':wdt_t'         => (int)($c['tempo_watchdog_us']    ?? 8000000),
            ':clk'           => (int)($c['clock_esp32_mhz']      ?? 240),
            ':qtd'           => (int)($c['qtd_pinos']            ?? 16),
            ':port'          => (int)($c['web_server_port']      ?? 8080),
            ':auth'          => (int)($c['auth_enabled']         ?? 0),
            ':wuser'         => $c['web_username']               ?? 'admin',
            ':wpass'         => $c['web_password']               ?? 'admin1234',
            ':dash'          => (int)($c['dashboard_auth_required'] ?? 0),
            ':mqtt_en'       => (int)($c['mqtt_enabled']         ?? 0),
            ':mqtt_srv'      => $c['mqtt_server']                ?? '',
            ':mqtt_port'     => (int)($c['mqtt_port']            ?? 1883),
            ':mqtt_usr'      => $c['mqtt_user']                  ?? '',
            ':mqtt_pass'     => $c['mqtt_password']              ?? '',
            ':mqtt_topic'    => $c['mqtt_topic_base']            ?? 'smcr',
            ':mqtt_pint'     => (int)($c['mqtt_publish_interval'] ?? 60),
            ':mqtt_had'      => (int)($c['mqtt_ha_discovery']    ?? 1),
            ':mqtt_hab'      => (int)($c['mqtt_ha_batch']        ?? 4),
            ':mqtt_haim'     => (int)($c['mqtt_ha_interval_ms']  ?? 100),
            ':mqtt_harp'     => (int)($c['mqtt_ha_repeat_sec']   ?? 900),
            ':imod_en'       => (int)($c['intermod_enabled']     ?? 0),
            ':imod_hchk'     => (int)($c['intermod_healthcheck'] ?? 60),
            ':imod_mfail'    => (int)($c['intermod_max_failures'] ?? 3),
            ':imod_adisc'    => (int)($c['intermod_auto_discovery'] ?? 0),
            ':tg_en'         => (int)($c['telegram_enabled']     ?? 0),
            ':tg_tok'        => $c['telegram_token']             ?? '',
            ':tg_chat'       => $c['telegram_chatid']            ?? '',
            ':tg_intv'       => (int)($c['telegram_interval']    ?? 30),
            ':cloud_url'     => $c['cloud_url']                  ?? '',
            ':cloud_port'    => (int)($c['cloud_port']           ?? 8765),
            ':cloud_sync_en' => (int)($c['cloud_sync_enabled']   ?? 0),
            ':cloud_sync_int'=> (int)($c['cloud_sync_interval_min'] ?? 5),
            ':cloud_hb_en'   => (int)($c['cloud_heartbeat_enabled'] ?? 0),
            ':cloud_hb_int'  => (int)($c['cloud_heartbeat_interval_min'] ?? 5),
        ]);
    }

    // Pinos
    $db->prepare('DELETE FROM device_pins WHERE device_id = ?')->execute([$device_id]);
    if (is_array($data['pins'] ?? null)) {
        $stmt = $db->prepare("
            INSERT INTO device_pins
                (device_id, nome, pino, tipo, modo, xor_logic, tempo_retencao,
                 nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt)
            VALUES
                (:device_id, :nome, :pino, :tipo, :modo, :xor, :ret, :nmin, :nmax, :cmqtt, :imqtt)
        ");
        foreach ($data['pins'] as $p) {
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
        }
    }

    // Ações
    $db->prepare('DELETE FROM device_actions WHERE device_id = ?')->execute([$device_id]);
    if (is_array($data['actions'] ?? null)) {
        $stmt = $db->prepare("
            INSERT INTO device_actions
                (device_id, pino_origem, numero_acao, pino_destino, acao,
                 tempo_on, tempo_off, pino_remoto, envia_modulo, telegram, assistente)
            VALUES
                (:device_id, :orig, :num, :dest, :acao,
                 :ton, :toff, :premoto, :modulo, :tg, :ass)
        ");
        foreach ($data['actions'] as $a) {
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
        }
    }

    // Inter-módulos
    $db->prepare('DELETE FROM device_intermod WHERE device_id = ?')->execute([$device_id]);
    if (is_array($data['intermod'] ?? null)) {
        $stmt = $db->prepare("
            INSERT INTO device_intermod (device_id, module_id, hostname, ip, porta,
                ativo, pins_offline, offline_alert_enabled, offline_flash_ms,
                pins_healthcheck, healthcheck_alert_enabled, healthcheck_flash_ms)
            VALUES (:device_id, :mid, :host, :ip, :port,
                :ativo, :pins_offline, :offline_en, :offline_ms,
                :pins_hc, :hc_en, :hc_ms)
        ");
        foreach ($data['intermod'] as $mod) {
            $mid = $mod['module_id'] ?? '';
            if ($mid === '') continue;
            $stmt->execute([
                ':device_id'    => $device_id,
                ':mid'          => substr($mid, 0, 64),
                ':host'         => substr($mod['hostname']   ?? '', 0, 64),
                ':ip'           => substr($mod['ip']         ?? '', 0, 45),
                ':port'         => (int)($mod['porta']       ?? 8080),
                ':ativo'        => (int)($mod['ativo']       ?? 0),
                ':pins_offline' => substr($mod['pins_offline']              ?? '', 0, 255),
                ':offline_en'   => (int)($mod['offline_alert_enabled']      ?? 0),
                ':offline_ms'   => (int)($mod['offline_flash_ms']           ?? 200),
                ':pins_hc'      => substr($mod['pins_healthcheck']          ?? '', 0, 255),
                ':hc_en'        => (int)($mod['healthcheck_alert_enabled']  ?? 0),
                ':hc_ms'        => (int)($mod['healthcheck_flash_ms']       ?? 500),
            ]);
        }
    }

    // Chave de API (opcional)
    if ($update_token && !empty($data['api_token'])) {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $data['api_token']);
        if (strlen($token) >= 16) {
            $stmt = $db->prepare('UPDATE devices SET api_token = ? WHERE id = ?');
            $stmt->execute([$token, $device_id]);
        }
    }

    $db->commit();

} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Erro ao importar: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Backup restaurado com sucesso']);
