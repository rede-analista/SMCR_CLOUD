<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$db = getDB();

// Auto-mark devices offline usando intervalo de heartbeat configurado por dispositivo
$db->exec("
    UPDATE devices d
    LEFT JOIN device_config dc ON dc.device_id = d.id
    SET d.online = 0
    WHERE d.last_seen IS NULL
       OR d.last_seen < DATE_SUB(NOW(), INTERVAL
           IF(dc.cloud_heartbeat_enabled = 1 AND dc.cloud_heartbeat_interval_min > 0,
              dc.cloud_heartbeat_interval_min + 1,
              2)
       MINUTE)
");

// Fetch all devices with status
$stmt = $db->query("
    SELECT d.id, d.name, d.unique_id, d.online, d.ativo, UNIX_TIMESTAMP(d.last_seen) as last_seen_unix, d.created_at,
           ds.ip, ds.hostname, ds.firmware_version, ds.free_heap, ds.uptime_ms, ds.wifi_rssi
    FROM devices d
    LEFT JOIN device_status ds ON ds.device_id = d.id
    ORDER BY d.ativo DESC, d.online DESC, d.name ASC
");
$devices = $stmt->fetchAll();

$total = count($devices);
$online_count = 0;
$offline_count = 0;
$inactive_count = 0;
foreach ($devices as $d) {
    if (!$d['ativo']) $inactive_count++;
    elseif ($d['online']) $online_count++;
    else $offline_count++;
}

function relative_time(?int $unix): string {
    if (!$unix) return 'Nunca';
    $diff = time() - $unix;
    if ($diff < 60) return 'há ' . $diff . ' segundo' . ($diff !== 1 ? 's' : '');
    if ($diff < 3600) { $m = (int)($diff/60); return 'há ' . $m . ' minuto' . ($m !== 1 ? 's' : ''); }
    if ($diff < 86400) { $h = (int)($diff/3600); return 'há ' . $h . ' hora' . ($h !== 1 ? 's' : ''); }
    $dys = (int)($diff/86400);
    return 'há ' . $dys . ' dia' . ($dys !== 1 ? 's' : '');
}

function format_heap(int $bytes): string {
    if ($bytes >= 1024) return round($bytes/1024, 1) . ' KB';
    return $bytes . ' B';
}

function format_uptime(int $ms): string {
    $s = (int)($ms / 1000);
    $h = (int)($s / 3600); $s %= 3600;
    $m = (int)($s / 60); $s %= 60;
    if ($h > 0) return "{$h}h {$m}m";
    if ($m > 0) return "{$m}m {$s}s";
    return "{$s}s";
}

$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'dashboard_refresh'");
$stmt->execute();
$dashboard_refresh = max(10, (int)($stmt->fetchColumn() ?: 30));

$page_title = 'Dashboard';
$breadcrumb = [['label' => 'Dashboard']];
include __DIR__ . '/includes/header.php';
?>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:linear-gradient(135deg,#0f3460,#533483);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-hdd-network-fill text-white fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Total</div>
                    <div class="fs-3 fw-bold text-dark"><?= $total ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:linear-gradient(135deg,#198754,#20c997);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-wifi text-white fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Online</div>
                    <div class="fs-3 fw-bold text-success"><?= $online_count ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:linear-gradient(135deg,#dc3545,#e74c3c);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-wifi-off text-white fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Offline</div>
                    <div class="fs-3 fw-bold text-danger"><?= $offline_count ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:linear-gradient(135deg,#6c757d,#495057);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-slash-circle text-white fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Inativo</div>
                    <div class="fs-3 fw-bold text-secondary"><?= $inactive_count ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Devices grid -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-cpu me-2"></i>Dispositivos Registrados</h5>
    <a href="/devices/add.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Adicionar Dispositivo
    </a>
</div>

<?php if (empty($devices)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-hdd-network display-4 d-block mb-3 opacity-25"></i>
        <p class="mb-2">Nenhum dispositivo registrado ainda.</p>
        <a href="/devices/add.php" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Adicionar primeiro dispositivo
        </a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($devices as $dev): ?>
    <div class="col-md-6 col-xl-4">
        <?php
        $border_color = !$dev['ativo'] ? '#6c757d' : ($dev['online'] ? '#198754' : '#dc3545');
        ?>
        <div class="card h-100" style="border-left: 4px solid <?= $border_color ?>;">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <h6 class="card-title mb-0 fw-bold"><?= h($dev['name'] ?: $dev['unique_id']) ?></h6>
                        <div class="text-muted small font-monospace"><?= h($dev['unique_id']) ?></div>
                    </div>
                    <?php if (!$dev['ativo']): ?>
                    <span class="badge bg-secondary ms-2">
                        <i class="bi bi-slash-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Inativo
                    </span>
                    <?php elseif ($dev['online']): ?>
                    <span class="badge badge-online ms-2">
                        <i class="bi bi-circle-fill me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Online
                    </span>
                    <?php else: ?>
                    <span class="badge badge-offline ms-2">
                        <i class="bi bi-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Offline
                    </span>
                    <?php endif; ?>
                </div>

                <div class="row g-1 small text-muted mb-3">
                    <?php if ($dev['ip']): ?>
                    <div class="col-6">
                        <i class="bi bi-ethernet me-1"></i>
                        <span class="font-monospace"><?= h($dev['ip']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($dev['hostname']): ?>
                    <div class="col-6">
                        <i class="bi bi-pc-display me-1"></i>
                        <?= h($dev['hostname']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($dev['firmware_version']): ?>
                    <div class="col-6">
                        <i class="bi bi-code-slash me-1"></i>
                        v<?= h($dev['firmware_version']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($dev['free_heap']): ?>
                    <div class="col-6">
                        <i class="bi bi-memory me-1"></i>
                        <?= format_heap((int)$dev['free_heap']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($dev['wifi_rssi']): ?>
                    <div class="col-6">
                        <i class="bi bi-wifi me-1"></i>
                        <?= h($dev['wifi_rssi']) ?> dBm
                    </div>
                    <?php endif; ?>
                    <?php if ($dev['uptime_ms']): ?>
                    <div class="col-6">
                        <i class="bi bi-clock me-1"></i>
                        <?= format_uptime((int)$dev['uptime_ms']) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="text-muted small mb-3">
                    <i class="bi bi-clock-history me-1"></i>
                    Última vez: <strong><?= relative_time((int)$dev['last_seen_unix']) ?></strong>
                </div>

                <div class="d-flex gap-2">
                    <a href="/devices/config_geral.php?device_id=<?= $dev['id'] ?>" class="btn btn-sm btn-primary flex-fill">
                        <i class="bi bi-gear me-1"></i>Configurar
                    </a>
                    <a href="/devices/view.php?device_id=<?= $dev['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill">
                        <i class="bi bi-eye me-1"></i>Detalhes
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="text-end text-muted small mt-3">
    <i class="bi bi-arrow-clockwise me-1"></i>
    Atualiza em <span id="refresh_countdown"><?= $dashboard_refresh ?></span>s
</div>

<script>
let countdown = <?= $dashboard_refresh ?>;
const el = document.getElementById('refresh_countdown');
setInterval(() => {
    countdown--;
    el.textContent = countdown;
    if (countdown <= 0) location.reload();
}, 1000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
