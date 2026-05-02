<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();
$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;

$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
$stmt->execute([$device_id]);
$device = $stmt->fetch();

$stmt = $db->prepare('SELECT ip, port FROM device_status WHERE device_id = ?');
$stmt->execute([$device_id]);
$status = $stmt->fetch() ?: ['ip' => '', 'port' => 8080];

if (!$device) {
    set_flash('danger', 'Dispositivo não encontrado.');
    header('Location: ' . BASE . '/devices/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name      = trim($_POST['name'] ?? '');
    $unique_id = trim($_POST['unique_id'] ?? '');
    $ip        = trim($_POST['ip'] ?? '');
    $port      = max(1, min(65535, (int)($_POST['port'] ?? 8080)));

    if ($unique_id === '') {
        $errors[] = 'O ID Único é obrigatório.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $unique_id)) {
        $errors[] = 'ID Único inválido: use apenas letras, números, _ ou -.';
    } else {
        $dup = $db->prepare('SELECT id FROM devices WHERE unique_id = ? AND id != ?');
        $dup->execute([$unique_id, $device_id]);
        if ($dup->fetch()) {
            $errors[] = 'Já existe outro dispositivo com este ID Único.';
        }
    }

    if (empty($errors)) {
        $db->prepare('UPDATE devices SET name = ?, unique_id = ? WHERE id = ?')
           ->execute([$name, $unique_id, $device_id]);

        $db->prepare('INSERT INTO device_status (device_id, ip, port)
                      VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE ip = VALUES(ip), port = VALUES(port)')
           ->execute([$device_id, $ip, $port]);

        set_flash('success', 'Dispositivo atualizado com sucesso.');
        header('Location: ' . BASE . '/devices/view.php?device_id=' . $device_id);
        exit;
    }

    $device['unique_id'] = $unique_id;
    $device['name']      = $name;
    $status['ip']        = $ip;
    $status['port']      = $port;
}

$page_title = 'Editar Dispositivo';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Editar']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-pencil-fill text-warning fs-5"></i>
                <h5 class="mb-0">Editar Dispositivo</h5>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($errors[0]) ?></div>
                <?php endif; ?>

                <form method="POST" action="<?= BASE ?>/devices/edit.php?device_id=<?= $device_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                    <div class="mb-3">
                        <label for="unique_id" class="form-label fw-semibold">ID Único</label>
                        <input type="text" class="form-control font-monospace" id="unique_id" name="unique_id"
                               value="<?= h($device['unique_id']) ?>"
                               placeholder="ex: smcr_A1B2C3D4E5F6"
                               pattern="[a-zA-Z0-9_\-]+" maxlength="64" required>
                        <div class="form-text text-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>Alterar o ID Único desvincula o ESP32 até que ele seja reconfigurado com o novo ID.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Nome do Dispositivo</label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?= h($device['name']) ?>"
                               placeholder="ex: ESP32 Sala Principal"
                               maxlength="100">
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label for="ip" class="form-label fw-semibold">IP</label>
                            <input type="text" class="form-control font-monospace" id="ip" name="ip"
                                   value="<?= h($status['ip']) ?>"
                                   placeholder="ex: 192.168.1.200" maxlength="45">
                        </div>
                        <div class="col-md-4">
                            <label for="port" class="form-label fw-semibold">Porta</label>
                            <input type="number" class="form-control" id="port" name="port"
                                   value="<?= h($status['port']) ?>"
                                   min="1" max="65535">
                        </div>
                        <div class="col-12">
                            <div class="form-text text-warning" style="margin-top:-0.5rem;">
                                <i class="bi bi-exclamation-triangle me-1"></i>O próximo heartbeat do ESP32 sobrescreve IP e porta automaticamente.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-lg me-1"></i>Salvar Alterações
                        </button>
                        <a href="<?= BASE ?>/devices/view.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
