<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();

// Auto-mark devices offline if last_seen > 2 minutes ago
$db->exec("UPDATE devices SET online = 0 WHERE last_seen < DATE_SUB(NOW(), INTERVAL 2 MINUTE) OR last_seen IS NULL");

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
    <a href="/devices/add.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Adicionar Dispositivo
    </a>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
