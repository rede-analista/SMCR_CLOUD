<?php
declare(strict_types=1);

// No auth required - ESP32 downloads files without credentials

$data_dir = realpath(__DIR__ . '/../data');

if ($data_dir === false || !is_dir($data_dir)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Data directory not found']);
    exit;
}

// List files
if (isset($_GET['list'])) {
    header('Content-Type: application/json; charset=utf-8');
    $files = [];
    foreach (scandir($data_dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $data_dir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($full)) {
            $files[] = [
                'name' => $entry,
                'size' => filesize($full),
                'modified' => date('Y-m-d H:i:s', filemtime($full)),
            ];
        }
    }
    echo json_encode(['ok' => true, 'files' => $files, 'count' => count($files)]);
    exit;
}

// Serve a specific file
if (isset($_GET['file'])) {
    $requested = $_GET['file'];

    // Prevent path traversal: strip any directory components, only allow basename
    $filename = basename($requested);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid filename']);
        exit;
    }

    // Only allow safe characters in filename
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid filename characters']);
        exit;
    }

    $file_path = $data_dir . DIRECTORY_SEPARATOR . $filename;

    // Final check: resolved path must start with data_dir
    $resolved = realpath($file_path);
    if ($resolved === false || strpos($resolved, $data_dir) !== 0) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    if (!is_file($resolved)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found', 'file' => $filename]);
        exit;
    }

    // Determine Content-Type
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $content_types = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        'ico'  => 'image/x-icon',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
    ];

    $content_type = $content_types[$ext] ?? 'application/octet-stream';

    // Set headers
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . filesize($resolved));
    header('Cache-Control: public, max-age=3600');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($resolved)) . ' GMT');
    header('X-Content-Type-Options: nosniff');

    // Check if-modified-since
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $client_time = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($client_time >= filemtime($resolved)) {
            http_response_code(304);
            exit;
        }
    }

    readfile($resolved);
    exit;
}

// Default: show usage
http_response_code(400);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'Missing parameter',
    'usage' => [
        'list files'  => '/api/files.php?list=1',
        'serve file'  => '/api/files.php?file=web_pins.html',
    ]
]);
