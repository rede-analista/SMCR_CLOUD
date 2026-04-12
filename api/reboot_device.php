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

$stmt = $db->prepare('
    SELECT ds.ip,
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

$url      = "http://{$device['ip']}:{$device['port']}/restart";
$use_auth = (bool)$device['auth_enabled'];

$ch = curl_init($url);
$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [],
];
if ($use_auth && $device['web_username'] !== '') {
    $opts[CURLOPT_USERPWD]  = "{$device['web_username']}:{$device['web_password']}";
    $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
}
curl_setopt_array($ch, $opts);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => "ESP32 retornou HTTP $code"]);
}
