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

$stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
$stmt->execute([$device_id]);
$cfg = $stmt->fetch();

if (!$cfg) {
    $db->prepare('INSERT INTO device_config (device_id) VALUES (?)')->execute([$device_id]);
    $stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $cfg = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'save_global';

    if ($action === 'save_global') {
        $fields = [
            'intermod_enabled'        => isset($_POST['intermod_enabled']) ? 1 : 0,
            'intermod_healthcheck'    => (int)($_POST['intermod_healthcheck'] ?? 60),
            'intermod_max_failures'   => (int)($_POST['intermod_max_failures'] ?? 3),
            'intermod_auto_discovery' => isset($_POST['intermod_auto_discovery']) ? 1 : 0,
        ];
        $set = array_map(fn($k) => "`$k` = ?", array_keys($fields));
        $vals = array_values($fields);
        $vals[] = $device_id;
        $db->prepare('UPDATE device_config SET ' . implode(', ', $set) . ' WHERE device_id = ?')->execute($vals);

        set_flash('success', 'Configurações globais de inter-módulos salvas.');
        $stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
        $stmt->execute([$device_id]);
        $cfg = $stmt->fetch();
    }

    elseif ($action === 'delete_module') {
        $mod_id = (int)($_POST['mod_id'] ?? 0);
        $db->prepare('DELETE FROM device_intermod WHERE id = ? AND device_id = ?')->execute([$mod_id, $device_id]);
        set_flash('success', 'Módulo removido.');
    }

    elseif ($action === 'save_module') {
        $mod_id                    = (int)($_POST['mod_id'] ?? 0);
        $module_id                 = trim($_POST['module_id'] ?? '');
        $hostname                  = trim($_POST['hostname'] ?? '');
        $ip                        = trim($_POST['ip'] ?? '');
        $porta                     = (int)($_POST['porta'] ?? 8080);
        $pins_offline              = trim($_POST['pins_offline'] ?? '');
        $offline_alert_enabled     = isset($_POST['offline_alert_enabled']) ? 1 : 0;
        $offline_flash_ms          = max(50, (int)($_POST['offline_flash_ms'] ?? 200));
        $pins_healthcheck          = trim($_POST['pins_healthcheck'] ?? '');
        $healthcheck_alert_enabled = isset($_POST['healthcheck_alert_enabled']) ? 1 : 0;
        $healthcheck_flash_ms      = max(50, (int)($_POST['healthcheck_flash_ms'] ?? 500));

        if ($module_id === '') {
            set_flash('danger', 'O ID do módulo é obrigatório.');
        } elseif ($mod_id > 0) {
            $db->prepare('UPDATE device_intermod SET module_id=?, hostname=?, ip=?, porta=?,
                pins_offline=?, offline_alert_enabled=?, offline_flash_ms=?,
                pins_healthcheck=?, healthcheck_alert_enabled=?, healthcheck_flash_ms=?
                WHERE id=? AND device_id=?')
               ->execute([$module_id, $hostname, $ip, $porta,
                          $pins_offline, $offline_alert_enabled, $offline_flash_ms,
                          $pins_healthcheck, $healthcheck_alert_enabled, $healthcheck_flash_ms,
                          $mod_id, $device_id]);
            set_flash('success', 'Módulo atualizado.');
        } else {
            try {
                $db->prepare('INSERT INTO device_intermod (device_id, module_id, hostname, ip, porta,
                    pins_offline, offline_alert_enabled, offline_flash_ms,
                    pins_healthcheck, healthcheck_alert_enabled, healthcheck_flash_ms)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$device_id, $module_id, $hostname, $ip, $porta,
                              $pins_offline, $offline_alert_enabled, $offline_flash_ms,
                              $pins_healthcheck, $healthcheck_alert_enabled, $healthcheck_flash_ms]);
                set_flash('success', 'Módulo adicionado com sucesso.');
            } catch (PDOException $e) {
                set_flash('danger', 'Erro: módulo com este ID já cadastrado.');
            }
        }
    }

    header('Location: /devices/config_intermod.php?device_id=' . $device_id);
    exit;
}

// Load modules
$stmt = $db->prepare('SELECT * FROM device_intermod WHERE device_id = ? ORDER BY module_id ASC');
$stmt->execute([$device_id]);
$modules = $stmt->fetchAll();

// Edit module
$edit_module = null;
if (isset($_GET['edit_mod'])) {
    $edit_mod = (int)$_GET['edit_mod'];
    $stmt = $db->prepare('SELECT * FROM device_intermod WHERE id = ? AND device_id = ?');
    $stmt->execute([$edit_mod, $device_id]);
    $edit_module = $stmt->fetch() ?: null;
}
$show_mod_form = isset($_GET['add_mod']) || $edit_module !== null;

$page_title = 'Inter-Módulos';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Inter-Módulos']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-share-fill me-2"></i>Inter-Módulos</h5>
    <span class="text-muted small"><?= h($device['name'] ?: $device['unique_id']) ?></span>
</div>

<!-- Global settings -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="bi bi-sliders me-1"></i>Configurações Globais</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="/devices/config_intermod.php?device_id=<?= $device_id ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_global">

            <div class="row g-3">
                <div class="col-12">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="intermod_enabled"
                               id="intermod_enabled" value="1"
                               <?= $cfg['intermod_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="intermod_enabled">
                            Habilitar comunicação entre módulos
                        </label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="intermod_auto_discovery"
                               id="intermod_auto_discovery" value="1"
                               <?= $cfg['intermod_auto_discovery'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="intermod_auto_discovery">
                            Habilitar descoberta automática de módulos
                        </label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Intervalo de Healthcheck (s)</label>
                    <input type="number" class="form-control" name="intermod_healthcheck"
                           value="<?= h($cfg['intermod_healthcheck']) ?>" min="1">
                    <div class="form-text">Frequência de verificação de módulos ativos.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Máx. Falhas antes de Desconectar</label>
                    <input type="number" class="form-control" name="intermod_max_failures"
                           value="<?= h($cfg['intermod_max_failures']) ?>" min="1" max="255">
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Salvar Configurações Globais
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Module add/edit form -->
<?php if ($show_mod_form): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi <?= $edit_module ? 'bi-pencil-fill text-warning' : 'bi-plus-circle-fill text-success' ?> fs-5"></i>
        <h6 class="mb-0"><?= $edit_module ? 'Editar Módulo' : 'Adicionar Módulo' ?></h6>
    </div>
    <div class="card-body">
        <form method="POST" action="/devices/config_intermod.php?device_id=<?= $device_id ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_module">
            <input type="hidden" name="mod_id" value="<?= $edit_module ? $edit_module['id'] : 0 ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">ID do Módulo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control font-monospace" name="module_id" maxlength="64"
                           value="<?= h($edit_module['module_id'] ?? '') ?>"
                           placeholder="ex: smcr_A1B2C3D4E5F6" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Hostname</label>
                    <input type="text" class="form-control" name="hostname" maxlength="64"
                           value="<?= h($edit_module['hostname'] ?? '') ?>"
                           placeholder="ex: esp32modularx">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">IP</label>
                    <input type="text" class="form-control font-monospace" name="ip" maxlength="45"
                           value="<?= h($edit_module['ip'] ?? '') ?>"
                           placeholder="ex: 192.168.1.200">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Porta</label>
                    <input type="number" class="form-control" name="porta" min="1" max="65535"
                           value="<?= h($edit_module['porta'] ?? 8080) ?>">
                </div>
                <div class="col-12">
                    <div class="border border-warning rounded p-3">
                        <div class="fw-semibold text-warning mb-2">🔴 Alerta de Offline</div>
                        <div class="row g-2">
                            <div class="col-auto d-flex align-items-center">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="offline_alert_enabled"
                                           id="offline_alert_enabled" value="1"
                                           <?= !empty($edit_module['offline_alert_enabled']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="offline_alert_enabled">Habilitar</label>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <input type="text" class="form-control form-control-sm" name="pins_offline" maxlength="255"
                                       value="<?= h($edit_module['pins_offline'] ?? '') ?>"
                                       placeholder="GPIOs offline (ex: 2,4)">
                            </div>
                            <div class="col-md-3">
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control" name="offline_flash_ms" min="50" max="5000"
                                           value="<?= h($edit_module['offline_flash_ms'] ?? 200) ?>">
                                    <span class="input-group-text">ms</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="border border-primary rounded p-3">
                        <div class="fw-semibold text-primary mb-2">🔵 Alerta de HealthCheck</div>
                        <div class="row g-2">
                            <div class="col-auto d-flex align-items-center">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="healthcheck_alert_enabled"
                                           id="healthcheck_alert_enabled" value="1"
                                           <?= !empty($edit_module['healthcheck_alert_enabled']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="healthcheck_alert_enabled">Habilitar</label>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <input type="text" class="form-control form-control-sm" name="pins_healthcheck" maxlength="255"
                                       value="<?= h($edit_module['pins_healthcheck'] ?? '') ?>"
                                       placeholder="GPIOs healthcheck (ex: 5)">
                            </div>
                            <div class="col-md-3">
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control" name="healthcheck_flash_ms" min="50" max="5000"
                                           value="<?= h($edit_module['healthcheck_flash_ms'] ?? 500) ?>">
                                    <span class="input-group-text">ms</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?= $edit_module ? 'Salvar' : 'Adicionar Módulo' ?>
                </button>
                <a href="/devices/config_intermod.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modules table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold">Módulos Registrados (<?= count($modules) ?>)</h6>
        <?php if (!$show_mod_form): ?>
        <a href="?device_id=<?= $device_id ?>&add_mod=1" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-lg me-1"></i>Adicionar Módulo
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($modules)): ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-share display-6 d-block mb-2 opacity-25"></i>
            <p class="small mb-2">Nenhum módulo registrado.</p>
            <a href="?device_id=<?= $device_id ?>&add_mod=1" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Adicionar módulo
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>ID do Módulo</th>
                        <th>Hostname</th>
                        <th>IP</th>
                        <th>Porta</th>
                        <th>GPIOs Offline</th>
                        <th>GPIOs HC</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $mod): ?>
                    <tr>
                        <td class="font-monospace"><?= h($mod['module_id']) ?></td>
                        <td><?= h($mod['hostname'] ?: '—') ?></td>
                        <td class="font-monospace"><?= h($mod['ip'] ?: '—') ?></td>
                        <td><?= $mod['porta'] ?></td>
                        <td><?= $mod['pins_offline'] ? h($mod['pins_offline']) . ($mod['offline_alert_enabled'] ? ' ✓' : ' ✗') : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $mod['pins_healthcheck'] ? h($mod['pins_healthcheck']) . ($mod['healthcheck_alert_enabled'] ? ' ✓' : ' ✗') : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="?device_id=<?= $device_id ?>&edit_mod=<?= $mod['id'] ?>"
                                   class="btn btn-outline-warning" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="/devices/config_intermod.php?device_id=<?= $device_id ?>"
                                      style="display:inline"
                                      onsubmit="return confirm('Remover módulo <?= h(addslashes($mod['module_id'])) ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_module">
                                    <input type="hidden" name="mod_id" value="<?= $mod['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger" title="Remover">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
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
