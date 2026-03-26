<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();
$errors = [];
$values = ['name' => '', 'unique_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name = trim($_POST['name'] ?? '');
    $unique_id = trim($_POST['unique_id'] ?? '');

    if ($unique_id === '') {
        $errors[] = 'O ID Único é obrigatório.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $unique_id)) {
        $errors[] = 'O ID Único deve conter apenas letras, números, _ e -.';
    }

    $values = ['name' => $name, 'unique_id' => $unique_id];

    if (empty($errors)) {
        // Check uniqueness
        $stmt = $db->prepare('SELECT id FROM devices WHERE unique_id = ?');
        $stmt->execute([$unique_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Este ID Único já está cadastrado.';
        }
    }

    if (empty($errors)) {
        $api_token = bin2hex(random_bytes(32));

        try {
            $db->beginTransaction();

            // Insert device
            $stmt = $db->prepare('INSERT INTO devices (name, unique_id, api_token) VALUES (?, ?, ?)');
            $stmt->execute([$name, $unique_id, $api_token]);
            $device_id = (int)$db->lastInsertId();

            // Insert default config
            $stmt = $db->prepare('INSERT INTO device_config (device_id) VALUES (?)');
            $stmt->execute([$device_id]);

            // Insert empty status
            $stmt = $db->prepare('INSERT INTO device_status (device_id) VALUES (?)');
            $stmt->execute([$device_id]);

            $db->commit();

            set_flash('success', "Dispositivo \"$unique_id\" cadastrado com sucesso!");
            header('Location: /devices/view.php?device_id=' . $device_id);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

$page_title = 'Adicionar Dispositivo';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => 'Adicionar']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-plus-circle-fill text-primary fs-5"></i>
                <h5 class="mb-0">Adicionar Dispositivo</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php foreach ($errors as $e): ?>
                    <div><?= h($e) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="/devices/add.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                    <div class="mb-3">
                        <label for="unique_id" class="form-label fw-semibold">
                            ID Único <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control font-monospace" id="unique_id" name="unique_id"
                               value="<?= h($values['unique_id']) ?>"
                               placeholder="ex: smcr_A1B2C3D4E5F6"
                               pattern="[a-zA-Z0-9_\-]+"
                               required autofocus>
                        <div class="form-text">
                            Identificador único do dispositivo ESP32. Geralmente no formato <code>smcr_XXXXXXXXXXXX</code>.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="name" class="form-label fw-semibold">Nome do Dispositivo</label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?= h($values['name']) ?>"
                               placeholder="ex: ESP32 Sala Principal"
                               maxlength="100">
                        <div class="form-text">Nome descritivo para identificar o dispositivo no painel.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Cadastrar Dispositivo
                        </button>
                        <a href="/devices/index.php" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>Como obter o ID Único</h6>
                <p class="small text-muted mb-0">
                    O ID Único do dispositivo é gerado automaticamente pelo firmware SMCR e é baseado
                    no endereço MAC do ESP32. Você pode encontrá-lo no dashboard do dispositivo ou
                    na saída serial durante a inicialização. O formato padrão é <code>smcr_</code>
                    seguido de 12 caracteres hexadecimais.
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
