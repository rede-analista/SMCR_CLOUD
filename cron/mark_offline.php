<?php
// Detecta devices offline e registra evento + envia alerta Telegram
require_once __DIR__ . '/../config/db.php';

try {
    $db = getDB();

    $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'offline_alert_minutes'");
    $minutes = (int)($stmt->fetchColumn() ?: 10);

    $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'offline_alert_enabled'");
    $enabled = (bool)($stmt->fetchColumn() ?: '0');

    $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'offline_alert_telegram_token'");
    $tg_token = trim((string)($stmt->fetchColumn() ?: ''));

    $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'offline_alert_telegram_chatid'");
    $tg_chatid = trim((string)($stmt->fetchColumn() ?: ''));

    // Encontra devices que estavam online mas não respondem há $minutes minutos
    $stmt = $db->prepare("
        SELECT id, name, unique_id FROM devices
        WHERE ativo = 1 AND online = 1
          AND last_seen < NOW() - INTERVAL ? MINUTE
    ");
    $stmt->execute([$minutes]);
    $gone_offline = $stmt->fetchAll();

    foreach ($gone_offline as $device) {
        $device_id = (int)$device['id'];

        // Marca offline
        $db->prepare('UPDATE devices SET online = 0 WHERE id = ?')->execute([$device_id]);

        // Registra evento
        $db->prepare("INSERT INTO device_events (device_id, event) VALUES (?, 'offline')")->execute([$device_id]);

        // Envia alerta Telegram se configurado
        if ($enabled && $tg_token !== '' && $tg_chatid !== '') {
            $msg = "[SMCR Cloud] Dispositivo offline: {$device['name']} ({$device['unique_id']}) — sem resposta há {$minutes} min.";
            $url = "https://api.telegram.org/bot{$tg_token}/sendMessage";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $tg_chatid, 'text' => $msg]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }
    // Limpeza automática do histórico de acionamentos
    $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'history_retention_days'");
    $retention_days = (int)($stmt->fetchColumn() ?: 90);
    if ($retention_days > 0) {
        $db->prepare("DELETE FROM device_action_events WHERE ocorrido_em < NOW() - INTERVAL ? DAY")
           ->execute([$retention_days]);
    }

} catch (Exception $e) {
    error_log('[SMCR cron] mark_offline error: ' . $e->getMessage());
}
