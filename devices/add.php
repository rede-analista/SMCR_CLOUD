<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();
$errors = [];
$values = ['name' => '', 'unique_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $import_mode = !empty($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK;
    $backup = null;

    if ($import_mode) {
        $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
        $backup = json_decode($raw, true);
        if (!is_array($backup) || empty($backup['smcr_backup'])) {
            $errors[] = 'Arquivo inválido: não é um backup SMCR.';
            $import_mode = false;
        }
    }

    $name      = trim($_POST['name'] ?? ($backup['name'] ?? ''));
    $unique_id = trim($_POST['unique_id'] ?? ($backup['unique_id'] ?? ''));

    if ($unique_id === '') {
        $errors[] = 'O ID Único é obrigatório.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $unique_id)) {
        $errors[] = 'O ID Único deve conter apenas letras, números, _ e -.';
    }

    $values = ['name' => $name, 'unique_id' => $unique_id];

    if (empty($errors)) {
        $stmt = $db->prepare('SELECT id FROM devices WHERE unique_id = ?');
        $stmt->execute([$unique_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Este ID Único já está cadastrado.';
        }
    }

    if (empty($errors)) {
        $use_backup_token = $import_mode && !empty($_POST['use_backup_token']) && !empty($backup['api_token']);
        $api_token = $use_backup_token
            ? preg_replace('/[^a-zA-Z0-9]/', '', $backup['api_token'])
            : bin2hex(random_bytes(32));

        if (strlen($api_token) < 16) {
            $api_token = bin2hex(random_bytes(32));
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('INSERT INTO devices (name, unique_id, api_token) VALUES (?, ?, ?)');
            $stmt->execute([$name, $unique_id, $api_token]);
            $device_id = (int)$db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO device_status (device_id) VALUES (?)');
            $stmt->execute([$device_id]);

            if ($import_mode && is_array($backup)) {
                // Config
                $c = $backup['config'] ?? [];
                $stmt = $db->prepare("
                    INSERT INTO device_config (device_id,
                        hostname, wifi_ssid, wifi_pass, wifi_attempts, wifi_check_interval,
                        ap_ssid, ap_pass, ap_fallback_enabled,
                        ntp_server1, gmt_offset_sec, daylight_offset_sec,
                        status_pinos_enabled, inter_modulos_enabled,
                        cor_com_alerta, cor_sem_alerta, tempo_refresh,
                        show_analog_history, show_digital_history,
                        serial_debug_enabled, log_flags,
                        watchdog_enabled, tempo_watchdog_us, clock_esp32_mhz, qtd_pinos,
                        web_server_port, auth_enabled, web_username, web_password, dashboard_auth_required,
                        mqtt_enabled, mqtt_server, mqtt_port, mqtt_user, mqtt_password,
                        mqtt_topic_base, mqtt_publish_interval,
                        mqtt_ha_discovery, mqtt_ha_batch, mqtt_ha_interval_ms, mqtt_ha_repeat_sec,
                        intermod_enabled, intermod_healthcheck, intermod_max_failures, intermod_auto_discovery,
                        telegram_enabled, telegram_token, telegram_chatid, telegram_interval,
                        cloud_url, cloud_port, cloud_sync_enabled, cloud_sync_interval_min,
                        cloud_heartbeat_enabled, cloud_heartbeat_interval_min
                    ) VALUES (
                        :device_id,
                        :hostname, :wifi_ssid, :wifi_pass, :wifi_attempts, :wifi_check_interval,
                        :ap_ssid, :ap_pass, :ap_fallback,
                        :ntp_server1, :gmt_offset, :daylight_offset,
                        :status_pinos, :inter_mod,
                        :cor_alerta, :cor_ok, :refresh,
                        :show_a, :show_d,
                        :dbg, :log,
                        :wdt_en, :wdt_t, :clk, :qtd,
                        :port, :auth, :wuser, :wpass, :dash,
                        :mqtt_en, :mqtt_srv, :mqtt_port, :mqtt_usr, :mqtt_pass,
                        :mqtt_topic, :mqtt_pint,
                        :mqtt_had, :mqtt_hab, :mqtt_haim, :mqtt_harp,
                        :imod_en, :imod_hchk, :imod_mfail, :imod_adisc,
                        :tg_en, :tg_tok, :tg_chat, :tg_intv,
                        :cloud_url, :cloud_port, :cloud_sync_en, :cloud_sync_int,
                        :cloud_hb_en, :cloud_hb_int
                    )
                ");
                $stmt->execute([
                    ':device_id'     => $device_id,
                    ':hostname'      => $c['hostname']                   ?? 'esp32modularx',
                    ':wifi_ssid'     => $c['wifi_ssid']                  ?? '',
                    ':wifi_pass'     => $c['wifi_pass']                  ?? '',
                    ':wifi_attempts' => (int)($c['wifi_attempts']        ?? 15),
                    ':wifi_check_interval' => (int)($c['wifi_check_interval'] ?? 15000),
                    ':ap_ssid'       => $c['ap_ssid']                    ?? 'SMCR_AP_SETUP',
                    ':ap_pass'       => $c['ap_pass']                    ?? 'senha1234',
                    ':ap_fallback'   => (int)($c['ap_fallback_enabled']  ?? 1),
                    ':ntp_server1'   => $c['ntp_server1']                ?? 'pool.ntp.br',
                    ':gmt_offset'    => (int)($c['gmt_offset_sec']       ?? -10800),
                    ':daylight_offset' => (int)($c['daylight_offset_sec'] ?? 0),
                    ':status_pinos'  => (int)($c['status_pinos_enabled'] ?? 1),
                    ':inter_mod'     => (int)($c['inter_modulos_enabled'] ?? 0),
                    ':cor_alerta'    => $c['cor_com_alerta']             ?? '#ff0000',
                    ':cor_ok'        => $c['cor_sem_alerta']             ?? '#00ff00',
                    ':refresh'       => (int)($c['tempo_refresh']        ?? 15),
                    ':show_a'        => (int)($c['show_analog_history']  ?? 1),
                    ':show_d'        => (int)($c['show_digital_history'] ?? 1),
                    ':dbg'           => (int)($c['serial_debug_enabled'] ?? 0),
                    ':log'           => (int)($c['log_flags']            ?? 0),
                    ':wdt_en'        => (int)($c['watchdog_enabled']     ?? 0),
                    ':wdt_t'         => (int)($c['tempo_watchdog_us']    ?? 8000000),
                    ':clk'           => (int)($c['clock_esp32_mhz']      ?? 240),
                    ':qtd'           => (int)($c['qtd_pinos']            ?? 16),
                    ':port'          => (int)($c['web_server_port']      ?? 8080),
                    ':auth'          => (int)($c['auth_enabled']         ?? 0),
                    ':wuser'         => $c['web_username']               ?? 'admin',
                    ':wpass'         => $c['web_password']               ?? 'admin1234',
                    ':dash'          => (int)($c['dashboard_auth_required'] ?? 0),
                    ':mqtt_en'       => (int)($c['mqtt_enabled']         ?? 0),
                    ':mqtt_srv'      => $c['mqtt_server']                ?? '',
                    ':mqtt_port'     => (int)($c['mqtt_port']            ?? 1883),
                    ':mqtt_usr'      => $c['mqtt_user']                  ?? '',
                    ':mqtt_pass'     => $c['mqtt_password']              ?? '',
                    ':mqtt_topic'    => $c['mqtt_topic_base']            ?? 'smcr',
                    ':mqtt_pint'     => (int)($c['mqtt_publish_interval'] ?? 60),
                    ':mqtt_had'      => (int)($c['mqtt_ha_discovery']    ?? 1),
                    ':mqtt_hab'      => (int)($c['mqtt_ha_batch']        ?? 4),
                    ':mqtt_haim'     => (int)($c['mqtt_ha_interval_ms']  ?? 100),
                    ':mqtt_harp'     => (int)($c['mqtt_ha_repeat_sec']   ?? 900),
                    ':imod_en'       => (int)($c['intermod_enabled']     ?? 0),
                    ':imod_hchk'     => (int)($c['intermod_healthcheck'] ?? 60),
                    ':imod_mfail'    => (int)($c['intermod_max_failures'] ?? 3),
                    ':imod_adisc'    => (int)($c['intermod_auto_discovery'] ?? 0),
                    ':tg_en'         => (int)($c['telegram_enabled']     ?? 0),
                    ':tg_tok'        => $c['telegram_token']             ?? '',
                    ':tg_chat'       => $c['telegram_chatid']            ?? '',
                    ':tg_intv'       => (int)($c['telegram_interval']    ?? 30),
                    ':cloud_url'     => $c['cloud_url']                  ?? '',
                    ':cloud_port'    => (int)($c['cloud_port']           ?? 8765),
                    ':cloud_sync_en' => (int)($c['cloud_sync_enabled']   ?? 0),
                    ':cloud_sync_int'=> (int)($c['cloud_sync_interval_min'] ?? 5),
                    ':cloud_hb_en'   => (int)($c['cloud_heartbeat_enabled'] ?? 0),
                    ':cloud_hb_int'  => (int)($c['cloud_heartbeat_interval_min'] ?? 5),
                ]);

                // Pinos
                if (is_array($backup['pins'] ?? null)) {
                    $stmt = $db->prepare("
                        INSERT INTO device_pins
                            (device_id, nome, pino, tipo, modo, xor_logic, tempo_retencao,
                             nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt)
                        VALUES (:device_id, :nome, :pino, :tipo, :modo, :xor, :ret, :nmin, :nmax, :cmqtt, :imqtt)
                    ");
                    foreach ($backup['pins'] as $p) {
                        if (!isset($p['pino'])) continue;
                        $stmt->execute([
                            ':device_id' => $device_id,
                            ':nome'  => substr($p['nome']       ?? '', 0, 64),
                            ':pino'  => (int)$p['pino'],
                            ':tipo'  => (int)($p['tipo']        ?? 0),
                            ':modo'  => (int)($p['modo']        ?? 0),
                            ':xor'   => (int)($p['xor_logic']   ?? 0),
                            ':ret'   => (int)($p['tempo_retencao'] ?? 0),
                            ':nmin'  => (int)($p['nivel_acionamento_min'] ?? 0),
                            ':nmax'  => (int)($p['nivel_acionamento_max'] ?? 1),
                            ':cmqtt' => substr($p['classe_mqtt'] ?? '', 0, 50),
                            ':imqtt' => substr($p['icone_mqtt']  ?? '', 0, 50),
                        ]);
                    }
                }

                // Ações
                if (is_array($backup['actions'] ?? null)) {
                    $stmt = $db->prepare("
                        INSERT INTO device_actions
                            (device_id, pino_origem, numero_acao, pino_destino, acao,
                             tempo_on, tempo_off, pino_remoto, envia_modulo, telegram, assistente)
                        VALUES (:device_id, :orig, :num, :dest, :acao, :ton, :toff, :premoto, :modulo, :tg, :ass)
                    ");
                    foreach ($backup['actions'] as $a) {
                        if (!isset($a['pino_origem'])) continue;
                        $stmt->execute([
                            ':device_id' => $device_id,
                            ':orig'   => (int)$a['pino_origem'],
                            ':num'    => (int)($a['numero_acao']  ?? 1),
                            ':dest'   => (int)($a['pino_destino'] ?? 0),
                            ':acao'   => (int)($a['acao']         ?? 0),
                            ':ton'    => (int)($a['tempo_on']     ?? 0),
                            ':toff'   => (int)($a['tempo_off']    ?? 0),
                            ':premoto'=> (int)($a['pino_remoto']  ?? 0),
                            ':modulo' => substr($a['envia_modulo'] ?? '', 0, 64),
                            ':tg'     => (int)($a['telegram']     ?? 0),
                            ':ass'    => (int)($a['assistente']   ?? 0),
                        ]);
                    }
                }

                // Inter-módulos
                if (is_array($backup['intermod'] ?? null)) {
                    $stmt = $db->prepare("
                        INSERT INTO device_intermod (device_id, module_id, hostname, ip, porta,
                            ativo, pins_offline, offline_alert_enabled, offline_flash_ms,
                            pins_healthcheck, healthcheck_alert_enabled, healthcheck_flash_ms)
                        VALUES (:device_id, :mid, :host, :ip, :port,
                            :ativo, :pins_offline, :offline_en, :offline_ms, :pins_hc, :hc_en, :hc_ms)
                    ");
                    foreach ($backup['intermod'] as $mod) {
                        $mid = $mod['module_id'] ?? '';
                        if ($mid === '') continue;
                        $stmt->execute([
                            ':device_id'    => $device_id,
                            ':mid'          => substr($mid, 0, 64),
                            ':host'         => substr($mod['hostname']   ?? '', 0, 64),
                            ':ip'           => substr($mod['ip']         ?? '', 0, 45),
                            ':port'         => (int)($mod['porta']       ?? 8080),
                            ':ativo'        => (int)($mod['ativo']       ?? 0),
                            ':pins_offline' => substr($mod['pins_offline']             ?? '', 0, 255),
                            ':offline_en'   => (int)($mod['offline_alert_enabled']     ?? 0),
                            ':offline_ms'   => (int)($mod['offline_flash_ms']          ?? 200),
                            ':pins_hc'      => substr($mod['pins_healthcheck']         ?? '', 0, 255),
                            ':hc_en'        => (int)($mod['healthcheck_alert_enabled'] ?? 0),
                            ':hc_ms'        => (int)($mod['healthcheck_flash_ms']      ?? 500),
                        ]);
                    }
                }
            } else {
                // Cadastro manual: config padrão
                $stmt = $db->prepare('INSERT INTO device_config (device_id) VALUES (?)');
                $stmt->execute([$device_id]);
            }

            $db->commit();

            $msg = $import_mode ? "Dispositivo \"$unique_id\" importado com sucesso!" : "Dispositivo \"$unique_id\" cadastrado com sucesso!";
            set_flash('success', $msg);
            header('Location: ' . BASE . '/devices/view.php?device_id=' . $device_id);
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
    <div class="col-md-8 col-lg-7">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="addTabs">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-manual-link" href="#" onclick="switchTab('manual');return false;">
                            <i class="bi bi-pencil-square me-1"></i>Cadastro Manual
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-import-link" href="#" onclick="switchTab('import');return false;">
                            <i class="bi bi-upload me-1"></i>Importar do Backup
                        </a>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <form method="POST" action="<?= BASE ?>/devices/add.php" enctype="multipart/form-data" id="addForm">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                    <!-- === MANUAL === -->
                    <div id="tab-manual">
                        <div class="mb-3">
                            <label for="unique_id" class="form-label fw-semibold">
                                ID Único <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control font-monospace" id="unique_id" name="unique_id"
                                   value="<?= h($values['unique_id']) ?>"
                                   placeholder="ex: smcr_A1B2C3D4E5F6"
                                   pattern="[a-zA-Z0-9_\-]+">
                            <div class="form-text">Formato padrão: <code>smcr_XXXXXXXXXXXX</code></div>
                        </div>
                        <div class="mb-4">
                            <label for="name" class="form-label fw-semibold">Nome do Dispositivo</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= h($values['name']) ?>"
                                   placeholder="ex: ESP32 Sala Principal"
                                   maxlength="100">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Cadastrar Dispositivo
                            </button>
                            <a href="<?= BASE ?>/devices/index.php" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </div>

                    <!-- === IMPORT === -->
                    <div id="tab-import" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Arquivo de Backup <span class="text-danger">*</span></label>
                            <input type="file" id="backup_file" name="backup_file" class="form-control" accept=".json">
                            <div class="form-text">Selecione um <code>.json</code> exportado pelo SMCR Cloud.</div>
                        </div>
                        <div id="import-preview" style="display:none;">
                            <div class="mb-3">
                                <label for="unique_id_import" class="form-label fw-semibold">ID Único</label>
                                <input type="text" class="form-control font-monospace" id="unique_id_import"
                                       placeholder="Carregado do backup" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="name_import" class="form-label fw-semibold">Nome do Dispositivo</label>
                                <input type="text" class="form-control" id="name_import"
                                       placeholder="Carregado do backup" readonly>
                            </div>
                            <div class="mb-3 p-3 bg-light rounded small">
                                <div id="import-summary" class="text-muted"></div>
                            </div>
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="use_backup_token" name="use_backup_token" value="1">
                                <label class="form-check-label" for="use_backup_token">
                                    Restaurar chave de API do backup
                                </label>
                                <div class="form-text text-warning">
                                    <i class="bi bi-exclamation-triangle me-1"></i>Ative somente se o ESP32 ainda usa a chave original.
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="btn-import-submit" disabled>
                                <i class="bi bi-upload me-1"></i>Importar e Cadastrar
                            </button>
                            <a href="<?= BASE ?>/devices/index.php" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3" id="card-manual-info">
            <div class="card-body">
                <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>Como obter o ID Único</h6>
                <p class="small text-muted mb-0">
                    O ID Único é gerado automaticamente pelo firmware SMCR com base no MAC do ESP32.
                    Encontre-o no dashboard do dispositivo ou na saída serial. Formato: <code>smcr_XXXXXXXXXXXX</code>.
                </p>
            </div>
        </div>

    </div>
</div>

<script>
function switchTab(tab) {
    const isImport = tab === 'import';
    document.getElementById('tab-manual').style.display     = isImport ? 'none' : '';
    document.getElementById('tab-import').style.display     = isImport ? '' : 'none';
    document.getElementById('card-manual-info').style.display = isImport ? 'none' : '';
    document.getElementById('tab-manual-link').classList.toggle('active', !isImport);
    document.getElementById('tab-import-link').classList.toggle('active', isImport);

    const uid   = document.getElementById('unique_id');
    const name  = document.getElementById('name');
    if (!isImport) {
        uid.required  = true;
        uid.name      = 'unique_id';
        name.name     = 'name';
    } else {
        uid.required  = false;
        uid.name      = '';
        name.name     = '';
    }
}

document.getElementById('backup_file').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        let data;
        try { data = JSON.parse(e.target.result); } catch { alert('Arquivo JSON inválido.'); return; }

        if (!data.smcr_backup) { alert('Este arquivo não é um backup SMCR válido.'); return; }

        document.getElementById('unique_id_import').value = data.unique_id || '';
        document.getElementById('name_import').value      = data.name      || '';

        const pins    = (data.pins    || []).length;
        const actions = (data.actions || []).length;
        const intermod= (data.intermod|| []).length;
        const exported= data.exported_at || '—';
        document.getElementById('import-summary').innerHTML =
            `<i class="bi bi-calendar3 me-1"></i>Exportado em: <strong>${exported}</strong><br>` +
            `<i class="bi bi-diagram-3 me-1"></i>Pinos: <strong>${pins}</strong> &nbsp;` +
            `<i class="bi bi-lightning me-1"></i>Ações: <strong>${actions}</strong> &nbsp;` +
            `<i class="bi bi-share me-1"></i>Inter-módulos: <strong>${intermod}</strong>`;

        // Injeta unique_id e name como hidden para o POST
        let hidUid = document.getElementById('hidden_unique_id');
        if (!hidUid) { hidUid = document.createElement('input'); hidUid.type='hidden'; hidUid.id='hidden_unique_id'; hidUid.name='unique_id'; document.getElementById('addForm').appendChild(hidUid); }
        hidUid.value = data.unique_id || '';

        let hidName = document.getElementById('hidden_name');
        if (!hidName) { hidName = document.createElement('input'); hidName.type='hidden'; hidName.id='hidden_name'; hidName.name='name'; document.getElementById('addForm').appendChild(hidName); }
        hidName.value = data.name || '';

        document.getElementById('import-preview').style.display = '';
        document.getElementById('btn-import-submit').disabled   = false;
    };
    reader.readAsText(file);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
