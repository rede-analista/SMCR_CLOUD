<?php
require_once __DIR__ . '/../config/auth.php';
session_init();

// Determine current page for active nav state
$current_script = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

$flash = get_flash();

// Build sidebar sub-menu for device pages
$device_id = isset($device_id) ? (int)$device_id : (isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0);
$device = isset($device) ? $device : null;

if ($device_id > 0 && $device === null) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, name, unique_id FROM devices WHERE id = ?');
        $stmt->execute([$device_id]);
        $device = $stmt->fetch() ?: null;
    } catch (Exception $e) {
        $device = null;
    }
}

$page_title = isset($page_title) ? $page_title : 'SMCR Cloud';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-base="<?= BASE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?> - SMCR Cloud</title>
    <link rel="icon" href="<?= BASE ?>/data/favicon.ico" type="image/x-icon">
    <script>const BASE_PATH = <?= json_encode(BASE) ?>;</script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-bg: #1a1a2e;
            --sidebar-accent: #16213e;
            --sidebar-active: #0f3460;
            --sidebar-text: #c8d0e0;
            --sidebar-text-muted: #7a8499;
            --topbar-height: 56px;
        }

        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        /* Sidebar */
        #sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            z-index: 1040;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            padding: 1rem 1.25rem;
            background: var(--sidebar-accent);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            text-decoration: none;
        }

        .sidebar-brand .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #0f3460, #533483);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-icon i {
            color: #fff;
            font-size: 1.1rem;
        }

        .sidebar-brand .brand-text {
            color: #fff;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.5px;
        }

        .sidebar-brand .brand-sub {
            color: var(--sidebar-text-muted);
            font-size: 0.7rem;
            display: block;
            margin-top: -2px;
        }

        .sidebar-section {
            padding: 1rem 0 0.5rem;
        }

        .sidebar-section-title {
            color: var(--sidebar-text-muted);
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 0 1.25rem 0.4rem;
        }

        .sidebar-nav .nav-link {
            display: flex;
            align-items: center;
            color: var(--sidebar-text);
            padding: 0.6rem 1.25rem;
            border-radius: 0;
            font-size: 0.875rem;
            transition: all 0.15s ease;
            text-decoration: none;
            border-left: 3px solid transparent;
        }

        .sidebar-nav .nav-link i {
            width: 20px;
            margin-right: 10px;
            font-size: 1rem;
            opacity: 0.7;
        }

        .sidebar-nav .nav-link:hover {
            background: rgba(255,255,255,0.05);
            color: #fff;
            border-left-color: rgba(255,255,255,0.2);
        }

        .sidebar-nav .nav-link:hover i {
            opacity: 1;
        }

        .sidebar-nav .nav-link.active {
            background: var(--sidebar-active);
            color: #fff;
            border-left-color: #4a9fd4;
            font-weight: 500;
        }

        .sidebar-nav .nav-link.active i {
            opacity: 1;
            color: #4a9fd4;
        }

        .sidebar-submenu {
            background: rgba(0,0,0,0.2);
            border-left: 3px solid #0f3460;
            margin: 0 0 0.25rem 0;
        }

        .sidebar-submenu .nav-link {
            padding: 0.45rem 1.25rem 0.45rem 2.5rem;
            font-size: 0.82rem;
            border-left: none;
        }

        .sidebar-submenu .nav-link.active {
            background: rgba(74, 159, 212, 0.15);
            color: #4a9fd4;
        }

        .sidebar-submenu .nav-link i {
            font-size: 0.8rem;
            width: 16px;
        }

        /* Main content */
        #main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Topbar */
        #topbar {
            height: var(--topbar-height);
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        #topbar .breadcrumb {
            margin: 0;
            background: none;
            padding: 0;
        }

        #topbar .breadcrumb-item a {
            color: #0f3460;
            text-decoration: none;
        }

        .topbar-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.75rem;
            background: #f0f2f5;
            border-radius: 20px;
            font-size: 0.85rem;
            color: #495057;
        }

        .user-badge .avatar {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #0f3460, #533483);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.75rem;
            font-weight: 700;
        }

        /* Page content */
        #page-content {
            flex: 1;
            padding: 1.5rem;
        }

        /* Cards */
        .card {
            border: none;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border-radius: 10px;
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #f0f2f5;
            padding: 1rem 1.25rem;
            border-radius: 10px 10px 0 0 !important;
        }

        /* Status badges */
        .badge-online {
            background-color: #198754;
            color: #fff;
        }

        .badge-offline {
            background-color: #dc3545;
            color: #fff;
        }

        /* Responsive */
        @media (max-width: 768px) {
            #sidebar {
                transform: translateX(-100%);
            }
            #sidebar.show {
                transform: translateX(0);
            }
            #main-wrapper {
                margin-left: 0;
            }
        }

        .device-submenu-header {
            padding: 0.5rem 1.25rem 0.25rem;
            color: var(--sidebar-text-muted);
            font-size: 0.75rem;
        }

        .device-submenu-header .device-name {
            color: #fff;
            font-weight: 600;
            font-size: 0.8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<nav id="sidebar">
    <a href="<?= BASE ?>/dashboard.php" class="sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-cpu-fill"></i>
        </div>
        <div>
            <span class="brand-text">SMCR Cloud</span>
            <span class="brand-sub">Gerenciamento ESP32</span>
        </div>
    </a>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <nav class="sidebar-nav">
            <a href="<?= BASE ?>/dashboard.php"
               class="nav-link <?= ($current_script === 'dashboard.php') ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i>
                Dashboard
            </a>
            <a href="<?= BASE ?>/devices/index.php"
               class="nav-link <?= ($current_script === 'index.php' && $current_dir === 'devices') ? 'active' : '' ?>">
                <i class="bi bi-hdd-network-fill"></i>
                Dispositivos
            </a>
            <a href="<?= BASE ?>/devices/add.php"
               class="nav-link <?= ($current_script === 'add.php' && $current_dir === 'devices') ? 'active' : '' ?>">
                <i class="bi bi-plus-circle-fill"></i>
                Adicionar Dispositivo
            </a>
            <a href="<?= BASE ?>/devices/discover.php"
               class="nav-link <?= ($current_script === 'discover.php') ? 'active' : '' ?>">
                <i class="bi bi-radar"></i>
                Descobrir Dispositivos
            </a>
            <a href="<?= BASE ?>/devices/clone.php"
               class="nav-link <?= ($current_script === 'clone.php') ? 'active' : '' ?>">
                <i class="bi bi-copy"></i>
                Clonar Configuração
            </a>
        </nav>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Sistema</div>
        <nav class="sidebar-nav">
            <a href="<?= BASE ?>/data_files/index.php"
               class="nav-link <?= ($current_script === 'index.php' && $current_dir === 'data_files') ? 'active' : '' ?>">
                <i class="bi bi-folder-fill"></i>
                Arquivos Data
            </a>
            <a href="<?= BASE ?>/settings.php"
               class="nav-link <?= ($current_script === 'settings.php') ? 'active' : '' ?>">
                <i class="bi bi-sliders"></i>
                Configurações
            </a>
        </nav>
    </div>

    <?php if ($device): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Dispositivo Atual</div>
        <div class="device-submenu-header">
            <div class="device-name"><i class="bi bi-cpu me-1"></i><?= h($device['name'] ?: $device['unique_id']) ?></div>
        </div>
        <nav class="sidebar-nav sidebar-submenu">
            <a href="<?= BASE ?>/devices/view.php?device_id=<?= $device_id ?>"
               class="nav-link <?= ($current_script === 'view.php') ? 'active' : '' ?>">
                <i class="bi bi-info-circle"></i>
                Visão Geral
            </a>
            <a href="<?= BASE ?>/devices/config_geral.php?device_id=<?= $device_id ?>"
               class="nav-link <?= ($current_script === 'config_geral.php') ? 'active' : '' ?>">
                <i class="bi bi-gear-fill"></i>
                Config. Geral
            </a>
            <a href="<?= BASE ?>/devices/config_pinos.php?device_id=<?= $device_id ?>"
               class="nav-link <?= ($current_script === 'config_pinos.php') ? 'active' : '' ?>">
                <i class="bi bi-diagram-3-fill"></i>
                Pinos
            </a>
            <a href="<?= BASE ?>/devices/config_acoes.php?device_id=<?= $device_id ?>"
               class="nav-link <?= ($current_script === 'config_acoes.php') ? 'active' : '' ?>">
                <i class="bi bi-lightning-fill"></i>
                Ações
            </a>
            <a href="<?= BASE ?>/devices/config_mqtt.php?device_id=<?= $device_id ?>"
               class="nav-link <?= ($current_script === 'config_mqtt.php') ? 'active' : '' ?>">
                <i class="bi bi-broadcast"></i>
                MQTT
            </a>
            <a href="<?= BASE ?>/devices/config_intermod.php?device_id=<?= $device_id ?>"
               class="nav-link <?= ($current_script === 'config_intermod.php') ? 'active' : '' ?>">
                <i class="bi bi-share-fill"></i>
                Inter-Módulos
            </a>
            <a href="<?= BASE ?>/devices/config_telegram.php?device_id=<?= $device_id ?>"
               class="nav-link <?= ($current_script === 'config_telegram.php') ? 'active' : '' ?>">
                <i class="bi bi-telegram"></i>
                Telegram
            </a>
        </nav>
    </div>
    <?php endif; ?>

    <div class="sidebar-section mt-auto" style="position:absolute;bottom:0;width:100%;padding-bottom:1rem;background:var(--sidebar-bg);">
        <nav class="sidebar-nav">
            <a href="<?= BASE ?>/logout.php" class="nav-link text-danger">
                <i class="bi bi-box-arrow-left"></i>
                Sair
            </a>
        </nav>
    </div>
</nav>

<!-- Main Wrapper -->
<div id="main-wrapper">
    <!-- Topbar -->
    <div id="topbar">
        <button class="btn btn-sm btn-outline-secondary d-md-none me-2" id="sidebarToggle">
            <i class="bi bi-list"></i>
        </button>

        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= BASE ?>/dashboard.php"><i class="bi bi-house-fill"></i></a></li>
                <?php if (isset($breadcrumb) && is_array($breadcrumb)): ?>
                    <?php foreach ($breadcrumb as $bc): ?>
                        <?php if (isset($bc['url'])): ?>
                            <?php $bc_href = (isset($bc['url'][0]) && $bc['url'][0] === '/') ? BASE . $bc['url'] : $bc['url']; ?>
                            <li class="breadcrumb-item"><a href="<?= h($bc_href) ?>"><?= h($bc['label']) ?></a></li>
                        <?php else: ?>
                            <li class="breadcrumb-item active"><?= h($bc['label']) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="breadcrumb-item active"><?= h($page_title) ?></li>
                <?php endif; ?>
            </ol>
        </nav>

        <div class="topbar-actions">
            <?php if (is_logged_in()): ?>
            <div class="user-badge">
                <div class="avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
                <span><?= h($_SESSION['username'] ?? '') ?></span>
            </div>
            <a href="<?= BASE ?>/logout.php" class="btn btn-sm btn-outline-danger" title="Sair">
                <i class="bi bi-box-arrow-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Page Content -->
    <div id="page-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show mb-3" role="alert">
        <?php if ($flash['type'] === 'success'): ?>
            <i class="bi bi-check-circle-fill me-2"></i>
        <?php elseif ($flash['type'] === 'danger'): ?>
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php else: ?>
            <i class="bi bi-info-circle-fill me-2"></i>
        <?php endif; ?>
        <?= h($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
