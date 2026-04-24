<?php
/**
 * SMCR Cloud - Auto-descoberta mDNS
 * Cron: * * * * * /usr/bin/php /var/www/html/cron/mdns_autodiscover.php >> /var/www/html/cron/autodiscover.log 2>&1
 *
 * Online/offline: GET / na porta anunciada pelo mDNS — qualquer resposta HTTP = online
 * unique_id: GET /api/mqtt/status — fallback para hostname se indisponível
 */

define('SMCR_CRON', true);
require_once __DIR__ . '/../config/db.php';

$db = getDB();

// ─── Throttle por intervalo configurável ───
$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'mdns_interval'");
$stmt->execute();
$mdns_interval_min = max(1, (int)($stmt->fetchColumn() ?: 5));

$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'last_mdns_discovery'");
$stmt->execute();
$last_run = $stmt->fetchColumn();

if ($last_run) {
    $elapsed = time() - strtotime($last_run);
    if ($elapsed < $mdns_interval_min * 60) {
        $remaining = $mdns_interval_min * 60 - $elapsed;
        echo '[' . date('Y-m-d H:i:s') . "] Aguardando próximo ciclo ({$remaining}s restantes). Saindo." . PHP_EOL;
        exit(0);
    }
}

echo '[' . date('Y-m-d H:i:s') . '] Iniciando...' . PHP_EOL;

// ─── Descobre dispositivos SMCR via mDNS ───
$mdns_output = shell_exec('python3 /var/www/html/scripts/mdns_scan.py 2>/dev/null');
$mdns_found  = [];

if ($mdns_output) {
    foreach (explode("\n", $mdns_output) as $line) {
        $parts = str_getcsv(trim($line), ';');
        if (count($parts) < 10 || $parts[0] !== '=') continue;

        $hostname = rtrim($parts[6], '.');
        $ip       = $parts[7];
        $port     = (int)$parts[8];
        $txt_raw  = $parts[9];

        if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;

        $is_smcr = false;
        $version = '';
        preg_match_all('/"([^"]*)"/', $txt_raw, $m);
        foreach ($m[1] as $txt) {
            if (stripos($txt, 'device_type=smcr') !== false ||
                stripos($txt, 'device=SMCR')      !== false) {
                $is_smcr = true;
            }
            if (stripos($txt, 'version=') === 0) {
                $version = substr($txt, 8);
            }
        }
        if (!$is_smcr) continue;

        $mdns_found[$ip . ':' . $port] = [
            'hostname' => $hostname,
            'ip'       => $ip,
            'port'     => $port,  // porta do mDNS — fonte de verdade
            'version'  => $version,
        ];
    }
}

echo '[' . date('Y-m-d H:i:s') . '] mDNS: ' . count($mdns_found) . ' dispositivo(s) SMCR.' . PHP_EOL;

// ─── Dispositivos cadastrados no banco ───
$rows = $db->query('
    SELECT d.id, d.unique_id, d.online,
           COALESCE(ds.ip, \'\') AS ip,
           COALESCE(ds.hostname, \'\') AS hostname,
           COALESCE(ds.port, 8080) AS port
    FROM devices d
    LEFT JOIN device_status ds ON ds.device_id = d.id
')->fetchAll();

$registered = [];
foreach ($rows as $r) {
    $registered[$r['unique_id']] = $r;
}

// ─── Monta lista de alvos para GET / (health check) ───
// Combina mDNS + dispositivos cadastrados ausentes do mDNS
$targets = [];
foreach ($mdns_found as $key => $dev) {
    $targets[$key] = "http://{$dev['ip']}:{$dev['port']}/";
}
foreach ($registered as $uid => $r) {
    if (empty($r['ip'])) continue;
    $key = $r['ip'] . ':' . $r['port'];
    if (!isset($mdns_found[$key])) {
        $targets['db_' . $key] = "http://{$r['ip']}:{$r['port']}/";
    }
}

// ─── GET / em paralelo (curl_multi) ───
$mh      = curl_multi_init();
$handles = [];
foreach ($targets as $key => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,  // 302/401 também confirma que está vivo
        // CURLOPT_NOBODY removido: ESP32 registra rota apenas para GET, HEAD não combinava e gerava 404
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$key] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.1);
} while ($running > 0);

$results = [];
foreach ($handles as $key => $ch) {
    $results[$key] = [
        'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'ms'   => (int)(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
    ];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

// ─── Processa dispositivos mDNS ───
foreach ($mdns_found as $key => $dev) {
    $r       = $results[$key] ?? ['code' => 0, 'ms' => 0];
    $is_live = $r['code'] > 0;

    // Tenta obter unique_id via /api/mqtt/status
    $unique_id = '';
    $ch2 = curl_init("http://{$dev['ip']}:{$dev['port']}/api/mqtt/status");
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body2 = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($code2 === 200 && $body2) {
        $json = json_decode($body2, true);
        $unique_id = trim($json['mqtt_unique_id'] ?? '');
    }
    if ($unique_id === '') $unique_id = $dev['hostname'];

    if (!$is_live) {
        echo '[' . date('H:i:s') . "] OFFLINE (sem resposta GET /): {$unique_id} [{$dev['ip']}:{$dev['port']}]" . PHP_EOL;
        $db->prepare('UPDATE devices SET online = 0 WHERE unique_id = ?')->execute([$unique_id]);
        continue;
    }

    // Online — cadastra ou atualiza
    if (isset($registered[$unique_id])) {
        // Não sobrescreve firmware_version: o heartbeat é a fonte autoritativa de versão
        $db->prepare('
            UPDATE devices d JOIN device_status ds ON ds.device_id = d.id
            SET d.online = 1, d.last_seen = NOW(),
                ds.ip = ?, ds.hostname = ?, ds.port = ?, ds.updated_at = NOW()
            WHERE d.unique_id = ?
        ')->execute([$dev['ip'], $dev['hostname'], $dev['port'], $unique_id]);
        echo '[' . date('H:i:s') . "] ONLINE: {$unique_id} [{$dev['ip']}:{$dev['port']}] GET/ {$r['code']} {$r['ms']}ms" . PHP_EOL;
    } else {
        // Antes de criar, verifica se já existe um dispositivo com o mesmo IP
        // (evita duplicatas quando unique_id muda entre execuções — ex: fallback hostname → MAC-ID)
        $stmt_ip = $db->prepare('SELECT d.id, d.unique_id FROM devices d JOIN device_status ds ON ds.device_id = d.id WHERE ds.ip = ? LIMIT 1');
        $stmt_ip->execute([$dev['ip']]);
        $existing_by_ip = $stmt_ip->fetch();

        if ($existing_by_ip) {
            $old_uid = $existing_by_ip['unique_id'];
            $did     = (int)$existing_by_ip['id'];
            // Atualiza unique_id se o novo parece mais canônico (mac-based) que o antigo (hostname)
            if ($old_uid !== $unique_id) {
                $db->prepare('UPDATE devices SET unique_id = ?, online = 1, last_seen = NOW() WHERE id = ?')
                   ->execute([$unique_id, $did]);
                $db->prepare('UPDATE device_status SET ip = ?, hostname = ?, port = ?, updated_at = NOW() WHERE device_id = ?')
                   ->execute([$dev['ip'], $dev['hostname'], $dev['port'], $did]);
                // Atualiza cache local para o restante do ciclo
                $registered[$unique_id] = $registered[$old_uid];
                unset($registered[$old_uid]);
                echo '[' . date('H:i:s') . "] ID ATUALIZADO: {$old_uid} → {$unique_id} [{$dev['ip']}:{$dev['port']}]" . PHP_EOL;
            } else {
                $db->prepare('UPDATE devices SET online = 1, last_seen = NOW() WHERE id = ?')->execute([$did]);
                echo '[' . date('H:i:s') . "] ONLINE (por IP): {$unique_id} [{$dev['ip']}:{$dev['port']}]" . PHP_EOL;
            }
        } else {
            try {
                $token = bin2hex(random_bytes(32));
                $db->beginTransaction();
                $db->prepare('INSERT INTO devices (unique_id, name, api_token, last_seen, online) VALUES (?,?,?,NOW(),1)')
                   ->execute([$unique_id, $dev['hostname'], $token]);
                $did = (int)$db->lastInsertId();
                $db->prepare('INSERT INTO device_config (device_id, hostname, web_server_port) VALUES (?,?,?)')
                   ->execute([$did, $dev['hostname'], $dev['port']]);
                $db->prepare('INSERT INTO device_status (device_id, ip, hostname, firmware_version, port) VALUES (?,?,?,?,?)')
                   ->execute([$did, $dev['ip'], $dev['hostname'], $dev['version'], $dev['port']]);
                $db->commit();
                echo '[' . date('H:i:s') . "] NOVO: {$unique_id} | {$dev['hostname']} | {$dev['ip']}:{$dev['port']}" . PHP_EOL;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                echo '[' . date('H:i:s') . "] ERRO ao cadastrar {$unique_id}: " . $e->getMessage() . PHP_EOL;
            }
        }
    }
}

// ─── Processa cadastrados ausentes do mDNS ───
foreach ($registered as $uid => $r) {
    if (empty($r['ip'])) continue;
    $key = 'db_' . $r['ip'] . ':' . $r['port'];
    if (!isset($results[$key])) continue;

    $res     = $results[$key];
    $is_live = $res['code'] > 0;

    if ($is_live) {
        $db->prepare('
            UPDATE devices d JOIN device_status ds ON ds.device_id = d.id
            SET d.online = 1, d.last_seen = NOW(), ds.updated_at = NOW()
            WHERE d.unique_id = ?
        ')->execute([$uid]);
        echo '[' . date('H:i:s') . "] ONLINE (sem mDNS): {$uid} [{$r['ip']}:{$r['port']}] GET/ {$res['code']} {$res['ms']}ms" . PHP_EOL;
    } else {
        if ($r['online']) {
            $db->prepare('UPDATE devices SET online = 0 WHERE unique_id = ?')->execute([$uid]);
            echo '[' . date('H:i:s') . "] OFFLINE: {$uid} [{$r['ip']}:{$r['port']}]" . PHP_EOL;
        }
    }
}

// ─── Atualiza timestamp da última execução ───
$db->prepare("INSERT INTO settings (`key`, value) VALUES ('last_mdns_discovery', NOW()) ON DUPLICATE KEY UPDATE value = NOW()")
   ->execute();

echo '[' . date('Y-m-d H:i:s') . '] Concluído.' . PHP_EOL;
