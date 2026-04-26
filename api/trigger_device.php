<?php
// Proxy POST para /api/trigger no ESP32 — simula acionamento de um pino
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth.php';
session_init();

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$device_id = (int)($_POST['device_id'] ?? 0);
$pino      = (int)($_POST['pino']      ?? -1);

if (!$device_id || $pino < 0) {
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

    $url = "http://{$dev['ip']}:{$dev['port']}/api/trigger";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => "pino={$pino}",
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    if ($dev['auth_enabled'] && $dev['web_username'] !== '') {
        curl_setopt($ch, CURLOPT_USERPWD,  "{$dev['web_username']}:{$dev['web_password']}");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        echo $body ?: json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => "HTTP {$code} do dispositivo"]);
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
