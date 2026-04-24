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

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name = trim($_POST['name'] ?? '');

    $stmt = $db->prepare('UPDATE devices SET name = ? WHERE id = ?');
    $stmt->execute([$name, $device_id]);

    set_flash('success', 'Nome do dispositivo atualizado com sucesso.');
    header('Location: ' . BASE . '/devices/view.php?device_id=' . $device_id);
    exit;
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
                <form method="POST" action="<?= BASE ?>/devices/edit.php?device_id=<?= $device_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">ID Único</label>
                        <input type="text" class="form-control font-monospace bg-light" value="<?= h($device['unique_id']) ?>" readonly>
                        <div class="form-text">O ID Único não pode ser alterado.</div>
                    </div>

                    <div class="mb-4">
                        <label for="name" class="form-label fw-semibold">Nome do Dispositivo</label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?= h($device['name']) ?>"
                               placeholder="ex: ESP32 Sala Principal"
                               maxlength="100" autofocus>
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
