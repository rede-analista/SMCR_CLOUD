<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$db = getDB();

// Ensure settings table and default values exist
function ensure_settings(PDO $db): void {
    $defaults = [
        'register_token'      => bin2hex(random_bytes(16)),
        'mdns_interval'       => '5',
        'dashboard_refresh'   => '30',
        'history_retention_days' => '90',
    ];
    foreach ($defaults as $key => $default) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        if ((int)$stmt->fetchColumn() === 0) {
            $value = ($key === 'register_token') ? $default : $default;
            $stmt  = $db->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
    }
}

ensure_settings($db);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_token') {
        $token = bin2hex(random_bytes(16));
        $stmt  = $db->prepare("UPDATE settings SET value = ? WHERE `key` = 'register_token'");
        $stmt->execute([$token]);
        set_flash('success', 'Token de auto-registro regenerado com sucesso.');
        header('Location: ' . BASE . '/settings.php');
        exit;
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $new_pass = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password_hash'])) {
            set_flash('danger', 'Senha atual incorreta.');
        } elseif (strlen($new_pass) < 6) {
            set_flash('danger', 'A nova senha deve ter pelo menos 6 caracteres.');
        } elseif ($new_pass !== $confirm) {
            set_flash('danger', 'A confirmação de senha não coincide.');
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $_SESSION['user_id']]);
            set_flash('success', 'Senha alterada com sucesso.');
        }
        header('Location: ' . BASE . '/settings.php');
        exit;
    }

    if ($action === 'add_user') {
        $username = trim($_POST['new_username'] ?? '');
        $password = $_POST['new_user_password'] ?? '';

        if ($username === '' || strlen($password) < 6) {
            set_flash('danger', 'Usuário e senha (mín. 6 caracteres) são obrigatórios.');
        } else {
            $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                set_flash('danger', "Usuário \"{$username}\" já existe.");
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
                $stmt->execute([$username, $hash]);
                set_flash('success', "Usuário \"{$username}\" criado com sucesso.");
            }
        }
        header('Location: ' . BASE . '/settings.php');
        exit;
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)$_SESSION['user_id']) {
            set_flash('danger', 'Você não pode excluir seu próprio usuário.');
        } else {
            $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            set_flash('success', 'Usuário excluído.');
        }
        header('Location: ' . BASE . '/settings.php');
        exit;
    }

    if ($action === 'save_offline_alert') {
        $alert_enabled  = isset($_POST['offline_alert_enabled']) ? '1' : '0';
        $alert_minutes  = max(1, (int)($_POST['offline_alert_minutes'] ?? 10));
        $alert_tg_token = substr(trim($_POST['offline_alert_telegram_token'] ?? ''), 0, 128);
        $alert_tg_chat  = substr(trim($_POST['offline_alert_telegram_chatid'] ?? ''), 0, 64);

        foreach ([
            'offline_alert_enabled'          => $alert_enabled,
            'offline_alert_minutes'          => (string)$alert_minutes,
            'offline_alert_telegram_token'   => $alert_tg_token,
            'offline_alert_telegram_chatid'  => $alert_tg_chat,
        ] as $k => $v) {
            $db->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?")
               ->execute([$k, $v, $v]);
        }
        set_flash('success', 'Configurações de alerta offline salvas.');
        header('Location: ' . BASE . '/settings.php');
        exit;
    }

    if ($action === 'save_timers') {
        $mdns_interval     = (int)($_POST['mdns_interval']     ?? 5);
        $dashboard_refresh = (int)($_POST['dashboard_refresh'] ?? 30);

        if ($mdns_interval < 1)      $mdns_interval     = 1;
        if ($dashboard_refresh < 10) $dashboard_refresh = 10;

        $db->prepare("INSERT INTO settings (`key`, value) VALUES ('mdns_interval', ?) ON DUPLICATE KEY UPDATE value = ?")
           ->execute([$mdns_interval, $mdns_interval]);
        $db->prepare("INSERT INTO settings (`key`, value) VALUES ('dashboard_refresh', ?) ON DUPLICATE KEY UPDATE value = ?")
           ->execute([$dashboard_refresh, $dashboard_refresh]);

        set_flash('success', 'Configurações de automação salvas.');
        header('Location: ' . BASE . '/settings.php');
        exit;
    }

    if ($action === 'save_history') {
        $days = max(0, (int)($_POST['history_retention_days'] ?? 90));
        $db->prepare("INSERT INTO settings (`key`, value) VALUES ('history_retention_days', ?) ON DUPLICATE KEY UPDATE value = ?")
           ->execute([$days, $days]);
        set_flash('success', 'Retenção do histórico salva.');
        header('Location: ' . BASE . '/settings.php');
        exit;
    }
}

// Load data
$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'register_token'");
$stmt->execute();
$register_token = $stmt->fetchColumn() ?: '—';

$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'mdns_interval'");
$stmt->execute();
$mdns_interval = (int)($stmt->fetchColumn() ?: 5);

$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'dashboard_refresh'");
$stmt->execute();
$dashboard_refresh = (int)($stmt->fetchColumn() ?: 30);

$stmt = $db->query("SELECT `key`, value FROM settings WHERE `key` LIKE 'offline_alert%'");
$offline_settings = [];
foreach ($stmt->fetchAll() as $row) $offline_settings[$row['key']] = $row['value'];
$offline_alert_enabled  = (bool)($offline_settings['offline_alert_enabled'] ?? false);
$offline_alert_minutes  = (int)($offline_settings['offline_alert_minutes']  ?? 10);
$offline_alert_tg_token = $offline_settings['offline_alert_telegram_token']  ?? '';
$offline_alert_tg_chat  = $offline_settings['offline_alert_telegram_chatid'] ?? '';

$stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'history_retention_days'");
$stmt->execute();
$history_retention_days = (int)($stmt->fetchColumn() ?: 90);

$users = $db->query('SELECT id, username, created_at FROM users ORDER BY id')->fetchAll();

$page_title = 'Configurações do Cloud';
$breadcrumb = [['label' => 'Configurações']];
include __DIR__ . '/includes/header.php';
?>

<div class="row g-4">

    <!-- Auto-registration token -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-key-fill text-warning fs-5"></i>
                <h5 class="mb-0">Token de Auto-Registro</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Este token deve ser configurado no firmware SMCR de cada ESP32 para que eles possam
                    se auto-registrar neste cloud ao ligar. Mantenha-o seguro.
                </p>
                <div class="input-group mb-3">
                    <input type="text" class="form-control font-monospace" id="reg_token_display"
                           value="<?= h($register_token) ?>" readonly>
                    <button class="btn btn-outline-secondary" onclick="copyText(this, '<?= h($register_token) ?>')" title="Copiar">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <form method="POST" onsubmit="return confirm('Regenerar o token vai invalidar todos os ESP32 que usam o token atual. Confirma?')">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="regenerate_token">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-clockwise me-1"></i>Regenerar Token
                    </button>
                </form>
                <hr>
                <p class="small fw-semibold mb-1">Endpoint de registro:</p>
                <code class="d-block bg-light rounded p-2 small">POST http://smcr.pensenet.com.br/api/register.php</code>
                <p class="small text-muted mt-2 mb-0">
                    <a href="<?= BASE ?>/devices/discover.php">Descobrir dispositivos na rede &rarr;</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Change password -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-shield-lock-fill text-primary fs-5"></i>
                <h5 class="mb-0">Alterar Minha Senha</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Senha Atual</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nova Senha</label>
                        <input type="password" name="new_password" class="form-control" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Confirmar Nova Senha</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Alterar Senha
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Automation timers -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-clock-fill text-success fs-5"></i>
                <h5 class="mb-0">Automação e Tempos</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_timers">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">
                                <i class="bi bi-broadcast me-1"></i>Intervalo de descoberta mDNS (minutos)
                            </label>
                            <input type="number" name="mdns_interval" class="form-control"
                                   value="<?= $mdns_interval ?>" min="1" max="60" required>
                            <div class="form-text">Mínimo: 1 minuto. Padrão: 5 minutos.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">
                                <i class="bi bi-arrow-clockwise me-1"></i>Refresh automático do dashboard (segundos)
                            </label>
                            <input type="number" name="dashboard_refresh" class="form-control"
                                   value="<?= $dashboard_refresh ?>" min="10" max="3600" required>
                            <div class="form-text">Mínimo: 10 segundos. Padrão: 30 segundos.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success mt-3">
                        <i class="bi bi-check-lg me-1"></i>Salvar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- History retention -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-journal-text text-success fs-5"></i>
                <h5 class="mb-0">Retenção do Histórico</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_history">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Dias de retenção do histórico de acionamentos</label>
                        <input type="number" name="history_retention_days" class="form-control"
                               value="<?= $history_retention_days ?>" min="0" max="3650">
                        <div class="form-text">0 = manter indefinidamente. Registros mais antigos que o período configurado são removidos automaticamente.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i>Salvar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Users management -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-people-fill text-info fs-5"></i>
                    <h5 class="mb-0">Usuários do Sistema</h5>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-8">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Usuário</th><th>Criado em</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <i class="bi bi-person-circle me-1 text-muted"></i>
                                    <?= h($u['username']) ?>
                                    <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                                        <span class="badge bg-primary ms-1">você</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= h($u['created_at']) ?></td>
                                <td class="text-end">
                                    <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Excluir usuário <?= h($u['username']) ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <h6 class="fw-semibold">Adicionar Usuário</h6>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="add_user">
                            <div class="mb-2">
                                <input type="text" name="new_username" class="form-control form-control-sm"
                                       placeholder="Usuário" pattern="[a-zA-Z0-9_\-]+" required>
                            </div>
                            <div class="mb-2">
                                <input type="password" name="new_user_password" class="form-control form-control-sm"
                                       placeholder="Senha (mín. 6 caracteres)" minlength="6" required>
                            </div>
                            <button type="submit" class="btn btn-sm btn-success w-100">
                                <i class="bi bi-person-plus me-1"></i>Adicionar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-bell-slash text-danger"></i>
                <h5 class="mb-0">Alerta de Dispositivo Offline</h5>
            </div>
            <div class="card-body">
                <form method="post" action="<?= BASE ?>/settings.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_offline_alert">
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="offline_alert_enabled" id="offline_alert_enabled" value="1" <?= $offline_alert_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="offline_alert_enabled">Alertas habilitados</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Minutos sem resposta para considerar offline</label>
                        <input type="number" class="form-control" name="offline_alert_minutes" value="<?= $offline_alert_minutes ?>" min="1" max="60">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Token do Bot Telegram</label>
                        <input type="text" class="form-control font-monospace" name="offline_alert_telegram_token" value="<?= h($offline_alert_tg_token) ?>" placeholder="123456789:ABC...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Chat ID do Telegram</label>
                        <input type="text" class="form-control font-monospace" name="offline_alert_telegram_chatid" value="<?= h($offline_alert_tg_chat) ?>" placeholder="-100123456789">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
function copyText(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        setTimeout(() => {
            btn.innerHTML = original;
            btn.classList.replace('btn-success', 'btn-outline-secondary');
        }, 1500);
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
