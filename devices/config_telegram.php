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

    $fields = [
        'telegram_enabled'  => isset($_POST['telegram_enabled']) ? 1 : 0,
        'telegram_token'    => trim($_POST['telegram_token'] ?? ''),
        'telegram_chatid'   => trim($_POST['telegram_chatid'] ?? ''),
        'telegram_interval' => (int)($_POST['telegram_interval'] ?? 30),
    ];

    $set = array_map(fn($k) => "`$k` = ?", array_keys($fields));
    $vals = array_values($fields);
    $vals[] = $device_id;
    $db->prepare('UPDATE device_config SET ' . implode(', ', $set) . ' WHERE device_id = ?')->execute($vals);

    set_flash('success', 'Configurações do Telegram salvas com sucesso.');
    header('Location: ' . BASE . '/devices/config_telegram.php?device_id=' . $device_id);
    exit;
}

$page_title = 'Telegram';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Telegram']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-telegram me-2"></i>Configuração Telegram</h5>
    <span class="text-muted small"><?= h($device['name'] ?: $device['unique_id']) ?></span>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="POST" action="<?= BASE ?>/devices/config_telegram.php?device_id=<?= $device_id ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-gear me-1"></i>Configurações do Telegram</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="telegram_enabled"
                                       id="telegram_enabled" value="1"
                                       <?= $cfg['telegram_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="telegram_enabled">
                                    Habilitar notificações via Telegram
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Token do Bot</label>
                            <div class="input-group">
                                <input type="password" class="form-control font-monospace" name="telegram_token"
                                       id="telegram_token" value="<?= h($cfg['telegram_token']) ?>"
                                       maxlength="128" placeholder="XXXXXXX:XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="toggleVis('telegram_token', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">
                                Obtido via <a href="https://t.me/BotFather" target="_blank">@BotFather</a> no Telegram.
                            </div>
                        </div>

                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Chat ID</label>
                            <input type="text" class="form-control font-monospace" name="telegram_chatid"
                                   value="<?= h($cfg['telegram_chatid']) ?>" maxlength="64"
                                   placeholder="ex: -1001234567890">
                            <div class="form-text">
                                ID do chat, grupo ou canal para envio das mensagens.
                                Use o bot <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> para obter o seu ID.
                            </div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Intervalo Mínimo entre Mensagens (s)</label>
                            <input type="number" class="form-control" name="telegram_interval"
                                   value="<?= h($cfg['telegram_interval']) ?>" min="1" max="3600">
                            <div class="form-text">Evita flood de mensagens.</div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Salvar Configurações
                    </button>
                    <a href="<?= BASE ?>/devices/view.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header" style="background:linear-gradient(135deg,#229ED9,#1a7db5);">
                <h6 class="mb-0 fw-bold text-white"><i class="bi bi-telegram me-1"></i>Como configurar o Telegram</h6>
            </div>
            <div class="card-body">
                <ol class="small mb-0">
                    <li class="mb-2">
                        <strong>Crie um bot:</strong><br>
                        Abra o Telegram e procure por <code>@BotFather</code>. Envie <code>/newbot</code>
                        e siga as instruções. Copie o token fornecido.
                    </li>
                    <li class="mb-2">
                        <strong>Obtenha o Chat ID:</strong><br>
                        Para receber mensagens no chat privado, envie qualquer mensagem ao seu bot
                        e acesse: <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code>
                        para ver o <code>chat.id</code>.
                    </li>
                    <li class="mb-2">
                        <strong>Para grupos:</strong><br>
                        Adicione o bot ao grupo, envie uma mensagem e use <code>/getUpdates</code>.
                        O Chat ID de grupos é negativo (ex: <code>-1001234567890</code>).
                    </li>
                    <li>
                        <strong>Configure as ações:</strong><br>
                        Em <a href="<?= BASE ?>/devices/config_acoes.php?device_id=<?= $device_id ?>">Configuração de Ações</a>,
                        marque a opção "Notificar via Telegram" para os eventos desejados.
                    </li>
                </ol>
            </div>
        </div>

        <?php if ($cfg['telegram_enabled'] && $cfg['telegram_token'] && $cfg['telegram_chatid']): ?>
        <div class="card mt-3 border-success">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 text-success">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <span class="fw-semibold">Telegram configurado</span>
                </div>
                <div class="mt-2 small text-muted">
                    Chat ID: <code><?= h($cfg['telegram_chatid']) ?></code><br>
                    Intervalo: <?= $cfg['telegram_interval'] ?>s entre mensagens
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleVis(id, btn) {
    const input = document.getElementById(id);
    if (input.type === 'password') {
        input.type = 'text';
        btn.querySelector('i').className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        btn.querySelector('i').className = 'bi bi-eye';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
