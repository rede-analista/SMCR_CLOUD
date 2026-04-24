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

// Count related records
$stmt = $db->prepare('SELECT COUNT(*) FROM device_pins WHERE device_id = ?');
$stmt->execute([$device_id]);
$pins_count = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM device_actions WHERE device_id = ?');
$stmt->execute([$device_id]);
$actions_count = (int)$stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (isset($_POST['confirm_delete'])) {
        $stmt = $db->prepare('DELETE FROM devices WHERE id = ?');
        $stmt->execute([$device_id]);

        set_flash('success', 'Dispositivo "' . $device['unique_id'] . '" excluído com sucesso.');
        header('Location: ' . BASE . '/devices/index.php');
        exit;
    } else {
        header('Location: ' . BASE . '/devices/index.php');
        exit;
    }
}

$page_title = 'Excluir Dispositivo';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Excluir']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <h5 class="mb-0">Confirmar Exclusão</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-danger">
                    <strong>Atenção!</strong> Esta ação é irreversível. Todos os dados do dispositivo serão excluídos permanentemente.
                </div>

                <table class="table table-sm mb-3">
                    <tr>
                        <th>ID Único:</th>
                        <td class="font-monospace"><?= h($device['unique_id']) ?></td>
                    </tr>
                    <tr>
                        <th>Nome:</th>
                        <td><?= h($device['name'] ?: '(sem nome)') ?></td>
                    </tr>
                    <tr>
                        <th>Cadastrado em:</th>
                        <td><?= h($device['created_at']) ?></td>
                    </tr>
                    <tr>
                        <th>Pinos configurados:</th>
                        <td><?= $pins_count ?></td>
                    </tr>
                    <tr>
                        <th>Ações configuradas:</th>
                        <td><?= $actions_count ?></td>
                    </tr>
                </table>

                <p class="text-muted small">
                    Ao excluir, serão removidos: configurações gerais, pinos, ações, configurações MQTT,
                    inter-módulos, Telegram e todos os status registrados.
                </p>

                <form method="POST" action="<?= BASE ?>/devices/delete.php?device_id=<?= $device_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" name="confirm_delete" value="1" class="btn btn-danger">
                            <i class="bi bi-trash-fill me-1"></i>Sim, excluir definitivamente
                        </button>
                        <a href="<?= BASE ?>/devices/view.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
