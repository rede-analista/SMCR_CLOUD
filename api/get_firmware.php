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

    $binCtx = stream_context_create(['http' => [
        'timeout'         => 60,
        'header'          => "User-Agent: SMCR-Cloud-Proxy\r\n",
        'follow_location' => 1,
    ]]);

    error_log('[SMCR OTA] Abrindo stream: ' . $binUrl);
    $stream = @fopen($binUrl, 'rb', false, $binCtx);
    if (!$stream) send_error('Falha ao abrir stream do firmware no GitHub', 502);

    // Obtém Content-Length do cabeçalho HTTP do GitHub
    $meta = stream_get_meta_data($stream);
    $contentLength = 0;
    foreach ($meta['wrapper_data'] ?? [] as $h) {
        if (stripos($h, 'Content-Length:') === 0) {
            $contentLength = (int) trim(substr($h, 15));
            break;
        }
    }

    // Envia headers imediatamente — ESP começa a receber antes do download completo
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="SMCR_' . $version . '_firmware.bin"');
    header('X-Firmware-Version: ' . $tag);
    if ($contentLength > 0) {
        header('Content-Length: ' . $contentLength);
    }
    http_response_code(200);

    $t0 = microtime(true);
    $totalSent = 0;
    while (!feof($stream)) {
        $chunk = fread($stream, 4096);
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        flush();
        $totalSent += strlen($chunk);
    }
    fclose($stream);
    error_log('[SMCR OTA] Streaming concluido: ' . $totalSent . ' bytes em ' . round(microtime(true) - $t0, 2) . 's');

} catch (PDOException $e) {
    error_log('[SMCR API] DB error in get_firmware: ' . $e->getMessage());
    send_error('Database error', 500);
} catch (Exception $e) {
    error_log('[SMCR API] Error in get_firmware: ' . $e->getMessage());
    send_error('Internal server error', 500);
}
