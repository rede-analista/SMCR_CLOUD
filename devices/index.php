<?php
require_once __DIR__ . '/../config/auth.php';
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

$stmt = $db->query("
    SELECT d.id, d.name, d.unique_id, d.online, d.last_seen, d.created_at,
           ds.ip, ds.hostname, ds.firmware_version
    FROM devices d
    LEFT JOIN device_status ds ON ds.device_id = d.id
    ORDER BY d.name ASC, d.unique_id ASC
");
$devices = $stmt->fetchAll();

function relative_time(?string $ts): string {
    if (!$ts || $ts === '0000-00-00 00:00:00') return 'Nunca';
    $diff = time() - strtotime($ts);
    if ($diff < 60) return 'há ' . $diff . 's';
    if ($diff < 3600) return 'há ' . (int)($diff/60) . 'min';
    if ($diff < 86400) return 'há ' . (int)($diff/3600) . 'h';
    return 'há ' . (int)($diff/86400) . 'd';
}

$page_title = 'Dispositivos';
$breadcrumb = [
    ['label' => 'Dispositivos']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-hdd-network-fill me-2"></i>Dispositivos</h4>
        <p class="text-muted small mb-0"><?= count($devices) ?> dispositivo(s) registrado(s)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <a href="/devices/add.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Adicionar Dispositivo
        </a>
        <button id="btn_ota_all" class="btn btn-outline-info" onclick="setAllOta()" <?= empty($devices) ? 'disabled' : '' ?>>
            <i class="bi bi-cloud-arrow-down me-1"></i>OTA em todos
        </button>
        <button id="btn_reboot_all" class="btn btn-outline-warning" onclick="setAllReboot()" <?= empty($devices) ? 'disabled' : '' ?>>
            <i class="bi bi-arrow-clockwise me-1"></i>Reboot em todos
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($devices)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-hdd-network display-4 d-block mb-3 opacity-25"></i>
            <p class="mb-2">Nenhum dispositivo registrado.</p>
            <a href="/devices/add.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Adicionar primeiro dispositivo
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nome</th>
                        <th>ID Único</th>
                        <th>IP / Hostname</th>
                        <th>Firmware</th>
                        <th>Status</th>
                        <th>Última vez online</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $dev): ?>
                    <tr>
                        <td>
                            <a href="/devices/view.php?device_id=<?= $dev['id'] ?>" class="text-decoration-none fw-semibold">
                                <?= h($dev['name'] ?: '(sem nome)') ?>
                            </a>
                        </td>
                        <td>
                            <span class="font-monospace small text-muted"><?= h($dev['unique_id']) ?></span>
                        </td>
                        <td>
                            <?php if ($dev['ip']): ?>
                            <span class="font-monospace small"><?= h($dev['ip']) ?></span>
                            <?php if ($dev['hostname']): ?>
                            <div class="text-muted small"><?= h($dev['hostname']) ?></div>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($dev['firmware_version']): ?>
                            <span class="badge bg-secondary">v<?= h($dev['firmware_version']) ?></span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($dev['online']): ?>
                            <span class="badge badge-online">
                                <i class="bi bi-circle-fill me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Online
                            </span>
                            <?php else: ?>
                            <span class="badge badge-offline">
                                <i class="bi bi-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Offline
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= relative_time($dev['last_seen']) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="/devices/config_geral.php?device_id=<?= $dev['id'] ?>"
                                   class="btn btn-outline-primary" title="Configurar">
                                    <i class="bi bi-gear"></i>
                                </a>
                                <a href="/devices/edit.php?device_id=<?= $dev['id'] ?>"
                                   class="btn btn-outline-secondary" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="/devices/view.php?device_id=<?= $dev['id'] ?>"
                                   class="btn btn-outline-info" title="Visualizar">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="/devices/delete.php?device_id=<?= $dev['id'] ?>"
                                   class="btn btn-outline-danger" title="Excluir">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function setAllOta() {
    if (!confirm('Agendar OTA no próximo sincronismo de TODOS os dispositivos?')) return;
    const btn = document.getElementById('btn_ota_all');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Agendando...';
    fetch('/api/set_ota_all.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (!data.ok) { alert('Erro: ' + data.error); btn.innerHTML = '<i class="bi bi-cloud-arrow-down me-1"></i>OTA em todos'; return; }
            btn.className = 'btn btn-info';
            btn.innerHTML = '<i class="bi bi-cloud-arrow-down me-1"></i>OTA agendado (' + data.updated + ')';
            setTimeout(() => { btn.className = 'btn btn-outline-info'; btn.innerHTML = '<i class="bi bi-cloud-arrow-down me-1"></i>OTA em todos'; }, 5000);
        })
        .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cloud-arrow-down me-1"></i>OTA em todos'; alert('Erro de comunicação.'); });
}

function setAllReboot() {
    if (!confirm('Agendar reboot no próximo sincronismo de TODOS os dispositivos?')) return;
    const btn = document.getElementById('btn_reboot_all');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Agendando...';
    fetch('/api/set_reboot_all.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (!data.ok) { alert('Erro: ' + data.error); btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reboot em todos'; return; }
            btn.className = 'btn btn-warning';
            btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reboot agendado (' + data.updated + ')';
            setTimeout(() => { btn.className = 'btn btn-outline-warning'; btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reboot em todos'; }, 5000);
        })
        .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reboot em todos'; alert('Erro de comunicação.'); });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
