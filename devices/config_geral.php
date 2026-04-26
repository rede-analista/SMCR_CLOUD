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
    // Create defaults if missing
    $db->prepare('INSERT INTO device_config (device_id) VALUES (?)')->execute([$device_id]);
    $stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $cfg = $stmt->fetch();
}

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $log_flags = 0;
    foreach ($_POST['log_flag'] ?? [] as $flag) {
        $log_flags |= (int)$flag;
    }

    $fields = [
        'hostname'               => trim($_POST['hostname'] ?? 'esp32modularx'),
        'wifi_ssid'              => trim($_POST['wifi_ssid'] ?? ''),
        'wifi_pass'              => $_POST['wifi_pass'] ?? '',
        'wifi_attempts'          => (int)($_POST['wifi_attempts'] ?? 15),
        'wifi_check_interval'    => (int)($_POST['wifi_check_interval'] ?? 15000),
        'ap_ssid'                => trim($_POST['ap_ssid'] ?? 'SMCR_AP_SETUP'),
        'ap_pass'                => $_POST['ap_pass'] ?? 'senha1234',
        'ap_fallback_enabled'    => isset($_POST['ap_fallback_enabled']) ? 1 : 0,
        'ntp_server1'            => trim($_POST['ntp_server1'] ?? 'pool.ntp.br'),
        'gmt_offset_sec'         => (int)($_POST['gmt_offset_sec'] ?? -10800),
        'daylight_offset_sec'    => (int)($_POST['daylight_offset_sec'] ?? 0),
        'status_pinos_enabled'   => isset($_POST['status_pinos_enabled']) ? 1 : 0,
        'inter_modulos_enabled'  => isset($_POST['inter_modulos_enabled']) ? 1 : 0,
        'cor_com_alerta'         => $_POST['cor_com_alerta'] ?? '#ff0000',
        'cor_sem_alerta'         => $_POST['cor_sem_alerta'] ?? '#00ff00',
        'tempo_refresh'          => (int)($_POST['tempo_refresh'] ?? 15),
        'show_analog_history'    => isset($_POST['show_analog_history']) ? 1 : 0,
        'show_digital_history'   => isset($_POST['show_digital_history']) ? 1 : 0,
        'serial_debug_enabled'   => isset($_POST['serial_debug_enabled']) ? 1 : 0,
        'log_flags'              => $log_flags,
        'watchdog_enabled'       => isset($_POST['watchdog_enabled']) ? 1 : 0,
        'tempo_watchdog_us'      => (int)($_POST['tempo_watchdog_us'] ?? 8000000),
        'clock_esp32_mhz'        => (int)($_POST['clock_esp32_mhz'] ?? 240),
        'qtd_pinos'              => (int)($_POST['qtd_pinos'] ?? 16),
        'web_server_port'        => (int)($_POST['web_server_port'] ?? 8080),
        'auth_enabled'           => isset($_POST['auth_enabled']) ? 1 : 0,
        'web_username'           => trim($_POST['web_username'] ?? 'admin'),
        'web_password'           => $_POST['web_password'] ?? 'admin1234',
        'dashboard_auth_required'=> isset($_POST['dashboard_auth_required']) ? 1 : 0,
        'cloud_url'                  => trim($_POST['cloud_url'] ?? 'smcr.pensenet.com.br'),
        'cloud_port'                 => (int)($_POST['cloud_port'] ?? 8765),
        'cloud_use_https'            => isset($_POST['cloud_use_https']) ? 1 : 0,
        'cloud_sync_enabled'         => isset($_POST['cloud_sync_enabled']) ? 1 : 0,
        'cloud_sync_interval_min'    => (int)($_POST['cloud_sync_interval_min'] ?? 5),
        'cloud_heartbeat_enabled'    => isset($_POST['cloud_heartbeat_enabled']) ? 1 : 0,
        'cloud_heartbeat_interval_min' => (int)($_POST['cloud_heartbeat_interval_min'] ?? 5),
        'cloud_register_token'       => trim($_POST['cloud_register_token'] ?? ''),
    ];
    if (isset($_POST['cloud_api_token'])) {
        $fields['cloud_api_token'] = trim($_POST['cloud_api_token']);
    }

    $set_parts = [];
    $values = [];
    foreach ($fields as $col => $val) {
        $set_parts[] = "`$col` = ?";
        $values[] = $val;
    }
    $values[] = $device_id;

    $sql = 'UPDATE device_config SET ' . implode(', ', $set_parts) . ' WHERE device_id = ?';
    $db->prepare($sql)->execute($values);

    set_flash('success', 'Configurações gerais salvas com sucesso.');

    // Reload
    $stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $cfg = $stmt->fetch();
    $success = true;
}

// Log flags definitions
$log_flag_defs = [
    1   => 'LOG_INIT',
    2   => 'LOG_NETWORK',
    4   => 'LOG_PINS',
    8   => 'LOG_FLASH',
    16  => 'LOG_WEB',
    32  => 'LOG_SENSOR',
    64  => 'LOG_ACTIONS',
    128 => 'LOG_INTERMOD',
    256 => 'LOG_WATCHDOG',
    512 => 'LOG_MQTT',
];

$active_tab = $_GET['tab'] ?? 'rede';
$page_title = 'Config. Geral';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'Config. Geral']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-gear-fill me-2"></i>Configurações Gerais</h5>
    <span class="text-muted small"><?= h($device['name'] ?: $device['unique_id']) ?></span>
</div>

<form method="POST" action="<?= BASE ?>/devices/config_geral.php?device_id=<?= $device_id ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

    <div class="card">
        <div class="card-header p-0">
            <ul class="nav nav-tabs card-header-tabs px-3" id="configTabs">
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'rede' ? 'active' : '' ?>" href="#tab-rede" data-bs-toggle="tab">
                        <i class="bi bi-wifi me-1"></i>Rede
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'ntp' ? 'active' : '' ?>" href="#tab-ntp" data-bs-toggle="tab">
                        <i class="bi bi-clock me-1"></i>NTP
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'interface' ? 'active' : '' ?>" href="#tab-interface" data-bs-toggle="tab">
                        <i class="bi bi-display me-1"></i>Interface Web
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'sistema' ? 'active' : '' ?>" href="#tab-sistema" data-bs-toggle="tab">
                        <i class="bi bi-cpu me-1"></i>Sistema
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'webserver' ? 'active' : '' ?>" href="#tab-webserver" data-bs-toggle="tab">
                        <i class="bi bi-server me-1"></i>Servidor Web
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'cloud' ? 'active' : '' ?>" href="#tab-cloud" data-bs-toggle="tab">
                        <i class="bi bi-cloud me-1"></i>SMCR Cloud
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body tab-content pt-4">

            <!-- Tab Rede -->
            <div class="tab-pane fade <?= $active_tab === 'rede' ? 'show active' : '' ?>" id="tab-rede">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-router me-1"></i>Configurações de Rede</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Hostname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="hostname"
                               value="<?= h($cfg['hostname']) ?>" maxlength="64" required>
                        <div class="form-text">Nome do dispositivo na rede.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Tentativas de Conexão WiFi</label>
                        <input type="number" class="form-control" name="wifi_attempts"
                               value="<?= h($cfg['wifi_attempts']) ?>" min="1" max="255">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">SSID WiFi</label>
                        <input type="text" class="form-control" name="wifi_ssid"
                               value="<?= h($cfg['wifi_ssid']) ?>" maxlength="64">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Senha WiFi</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="wifi_pass"
                                   id="wifi_pass" value="<?= h($cfg['wifi_pass']) ?>" maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('wifi_pass',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Intervalo de Verificação WiFi (ms)</label>
                        <input type="number" class="form-control" name="wifi_check_interval"
                               value="<?= h($cfg['wifi_check_interval']) ?>" min="1000" step="1000">
                        <div class="form-text">Intervalo em milissegundos para verificar a conexão.</div>
                    </div>
                </div>

                <hr class="my-4">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-broadcast me-1"></i>Ponto de Acesso (AP) de Fallback</h6>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="ap_fallback_enabled"
                                   id="ap_fallback_enabled" value="1"
                                   <?= $cfg['ap_fallback_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ap_fallback_enabled">
                                Ativar AP de fallback quando WiFi falhar
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SSID do AP</label>
                        <input type="text" class="form-control" name="ap_ssid"
                               value="<?= h($cfg['ap_ssid']) ?>" maxlength="64">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Senha do AP</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="ap_pass"
                                   id="ap_pass" value="<?= h($cfg['ap_pass']) ?>" maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('ap_pass',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab NTP -->
            <div class="tab-pane fade <?= $active_tab === 'ntp' ? 'show active' : '' ?>" id="tab-ntp">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-clock-fill me-1"></i>Sincronização de Tempo (NTP)</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Servidor NTP</label>
                        <input type="text" class="form-control" name="ntp_server1"
                               value="<?= h($cfg['ntp_server1']) ?>" maxlength="64">
                        <div class="form-text">Ex: pool.ntp.br, time.google.com</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Offset GMT (segundos)</label>
                        <input type="number" class="form-control" name="gmt_offset_sec"
                               value="<?= h($cfg['gmt_offset_sec']) ?>">
                        <div class="form-text">Brasília: -10800 (UTC-3)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Offset Horário de Verão (segundos)</label>
                        <input type="number" class="form-control" name="daylight_offset_sec"
                               value="<?= h($cfg['daylight_offset_sec']) ?>">
                        <div class="form-text">Normalmente 0 ou 3600</div>
                    </div>
                </div>
                <div class="alert alert-info mt-3 small">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Fusos comuns:</strong> Brasília/São Paulo = -10800 | Manaus = -14400 | Acre = -18000
                </div>
            </div>

            <!-- Tab Interface Web -->
            <div class="tab-pane fade <?= $active_tab === 'interface' ? 'show active' : '' ?>" id="tab-interface">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-display me-1"></i>Interface Web do Dispositivo</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="status_pinos_enabled"
                                   id="status_pinos_enabled" value="1"
                                   <?= $cfg['status_pinos_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status_pinos_enabled">
                                Mostrar Status de Pinos
                            </label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="inter_modulos_enabled"
                                   id="inter_modulos_enabled" value="1"
                                   <?= $cfg['inter_modulos_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="inter_modulos_enabled">
                                Mostrar Info Inter-Módulos
                            </label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="show_analog_history"
                                   id="show_analog_history" value="1"
                                   <?= $cfg['show_analog_history'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="show_analog_history">
                                Mostrar histórico de pinos analógicos
                            </label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="show_digital_history"
                                   id="show_digital_history" value="1"
                                   <?= $cfg['show_digital_history'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="show_digital_history">
                                Mostrar histórico de pinos digitais
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label">Cor com Alerta</label>
                                <div class="input-group">
                                    <input type="color" class="form-control form-control-color" name="cor_com_alerta"
                                           value="<?= h($cfg['cor_com_alerta']) ?>">
                                    <input type="text" class="form-control form-control-sm font-monospace"
                                           value="<?= h($cfg['cor_com_alerta']) ?>" id="cor_com_alerta_text"
                                           readonly>
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Cor sem Alerta</label>
                                <div class="input-group">
                                    <input type="color" class="form-control form-control-color" name="cor_sem_alerta"
                                           value="<?= h($cfg['cor_sem_alerta']) ?>">
                                    <input type="text" class="form-control form-control-sm font-monospace"
                                           value="<?= h($cfg['cor_sem_alerta']) ?>" id="cor_sem_alerta_text"
                                           readonly>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Tempo de Refresh (segundos)</label>
                                <input type="number" class="form-control" name="tempo_refresh"
                                       value="<?= h($cfg['tempo_refresh']) ?>" min="1" max="3600">
                                <div class="form-text">Intervalo de atualização da interface web local.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Sistema -->
            <div class="tab-pane fade <?= $active_tab === 'sistema' ? 'show active' : '' ?>" id="tab-sistema">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-cpu-fill me-1"></i>Configurações do Sistema</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Quantidade de Pinos</label>
                        <input type="number" class="form-control" name="qtd_pinos"
                               value="<?= h($cfg['qtd_pinos']) ?>" min="1" max="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Clock do ESP32 (MHz)</label>
                        <select class="form-select" name="clock_esp32_mhz">
                            <option value="80"  <?= $cfg['clock_esp32_mhz'] == 80  ? 'selected' : '' ?>>80 MHz</option>
                            <option value="160" <?= $cfg['clock_esp32_mhz'] == 160 ? 'selected' : '' ?>>160 MHz</option>
                            <option value="240" <?= $cfg['clock_esp32_mhz'] == 240 ? 'selected' : '' ?>>240 MHz</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Timeout do Watchdog (µs)</label>
                        <input type="number" class="form-control" name="tempo_watchdog_us"
                               value="<?= h($cfg['tempo_watchdog_us']) ?>" min="0">
                        <div class="form-text">Padrão: 8000000 µs (8 seg)</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="watchdog_enabled"
                                   id="watchdog_enabled" value="1"
                                   <?= $cfg['watchdog_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="watchdog_enabled">
                                Habilitar Watchdog
                            </label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="serial_debug_enabled"
                                   id="serial_debug_enabled" value="1"
                                   <?= $cfg['serial_debug_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="serial_debug_enabled">
                                Habilitar debug via porta serial
                            </label>
                        </div>
                    </div>
                </div>

                <hr class="my-3">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-journal-text me-1"></i>Flags de Log</h6>
                <div class="row g-2">
                    <?php foreach ($log_flag_defs as $flag => $label): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="log_flag[]"
                                   value="<?= $flag ?>" id="log_<?= $flag ?>"
                                   <?= ($cfg['log_flags'] & $flag) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="log_<?= $flag ?>">
                                <code><?= $label ?></code>
                                <span class="text-muted">(<?= $flag ?>)</span>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        Valor atual: <code id="log_flags_value"><?= $cfg['log_flags'] ?></code>
                    </small>
                </div>
            </div>

            <!-- Tab Servidor Web -->
            <div class="tab-pane fade <?= $active_tab === 'webserver' ? 'show active' : '' ?>" id="tab-webserver">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-server me-1"></i>Servidor Web Interno do ESP32</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Porta do Servidor Web</label>
                        <input type="number" class="form-control" name="web_server_port"
                               value="<?= h($cfg['web_server_port']) ?>" min="1" max="65535">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="auth_enabled"
                                   id="auth_enabled" value="1"
                                   <?= $cfg['auth_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auth_enabled">
                                Habilitar autenticação no servidor web
                            </label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="dashboard_auth_required"
                                   id="dashboard_auth_required" value="1"
                                   <?= $cfg['dashboard_auth_required'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="dashboard_auth_required">
                                Exigir autenticação no dashboard do dispositivo
                            </label>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Usuário Web</label>
                        <input type="text" class="form-control" name="web_username"
                               value="<?= h($cfg['web_username']) ?>" maxlength="64">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Senha Web</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="web_password"
                                   id="web_password" value="<?= h($cfg['web_password']) ?>" maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('web_password',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab SMCR Cloud -->
            <div class="tab-pane fade <?= $active_tab === 'cloud' ? 'show active' : '' ?>" id="tab-cloud">
                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-cloud-fill me-1"></i>Configurações SMCR Cloud</h6>
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i>
                    Quando o sync com a cloud estiver habilitado, o ESP32 busca periodicamente as configurações deste painel e aplica automaticamente.
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">URL da Cloud</label>
                        <input type="text" class="form-control" name="cloud_url"
                               value="<?= h($cfg['cloud_url'] ?? 'smcr.pensenet.com.br') ?>" maxlength="128">
                        <div class="form-text">Endereço do servidor SMCR Cloud (sem http://)</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Porta</label>
                        <input type="number" class="form-control" name="cloud_port"
                               value="<?= h($cfg['cloud_port'] ?? 8765) ?>" min="1" max="65535">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">HTTPS</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="cloud_use_https"
                                   id="cloud_use_https" value="1"
                                   <?= ($cfg['cloud_use_https'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cloud_use_https">Usar HTTPS</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Intervalo de Sync (minutos)</label>
                        <input type="number" class="form-control" name="cloud_sync_interval_min"
                               value="<?= h($cfg['cloud_sync_interval_min'] ?? 5) ?>" min="1" max="1440">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="cloud_sync_enabled"
                                   id="cloud_sync_enabled" value="1"
                                   <?= ($cfg['cloud_sync_enabled'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cloud_sync_enabled">
                                Habilitar Sync Automático com a Cloud
                            </label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="cloud_heartbeat_enabled"
                                   id="cloud_heartbeat_enabled" value="1"
                                   <?= ($cfg['cloud_heartbeat_enabled'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cloud_heartbeat_enabled">
                                Heartbeat para Cloud
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Intervalo do Heartbeat (minutos)</label>
                        <input type="number" class="form-control" name="cloud_heartbeat_interval_min"
                               value="<?= h($cfg['cloud_heartbeat_interval_min'] ?? 5) ?>" min="1" max="1440">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Token de Registro</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="cloud_register_token"
                                   id="cloud_register_token" value="<?= h($cfg['cloud_register_token'] ?? '') ?>" maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('cloud_register_token',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Copie de Configurações → SMCR Cloud no painel. O ESP32 usa este token para se registrar automaticamente.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Token API</label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="cloud_api_token"
                                   id="cloud_api_token" value="<?= h($cfg['cloud_api_token'] ?? '') ?>" maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('cloud_api_token',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Gerado automaticamente após o registro. Deixe em branco para forçar novo registro no próximo sync.</div>
                    </div>
                </div>
            </div>

        </div><!-- /.card-body tab-content -->

        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Salvar Configurações
            </button>
            <a href="<?= BASE ?>/devices/view.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div><!-- /.card -->
</form>

<script>
// Sync color inputs with text display
document.querySelectorAll('input[type="color"]').forEach(function(colorInput) {
    colorInput.addEventListener('input', function() {
        const textId = this.name.replace('cor_', 'cor_') + '_text';
        const textInput = document.getElementById(textId);
        if (textInput) textInput.value = this.value;
    });
});

// Update log flags value display
document.querySelectorAll('input[name="log_flag[]"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        let total = 0;
        document.querySelectorAll('input[name="log_flag[]"]:checked').forEach(function(c) {
            total |= parseInt(c.value);
        });
        document.getElementById('log_flags_value').textContent = total;
    });
});

// Toggle password visibility
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
