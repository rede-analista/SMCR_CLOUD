<?php
// Proxy para endpoints GET do ESP32 (serial/logs, history, etc.)
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth.php';
session_init();

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$device_id = (int)($_GET['device_id'] ?? 0);
$endpoint  = $_GET['endpoint']  ?? '';

$allowed = ['api/serial/logs', 'api/history'];
if (!$device_id || !in_array($endpoint, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT ds.ip, COALESCE(dc.web_server_port, 8080) AS port,
               COALESCE(dc.auth_enabled, 0) AS auth_enabled,
               COALESCE(dc.web_username, \'\') AS web_username,
               COALESCE(dc.web_password, \'\') AS web_password
        FROM device_status ds
        LEFT JOIN device_config dc ON dc.device_id = ds.device_id
        WHERE ds.device_id = ?
    ');
    $stmt->execute([$device_id]);
    $dev = $stmt->fetch();

    if (!$dev || empty($dev['ip'])) {
        echo json_encode(['ok' => false, 'error' => 'IP desconhecido']);
        exit;
    }

    $url = "http://{$dev['ip']}:{$dev['port']}/{$endpoint}";
    // Forward query string (ex: since= do serial/logs)
    $qs = http_build_query(array_diff_key($_GET, ['device_id' => 1, 'endpoint' => 1]));
    if ($qs) $url .= '?' . $qs;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    if ($dev['auth_enabled'] && $dev['web_username'] !== '') {
        curl_setopt($ch, CURLOPT_USERPWD,  "{$dev['web_username']}:{$dev['web_password']}");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $body) {
        echo $body; // já é JSON do ESP32
    } else {
        echo json_encode(['ok' => false, 'error' => "HTTP {$code} do dispositivo"]);
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
