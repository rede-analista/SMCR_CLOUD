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

$data = json_decode(file_get_contents('php://input'), true);
$ip_range = trim($data['ip_range'] ?? '');
$port     = (int)($data['port'] ?? 8080);

if ($ip_range === '') {
    echo json_encode(['ok' => false, 'error' => 'ip_range is required']);
    exit;
}

if ($port < 1 || $port > 65535) {
    echo json_encode(['ok' => false, 'error' => 'Invalid port']);
    exit;
}

// Parse IP range: supports "192.168.1.0/24", "192.168.1.1-254", "192.168.1.1"
function parse_ip_range(string $range): array {
    $ips = [];

    // CIDR notation: 192.168.1.0/24
    if (strpos($range, '/') !== false) {
        [$base, $prefix] = explode('/', $range, 2);
        $prefix = (int)$prefix;
        if ($prefix < 16 || $prefix > 30) return []; // safety limit
        $base_long = ip2long($base);
        if ($base_long === false) return [];
        $mask   = -1 << (32 - $prefix);
        $start  = ($base_long & $mask) + 1;
        $end    = ($base_long | ~$mask) - 1;
        if (($end - $start) > 254) return []; // max 254
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    // Range notation: 192.168.1.1-254
    if (strpos($range, '-') !== false) {
        [$start_ip, $end_part] = explode('-', $range, 2);
        $parts = explode('.', $start_ip);
        if (count($parts) !== 4) return [];
        $start_last = (int)$parts[3];
        $end_last   = (int)$end_part;
        if ($end_last < $start_last || $end_last > 254) return [];
        for ($i = $start_last; $i <= $end_last; $i++) {
            $ips[] = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . $i;
        }
        return $ips;
    }

    // Single IP
    if (filter_var($range, FILTER_VALIDATE_IP)) {
        return [$range];
    }

    return [];
}

$ips = parse_ip_range($ip_range);

if (empty($ips)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid IP range format. Use: 192.168.1.0/24 or 192.168.1.1-254']);
    exit;
}

// Scan using curl_multi for parallel requests
$found   = [];
$timeout = 1; // seconds per request

$mh      = curl_multi_init();
$handles = [];

foreach ($ips as $ip) {
    $url = "http://{$ip}:{$port}/api/mqtt/status";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$ip] = $ch;
}

// Execute all requests
$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.1);
} while ($running > 0);

// Collect results
foreach ($handles as $ip => $ch) {
    $body    = curl_multi_getcontent($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $elapsed = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    if ($code === 200 && $body) {
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['mqtt_unique_id']) && $json['mqtt_unique_id'] !== '') {
            $found[] = [
                'ip'        => $ip,
                'port'      => $port,
                'unique_id' => $json['mqtt_unique_id'],
                'status'    => $json['mqtt_status'] ?? 'unknown',
                'elapsed'   => round($elapsed * 1000) . 'ms',
            ];
        }
    }
}

curl_multi_close($mh);

// Check which IPs are already registered
$db = getDB();
$registered = [];
if (!empty($found)) {
    $ids   = array_column($found, 'unique_id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $db->prepare("SELECT unique_id FROM devices WHERE unique_id IN ($place)");
    $stmt->execute($ids);
    $registered = array_column($stmt->fetchAll(), 'unique_id');
}

foreach ($found as &$dev) {
    $dev['already_registered'] = in_array($dev['unique_id'], $registered, true);
}

echo json_encode([
    'ok'        => true,
    'scanned'   => count($ips),
    'found'     => $found,
]);
