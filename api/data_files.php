<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
session_init();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$DATA_DIR = realpath(__DIR__ . '/../data');
$action   = $_GET['action'] ?? '';

// ─── Segurança: garante que o caminho é dentro de data/ ───
function safe_path(string $filename, string $base): string|false {
    // Só nome de arquivo, sem subdiretórios
    if ($filename !== basename($filename)) return false;
    $path = $base . DIRECTORY_SEPARATOR . $filename;
    $real = realpath($path);
    // Para arquivos que ainda não existem, verifica o diretório pai
    if ($real === false) {
        $real = realpath($base) . DIRECTORY_SEPARATOR . basename($filename);
    }
    if (strpos($real, $base) !== 0) return false;
    return $real;
}

// ─── GET: lê arquivo ───
if ($action === 'read' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $file = $_GET['file'] ?? '';
    $path = safe_path($file, $DATA_DIR);
    if (!$path || !file_exists($path) || !is_file($path)) {
        echo json_encode(['ok' => false, 'error' => 'Arquivo não encontrado']);
        exit;
    }
    echo json_encode(['ok' => true, 'content' => file_get_contents($path), 'file' => $file]);
    exit;
}

// ─── POST: salva arquivo ───
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $input   = json_decode(file_get_contents('php://input'), true);
    $file    = $input['file']    ?? '';
    $content = $input['content'] ?? '';

    if ($file === '') {
        echo json_encode(['ok' => false, 'error' => 'Nome de arquivo obrigatório']);
        exit;
    }

    // Só permite .html
    if (!preg_match('/\.html?$/i', $file)) {
        echo json_encode(['ok' => false, 'error' => 'Apenas arquivos .html são editáveis']);
        exit;
    }

    $path = safe_path($file, $DATA_DIR);
    if ($path === false) {
        echo json_encode(['ok' => false, 'error' => 'Caminho inválido']);
        exit;
    }

    if (file_put_contents($path, $content) === false) {
        echo json_encode(['ok' => false, 'error' => 'Erro ao salvar o arquivo']);
        exit;
    }

    echo json_encode(['ok' => true, 'file' => $file, 'size' => strlen($content)]);
    exit;
}

// ─── POST: upload de arquivo ───
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Erro no upload']);
        exit;
    }

    $orig = basename($_FILES['file']['name']);
    if (!preg_match('/\.(html?|ico|css|js|json|txt)$/i', $orig)) {
        echo json_encode(['ok' => false, 'error' => 'Tipo de arquivo não permitido']);
        exit;
    }

    $path = safe_path($orig, $DATA_DIR);
    if ($path === false) {
        echo json_encode(['ok' => false, 'error' => 'Caminho inválido']);
        exit;
    }

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
        echo json_encode(['ok' => false, 'error' => 'Erro ao mover arquivo']);
        exit;
    }

    echo json_encode(['ok' => true, 'file' => $orig]);
    exit;
}

// ─── POST: deleta arquivo ───
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $file  = $input['file'] ?? '';
    $path  = safe_path($file, $DATA_DIR);
    if (!$path || !file_exists($path) || !is_file($path)) {
        echo json_encode(['ok' => false, 'error' => 'Arquivo não encontrado']);
        exit;
    }
    if (!unlink($path)) {
        echo json_encode(['ok' => false, 'error' => 'Erro ao deletar']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Ação inválida']);
