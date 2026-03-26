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

/**
 * Executa avahi-browse e retorna dispositivos SMCR encontrados.
 * Filtra por TXT records: device_type=smcr ou device=SMCR
 */
function discover_via_mdns(): array {
    // -t = termina após listar tudo, -r = resolve (mostra IP/porta/TXT), -p = formato parseável
    $output = shell_exec('avahi-browse -t -r -p _http._tcp 2>/dev/null');
    if ($output === null || $output === '') {
        return [];
    }

    $devices   = [];
    $current   = null;

    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts = str_getcsv($line, ';');
        if (count($parts) < 2) continue;

        $type = $parts[0]; // '+' = novo serviço, '=' = resolvido, '-' = removido

        if ($type === '=') {
            // Linha resolvida: =;<iface>;<proto>;<name>;<type>;<domain>;<hostname>;<addr>;<port>;<txt>
            if (count($parts) < 10) continue;

            $hostname = rtrim($parts[6], '.');
            $ip       = $parts[7];
            $port     = (int)$parts[8];
            $txt_raw  = $parts[9];

            // Verifica se tem TXT records indicando dispositivo SMCR
            $is_smcr = false;
            $version = '';
            $txt_records = [];

            // TXT vem como: "key=val" "key2=val2"
            preg_match_all('/"([^"]*)"/', $txt_raw, $matches);
            foreach ($matches[1] as $txt) {
                $txt_records[] = $txt;
                if (stripos($txt, 'device_type=smcr') !== false ||
                    stripos($txt, 'device=SMCR')      !== false) {
                    $is_smcr = true;
                }
                if (str_starts_with(strtolower($txt), 'version=')) {
                    $version = substr($txt, 8);
                }
            }

            if (!$is_smcr) continue;
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;

            $key = $ip . ':' . $port;
            if (!isset($devices[$key])) {
                $devices[$key] = [
                    'hostname'    => $hostname,
                    'ip'          => $ip,
                    'port'        => $port,
                    'version'     => $version,
                    'txt_records' => $txt_records,
                    'unique_id'   => '',
                ];
            }
        }
    }

    return array_values($devices);
}

/**
 * Busca o unique_id de cada dispositivo via HTTP /api/mqtt/status
 */
function enrich_with_unique_ids(array $devices): array {
    if (empty($devices)) return $devices;

    $mh      = curl_multi_init();
    $handles = [];

    foreach ($devices as $i => $dev) {
        $url = "http://{$dev['ip']}:{$dev['port']}/api/mqtt/status";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    foreach ($handles as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($code === 200 && $body) {
            $json = json_decode($body, true);
            if (is_array($json) && !empty($json['mqtt_unique_id'])) {
                $devices[$i]['unique_id'] = $json['mqtt_unique_id'];
            }
        }

        // Fallback: usa hostname como unique_id se não conseguiu via API
        if ($devices[$i]['unique_id'] === '') {
            $devices[$i]['unique_id'] = $devices[$i]['hostname'];
        }
    }

    curl_multi_close($mh);
    return $devices;
}

// --- Main ---
try {
    $found = discover_via_mdns();
    $found = enrich_with_unique_ids($found);

    // Verifica quais já estão cadastrados
    $db = getDB();
    $registered = [];
    if (!empty($found)) {
        $ids   = array_column($found, 'unique_id');
        $ids   = array_filter($ids);
        if (!empty($ids)) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt  = $db->prepare("SELECT unique_id FROM devices WHERE unique_id IN ($place)");
            $stmt->execute(array_values($ids));
            $registered = array_column($stmt->fetchAll(), 'unique_id');
        }
    }

    foreach ($found as &$dev) {
        $dev['already_registered'] = in_array($dev['unique_id'], $registered, true);
    }

    echo json_encode([
        'ok'    => true,
        'found' => $found,
        'count' => count($found),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
