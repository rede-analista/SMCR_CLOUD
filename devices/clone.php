<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db = getDB();

// Busca todos os dispositivos
$devices = $db->query('SELECT id, name, unique_id FROM devices ORDER BY name, unique_id')->fetchAll();

$results  = [];
$errors   = [];
$done     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $src_id   = (int)($_POST['source_id']   ?? 0);
    $targets  = array_map('intval', $_POST['targets'] ?? []);
    $sections = $_POST['sections'] ?? [];

    if (!$src_id || empty($targets) || empty($sections)) {
        $errors[] = 'Selecione origem, ao menos um destino e ao menos uma seção.';
    } elseif (in_array($src_id, $targets)) {
        $errors[] = 'O dispositivo de origem não pode ser um dos destinos.';
    } else {
        // Carrega dados de origem
        $src_cfg = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
        $src_cfg->execute([$src_id]);
        $cfg = $src_cfg->fetch() ?: [];

        $src_pins = $db->prepare('SELECT * FROM device_pins WHERE device_id = ?');
        $src_pins->execute([$src_id]);
        $pins = $src_pins->fetchAll();

        $src_actions = $db->prepare('SELECT * FROM device_actions WHERE device_id = ?');
        $src_actions->execute([$src_id]);
        $actions = $src_actions->fetchAll();

        $src_mods = $db->prepare('SELECT * FROM device_intermod WHERE device_id = ?');
        $src_mods->execute([$src_id]);
        $modules = $src_mods->fetchAll();

        foreach ($targets as $tgt_id) {
            $tgt = $db->prepare('SELECT name, unique_id FROM devices WHERE id = ?');
            $tgt->execute([$tgt_id]);
            $tgt_dev = $tgt->fetch();
            $tgt_name = $tgt_dev['name'] ?: $tgt_dev['unique_id'];
            $cloned = [];

            try {
                $db->beginTransaction();

                // ─── Config Geral ───
                if (in_array('config_geral', $sections) && $cfg) {
                    $db->prepare("UPDATE device_config SET
                        wifi_attempts=:wa, wifi_check_interval=:wci,
                        ntp_server1=:ntp, gmt_offset_sec=:gmt, daylight_offset_sec=:dst,
                        status_pinos_enabled=:spe, inter_modulos_enabled=:ime,
                        cor_com_alerta=:cca, cor_sem_alerta=:csa, tempo_refresh=:tr,
                        show_analog_history=:sah, show_digital_history=:sdh,
                        watchdog_enabled=:wde, clock_esp32_mhz=:clk, tempo_watchdog_us=:twu,
                        qtd_pinos=:qp, serial_debug_enabled=:sde, log_flags=:lf,
                        web_server_port=:wsp, auth_enabled=:ae,
                        dashboard_auth_required=:dar, ap_fallback_enabled=:afe
                        WHERE device_id=:id"
                    )->execute([
                        ':wa'  => $cfg['wifi_attempts'],        ':wci' => $cfg['wifi_check_interval'],
                        ':ntp' => $cfg['ntp_server1'],          ':gmt' => $cfg['gmt_offset_sec'],
                        ':dst' => $cfg['daylight_offset_sec'],  ':spe' => $cfg['status_pinos_enabled'],
                        ':ime' => $cfg['inter_modulos_enabled'],':cca' => $cfg['cor_com_alerta'],
                        ':csa' => $cfg['cor_sem_alerta'],       ':tr'  => $cfg['tempo_refresh'],
                        ':sah' => $cfg['show_analog_history'],  ':sdh' => $cfg['show_digital_history'],
                        ':wde' => $cfg['watchdog_enabled'],     ':clk' => $cfg['clock_esp32_mhz'],
                        ':twu' => $cfg['tempo_watchdog_us'],    ':qp'  => $cfg['qtd_pinos'],
                        ':sde' => $cfg['serial_debug_enabled'], ':lf'  => $cfg['log_flags'],
                        ':wsp' => $cfg['web_server_port'],      ':ae'  => $cfg['auth_enabled'],
                        ':dar' => $cfg['dashboard_auth_required'],':afe'=> $cfg['ap_fallback_enabled'],
                        ':id'  => $tgt_id,
                    ]);
                    $cloned[] = 'Config Geral';
                }

                // ─── MQTT ───
                if (in_array('mqtt', $sections) && $cfg) {
                    $db->prepare("UPDATE device_config SET
                        mqtt_enabled=:en, mqtt_server=:srv, mqtt_port=:port,
                        mqtt_user=:usr, mqtt_password=:pass, mqtt_topic_base=:topic,
                        mqtt_publish_interval=:pint, mqtt_ha_discovery=:had,
                        mqtt_ha_batch=:hab, mqtt_ha_interval_ms=:haim, mqtt_ha_repeat_sec=:harp
                        WHERE device_id=:id"
                    )->execute([
                        ':en'   => $cfg['mqtt_enabled'],          ':srv'  => $cfg['mqtt_server'],
                        ':port' => $cfg['mqtt_port'],             ':usr'  => $cfg['mqtt_user'],
                        ':pass' => $cfg['mqtt_password'],         ':topic'=> $cfg['mqtt_topic_base'],
                        ':pint' => $cfg['mqtt_publish_interval'], ':had'  => $cfg['mqtt_ha_discovery'],
                        ':hab'  => $cfg['mqtt_ha_batch'],         ':haim' => $cfg['mqtt_ha_interval_ms'],
                        ':harp' => $cfg['mqtt_ha_repeat_sec'],    ':id'   => $tgt_id,
                    ]);
                    $cloned[] = 'MQTT';
                }

                // ─── Telegram ───
                if (in_array('telegram', $sections) && $cfg) {
                    $db->prepare("UPDATE device_config SET
                        telegram_enabled=:en, telegram_token=:tok,
                        telegram_chatid=:chat, telegram_interval=:intv
                        WHERE device_id=:id"
                    )->execute([
                        ':en'   => $cfg['telegram_enabled'],  ':tok'  => $cfg['telegram_token'],
                        ':chat' => $cfg['telegram_chatid'],   ':intv' => $cfg['telegram_interval'],
                        ':id'   => $tgt_id,
                    ]);
                    $cloned[] = 'Telegram';
                }

                // ─── Inter-módulos config ───
                if (in_array('intermod_cfg', $sections) && $cfg) {
                    $db->prepare("UPDATE device_config SET
                        intermod_enabled=:en, intermod_healthcheck=:hchk,
                        intermod_max_failures=:mf, intermod_auto_discovery=:ad
                        WHERE device_id=:id"
                    )->execute([
                        ':en'   => $cfg['intermod_enabled'],        ':hchk' => $cfg['intermod_healthcheck'],
                        ':mf'   => $cfg['intermod_max_failures'],   ':ad'   => $cfg['intermod_auto_discovery'],
                        ':id'   => $tgt_id,
                    ]);
                    $cloned[] = 'Inter-módulos Config';
                }

                // ─── Pinos ───
                if (in_array('pinos', $sections)) {
                    $db->prepare('DELETE FROM device_pins WHERE device_id = ?')->execute([$tgt_id]);
                    $stmt = $db->prepare('INSERT INTO device_pins
                        (device_id, nome, pino, tipo, modo, xor_logic, tempo_retencao,
                         nivel_acionamento_min, nivel_acionamento_max, classe_mqtt, icone_mqtt)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    foreach ($pins as $p) {
                        $stmt->execute([$tgt_id, $p['nome'], $p['pino'], $p['tipo'], $p['modo'],
                            $p['xor_logic'], $p['tempo_retencao'], $p['nivel_acionamento_min'],
                            $p['nivel_acionamento_max'], $p['classe_mqtt'], $p['icone_mqtt']]);
                    }
                    $cloned[] = 'Pinos (' . count($pins) . ')';
                }

                // ─── Ações ───
                if (in_array('acoes', $sections)) {
                    $db->prepare('DELETE FROM device_actions WHERE device_id = ?')->execute([$tgt_id]);
                    $stmt = $db->prepare('INSERT INTO device_actions
                        (device_id, pino_origem, numero_acao, pino_destino, acao,
                         tempo_on, tempo_off, pino_remoto, envia_modulo, telegram, assistente)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    foreach ($actions as $a) {
                        $stmt->execute([$tgt_id, $a['pino_origem'], $a['numero_acao'], $a['pino_destino'],
                            $a['acao'], $a['tempo_on'], $a['tempo_off'], $a['pino_remoto'],
                            $a['envia_modulo'], $a['telegram'], $a['assistente']]);
                    }
                    $cloned[] = 'Ações (' . count($actions) . ')';
                }

                // ─── Módulos inter-módulos ───
                if (in_array('intermod_mods', $sections)) {
                    $db->prepare('DELETE FROM device_intermod WHERE device_id = ?')->execute([$tgt_id]);
                    $stmt = $db->prepare('INSERT INTO device_intermod
                        (device_id, module_id, hostname, ip, porta, pins_alerta)
                        VALUES (?,?,?,?,?,?)');
                    foreach ($modules as $m) {
                        $stmt->execute([$tgt_id, $m['module_id'], $m['hostname'],
                            $m['ip'], $m['porta'], $m['pins_alerta']]);
                    }
                    $cloned[] = 'Módulos Inter-módulos (' . count($modules) . ')';
                }

                $db->commit();
                $results[] = ['name' => $tgt_name, 'cloned' => $cloned, 'ok' => true];

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $results[] = ['name' => $tgt_name, 'error' => $e->getMessage(), 'ok' => false];
            }
        }
        $done = true;
    }
}

$page_title = 'Clonar Configuração';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => 'Clonar Configuração'],
];
include __DIR__ . '/../includes/header.php';
?>

<?php if ($done): ?>
<!-- Resultado -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="bi bi-clipboard-check me-2"></i>Resultado da Clonagem</h6>
    </div>
    <div class="card-body">
        <?php foreach ($results as $r): ?>
        <div class="d-flex align-items-start gap-3 mb-3 pb-3 border-bottom">
            <div class="mt-1">
                <?php if ($r['ok']): ?>
                <span class="badge bg-success fs-6"><i class="bi bi-check-lg"></i></span>
                <?php else: ?>
                <span class="badge bg-danger fs-6"><i class="bi bi-x-lg"></i></span>
                <?php endif; ?>
            </div>
            <div>
                <div class="fw-semibold"><?= h($r['name']) ?></div>
                <?php if ($r['ok']): ?>
                <div class="small text-muted">Copiado: <?= implode(', ', array_map('h', $r['cloned'])) ?></div>
                <?php else: ?>
                <div class="small text-danger"><?= h($r['error']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="d-flex gap-2 mt-2">
            <a href="/devices/clone.php" class="btn btn-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Nova Clonagem
            </a>
            <a href="/devices/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list me-1"></i>Ver Dispositivos
            </a>
        </div>
    </div>
</div>

<?php else: ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($errors[0]) ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- Origem -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="bi bi-box-arrow-right me-2 text-primary"></i>Origem</h6>
                <div class="text-muted small mt-1">Dispositivo que será copiado</div>
            </div>
            <div class="card-body">
                <select name="source_id" class="form-select" id="source_select" required onchange="updateTargets()">
                    <option value="">Selecione o dispositivo...</option>
                    <?php foreach ($devices as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= h($d['name'] ?: $d['unique_id']) ?> <small>(<?= h($d['unique_id']) ?>)</small></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Seções -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-check me-2 text-warning"></i>O que clonar</h6>
                    <div class="text-muted small mt-1">Seções a copiar</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll()">Todos</button>
            </div>
            <div class="card-body">
                <?php
                $sections_list = [
                    'config_geral'  => ['Config Geral',            'bi-gear-fill',     'text-secondary', 'Rede, NTP, display — exceto WiFi/hostname'],
                    'mqtt'          => ['MQTT',                     'bi-broadcast',     'text-purple',    'Broker, tópicos, Home Assistant'],
                    'telegram'      => ['Telegram',                 'bi-telegram',      'text-info',      'Token, chat ID, intervalo'],
                    'intermod_cfg'  => ['Inter-módulos Config',     'bi-share-fill',    'text-primary',   'Habilitado, healthcheck, falhas'],
                    'pinos'         => ['Pinos',                    'bi-diagram-3-fill','text-success',   'Todos os pinos (substitui destino)'],
                    'acoes'         => ['Ações',                    'bi-lightning-fill','text-warning',   'Todas as ações (substitui destino)'],
                    'intermod_mods' => ['Módulos Inter-módulos',    'bi-hdd-network',   'text-info',      'Lista de módulos cadastrados'],
                ];
                ?>
                <?php foreach ($sections_list as $key => [$label, $icon, $color, $desc]): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input section-check" type="checkbox" name="sections[]"
                           value="<?= $key ?>" id="sec_<?= $key ?>" checked>
                    <label class="form-check-label" for="sec_<?= $key ?>">
                        <i class="bi <?= $icon ?> <?= $color ?> me-1"></i>
                        <span class="fw-semibold"><?= $label ?></span>
                        <div class="text-muted" style="font-size:0.75rem;margin-left:1.4rem;"><?= $desc ?></div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Destinos -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="bi bi-box-arrow-in-right me-2 text-success"></i>Destino(s)</h6>
                <div class="text-muted small mt-1">Dispositivos que receberão a config</div>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" id="targets-list">
                    <?php foreach ($devices as $d): ?>
                    <label class="list-group-item list-group-item-action d-flex align-items-center gap-2 target-item"
                           data-id="<?= $d['id'] ?>">
                        <input class="form-check-input" type="checkbox" name="targets[]" value="<?= $d['id'] ?>">
                        <div>
                            <div class="small fw-semibold"><?= h($d['name'] ?: $d['unique_id']) ?></div>
                            <div class="text-muted font-monospace" style="font-size:0.7rem;"><?= h($d['unique_id']) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="card mt-4">
    <div class="card-body d-flex align-items-center justify-content-between">
        <div class="text-muted small">
            <i class="bi bi-info-circle me-1"></i>
            A clonagem copia os dados do banco de dados. Use <strong>Cloud → ESP32</strong> na página do dispositivo para enviar ao hardware.
        </div>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-copy me-2"></i>Clonar Configuração
        </button>
    </div>
</div>
</form>
<?php endif; ?>

<script>
function updateTargets() {
    var srcId = document.getElementById('source_select').value;
    document.querySelectorAll('.target-item').forEach(function(item) {
        var id = item.getAttribute('data-id');
        var cb = item.querySelector('input[type=checkbox]');
        if (id === srcId) {
            item.classList.add('opacity-50');
            cb.disabled = true;
            cb.checked  = false;
        } else {
            item.classList.remove('opacity-50');
            cb.disabled = false;
        }
    });
}

var allChecked = true;
function toggleAll() {
    allChecked = !allChecked;
    document.querySelectorAll('.section-check').forEach(function(cb) {
        cb.checked = allChecked;
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
