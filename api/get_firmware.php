<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

function send_error(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
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
if (!$token) send_error('Missing authentication token', 401);
if (strlen($token) !== 64 || !ctype_xdigit($token)) send_error('Invalid token format', 401);

try {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM devices WHERE api_token = ? AND ativo = 1');
    $stmt->execute([$token]);
    if (!$stmt->fetch()) send_error('Device not found or inactive', 401);

    $apiCtx = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "User-Agent: SMCR-Cloud-Proxy\r\n",
    ]]);
    $apiJson = @file_get_contents(
        'https://api.github.com/repos/rede-analista/SMCR/releases/latest',
        false, $apiCtx
    );
    if (!$apiJson) send_error('Falha ao consultar API GitHub', 502);

    $release = json_decode($apiJson, true);
    $tag = $release['tag_name'] ?? '';
    if (!$tag) send_error('Tag nao encontrada na API GitHub', 502);

    $version = ltrim($tag, 'v');
    $binUrl  = "https://raw.githubusercontent.com/rede-analista/SMCR/{$tag}"
             . "/firmware/v{$version}/SMCR_v{$version}_firmware.bin";

    set_time_limit(0);

    $binCtx  = stream_context_create(['http' => [
        'timeout' => 240,
        'header'  => "User-Agent: SMCR-Cloud-Proxy\r\n",
    ]]);
    $firmware = @file_get_contents($binUrl, false, $binCtx);
    if (!$firmware || strlen($firmware) < 65536) send_error('Falha ao baixar firmware do GitHub', 502);

    while (ob_get_level() > 0) ob_end_flush();
    ob_implicit_flush(true);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="SMCR_' . $version . '_firmware.bin"');
    header('X-Firmware-Version: ' . $tag);
    header('Content-Length: ' . strlen($firmware));
    http_response_code(200);
    echo $firmware;
    flush();

} catch (PDOException $e) {
    error_log('[SMCR API] DB error in get_firmware: ' . $e->getMessage());
    send_error('Database error', 500);
} catch (Exception $e) {
    error_log('[SMCR API] Error in get_firmware: ' . $e->getMessage());
    send_error('Internal server error', 500);
}
