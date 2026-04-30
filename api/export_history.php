<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();
$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
if (!$device_id) { http_response_code(400); exit; }

$stmt = $db->prepare('SELECT name, unique_id FROM devices WHERE id = ?');
$stmt->execute([$device_id]);
$device = $stmt->fetch();
if (!$device) { http_response_code(404); exit; }

$stmt = $db->prepare('
    SELECT gpio_origem, gpio_destino, tipo, valor_pino, ocorrido_em
    FROM device_action_events
    WHERE device_id = ?
    ORDER BY ocorrido_em DESC
    LIMIT 10000
');
$stmt->execute([$device_id]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $device['name'] ?: $device['unique_id']);
$filename = 'historico_' . $name . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$ACTION_NAMES = [1 => 'LIGA', 2 => 'LIGA_DELAY', 3 => 'PISCA', 4 => 'PULSO', 5 => 'PULSO_DELAY'];

$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
fputcsv($out, ['Tipo', 'GPIO Origem', 'GPIO Destino', 'Valor Pino', 'Horário'], ';');
foreach ($events as $e) {
    fputcsv($out, [
        $ACTION_NAMES[$e['tipo']] ?? 'TIPO_' . $e['tipo'],
        'GPIO ' . $e['gpio_origem'],
        'GPIO ' . $e['gpio_destino'],
        $e['valor_pino'] ?? '',
        $e['ocorrido_em'],
    ], ';');
}
fclose($out);
