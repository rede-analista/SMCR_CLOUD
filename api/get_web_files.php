<?php
// API pública para servir arquivos HTML/assets da pasta data/ para o ESP32
// Sem autenticação — necessário para o setup inicial do dispositivo

header('Access-Control-Allow-Origin: *');

$data_dir = realpath(__DIR__ . '/../data') . '/';
$file = $_GET['file'] ?? null;

if ($file !== null) {
    // Serve um arquivo específico
    $filename = basename($file); // evita path traversal
    $filepath = $data_dir . $filename;

    if (!file_exists($filepath) || !is_file($filepath)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = ['html', 'css', 'js', 'ico', 'png', 'jpg', 'svg'];
    if (!in_array($ext, $allowed)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $mimes = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'ico'  => 'image/x-icon',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'svg'  => 'image/svg+xml',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);

} else {
    // Retorna lista de arquivos disponíveis
    header('Content-Type: application/json; charset=utf-8');

    $extensions = ['html', 'ico', 'png'];
    $files = [];
    foreach ($extensions as $ext) {
        foreach (glob($data_dir . '*.' . $ext) as $path) {
            $files[] = basename($path);
        }
    }
    sort($files);

    echo json_encode(['ok' => true, 'files' => $files, 'count' => count($files)]);
}
