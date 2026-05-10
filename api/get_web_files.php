<?php
// API pública para servir arquivos HTML/assets da pasta data/ para o ESP32
// Sem autenticação — necessário para o setup inicial do dispositivo

header('Access-Control-Allow-Origin: *');

$source = $_GET['source'] ?? 'local';
$file   = $_GET['file']   ?? null;

$GITHUB_REPO   = 'rede-analista/SMCR';
$GITHUB_BRANCH = 'main';
$GITHUB_RAW    = "https://raw.githubusercontent.com/{$GITHUB_REPO}/{$GITHUB_BRANCH}/data/";
$GITHUB_API    = "https://api.github.com/repos/{$GITHUB_REPO}/contents/data?ref={$GITHUB_BRANCH}";

$allowed_ext = ['html', 'css', 'js', 'ico', 'png', 'jpg', 'svg'];
$mimes = [
    'html' => 'text/html; charset=utf-8',
    'css'  => 'text/css',
    'js'   => 'application/javascript',
    'ico'  => 'image/x-icon',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'svg'  => 'image/svg+xml',
];

if ($source === 'github') {

    if ($file !== null) {
        // Proxy arquivo individual do GitHub
        $filename = basename($file);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) { http_response_code(403); echo 'Forbidden'; exit; }

        $ctx = stream_context_create(['http' => [
            'header'  => "User-Agent: SMCR-Server\r\n",
            'timeout' => 15,
        ]]);
        $content = @file_get_contents($GITHUB_RAW . rawurlencode($filename), false, $ctx);
        if ($content === false) { http_response_code(502); echo 'GitHub unavailable'; exit; }

        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . strlen($content));
        echo $content;

    } else {
        // Lista arquivos disponíveis no GitHub via API
        $ctx = stream_context_create(['http' => [
            'header'  => "User-Agent: SMCR-Server\r\n",
            'timeout' => 10,
        ]]);
        $apiResp = @file_get_contents($GITHUB_API, false, $ctx);
        if ($apiResp === false) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'GitHub API unavailable']);
            exit;
        }
        $items = json_decode($apiResp, true) ?? [];
        $files = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'file') continue;
            $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['html', 'ico', 'png'])) {
                $files[] = $item['name'];
            }
        }
        sort($files);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'files' => $files, 'count' => count($files)]);
    }
    exit;
}

// ── Fonte local (pasta data/) ────────────────────────────────────────────────

$data_dir = realpath(__DIR__ . '/../data') . '/';

if ($file !== null) {
    $filename = basename($file);
    $filepath = $data_dir . $filename;

    if (!file_exists($filepath) || !is_file($filepath)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);

} else {
    header('Content-Type: application/json; charset=utf-8');

    $files = [];
    foreach (['html', 'ico', 'png'] as $ext) {
        foreach (glob($data_dir . '*.' . $ext) as $path) {
            $files[] = basename($path);
        }
    }
    sort($files);

    echo json_encode(['ok' => true, 'files' => $files, 'count' => count($files)]);
}
