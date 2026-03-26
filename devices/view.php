<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();
$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;

$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
$stmt->execute([$device_id]);
$device = $stmt->fetch();

if (!$device) {
    set_flash('danger', 'Dispositivo não encontrado.');
    header('Location: /devices/index.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM device_status WHERE device_id = ?');
$stmt->execute([$device_id]);
$status = $stmt->fetch() ?: [];

$stmt = $db->prepare('SELECT COUNT(*) FROM device_pins WHERE device_id = ?');
$stmt->execute([$device_id]);
$pins_count = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM device_actions WHERE device_id = ?');
$stmt->execute([$device_id]);
$actions_count = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM device_intermod WHERE device_id = ?');
$stmt->execute([$device_id]);
$intermod_count = (int)$stmt->fetchColumn();

function format_uptime_full(int $ms): string {
    $s = (int)($ms / 1000);
    $d = (int)($s / 86400); $s %= 86400;
    $h = (int)($s / 3600); $s %= 3600;
    $m = (int)($s / 60); $s %= 60;
    $parts = [];
    if ($d) $parts[] = "{$d}d";
    if ($h) $parts[] = "{$h}h";
    if ($m) $parts[] = "{$m}m";
    $parts[] = "{$s}s";
    return implode(' ', $parts);
}

function format_heap(int $bytes): string {
    if ($bytes >= 1024) return round($bytes/1024, 1) . ' KB';
    return $bytes . ' B';
}

// Mark offline check
$is_online = (bool)$device['online'];

$page_title = $device['name'] ?: $device['unique_id'];
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => $page_title]
];
include __DIR__ . '/../includes/header.php';
?>

<!-- Device header bar -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div style="width:52px;height:52px;background:linear-gradient(135deg,#0f3460,#533483);border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-cpu-fill text-white fs-4"></i>
                </div>
                <div>
                    <h4 class="mb-0 fw-bold"><?= h($device['name'] ?: $device['unique_id']) ?></h4>
                    <div class="text-muted small font-monospace"><?= h($device['unique_id']) ?></div>
                </div>
                <span class="badge <?= $is_online ? 'badge-online' : 'badge-offline' ?> ms-2 fs-6">
                    <i class="bi <?= $is_online ? 'bi-circle-fill' : 'bi-circle' ?> me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                    <?= $is_online ? 'Online' : 'Offline' ?>
                </span>
            </div>
            <div class="d-flex gap-2">
                <a href="/devices/edit.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Editar Nome
                </a>
                <a href="/devices/config_geral.php?device_id=<?= $device_id ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-gear me-1"></i>Configurar
                </a>
                <a href="/devices/delete.php?device_id=<?= $device_id ?>" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash me-1"></i>Excluir
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Status info -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h6 class="mb-0 fw-bold"><i class="bi bi-activity me-2"></i>Status em Tempo Real</h6>
                <div class="d-flex gap-2 flex-wrap">
                    <button id="btn_pull" class="btn btn-sm btn-success"
                            onclick="pullDevice(<?= $device_id ?>)"
                            <?= $device['online'] ? '' : 'disabled title="Dispositivo offline"' ?>>
                        <i class="bi bi-cloud-download me-1"></i>ESP32 → Cloud
                    </button>
                    <button id="btn_push" class="btn btn-sm btn-primary"
                            onclick="pushDevice(<?= $device_id ?>)"
                            <?= $device['online'] ? '' : 'disabled title="Dispositivo offline"' ?>>
                        <i class="bi bi-cloud-upload me-1"></i>Cloud → ESP32
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($status) || (!$status['ip'] && !$status['firmware_version'])): ?>
                <div class="text-center text-muted py-3">
                    <i class="bi bi-exclamation-circle display-6 d-block mb-2 opacity-25"></i>
                    <p class="small mb-0">O dispositivo ainda não enviou dados de status.<br>
                    Configure o ESP32 com o token API para começar.</p>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-6 col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small mb-1"><i class="bi bi-ethernet me-1"></i>IP</div>
                            <div class="fw-semibold font-monospace small"><?= h($status['ip'] ?: '—') ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small mb-1"><i class="bi bi-pc-display me-1"></i>Hostname</div>
                            <div class="fw-semibold small"><?= h($status['hostname'] ?: '—') ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small mb-1"><i class="bi bi-code-slash me-1"></i>Firmware</div>
                            <div class="fw-semibold small"><?= $status['firmware_version'] ? 'v' . h($status['firmware_version']) : '—' ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small mb-1"><i class="bi bi-memory me-1"></i>Heap Livre</div>
                            <div class="fw-semibold small"><?= $status['free_heap'] ? format_heap((int)$status['free_heap']) : '—' ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small mb-1"><i class="bi bi-wifi me-1"></i>WiFi RSSI</div>
                            <div class="fw-semibold small"><?= $status['wifi_rssi'] ? $status['wifi_rssi'] . ' dBm' : '—' ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small mb-1"><i class="bi bi-clock me-1"></i>Uptime</div>
                            <div class="fw-semibold small"><?= $status['uptime_ms'] ? format_uptime_full((int)$status['uptime_ms']) : '—' ?></div>
                        </div>
                    </div>
                </div>
                <?php if ($status['updated_at']): ?>
                <div class="text-muted small mt-3">
                    <i class="bi bi-clock-history me-1"></i>
                    Último update: <?= h($status['updated_at']) ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Token & summary -->
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="bi bi-key-fill me-2"></i>Token de API</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">Use este token para autenticar o ESP32 com a API.</p>
                <div class="input-group">
                    <input type="password" class="form-control form-control-sm font-monospace" id="api_token"
                           value="<?= h($device['api_token']) ?>" readonly>
                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="toggleToken()">
                        <i class="bi bi-eye" id="token_eye"></i>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyToken()">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <div class="mt-2 small text-muted">
                    <strong>Endpoint:</strong>
                    <code class="d-block">POST /api/status.php</code>
                    <code>Authorization: Bearer &lt;token&gt;</code>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="bi bi-bar-chart-fill me-2"></i>Resumo de Configurações</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <a href="/devices/config_pinos.php?device_id=<?= $device_id ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-diagram-3 me-2 text-primary"></i>Pinos configurados</span>
                        <span class="badge bg-primary rounded-pill"><?= $pins_count ?></span>
                    </a>
                    <a href="/devices/config_acoes.php?device_id=<?= $device_id ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-lightning me-2 text-warning"></i>Ações configuradas</span>
                        <span class="badge bg-warning text-dark rounded-pill"><?= $actions_count ?></span>
                    </a>
                    <a href="/devices/config_intermod.php?device_id=<?= $device_id ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-share me-2 text-info"></i>Inter-Módulos</span>
                        <span class="badge bg-info text-dark rounded-pill"><?= $intermod_count ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Config quick nav -->
<div class="card mt-4">
    <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="bi bi-grid me-2"></i>Configurações Disponíveis</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php
            $config_sections = [
                ['url' => '/devices/config_geral.php', 'icon' => 'bi-gear-fill', 'color' => '#0f3460', 'title' => 'Config. Geral', 'desc' => 'Rede, NTP, Web, Sistema'],
                ['url' => '/devices/config_pinos.php', 'icon' => 'bi-diagram-3-fill', 'color' => '#198754', 'title' => 'Pinos', 'desc' => 'Entradas e saídas digitais/analógicas'],
                ['url' => '/devices/config_acoes.php', 'icon' => 'bi-lightning-fill', 'color' => '#fd7e14', 'title' => 'Ações', 'desc' => 'Regras e automações por pino'],
                ['url' => '/devices/config_mqtt.php', 'icon' => 'bi-broadcast', 'color' => '#6f42c1', 'title' => 'MQTT', 'desc' => 'Broker, tópicos, Home Assistant'],
                ['url' => '/devices/config_intermod.php', 'icon' => 'bi-share-fill', 'color' => '#0dcaf0', 'title' => 'Inter-Módulos', 'desc' => 'Comunicação entre dispositivos'],
                ['url' => '/devices/config_telegram.php', 'icon' => 'bi-telegram', 'color' => '#229ED9', 'title' => 'Telegram', 'desc' => 'Alertas e notificações'],
            ];
            foreach ($config_sections as $sec):
            ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="<?= $sec['url'] ?>?device_id=<?= $device_id ?>" class="text-decoration-none">
                    <div class="card h-100 text-center p-3 hover-shadow" style="transition:all 0.2s;cursor:pointer;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.12)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                        <div style="width:44px;height:44px;background:<?= $sec['color'] ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 0.75rem;">
                            <i class="bi <?= $sec['icon'] ?> text-white fs-5"></i>
                        </div>
                        <div class="fw-semibold small"><?= $sec['title'] ?></div>
                        <div class="text-muted" style="font-size:0.72rem;"><?= $sec['desc'] ?></div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleToken() {
    const input = document.getElementById('api_token');
    const eye = document.getElementById('token_eye');
    if (input.type === 'password') {
        input.type = 'text';
        eye.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        eye.className = 'bi bi-eye';
    }
}
function copyToken() {
    const input = document.getElementById('api_token');
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = event.currentTarget;
        btn.innerHTML = '<i class="bi bi-check text-success"></i>';
        setTimeout(() => btn.innerHTML = '<i class="bi bi-clipboard"></i>', 1500);
    });
}

function pullDevice(device_id) {
    const btn = document.getElementById('btn_pull');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...';

    fetch('/api/sync_device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-download me-1"></i>ESP32 → Cloud';
        if (!data.ok) { alert('Erro:\n' + data.error); return; }
        const warnings = data.errors.length ? '\n\nAvisos:\n' + data.errors.join('\n') : '';
        alert('ESP32 → Cloud concluído!\n\nImportado: ' + data.imported.join(', ') + warnings);
        location.reload();
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-download me-1"></i>ESP32 → Cloud';
        alert('Erro: ' + err.message);
    });
}

function pushDevice(device_id) {
    if (!confirm('Sincronizar TUDO do Cloud para o ESP32?\n\nIsso inclui configurações gerais, pinos, ações e serviços.\nO ESP32 será reiniciado ao final.')) return;

    const btn = document.getElementById('btn_push');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';

    fetch('/api/push_device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id, push_config: true }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Cloud → ESP32';
        if (!data.ok) { alert('Erro:\n' + data.error); return; }
        const warnings = data.errors.length ? '\n\nAvisos:\n' + data.errors.join('\n') : '';
        alert('Cloud → ESP32 concluído!\n\nEnviado: ' + data.pushed.join(', ') + warnings);
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Cloud → ESP32';
        alert('Erro: ' + err.message);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
