<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();

// Handle quick-register from scan result
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_register'])) {
    csrf_verify();
    $unique_id = trim($_POST['unique_id'] ?? '');
    $hostname  = trim($_POST['hostname']  ?? '');
    $ip        = trim($_POST['ip']        ?? '');
    $name      = trim($_POST['name']      ?? '') ?: $hostname ?: $unique_id;

    if ($unique_id !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $unique_id)) {
        $stmt = $db->prepare('SELECT id FROM devices WHERE unique_id = ?');
        $stmt->execute([$unique_id]);
        if (!$stmt->fetch()) {
            $api_token = bin2hex(random_bytes(32));
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO devices (unique_id, name, api_token, last_seen, online) VALUES (?, ?, ?, NOW(), 1)');
            $stmt->execute([$unique_id, $name, $api_token]);
            $device_id = (int)$db->lastInsertId();
            $stmt = $db->prepare('INSERT INTO device_config (device_id, hostname) VALUES (?, ?)');
            $stmt->execute([$device_id, $hostname ?: 'esp32modularx']);
            $stmt = $db->prepare('INSERT INTO device_status (device_id, ip, hostname) VALUES (?, ?, ?)');
            $stmt->execute([$device_id, $ip, $hostname]);
            $db->commit();
            set_flash('success', "Dispositivo \"{$name}\" cadastrado com sucesso!");
            header('Location: ' . BASE . '/devices/view.php?device_id=' . $device_id);
            exit;
        } else {
            set_flash('danger', "Dispositivo com ID \"{$unique_id}\" já está cadastrado.");
        }
    }
}

// Get register token from settings
$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'register_token'");
$stmt->execute();
$reg_token_row = $stmt->fetch();
$register_token = $reg_token_row ? $reg_token_row['value'] : 'NÃO CONFIGURADO';

$page_title = 'Descobrir Dispositivos';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => 'Descobrir'],
];
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">

    <!-- mDNS Discovery (método principal) -->
    <div class="col-lg-7">
        <div class="card border-success border-opacity-25">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-broadcast-pin text-success fs-5"></i>
                <h5 class="mb-0">Descoberta via mDNS <span class="badge bg-success ms-1">Recomendado</span></h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    O servidor usa <code>avahi-browse</code> para encontrar automaticamente todos os ESP32
                    com firmware SMCR anunciando na rede via mDNS (<code>_http._tcp</code> com
                    <code>device_type=smcr</code>).
                </p>

                <button id="btn_mdns" class="btn btn-success">
                    <i class="bi bi-broadcast me-1"></i>Descobrir via mDNS
                </button>

                <div id="mdns_progress" class="mt-3 d-none">
                    <div class="d-flex align-items-center gap-2 text-muted">
                        <div class="spinner-border spinner-border-sm text-success"></div>
                        <span>Consultando mDNS na rede...</span>
                    </div>
                </div>

                <div id="mdns_results" class="mt-3 d-none"></div>
            </div>
        </div>

        <!-- IP Range Scanner (método alternativo) -->
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-radar text-primary fs-5"></i>
                <h5 class="mb-0">Scanner por Range de IP <span class="badge bg-secondary ms-1">Alternativo</span></h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Varre um range de IPs buscando dispositivos SMCR. Use como alternativa ao mDNS.
                </p>
                <div class="row g-2 mb-3">
                    <div class="col-sm-8">
                        <label class="form-label fw-semibold small">Range de IP</label>
                        <input type="text" id="ip_range" class="form-control form-control-sm font-monospace"
                               placeholder="192.168.1.0/24  ou  192.168.1.1-254">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fw-semibold small">Porta</label>
                        <input type="number" id="scan_port" class="form-control form-control-sm" value="8080" min="1" max="65535">
                    </div>
                </div>
                <button id="btn_scan" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Varrer IPs
                </button>
                <div id="scan_progress" class="mt-3 d-none">
                    <div class="d-flex align-items-center gap-2 text-muted">
                        <div class="spinner-border spinner-border-sm text-primary"></div>
                        <span id="scan_status">Varrendo...</span>
                    </div>
                </div>
                <div id="scan_results" class="mt-3 d-none"></div>
            </div>
        </div>
    </div>

    <!-- Auto-registro pelo ESP32 -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-cpu-fill text-primary fs-5"></i>
                <h5 class="mb-0">Auto-Registro (ESP32 → Cloud)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Configure o ESP32 para se registrar automaticamente neste cloud ao ligar.
                    Ele chama o endpoint abaixo e recebe seu <code>api_token</code>.
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Endpoint de Registro</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light font-monospace text-muted">POST</span>
                        <input type="text" class="form-control font-monospace bg-light"
                               value="http://smcr.pensenet.com.br/api/register.php" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyText(this, 'http://smcr.pensenet.com.br/api/register.php')">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Token de Auto-Registro</label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="reg_token_field" class="form-control font-monospace bg-light"
                               value="<?= h($register_token) ?>" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyText(this, document.getElementById('reg_token_field').value)">
                            <i class="bi bi-clipboard"></i>
                        </button>
                        <a href="<?= BASE ?>/settings.php" class="btn btn-outline-warning" title="Gerenciar token">
                            <i class="bi bi-sliders"></i>
                        </a>
                    </div>
                </div>

                <hr>
                <p class="fw-semibold small mb-2">Payload enviado pelo ESP32:</p>
                <pre class="bg-dark text-success rounded p-2 mb-0" style="font-size:0.72rem">{
  "unique_id":       "smcr_A1B2C3D4E5F6",
  "register_token":  "<?= h(substr($register_token, 0, 8)) ?>...",
  "hostname":        "esp32modularx",
  "ip":              "192.168.1.100",
  "port":            8080,
  "firmware_version":"2.1.2"
}</pre>
            </div>
        </div>

        <!-- Como o mDNS funciona -->
        <div class="card mt-3 bg-light border-0">
            <div class="card-body py-3">
                <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-1 text-primary"></i>Como funciona</h6>
                <p class="small text-muted mb-1">
                    O firmware SMCR anuncia via mDNS com os TXT records:
                </p>
                <pre class="bg-dark text-light rounded p-2 mb-0" style="font-size:0.72rem">device_type = smcr
device      = SMCR
version     = 2.1.2</pre>
                <p class="small text-muted mt-2 mb-0">
                    O servidor detecta esses anúncios automaticamente com <code>avahi-browse</code>
                    e consulta <code>/api/mqtt/status</code> para obter o <code>unique_id</code> de cada dispositivo.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Quick-register form -->
<form method="POST" action="<?= BASE ?>/devices/discover.php" id="quick_register_form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="quick_register" value="1">
    <input type="hidden" name="unique_id" id="qr_unique_id">
    <input type="hidden" name="hostname"  id="qr_hostname">
    <input type="hidden" name="ip"        id="qr_ip">
    <input type="hidden" name="name"      id="qr_name">
</form>

<script>
function copyText(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        setTimeout(() => { btn.innerHTML = orig; btn.classList.replace('btn-success', 'btn-outline-secondary'); }, 1500);
    });
}

function renderDeviceTable(found, container) {
    if (found.length === 0) {
        container.innerHTML = `<div class="alert alert-warning mb-0">
            <i class="bi bi-search me-2"></i>Nenhum dispositivo SMCR encontrado na rede.
        </div>`;
        return;
    }

    let html = `<div class="alert alert-success py-2 mb-3">
        <i class="bi bi-check-circle me-1"></i>
        <strong>${found.length}</strong> dispositivo(s) SMCR encontrado(s).
    </div>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th>Hostname</th><th>IP</th><th>ID Único</th><th>Versão</th><th>Situação</th><th></th>
        </tr></thead><tbody>`;

    found.forEach(dev => {
        const badge = dev.already_registered
            ? '<span class="badge bg-secondary">Cadastrado</span>'
            : '<span class="badge bg-warning text-dark">Novo</span>';
        const action = dev.already_registered
            ? '<span class="text-muted small">—</span>'
            : `<button class="btn btn-sm btn-success"
                onclick="quickRegister('${dev.unique_id}','${dev.ip}','${dev.hostname}')">
                <i class="bi bi-plus-lg me-1"></i>Cadastrar
               </button>`;

        html += `<tr>
            <td class="font-monospace small">${dev.hostname}</td>
            <td class="font-monospace small">${dev.ip}:${dev.port}</td>
            <td class="font-monospace small">${dev.unique_id || '<span class="text-muted">—</span>'}</td>
            <td><span class="badge bg-light text-dark border">${dev.version || '—'}</span></td>
            <td>${badge}</td>
            <td>${action}</td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
}

// mDNS scan
document.getElementById('btn_mdns').addEventListener('click', function () {
    const btn      = this;
    const progress = document.getElementById('mdns_progress');
    const results  = document.getElementById('mdns_results');

    btn.disabled = true;
    progress.classList.remove('d-none');
    results.classList.add('d-none');

    fetch(BASE_PATH + '/api/mdns_scan.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            progress.classList.add('d-none');
            btn.disabled = false;
            results.classList.remove('d-none');
            if (!data.ok) {
                results.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                return;
            }
            renderDeviceTable(data.found, results);
        })
        .catch(err => {
            progress.classList.add('d-none');
            btn.disabled = false;
            results.classList.remove('d-none');
            results.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        });
});

// IP range scan
document.getElementById('btn_scan').addEventListener('click', function () {
    const ip_range = document.getElementById('ip_range').value.trim();
    const port     = parseInt(document.getElementById('scan_port').value) || 8080;
    const btn      = this;
    const progress = document.getElementById('scan_progress');
    const results  = document.getElementById('scan_results');

    if (!ip_range) { alert('Informe o range de IP.'); return; }

    btn.disabled = true;
    progress.classList.remove('d-none');
    results.classList.add('d-none');
    document.getElementById('scan_status').textContent = `Varrendo ${ip_range}:${port}...`;

    fetch(BASE_PATH + '/api/scan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ip_range, port }),
    })
    .then(r => r.json())
    .then(data => {
        progress.classList.add('d-none');
        btn.disabled = false;
        results.classList.remove('d-none');
        if (!data.ok) {
            results.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            return;
        }
        renderDeviceTable(data.found, results);
    })
    .catch(err => {
        progress.classList.add('d-none');
        btn.disabled = false;
        results.classList.remove('d-none');
        results.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
    });
});

function quickRegister(unique_id, ip, hostname) {
    const name = prompt(`Nome para o dispositivo:\n(${hostname}  ${ip})`, hostname);
    if (name === null) return;
    document.getElementById('qr_unique_id').value = unique_id;
    document.getElementById('qr_hostname').value  = hostname;
    document.getElementById('qr_ip').value        = ip;
    document.getElementById('qr_name').value      = name || hostname;
    document.getElementById('quick_register_form').submit();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
