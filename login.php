<?php
require_once __DIR__ . '/config/auth.php';
session_init();

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Preencha o usuário e a senha.';
    } elseif (login($username, $password)) {
        header('Location: /dashboard.php');
        exit;
    } else {
        $error = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SMCR Cloud</title>
    <link rel="icon" href="/data/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, #0f3460, #533483);
            padding: 2rem;
            text-align: center;
            color: #fff;
        }

        .login-header .brand-icon {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .login-header .brand-icon i {
            font-size: 2rem;
            color: #fff;
        }

        .login-header h4 {
            margin: 0;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .login-header p {
            margin: 0.25rem 0 0;
            opacity: 0.7;
            font-size: 0.875rem;
        }

        .login-body {
            background: #fff;
            padding: 2rem;
        }

        .form-control:focus {
            border-color: #0f3460;
            box-shadow: 0 0 0 0.25rem rgba(15, 52, 96, 0.15);
        }

        .btn-login {
            background: linear-gradient(135deg, #0f3460, #533483);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 0.6rem;
            font-size: 1rem;
            transition: opacity 0.2s;
        }

        .btn-login:hover {
            opacity: 0.9;
            color: #fff;
        }

        .input-group-text {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="login-card card">
        <div class="login-header">
            <div class="brand-icon">
                <i class="bi bi-cpu-fill"></i>
            </div>
            <h4>SMCR Cloud</h4>
            <p>Gerenciamento de dispositivos ESP32</p>
        </div>
        <div class="login-body">
            <?php if ($error !== ''): ?>
            <div class="alert alert-danger d-flex align-items-center" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= h($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="/login.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">Usuário</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= h($_POST['username'] ?? '') ?>"
                               placeholder="Digite seu usuário" autofocus required>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Digite sua senha" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-login w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Entrar
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
