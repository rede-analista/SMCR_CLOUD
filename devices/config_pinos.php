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

$tipo_labels = [
    0     => 'Não usado',
    1     => 'Digital',
    192   => 'Analógico (ADC)',
    193   => 'PWM',
    65533 => 'Analógico Remoto',
    65534 => 'Digital Remoto',
];

$modo_labels = [
    0 => 'UNUSED',
    1 => 'INPUT',
    2 => 'INPUT_PULLDOWN',
    3 => 'OUTPUT',
    5 => 'INPUT_PULLUP',
];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $pin_id = (int)($_POST['pin_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM device_pins WHERE id = ? AND device_id = ?');
        $stmt->execute([$pin_id, $device_id]);
        set_flash('success', 'Pino removido com sucesso.');
        header('Location: /devices/config_pinos.php?device_id=' . $device_id);
        exit;
    }

    if ($action === 'save') {
        $pin_id = (int)($_POST['pin_id'] ?? 0);
        $nome                 = trim($_POST['nome'] ?? '');
        $pino                 = (int)($_POST['pino'] ?? 0);
        $tipo                 = (int)($_POST['tipo'] ?? 0);
        $modo                 = (int)($_POST['modo'] ?? 0);
        $xor_logic            = isset($_POST['xor_logic']) ? (int)$_POST['xor_logic'] : 0;
        $tempo_retencao       = (int)($_POST['tempo_retencao'] ?? 0);
        $nivel_acionamento_min = (int)($_POST['nivel_acionamento_min'] ?? 0);
        $nivel_acionamento_max = (int)($_POST['nivel_acionamento_max'] ?? 1);
        $classe_mqtt          = trim($_POST['classe_mqtt'] ?? '');
        $icone_mqtt           = trim($_POST['icone_mqtt'] ?? '');

        if ($pin_id > 0) {
            // Update
            $stmt = $db->prepare('UPDATE device_pins SET nome=?, pino=?, tipo=?, modo=?, xor_logic=?,
                tempo_retencao=?, nivel_acionamento_min=?, nivel_acionamento_max=?,
                classe_mqtt=?, icone_mqtt=?
                WHERE id=? AND device_id=?');
            $stmt->execute([$nome, $pino, $tipo, $modo, $xor_logic,
                $tempo_retencao, $nivel_acionamento_min, $nivel_acionamento_max,
                $classe_mqtt, $icone_mqtt, $pin_id, $device_id]);
            set_flash('success', 'Pino atualizado com sucesso.');
        } else {
            // Insert
            try {
                $stmt = $db->prepare('INSERT INTO device_pins
                    (device_id, nome, pino, tipo, modo, xor_logic, tempo_retencao,
                     nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$device_id, $nome, $pino, $tipo, $modo, $xor_logic,
                    $tempo_retencao, $nivel_acionamento_min, $nivel_acionamento_max,
                    $classe_mqtt, $icone_mqtt]);
                set_flash('success', 'Pino adicionado com sucesso.');
            } catch (PDOException $e) {
                set_flash('danger', 'Erro: pino ' . $pino . ' já está cadastrado para este dispositivo.');
            }
        }
        header('Location: /devices/config_pinos.php?device_id=' . $device_id);
        exit;
    }
}

// Load existing pins
$stmt = $db->prepare('SELECT * FROM device_pins WHERE device_id = ? ORDER BY pino ASC');
$stmt->execute([$device_id]);
$pins = $stmt->fetchAll();

// Edit mode
$edit_pin = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare('SELECT * FROM device_pins WHERE id = ? AND device_id = ?');
    $stmt->execute([$edit_id, $device_id]);
    $edit_pin = $stmt->fetch() ?: null;
}
$show_form = isset($_GET['add']) || $edit_pin !== null;

$page_title = 'Configuração de Pinos';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Pinos']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill me-2"></i>Configuração de Pinos</h5>
    <div class="d-flex gap-2">
        <span class="text-muted small align-self-center"><?= h($device['name'] ?: $device['unique_id']) ?></span>
        <?php if (!$show_form): ?>
        <a href="?device_id=<?= $device_id ?>&add=1" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Adicionar Pino
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Form -->
<?php if ($show_form): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi <?= $edit_pin ? 'bi-pencil-fill text-warning' : 'bi-plus-circle-fill text-success' ?> fs-5"></i>
        <h6 class="mb-0"><?= $edit_pin ? 'Editar Pino' : 'Adicionar Pino' ?></h6>
    </div>
    <div class="card-body">
        <form method="POST" action="/devices/config_pinos.php?device_id=<?= $device_id ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="pin_id" value="<?= $edit_pin ? $edit_pin['id'] : 0 ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Nome</label>
                    <input type="text" class="form-control" name="nome" maxlength="64"
                           value="<?= h($edit_pin['nome'] ?? '') ?>"
                           placeholder="ex: Sensor Porta">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Pino (GPIO) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="pino" min="0" max="65533" required
                           value="<?= h($edit_pin['pino'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tipo</label>
                    <select class="form-select" name="tipo">
                        <?php foreach ($tipo_labels as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= ($edit_pin['tipo'] ?? 0) == $val ? 'selected' : '' ?>>
                            <?= h($lbl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Modo</label>
                    <select class="form-select" name="modo">
                        <?php foreach ($modo_labels as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= ($edit_pin['modo'] ?? 0) == $val ? 'selected' : '' ?>>
                            <?= h($lbl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Lógica</label>
                    <select class="form-select" name="xor_logic">
                        <option value="0" <?= ($edit_pin['xor_logic'] ?? 0) == 0 ? 'selected' : '' ?>>Normal</option>
                        <option value="1" <?= ($edit_pin['xor_logic'] ?? 0) == 1 ? 'selected' : '' ?>>Invertido</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tempo de Retenção (ms)</label>
                    <input type="number" class="form-control" name="tempo_retencao" min="0"
                           value="<?= h($edit_pin['tempo_retencao'] ?? 0) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Nível Min. Acionamento</label>
                    <input type="number" class="form-control" name="nivel_acionamento_min" min="0" max="4095"
                           value="<?= h($edit_pin['nivel_acionamento_min'] ?? 0) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Nível Máx. Acionamento</label>
                    <input type="number" class="form-control" name="nivel_acionamento_max" min="0" max="4095"
                           value="<?= h($edit_pin['nivel_acionamento_max'] ?? 1) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Classe MQTT</label>
                    <input type="text" class="form-control" name="classe_mqtt" maxlength="50"
                           value="<?= h($edit_pin['classe_mqtt'] ?? '') ?>"
                           placeholder="ex: motion, door, temperature">
                    <div class="form-text">Home Assistant device class.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ícone MQTT</label>
                    <input type="text" class="form-control" name="icone_mqtt" maxlength="50"
                           value="<?= h($edit_pin['icone_mqtt'] ?? '') ?>"
                           placeholder="ex: mdi:door-open">
                    <div class="form-text">Ícone Material Design Icons.</div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?= $edit_pin ? 'Salvar Alterações' : 'Adicionar Pino' ?>
                </button>
                <a href="/devices/config_pinos.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Pins table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold">Pinos Configurados (<?= count($pins) ?>)</h6>
        <?php if (!$show_form): ?>
        <a href="?device_id=<?= $device_id ?>&add=1" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-lg me-1"></i>Adicionar
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pins)): ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-diagram-3 display-6 d-block mb-2 opacity-25"></i>
            <p class="small mb-2">Nenhum pino configurado.</p>
            <a href="?device_id=<?= $device_id ?>&add=1" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Adicionar primeiro pino
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Pino</th>
                        <th>Nome</th>
                        <th>Tipo</th>
                        <th>Modo</th>
                        <th>Lógica</th>
                        <th>Retenção</th>
                        <th>Níveis</th>
                        <th>MQTT</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pins as $pin): ?>
                    <tr>
                        <td><span class="badge bg-secondary font-monospace">GPIO <?= $pin['pino'] ?></span></td>
                        <td><?= h($pin['nome'] ?: '—') ?></td>
                        <td><span class="badge bg-info text-dark"><?= h($tipo_labels[$pin['tipo']] ?? $pin['tipo']) ?></span></td>
                        <td><?= h($modo_labels[$pin['modo']] ?? $pin['modo']) ?></td>
                        <td><?= $pin['xor_logic'] ? '<span class="badge bg-warning text-dark">Invertido</span>' : 'Normal' ?></td>
                        <td><?= $pin['tempo_retencao'] ? $pin['tempo_retencao'] . ' ms' : '—' ?></td>
                        <td><?= $pin['nivel_acionamento_min'] . ' – ' . $pin['nivel_acionamento_max'] ?></td>
                        <td>
                            <?php if ($pin['classe_mqtt']): ?>
                            <span class="badge bg-purple text-white" style="background:#6f42c1!important"><?= h($pin['classe_mqtt']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="?device_id=<?= $device_id ?>&edit_id=<?= $pin['id'] ?>"
                                   class="btn btn-outline-warning" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="/devices/config_pinos.php?device_id=<?= $device_id ?>"
                                      style="display:inline"
                                      onsubmit="return confirm('Remover GPIO <?= $pin['pino'] ?> (<?= h(addslashes($pin['nome'])) ?>)?')">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="pin_id" value="<?= $pin['id'] ?>">
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
