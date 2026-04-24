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

$acao_labels = [
    0     => 'Nenhuma',
    1     => 'Liga',
    2     => 'Liga c/ Delay',
    3     => 'Pisca',
    4     => 'Pulso',
    5     => 'Pulso c/ Delay On',
    65534 => 'Status',
    65535 => 'Sincronismo',
];

// Load device pins for selects
$stmt = $db->prepare('SELECT id, pino, nome FROM device_pins WHERE device_id = ? ORDER BY pino ASC');
$stmt->execute([$device_id]);
$device_pins = $stmt->fetchAll();

// Load inter-modules for envia_modulo select
$stmt = $db->prepare('SELECT module_id, hostname FROM device_intermod WHERE device_id = ? ORDER BY module_id ASC');
$stmt->execute([$device_id]);
$intermod_modules = $stmt->fetchAll();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $act_id = (int)($_POST['action_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM device_actions WHERE id = ? AND device_id = ?');
        $stmt->execute([$act_id, $device_id]);
        set_flash('success', 'Ação removida com sucesso.');
        header('Location: ' . BASE . '/devices/config_acoes.php?device_id=' . $device_id);
        exit;
    }

    if ($action === 'save') {
        $act_id       = (int)($_POST['act_id'] ?? 0);
        $pino_origem  = (int)($_POST['pino_origem'] ?? 0);
        $numero_acao  = (int)($_POST['numero_acao'] ?? 1);
        $acao         = (int)($_POST['acao'] ?? 0);
        $pino_destino = (int)($_POST['pino_destino'] ?? 0);
        $tempo_on     = (int)($_POST['tempo_on'] ?? 0);
        $tempo_off    = (int)($_POST['tempo_off'] ?? 0);
        $pino_remoto  = (int)($_POST['pino_remoto'] ?? 0);
        $envia_modulo = trim($_POST['envia_modulo'] ?? '');
        $telegram_chk = isset($_POST['telegram']) ? 1 : 0;
        $assistente   = isset($_POST['assistente']) ? 1 : 0;

        if ($act_id > 0) {
            $stmt = $db->prepare('UPDATE device_actions SET pino_origem=?, numero_acao=?, acao=?,
                pino_destino=?, tempo_on=?, tempo_off=?, pino_remoto=?, envia_modulo=?,
                telegram=?, assistente=? WHERE id=? AND device_id=?');
            $stmt->execute([$pino_origem, $numero_acao, $acao, $pino_destino, $tempo_on, $tempo_off,
                $pino_remoto, $envia_modulo, $telegram_chk, $assistente, $act_id, $device_id]);
            set_flash('success', 'Ação atualizada com sucesso.');
        } else {
            try {
                $stmt = $db->prepare('INSERT INTO device_actions
                    (device_id, pino_origem, numero_acao, acao, pino_destino, tempo_on, tempo_off,
                     pino_remoto, envia_modulo, telegram, assistente)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$device_id, $pino_origem, $numero_acao, $acao, $pino_destino,
                    $tempo_on, $tempo_off, $pino_remoto, $envia_modulo, $telegram_chk, $assistente]);
                set_flash('success', 'Ação adicionada com sucesso.');
            } catch (PDOException $e) {
                set_flash('danger', 'Erro: já existe uma ação ' . $numero_acao . ' para este pino.');
            }
        }
        header('Location: ' . BASE . '/devices/config_acoes.php?device_id=' . $device_id);
        exit;
    }
}

// Load actions
$stmt = $db->prepare('SELECT * FROM device_actions WHERE device_id = ? ORDER BY pino_origem ASC, numero_acao ASC');
$stmt->execute([$device_id]);
$actions = $stmt->fetchAll();

// Edit mode
$edit_action = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare('SELECT * FROM device_actions WHERE id = ? AND device_id = ?');
    $stmt->execute([$edit_id, $device_id]);
    $edit_action = $stmt->fetch() ?: null;
}
$show_form = isset($_GET['add']) || $edit_action !== null;

// Build pin label map
$pin_labels = [];
foreach ($device_pins as $p) {
    $pin_labels[$p['pino']] = 'GPIO ' . $p['pino'] . ($p['nome'] ? ' (' . $p['nome'] . ')' : '');
}

$page_title = 'Configuração de Ações';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Ações']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-lightning-fill me-2"></i>Configuração de Ações</h5>
    <div class="d-flex gap-2 align-items-center">
        <span class="text-muted small"><?= h($device['name'] ?: $device['unique_id']) ?></span>
        <?php if (!$show_form): ?>
        <a href="?device_id=<?= $device_id ?>&add=1" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Adicionar Ação
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($device_pins)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Nenhum pino configurado para este dispositivo. <a href="<?= BASE ?>/devices/config_pinos.php?device_id=<?= $device_id ?>">Configure pinos</a> antes de adicionar ações.
</div>
<?php endif; ?>

<!-- Add/Edit Form -->
<?php if ($show_form): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi <?= $edit_action ? 'bi-pencil-fill text-warning' : 'bi-plus-circle-fill text-success' ?> fs-5"></i>
        <h6 class="mb-0"><?= $edit_action ? 'Editar Ação' : 'Adicionar Ação' ?></h6>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= BASE ?>/devices/config_acoes.php?device_id=<?= $device_id ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="act_id" value="<?= $edit_action ? $edit_action['id'] : 0 ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Pino de Origem <span class="text-danger">*</span></label>
                    <?php if (!empty($device_pins)): ?>
                    <select class="form-select" name="pino_origem" required>
                        <?php foreach ($device_pins as $p): ?>
                        <option value="<?= $p['pino'] ?>"
                            <?= ($edit_action['pino_origem'] ?? -1) == $p['pino'] ? 'selected' : '' ?>>
                            GPIO <?= $p['pino'] ?><?= $p['nome'] ? ' - ' . h($p['nome']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="number" class="form-control" name="pino_origem" min="0" max="65533"
                           value="<?= h($edit_action['pino_origem'] ?? '') ?>" required>
                    <?php endif; ?>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold">Número da Ação <span class="text-danger">*</span></label>
                    <select class="form-select" name="numero_acao" required>
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <option value="<?= $i ?>" <?= ($edit_action['numero_acao'] ?? 1) == $i ? 'selected' : '' ?>>
                            Ação <?= $i ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ação</label>
                    <select class="form-select" name="acao">
                        <?php foreach ($acao_labels as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= ($edit_action['acao'] ?? 0) == $val ? 'selected' : '' ?>>
                            <?= h($lbl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Pino Destino</label>
                    <?php if (!empty($device_pins)): ?>
                    <select class="form-select" name="pino_destino">
                        <option value="0">— Nenhum —</option>
                        <?php foreach ($device_pins as $p): ?>
                        <option value="<?= $p['pino'] ?>"
                            <?= ($edit_action['pino_destino'] ?? 0) == $p['pino'] ? 'selected' : '' ?>>
                            GPIO <?= $p['pino'] ?><?= $p['nome'] ? ' - ' . h($p['nome']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="number" class="form-control" name="pino_destino" min="0" max="65533"
                           value="<?= h($edit_action['pino_destino'] ?? 0) ?>">
                    <?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tempo ON (ms)</label>
                    <input type="number" class="form-control" name="tempo_on" min="0"
                           value="<?= h($edit_action['tempo_on'] ?? 0) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tempo OFF (ms)</label>
                    <input type="number" class="form-control" name="tempo_off" min="0"
                           value="<?= h($edit_action['tempo_off'] ?? 0) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Pino Remoto</label>
                    <input type="number" class="form-control" name="pino_remoto" min="0" max="65533"
                           value="<?= h($edit_action['pino_remoto'] ?? 0) ?>">
                    <div class="form-text">GPIO no módulo remoto.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Enviar para Módulo</label>
                    <?php if (!empty($intermod_modules)): ?>
                    <select class="form-select" name="envia_modulo">
                        <option value="">— Local —</option>
                        <?php foreach ($intermod_modules as $mod): ?>
                        <option value="<?= h($mod['module_id']) ?>"
                            <?= ($edit_action['envia_modulo'] ?? '') === $mod['module_id'] ? 'selected' : '' ?>>
                            <?= h($mod['module_id']) ?><?= $mod['hostname'] ? ' (' . h($mod['hostname']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="text" class="form-control" name="envia_modulo" maxlength="64"
                           value="<?= h($edit_action['envia_modulo'] ?? '') ?>"
                           placeholder="ID do módulo remoto">
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="telegram" id="acao_telegram" value="1"
                               <?= ($edit_action['telegram'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="acao_telegram">
                            <i class="bi bi-telegram me-1"></i>Notificar via Telegram
                        </label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="assistente" id="acao_assistente" value="1"
                               <?= ($edit_action['assistente'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="acao_assistente">
                            <i class="bi bi-robot me-1"></i>Ativar Assistente
                        </label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?= $edit_action ? 'Salvar Alterações' : 'Adicionar Ação' ?>
                </button>
                <a href="<?= BASE ?>/devices/config_acoes.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Actions table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold">Ações Configuradas (<?= count($actions) ?>)</h6>
        <?php if (!$show_form): ?>
        <a href="?device_id=<?= $device_id ?>&add=1" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-lg me-1"></i>Adicionar
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($actions)): ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-lightning display-6 d-block mb-2 opacity-25"></i>
            <p class="small mb-2">Nenhuma ação configurada.</p>
            <a href="?device_id=<?= $device_id ?>&add=1" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Adicionar primeira ação
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Pino Origem</th>
                        <th>#</th>
                        <th>Ação</th>
                        <th>Destino</th>
                        <th>Tempos</th>
                        <th>Módulo</th>
                        <th>Extras</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actions as $act): ?>
                    <tr>
                        <td>
                            <span class="badge bg-secondary font-monospace">
                                GPIO <?= $act['pino_origem'] ?>
                            </span>
                            <?php if (isset($pin_labels[$act['pino_origem']])): ?>
                            <div class="text-muted" style="font-size:0.75rem;"><?= h(str_replace('GPIO ' . $act['pino_origem'] . ' ', '', $pin_labels[$act['pino_origem']])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-primary">Ação <?= $act['numero_acao'] ?></span></td>
                        <td><span class="badge bg-success"><?= h($acao_labels[$act['acao']] ?? $act['acao']) ?></span></td>
                        <td>
                            <?php if ($act['pino_destino']): ?>
                            <span class="badge bg-info text-dark">GPIO <?= $act['pino_destino'] ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($act['tempo_on'] || $act['tempo_off']): ?>
                            <span title="ON/OFF">ON: <?= $act['tempo_on'] ?>ms / OFF: <?= $act['tempo_off'] ?>ms</span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $act['envia_modulo'] ? h($act['envia_modulo']) : '<span class="text-muted">Local</span>' ?></td>
                        <td>
                            <?= $act['telegram'] ? '<span class="badge bg-primary me-1"><i class="bi bi-telegram"></i></span>' : '' ?>
                            <?= $act['assistente'] ? '<span class="badge bg-secondary"><i class="bi bi-robot"></i></span>' : '' ?>
                            <?php if (!$act['telegram'] && !$act['assistente']): ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="?device_id=<?= $device_id ?>&edit_id=<?= $act['id'] ?>"
                                   class="btn btn-outline-warning" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="<?= BASE ?>/devices/config_acoes.php?device_id=<?= $device_id ?>"
                                      style="display:inline"
                                      onsubmit="return confirm('Remover esta ação?')">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="action_id" value="<?= $act['id'] ?>">
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
