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

$page_title  = 'Logs — ' . h($device['name'] ?: $device['unique_id']);
$breadcrumb  = [
    ['label' => 'Dispositivos', 'url' => BASE . '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => BASE . '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Logs & Histórico'],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-3 pt-3 pb-2">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Logs & Histórico — <?= h($device['name'] ?: $device['unique_id']) ?></h5>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <label class="form-label mb-0 small text-muted">Auto-refresh:</label>
            <select class="form-select form-select-sm" id="autoRefreshInterval" style="width:auto" onchange="setAutoRefresh(this.value)">
                <option value="0">Desligado</option>
                <option value="5">5s</option>
                <option value="10" selected>10s</option>
                <option value="30">30s</option>
                <option value="60">1min</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadAll()">
                <i class="bi bi-arrow-clockwise"></i> Atualizar
            </button>
        </div>
    </div>
</div>

<!-- Histórico de Acionamentos -->
<div class="container-fluid px-3 pb-3">
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-lightning-charge me-2 text-warning"></i>Histórico de Acionamentos</span>
            <div class="d-flex gap-2 align-items-center">
                <span id="historyCount" class="badge bg-secondary">—</span>
                <a href="<?= BASE ?>/api/export_history.php?device_id=<?= $device_id ?>" class="btn btn-sm btn-outline-success" title="Exportar CSV">
                    <i class="bi bi-download me-1"></i>CSV
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tipo</th>
                            <th>Origem</th>
                            <th>Destino</th>
                            <th>Valor</th>
                            <th class="text-end">Horário</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr><td colspan="4" class="text-muted text-center py-3">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Log Serial -->
<div class="container-fluid px-3 pb-4">
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-terminal me-2 text-success"></i>Log Serial do ESP32</span>
            <div class="d-flex gap-2 align-items-center">
                <span id="serialStatus" class="badge bg-secondary small">—</span>
                <button class="btn btn-sm btn-outline-success" onclick="exportSerialLog()" title="Exportar TXT">
                    <i class="bi bi-download me-1"></i>TXT
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="loadSerialLog()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="serialLogBody"
                 style="min-height:320px;max-height:600px;overflow-y:auto;background:#1e1e1e;font-family:monospace;font-size:0.8rem;padding:12px;color:#d4d4d4;border-radius:0 0 4px 4px">
                <span class="text-secondary">Carregando...</span>
            </div>
        </div>
    </div>
</div>

<script>
const ACTION_NAMES = {1:'LIGA', 2:'LIGA_DELAY', 3:'PISCA', 4:'PULSO', 5:'PULSO_DELAY'};
const ACTION_COLORS = {1:'success', 2:'info', 3:'warning', 4:'primary', 5:'secondary'};
let autoRefreshTimer = null;
let currentSerialLogs = [];

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function loadHistory() {
    fetch('<?= BASE ?>/api/get_action_history.php?device_id=<?= $device_id ?>')
    .then(r => r.json())
    .then(data => {
        const tbody = document.getElementById('historyBody');
        const count = document.getElementById('historyCount');
        if (!data.ok || !data.events || data.events.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3">Nenhum acionamento registrado.</td></tr>';
            count.textContent = '0';
            return;
        }
        count.textContent = data.events.length;
        tbody.innerHTML = data.events.map(e => {
            const tipo = e.tipo;
            const nome = ACTION_NAMES[tipo] || 'TIPO ' + tipo;
            const cor  = ACTION_COLORS[tipo] || 'secondary';
            const val  = e.valor_pino !== undefined && e.valor_pino !== null
                ? `<span class="badge bg-secondary font-monospace">${escHtml(String(e.valor_pino))}</span>`
                : '<span class="text-muted">—</span>';
            return `<tr>
                <td><span class="badge bg-${cor}">${escHtml(nome)}</span></td>
                <td><span class="badge bg-dark font-monospace">GPIO ${escHtml(e.gpio_origem)}</span></td>
                <td><span class="badge bg-dark font-monospace">GPIO ${escHtml(e.gpio_destino)}</span></td>
                <td>${val}</td>
                <td class="text-end text-muted small">${escHtml(e.ts)}</td>
            </tr>`;
        }).join('');
    })
    .catch(() => {
        document.getElementById('historyBody').innerHTML = '<tr><td colspan="4" class="text-danger text-center py-3">Erro ao carregar histórico.</td></tr>';
    });
}

function loadSerialLog() {
    const el     = document.getElementById('serialLogBody');
    const status = document.getElementById('serialStatus');
    const wasAtBottom = el.scrollHeight - el.clientHeight <= el.scrollTop + 10;

    fetch('<?= BASE ?>/api/proxy_device.php?device_id=<?= $device_id ?>&endpoint=api/serial/logs')
    .then(r => r.json())
    .then(data => {
        if (!data.logs || data.logs.length === 0) {
            el.innerHTML = '<span class="text-secondary">Nenhum log disponível.</span>';
            status.textContent = '0 linhas';
            status.className = 'badge bg-secondary small';
            return;
        }
        currentSerialLogs = data.logs;
        el.innerHTML = data.logs.map(l => `<div>${escHtml(l)}</div>`).join('');
        status.textContent = data.logs.length + ' linhas';
        status.className = 'badge bg-success small';
        if (wasAtBottom) el.scrollTop = el.scrollHeight;
    })
    .catch(() => {
        el.innerHTML = '<span class="text-danger">Dispositivo offline ou sem resposta.</span>';
        status.textContent = 'Offline';
        status.className = 'badge bg-danger small';
    });
}

function exportSerialLog() {
    if (!currentSerialLogs.length) return;
    const blob = new Blob([currentSerialLogs.join('\n')], {type: 'text/plain;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'serial_<?= $device_id ?>_' + new Date().toISOString().replace(/[:.]/g,'-').slice(0,19) + '.txt';
    a.click();
    URL.revokeObjectURL(a.href);
}

function loadAll() {
    loadHistory();
    loadSerialLog();
}

function setAutoRefresh(seconds) {
    if (autoRefreshTimer) { clearInterval(autoRefreshTimer); autoRefreshTimer = null; }
    if (parseInt(seconds) > 0) {
        autoRefreshTimer = setInterval(loadAll, parseInt(seconds) * 1000);
    }
}

// Carrega ao abrir a página e inicia auto-refresh padrão (10s)
loadAll();
setAutoRefresh(document.getElementById('autoRefreshInterval').value);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
