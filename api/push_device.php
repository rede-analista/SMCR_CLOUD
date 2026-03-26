<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(0);
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

try {
$db = getDB();

// Busca IP e porta do dispositivo
$stmt = $db->prepare('
    SELECT d.id, d.unique_id, ds.ip,
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

$base     = "http://{$device['ip']}:{$device['port']}";
$use_auth = (bool)$device['auth_enabled'];
$user     = $device['web_username'];
$pass     = $device['web_password'];

$pushed = [];
$errors = [];

// ─── Helper: POST com form data ───
function esp_post(string $url, array $fields, bool $use_auth, string $user, string $pass): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
    ];
    if ($use_auth && $user !== '') {
        $opts[CURLOPT_USERPWD]  = "{$user}:{$pass}";
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

// ─── Helper: GET ───
function esp_get(string $url, bool $use_auth, string $user, string $pass): ?array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($use_auth && $user !== '') {
        $opts[CURLOPT_USERPWD]  = "{$user}:{$pass}";
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $body) return json_decode($body, true);
    return null;
}

// ─── Helper: DELETE ───
function esp_delete(string $url, bool $use_auth, string $user, string $pass): int {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
    ];
    if ($use_auth && $user !== '') {
        $opts[CURLOPT_USERPWD]  = "{$user}:{$pass}";
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

// ─── Helper: PUT JSON ───
function esp_put_json(string $url, array $data, bool $use_auth, string $user, string $pass): array {
    $ch = curl_init($url);
    $json = json_encode($data);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
    ];
    if ($use_auth && $user !== '') {
        $opts[CURLOPT_USERPWD]  = "{$user}:{$pass}";
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

// ─── 1. MQTT ───
$stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
$stmt->execute([$device_id]);
$cfg = $stmt->fetch() ?: [];

if (!empty($cfg)) {
    $r = esp_post("$base/api/mqtt/save", [
        'mqtt_enabled'          => (int)($cfg['mqtt_enabled']          ?? 0),
        'mqtt_server'           => $cfg['mqtt_server']                  ?? '',
        'mqtt_port'             => (int)($cfg['mqtt_port']             ?? 1883),
        'mqtt_user'             => $cfg['mqtt_user']                    ?? '',
        'mqtt_password'         => $cfg['mqtt_password']               ?? '',
        'mqtt_topic_base'       => $cfg['mqtt_topic_base']             ?? '',
        'mqtt_publish_interval' => (int)($cfg['mqtt_publish_interval'] ?? 30),
        'mqtt_ha_discovery'     => (int)($cfg['mqtt_ha_discovery']     ?? 0),
        'mqtt_ha_batch'         => (int)($cfg['mqtt_ha_batch']         ?? 0),
        'mqtt_ha_interval_ms'   => (int)($cfg['mqtt_ha_interval_ms']   ?? 500),
        'mqtt_ha_repeat_sec'    => (int)($cfg['mqtt_ha_repeat_sec']    ?? 60),
    ], $use_auth, $user, $pass);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $pushed[] = 'mqtt';
    } else {
        $errors[] = "mqtt: HTTP {$r['code']}";
    }

    // ─── 2. Telegram ───
    $r = esp_post("$base/api/assistentes/config", [
        'telegram_enabled'  => (int)($cfg['telegram_enabled']  ?? 0),
        'telegram_token'    => $cfg['telegram_token']           ?? '',
        'telegram_chatid'   => $cfg['telegram_chatid']          ?? '',
        'telegram_interval' => (int)($cfg['telegram_interval'] ?? 30),
    ], $use_auth, $user, $pass);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $pushed[] = 'telegram';
    } else {
        $errors[] = "telegram: HTTP {$r['code']}";
    }

    // ─── 3. Inter-módulos config ───
    $r = esp_post("$base/api/intermod/config", [
        'enabled'             => (int)($cfg['intermod_enabled']        ?? 0),
        'healthCheckInterval' => (int)($cfg['intermod_healthcheck']    ?? 30),
        'maxFailures'         => (int)($cfg['intermod_max_failures']   ?? 3),
        'autoDiscovery'       => (int)($cfg['intermod_auto_discovery'] ?? 1),
    ], $use_auth, $user, $pass);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $pushed[] = 'intermod_config';
    } else {
        $errors[] = "intermod_config: HTTP {$r['code']}";
    }
}

// ─── 4. Pinos ───
// Estratégia: 1) deletar TODAS as ações do ESP32, 2) deletar TODOS os pinos,
// 3) adicionar pinos do banco, 4) adicionar ações do banco, 5) save_flash
// Isso garante estado limpo sem conflito de pinos em uso por ações.

$stmt = $db->prepare('SELECT * FROM device_pins WHERE device_id = ? ORDER BY pino');
$stmt->execute([$device_id]);
$db_pins = $stmt->fetchAll();

// Busca estado atual do ESP32
$esp_pins_raw    = esp_get("$base/api/pins",    $use_auth, $user, $pass);
$esp_actions_raw = esp_get("$base/api/actions", $use_auth, $user, $pass);

// 1. Deleta todas as ações do ESP32 primeiro (pinos em uso não podem ser deletados)
if (is_array($esp_actions_raw) && isset($esp_actions_raw['actions'])) {
    foreach ($esp_actions_raw['actions'] as $a) {
        $orig = (int)$a['pino_origem'];
        $num  = (int)($a['numero_acao'] ?? 1);
        esp_delete("$base/api/actions/$orig/$num", $use_auth, $user, $pass);
    }
}

// 2. Deleta todos os pinos do ESP32
if (is_array($esp_pins_raw) && isset($esp_pins_raw['pins'])) {
    foreach ($esp_pins_raw['pins'] as $p) {
        esp_delete("$base/api/pins/{$p['pino']}", $use_auth, $user, $pass);
    }
}

// 3. Adiciona pinos do banco
$pins_ok = 0;
$pins_err = 0;
foreach ($db_pins as $p) {
    $form = [
        'nome'                  => (string)($p['nome']                   ?? ''),
        'pino'                  => (string)(int)$p['pino'],
        'tipo'                  => (string)(int)($p['tipo']             ?? 0),
        'modo'                  => (string)(int)($p['modo']             ?? 0),
        'xor_logic'             => (string)(int)($p['xor_logic']        ?? 0),
        'tempo_retencao'        => (string)(int)($p['tempo_retencao']   ?? 0),
        'nivel_acionamento_min' => (string)(int)($p['nivel_acionamento_min'] ?? 0),
        'nivel_acionamento_max' => (string)(int)($p['nivel_acionamento_max'] ?? 1),
        'classe_mqtt'           => (string)($p['classe_mqtt']           ?? ''),
        'icone_mqtt'            => (string)($p['icone_mqtt']            ?? ''),
    ];
    $r = esp_post("$base/pins/add", $form, $use_auth, $user, $pass);
    if (trim($r['body']) === 'OK' || ($r['code'] >= 200 && $r['code'] < 300)) { $pins_ok++; } else { $pins_err++; }
}

// save_flash após pinos (ações serão adicionadas a seguir)
esp_post("$base/pins/save_flash", [], $use_auth, $user, $pass);

if ($pins_err === 0) {
    $pushed[] = "pinos ($pins_ok)";
} else {
    $errors[] = "pinos: $pins_ok ok, $pins_err erros";
}

// ─── 5. Ações ───
$stmt = $db->prepare('SELECT * FROM device_actions WHERE device_id = ?');
$stmt->execute([$device_id]);
$db_actions = $stmt->fetchAll();

// As ações antigas já foram deletadas no passo 1 — apenas adiciona as do banco
$actions_ok = 0;
$actions_err = 0;

foreach ($db_actions as $a) {
    $fields = [
        'pino_origem'  => (string)(int)$a['pino_origem'],
        'numero_acao'  => (string)(int)$a['numero_acao'],
        'pino_destino' => (string)(int)($a['pino_destino'] ?? 0),
        'acao'         => (string)(int)($a['acao']         ?? 0),
        'tempo_on'     => (string)(int)($a['tempo_on']     ?? 0),
        'tempo_off'    => (string)(int)($a['tempo_off']    ?? 0),
        'pino_remoto'  => (string)(int)($a['pino_remoto']  ?? 0),
        'envia_modulo' => $a['envia_modulo']               ?? '',
        'telegram'     => (string)(int)($a['telegram']     ?? 0),
        'assistente'   => (string)(int)($a['assistente']   ?? 0),
    ];
    $ch = curl_init("$base/api/actions");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ];
    if ($use_auth && $user !== '') {
        $opts[CURLOPT_USERPWD]  = "{$user}:{$pass}";
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) { $actions_ok++; } else { $actions_err++; }
}

if ($actions_err === 0) {
    $pushed[] = "acoes ($actions_ok)";
} else {
    $errors[] = "acoes: $actions_ok ok, $actions_err erros";
}

// ─── 6. Inter-módulos cadastrados ───
$stmt = $db->prepare('SELECT * FROM device_intermod WHERE device_id = ?');
$stmt->execute([$device_id]);
$db_modules = $stmt->fetchAll();

$esp_modules_raw = esp_get("$base/api/intermod/modules", $use_auth, $user, $pass);
$esp_modules_map = [];
if (is_array($esp_modules_raw)) {
    $list = $esp_modules_raw['modules'] ?? $esp_modules_raw;
    if (is_array($list)) {
        foreach ($list as $m) {
            $mid = $m['id'] ?? $m['module_id'] ?? '';
            if ($mid !== '') $esp_modules_map[$mid] = $m;
        }
    }
}

$db_modules_map = [];
foreach ($db_modules as $m) {
    $db_modules_map[$m['module_id']] = $m;
}

$mods_ok = 0;

foreach ($db_modules as $m) {
    $fields = [
        'moduleId'                 => $m['module_id'],
        'moduleHostname'           => $m['hostname']           ?? '',
        'moduleIp'                 => $m['ip']                 ?? '',
        'modulePort'               => (string)(int)($m['porta'] ?? 8080),
        'pinsOffline'              => $m['pins_offline']        ?? '',
        'offlineAlertEnabled'      => (string)(int)($m['offline_alert_enabled']     ?? 0),
        'offlineFlashMs'           => (string)(int)($m['offline_flash_ms']          ?? 200),
        'pinsHealthcheck'          => $m['pins_healthcheck']    ?? '',
        'healthcheckAlertEnabled'  => (string)(int)($m['healthcheck_alert_enabled'] ?? 0),
        'healthcheckFlashMs'       => (string)(int)($m['healthcheck_flash_ms']      ?? 500),
    ];
    $endpoint = isset($esp_modules_map[$m['module_id']]) ? 'update' : 'add';
    $r = esp_post("$base/api/intermod/modules/$endpoint", $fields, $use_auth, $user, $pass);
    if ($r['code'] >= 200 && $r['code'] < 300) $mods_ok++;
}

// Remove módulos que estão no ESP32 mas não no banco
foreach ($esp_modules_map as $mid => $m) {
    if (!isset($db_modules_map[$mid])) {
        esp_get("$base/api/intermod/modules/delete?id=" . urlencode($mid), $use_auth, $user, $pass);
    }
}

$pushed[] = "intermod_modulos ($mods_ok)";

// ─── 7. Config Geral (por último — reinicia o ESP32) ───
// Só envia se solicitado explicitamente
$push_config = (bool)($input['push_config'] ?? false);
if ($push_config && !empty($cfg)) {
    $r = esp_post("$base/save_config", [
        'hostname'               => $cfg['hostname']               ?? '',
        'wifi_ssid'              => $cfg['wifi_ssid']              ?? '',
        'wifi_attempts'          => (int)($cfg['wifi_attempts']    ?? 15),
        'wifi_check_interval'    => (int)($cfg['wifi_check_interval'] ?? 15000),
        'ntp_server1'            => $cfg['ntp_server1']            ?? 'pool.ntp.br',
        'gmt_offset'             => (int)($cfg['gmt_offset_sec']   ?? -10800),
        'daylight_offset'        => (int)($cfg['daylight_offset_sec'] ?? 0),
        'status_pinos_enabled'   => (int)($cfg['status_pinos_enabled']  ?? 1),
        'inter_modulos_enabled'  => (int)($cfg['inter_modulos_enabled'] ?? 0),
        'cor_com_alerta'         => $cfg['cor_com_alerta']         ?? '#ff0000',
        'cor_sem_alerta'         => $cfg['cor_sem_alerta']         ?? '#00ff00',
        'tempo_refresh'          => (int)($cfg['tempo_refresh']    ?? 15),
        'show_analog_history'    => (int)($cfg['show_analog_history']  ?? 1),
        'show_digital_history'   => (int)($cfg['show_digital_history'] ?? 0),
        'watchdog_enabled'       => (int)($cfg['watchdog_enabled'] ?? 0),
        'tempo_watchdog_us'      => (int)($cfg['tempo_watchdog_us'] ?? 2000000),
        'clock_esp32_mhz'        => (int)($cfg['clock_esp32_mhz'] ?? 240),
        'qtd_pinos'              => (int)($cfg['qtd_pinos']        ?? 10),
        'serial_debug_enabled'   => (int)($cfg['serial_debug_enabled'] ?? 0),
        'active_log_flags'       => (int)($cfg['log_flags']        ?? 0),
        'web_server_port'        => (int)($cfg['web_server_port']  ?? 8080),
        'auth_enabled'           => (int)($cfg['auth_enabled']     ?? 0),
        'web_username'           => $cfg['web_username']           ?? '',
        'web_password'           => $cfg['web_password']           ?? '',
        'dashboard_auth_required'=> (int)($cfg['dashboard_auth_required'] ?? 0),
        'ap_ssid'                => $cfg['ap_ssid']                ?? '',
        'ap_pass'                => $cfg['ap_pass']                ?? '',
        'ap_fallback_enabled'    => (int)($cfg['ap_fallback_enabled'] ?? 1),
    ], $use_auth, $user, $pass);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $pushed[] = 'config_geral (ESP32 reiniciará)';
    } else {
        $errors[] = "config_geral: HTTP {$r['code']}";
    }
}

echo json_encode([
    'ok'     => true,
    'pushed' => $pushed,
    'errors' => $errors,
    'device' => $device['unique_id'],
]);

} catch (PDOException $e) {
    error_log('[SMCR PUSH] DB error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erro de banco: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('[SMCR PUSH] Error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}
