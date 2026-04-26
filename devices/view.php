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
    header('Location: ' . BASE . '/devices/index.php');
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

$stmt = $db->prepare('SELECT reboot_on_sync, ota_update_on_sync FROM device_config WHERE device_id = ?');
$stmt->execute([$device_id]);
$sync_flags = $stmt->fetch();

$stmt = $db->prepare('SELECT event, created_at FROM device_events WHERE device_id = ? ORDER BY created_at DESC LIMIT 30');
$stmt->execute([$device_id]);
$events = $stmt->fetchAll();
$reboot_on_sync    = (bool)($sync_flags['reboot_on_sync'] ?? false);
$ota_update_on_sync = (bool)($sync_flags['ota_update_on_sync'] ?? false);

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

$is_online = (bool)$device['online'];
$is_ativo  = (bool)($device['ativo'] ?? true);

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
                <?php if (!$is_ativo): ?>
                <span class="badge bg-secondary ms-2 fs-6">
                    <i class="bi bi-slash-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Inativo
                </span>
                <?php else: ?>
                <span class="badge <?= $is_online ? 'badge-online' : 'badge-offline' ?> ms-2 fs-6">
                    <i class="bi <?= $is_online ? 'bi-circle-fill' : 'bi-circle' ?> me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                    <?= $is_online ? 'Online' : 'Offline' ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE ?>/devices/edit.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Editar Nome
                </a>
                <a href="<?= BASE ?>/devices/config_geral.php?device_id=<?= $device_id ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-gear me-1"></i>Configurar
                </a>
                <a href="<?= BASE ?>/api/export_device.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download me-1"></i>Exportar
                </a>
                <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalImport">
                    <i class="bi bi-upload me-1"></i>Importar
                </button>
                <button id="btn_toggle_ativo" class="btn btn-sm <?= $is_ativo ? 'btn-outline-secondary' : 'btn-secondary' ?>"
                        onclick="toggleAtivo(<?= $device_id ?>)"
                        title="<?= $is_ativo ? 'Desativar dispositivo' : 'Ativar dispositivo' ?>">
                    <i class="bi <?= $is_ativo ? 'bi-pause-circle me-1' : 'bi-play-circle me-1' ?>"></i><?= $is_ativo ? 'Desativar' : 'Ativar' ?>
                </button>
                <a href="<?= BASE ?>/devices/delete.php?device_id=<?= $device_id ?>" class="btn btn-outline-danger btn-sm">
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
                    <button id="btn_reboot" class="btn btn-sm btn-outline-danger"
                            onclick="rebootDevice(<?= $device_id ?>)"
                            <?= $device['online'] ? '' : 'disabled title="Dispositivo offline"' ?>>
                        <i class="bi bi-power me-1"></i>Reiniciar ESP32
                    </button>
                    <button id="btn_reboot_sync" class="btn btn-sm <?= $reboot_on_sync ? 'btn-warning' : 'btn-outline-warning' ?>"
                            onclick="toggleRebootOnSync(<?= $device_id ?>)"
                            title="<?= $reboot_on_sync ? 'Reboot agendado para o próximo sincronismo — clique para cancelar' : 'Agendar reboot no próximo sincronismo do ESP32' ?>">
                        <i class="bi bi-arrow-clockwise me-1"></i><?= $reboot_on_sync ? 'Reboot agendado' : 'Reboot no sync' ?>
                    </button>
                    <button id="btn_ota_sync" class="btn btn-sm <?= $ota_update_on_sync ? 'btn-info' : 'btn-outline-info' ?>"
                            onclick="toggleOtaOnSync(<?= $device_id ?>)"
                            title="<?= $ota_update_on_sync ? 'OTA agendado para o próximo sincronismo — clique para cancelar' : 'Instalar firmware mais recente do GitHub no próximo sincronismo do ESP32' ?>">
                        <i class="bi bi-cloud-arrow-down me-1"></i><?= $ota_update_on_sync ? 'OTA agendado' : 'OTA no sync' ?>
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
                            <div class="text-muted small mb-1"><i class="bi bi-hdd me-1"></i>Flash Firmware</div>
                            <?php
                            $sk_size = (int)($status['sketch_size'] ?? 0);
                            $sk_free = (int)($status['sketch_free'] ?? 0);
                            $sk_total = $sk_size + $sk_free;
                            $sk_pct = $sk_total > 0 ? round($sk_size / $sk_total * 100, 1) : 0;
                            ?>
                            <?php if ($sk_total > 0): ?>
                            <div class="fw-semibold small"><?= $sk_pct ?>% <span class="text-muted">(<?= round($sk_size/1024) ?> / <?= round($sk_total/1024) ?> KB)</span></div>
                            <div class="progress mt-1" style="height:4px">
                                <div class="progress-bar <?= $sk_pct > 90 ? 'bg-danger' : ($sk_pct > 75 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $sk_pct ?>%"></div>
                            </div>
                            <?php else: ?>
                            <div class="fw-semibold small">—</div>
                            <?php endif; ?>
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
                    <a href="<?= BASE ?>/devices/config_pinos.php?device_id=<?= $device_id ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-diagram-3 me-2 text-primary"></i>Pinos configurados</span>
                        <span class="badge bg-primary rounded-pill"><?= $pins_count ?></span>
                    </a>
                    <a href="<?= BASE ?>/devices/config_acoes.php?device_id=<?= $device_id ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-lightning me-2 text-warning"></i>Ações configuradas</span>
                        <span class="badge bg-warning text-dark rounded-pill"><?= $actions_count ?></span>
                    </a>
                    <a href="<?= BASE ?>/devices/config_intermod.php?device_id=<?= $device_id ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
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
                <a href="<?= BASE . $sec['url'] ?>?device_id=<?= $device_id ?>" class="text-decoration-none">
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

<?php if (!empty($events)): ?>
<div class="container-fluid px-3 pb-4">
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-clock-history me-2"></i>Histórico de Conexões
        </div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush" style="max-height:240px;overflow-y:auto">
                <?php foreach ($events as $ev): ?>
                <div class="list-group-item d-flex align-items-center gap-2 py-2">
                    <?php if ($ev['event'] === 'online'): ?>
                        <span class="badge bg-success">Online</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Offline</span>
                    <?php endif; ?>
                    <span class="text-muted small"><?= date('d/m/Y H:i:s', strtotime($ev['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const ACTION_NAMES = {1:'LIGA', 2:'LIGA_DELAY', 3:'PISCA', 4:'PULSO', 5:'PULSO_DELAY'};

function loadHistory() {
    fetch(BASE_PATH + '/api/get_action_history.php?device_id=<?= $device_id ?>')
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('historyBody');
        if (!data.ok || !data.events || data.events.length === 0) {
            el.innerHTML = '<div class="list-group-item text-muted small">Nenhum acionamento registrado. Os eventos são salvos a cada heartbeat (~1 min).</div>';
            return;
        }
        el.innerHTML = data.events.map(e =>
            `<div class="list-group-item d-flex gap-2 align-items-center py-2 flex-wrap">
                <span class="badge bg-primary">${ACTION_NAMES[e.tipo] || 'TIPO ' + e.tipo}</span>
                <span class="small">Origem: <strong>GPIO ${e.gpio_origem}</strong></span>
                <span class="small">→ Destino: <strong>GPIO ${e.gpio_destino}</strong></span>
                <span class="text-muted small ms-auto">${e.ts}</span>
            </div>`
        ).join('');
    })
    .catch(() => { document.getElementById('historyBody').innerHTML = '<div class="list-group-item text-danger small">Erro ao carregar histórico.</div>'; });
}

function loadSerialLog() {
    fetch(BASE_PATH + '/api/proxy_device.php?device_id=<?= $device_id ?>&endpoint=api/serial/logs')
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('serialLogBody');
        if (!data.logs || data.logs.length === 0) {
            el.innerHTML = '<span class="text-secondary">Nenhum log disponível.</span>';
            return;
        }
        el.innerHTML = data.logs.map(l => `<div>${escHtml(l)}</div>`).join('');
        el.scrollTop = el.scrollHeight;
    })
    .catch(() => { document.getElementById('serialLogBody').innerHTML = '<span class="text-danger">Erro ao conectar ao dispositivo.</span>'; });
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

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

    fetch(BASE_PATH + '/api/sync_device.php', {
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

function rebootDevice(device_id) {
    if (!confirm('Reiniciar o ESP32 agora?\n\nO dispositivo ficará offline por alguns segundos.')) return;

    const btn = document.getElementById('btn_reboot');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Reiniciando...';

    fetch(BASE_PATH + '/api/reboot_device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-power me-1"></i>Reiniciar ESP32';
        if (!data.ok) { alert('Erro:\n' + data.error); return; }
        alert('Comando de reinicialização enviado.\nO ESP32 estará de volta em alguns segundos.');
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-power me-1"></i>Reiniciar ESP32';
        alert('Erro: ' + err.message);
    });
}

function toggleRebootOnSync(device_id) {
    const btn = document.getElementById('btn_reboot_sync');
    const isActive = btn.classList.contains('btn-warning');
    const enable = !isActive;

    if (enable && !confirm('Agendar reboot no próximo sincronismo?\n\nO ESP32 será reiniciado na próxima vez que buscar a configuração no cloud.')) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';

    fetch(BASE_PATH + '/api/set_reboot_on_sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id, enable }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (!data.ok) { alert('Erro:\n' + data.error); return; }
        if (data.reboot_on_sync) {
            btn.className = 'btn btn-sm btn-warning';
            btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reboot agendado';
            btn.title = 'Reboot agendado para o próximo sincronismo — clique para cancelar';
        } else {
            btn.className = 'btn btn-sm btn-outline-warning';
            btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reboot no sync';
            btn.title = 'Agendar reboot no próximo sincronismo do ESP32';
        }
    })
    .catch(err => {
        btn.disabled = false;
        alert('Erro: ' + err.message);
    });
}

function toggleOtaOnSync(device_id) {
    const btn = document.getElementById('btn_ota_sync');
    const isActive = btn.classList.contains('btn-info');
    const enable = !isActive;

    if (enable && !confirm('Agendar atualização de firmware (OTA) no próximo sincronismo?\n\nO ESP32 buscará o firmware mais recente do GitHub e se atualizará automaticamente.\nO dispositivo ficará offline por aproximadamente 1 minuto durante a atualização.')) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';

    fetch(BASE_PATH + '/api/set_ota_on_sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id, enable }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (!data.ok) { alert('Erro:\n' + data.error); return; }
        if (data.ota_update_on_sync) {
            btn.className = 'btn btn-sm btn-info';
            btn.innerHTML = '<i class="bi bi-cloud-arrow-down me-1"></i>OTA agendado';
            btn.title = 'OTA agendado para o próximo sincronismo — clique para cancelar';
        } else {
            btn.className = 'btn btn-sm btn-outline-info';
            btn.innerHTML = '<i class="bi bi-cloud-arrow-down me-1"></i>OTA no sync';
            btn.title = 'Instalar firmware mais recente do GitHub no próximo sincronismo do ESP32';
        }
    })
    .catch(err => {
        btn.disabled = false;
        alert('Erro: ' + err.message);
    });
}

function pushDevice(device_id) {
    const btn = document.getElementById('btn_push');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Carregando...';

    fetch(BASE_PATH + '/api/get_push_preview.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Cloud → ESP32';
        if (!data.ok) { alert('Erro: ' + data.error); return; }

        const d = data.device, c = data.config, n = data.counts;
        let html = '';
        if (!d.online) html += '<div class="alert alert-warning py-2 mb-3"><i class="bi bi-exclamation-triangle me-1"></i>Dispositivo está <strong>offline</strong>. O envio pode falhar.</div>';
        html += '<table class="table table-sm table-bordered mb-3">';
        html += '<tbody>';
        html += `<tr><td class="text-muted" style="width:45%">Dispositivo</td><td><strong>${d.name}</strong></td></tr>`;
        html += `<tr><td class="text-muted">IP</td><td>${d.ip || '—'}</td></tr>`;
        if (d.firmware_version) html += `<tr><td class="text-muted">Firmware</td><td>v${d.firmware_version}</td></tr>`;
        if (d.flash_pct !== null) html += `<tr><td class="text-muted">Flash uso</td><td>${d.flash_pct}%</td></tr>`;
        html += `<tr><td class="text-muted">Pinos</td><td>${n.pins}</td></tr>`;
        html += `<tr><td class="text-muted">Ações</td><td>${n.actions}</td></tr>`;
        html += `<tr><td class="text-muted">Inter-módulos ativos</td><td>${n.intermod}</td></tr>`;
        html += `<tr><td class="text-muted">MQTT</td><td>${c.mqtt_enabled ? '✓ ' + c.mqtt_server : '✗ Desabilitado'}</td></tr>`;
        html += `<tr><td class="text-muted">Telegram</td><td>${c.telegram_enabled ? '✓ Habilitado' : '✗ Desabilitado'}</td></tr>`;
        html += `<tr><td class="text-muted">Cloud URL</td><td>${c.cloud_url}</td></tr>`;
        html += `<tr><td class="text-muted">OTA no sync</td><td>${c.ota_enabled ? '✓' : '✗'}</td></tr>`;
        html += `<tr><td class="text-muted">Reboot no sync</td><td>${c.reboot_on_sync ? '✓' : '✗'}</td></tr>`;
        html += '</tbody></table>';

        document.getElementById('pushPreviewBody').innerHTML = html;
        document.getElementById('btnConfirmPush').onclick = () => {
            bootstrap.Modal.getInstance(document.getElementById('modalPushConfirm')).hide();
            doPushDevice(device_id);
        };
        new bootstrap.Modal(document.getElementById('modalPushConfirm')).show();
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Cloud → ESP32';
        alert('Erro: ' + err.message);
    });
}

function doPushDevice(device_id) {
    const btn = document.getElementById('btn_push');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';

    fetch(BASE_PATH + '/api/push_device.php', {
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

function toggleAtivo(device_id) {
    const btn = document.getElementById('btn_toggle_ativo');
    const ativando = btn.querySelector('i').classList.contains('bi-play-circle');
    const msg = ativando ? 'Ativar este dispositivo?' : 'Desativar este dispositivo?\n\nEle não receberá sync e não será monitorado.';
    if (!confirm(msg)) return;
    btn.disabled = true;
    fetch(BASE_PATH + '/api/toggle_device_active.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id: device_id })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { alert('Erro: ' + data.error); btn.disabled = false; return; }
        location.reload();
    })
    .catch(err => { btn.disabled = false; alert('Erro: ' + err.message); });
}

function importBackup() {
    const fileInput = document.getElementById('import_file');
    const updateToken = document.getElementById('import_update_token').checked;
    if (!fileInput.files.length) { alert('Selecione um arquivo de backup.'); return; }

    const btn = document.getElementById('btn_import_submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...';

    const fd = new FormData();
    fd.append('device_id', <?= $device_id ?>);
    fd.append('update_token', updateToken ? '1' : '');
    fd.append('backup_file', fileInput.files[0]);

    fetch(BASE_PATH + '/api/import_device.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-upload me-1"></i>Restaurar';
            if (!data.ok) { alert('Erro:\n' + data.error); return; }
            bootstrap.Modal.getInstance(document.getElementById('modalImport')).hide();
            alert('Backup restaurado com sucesso!');
            location.reload();
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-upload me-1"></i>Restaurar';
            alert('Erro: ' + err.message);
        });
}
</script>

<!-- Histórico de Acionamentos + Log Serial -->
<div class="container-fluid px-3 pb-4">
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-lightning-charge me-2"></i>Histórico de Acionamentos</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadHistory()"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="card-body p-0">
                    <div id="historyBody" class="list-group list-group-flush" style="max-height:220px;overflow-y:auto">
                        <div class="list-group-item text-muted small">Clique em atualizar para carregar.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-terminal me-2"></i>Log Serial</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadSerialLog()"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="card-body p-0">
                    <div id="serialLogBody" style="max-height:220px;overflow-y:auto;background:#1e1e1e;font-family:monospace;font-size:0.75rem;padding:8px;color:#d4d4d4;border-radius:0 0 4px 4px">
                        <span class="text-secondary">Clique em atualizar para carregar.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Push Confirm -->
<div class="modal fade" id="modalPushConfirm" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Confirmar Sincronização Cloud → ESP32</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Revise o que será enviado ao dispositivo:</p>
                <div id="pushPreviewBody"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnConfirmPush" class="btn btn-primary">
                    <i class="bi bi-cloud-upload me-1"></i>Confirmar Envio
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Import -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Importar Backup</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Selecione um arquivo <code>.json</code> exportado pelo SMCR Cloud. As configurações, pinos, ações e inter-módulos serão substituídos pelos dados do backup.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Arquivo de Backup</label>
                    <input type="file" id="import_file" class="form-control" accept=".json">
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="import_update_token">
                    <label class="form-check-label" for="import_update_token">
                        Restaurar chave de API (api_token)
                    </label>
                    <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Só ative se o ESP32 ainda usa a chave do backup.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btn_import_submit" class="btn btn-primary" onclick="importBackup()">
                    <i class="bi bi-upload me-1"></i>Restaurar
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
