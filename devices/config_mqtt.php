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
        'mqtt_enabled'          => isset($_POST['mqtt_enabled']) ? 1 : 0,
        'mqtt_server'           => trim($_POST['mqtt_server'] ?? ''),
        'mqtt_port'             => (int)($_POST['mqtt_port'] ?? 1883),
        'mqtt_user'             => trim($_POST['mqtt_user'] ?? ''),
        'mqtt_password'         => $_POST['mqtt_password'] ?? '',
        'mqtt_topic_base'       => trim($_POST['mqtt_topic_base'] ?? 'smcr'),
        'mqtt_publish_interval' => (int)($_POST['mqtt_publish_interval'] ?? 60),
        'mqtt_ha_discovery'     => isset($_POST['mqtt_ha_discovery']) ? 1 : 0,
        'mqtt_ha_batch'         => (int)($_POST['mqtt_ha_batch'] ?? 4),
        'mqtt_ha_interval_ms'   => (int)($_POST['mqtt_ha_interval_ms'] ?? 100),
        'mqtt_ha_repeat_sec'    => (int)($_POST['mqtt_ha_repeat_sec'] ?? 900),
    ];

    $set_parts = [];
    $values = [];
    foreach ($fields as $col => $val) {
        $set_parts[] = "`$col` = ?";
        $values[] = $val;
    }
    $values[] = $device_id;

    $db->prepare('UPDATE device_config SET ' . implode(', ', $set_parts) . ' WHERE device_id = ?')->execute($values);

    set_flash('success', 'Configurações MQTT salvas com sucesso.');

    $stmt = $db->prepare('SELECT * FROM device_config WHERE device_id = ?');
    $stmt->execute([$device_id]);
    $cfg = $stmt->fetch();
}

$base = $cfg['mqtt_topic_base'] ?: 'smcr';
$uid  = $device['unique_id'];

$page_title = 'MQTT';
$breadcrumb = [
    ['label' => 'Dispositivos', 'url' => '/devices/index.php'],
    ['label' => h($device['name'] ?: $device['unique_id']), 'url' => '/devices/view.php?device_id=' . $device_id],
    ['label' => 'MQTT']
];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-broadcast me-2"></i>Configuração MQTT</h5>
    <span class="text-muted small"><?= h($device['name'] ?: $device['unique_id']) ?></span>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <form method="POST" action="<?= BASE ?>/devices/config_mqtt.php?device_id=<?= $device_id ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-server me-1"></i>Broker MQTT</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="mqtt_enabled"
                                       id="mqtt_enabled" value="1"
                                       <?= $cfg['mqtt_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="mqtt_enabled">
                                    Habilitar MQTT
                                </label>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Servidor / IP do Broker</label>
                            <input type="text" class="form-control" name="mqtt_server"
                                   value="<?= h($cfg['mqtt_server']) ?>"
                                   placeholder="ex: 192.168.1.10 ou mqtt.exemplo.com" maxlength="128">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Porta</label>
                            <input type="number" class="form-control" name="mqtt_port"
                                   value="<?= h($cfg['mqtt_port']) ?>" min="1" max="65535">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Usuário MQTT</label>
                            <input type="text" class="form-control" name="mqtt_user"
                                   value="<?= h($cfg['mqtt_user']) ?>" maxlength="64">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Senha MQTT</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="mqtt_password"
                                       id="mqtt_password" value="<?= h($cfg['mqtt_password']) ?>" maxlength="128">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="toggleVis('mqtt_password',this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-chat-dots me-1"></i>Tópicos e Publicação</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">ID Único (readonly)</label>
                            <input type="text" class="form-control font-monospace bg-light"
                                   value="<?= h($device['unique_id']) ?>" readonly>
                            <div class="form-text">Usado como identificador nos tópicos MQTT.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Base dos Tópicos</label>
                            <input type="text" class="form-control" name="mqtt_topic_base"
                                   value="<?= h($cfg['mqtt_topic_base']) ?>" maxlength="64"
                                   id="topic_base" oninput="updateTopicExamples()">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Intervalo Publicação (s)</label>
                            <input type="number" class="form-control" name="mqtt_publish_interval"
                                   value="<?= h($cfg['mqtt_publish_interval']) ?>" min="1" max="3600">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-house-fill me-1"></i>Home Assistant Discovery</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="mqtt_ha_discovery"
                                       id="mqtt_ha_discovery" value="1"
                                       <?= $cfg['mqtt_ha_discovery'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mqtt_ha_discovery">
                                    Habilitar HA Discovery automático
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Lote de Discovery</label>
                            <input type="number" class="form-control" name="mqtt_ha_batch"
                                   value="<?= h($cfg['mqtt_ha_batch']) ?>" min="1" max="32">
                            <div class="form-text">Sensores por lote.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Intervalo entre Lotes (ms)</label>
                            <input type="number" class="form-control" name="mqtt_ha_interval_ms"
                                   value="<?= h($cfg['mqtt_ha_interval_ms']) ?>" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Repetição do Discovery (s)</label>
                            <input type="number" class="form-control" name="mqtt_ha_repeat_sec"
                                   value="<?= h($cfg['mqtt_ha_repeat_sec']) ?>" min="0">
                            <div class="form-text">0 = desabilitar repetição.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Salvar Configurações MQTT
                </button>
                <a href="<?= BASE ?>/devices/view.php?device_id=<?= $device_id ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card sticky-top" style="top:1.5rem;">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="bi bi-code-square me-1"></i>Exemplos de Tópicos MQTT</h6>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-2">Com base nas configurações atuais:</p>
                <div class="bg-dark text-light p-3 rounded small font-monospace" style="font-size:0.75rem;">
                    <div class="text-success mb-1"># Estado dos pinos</div>
                    <div id="topic_state" class="mb-2"><?= h($base) ?>/<?= h($uid) ?>/pin/&lt;N&gt;/state</div>

                    <div class="text-success mb-1"># Comando para pino</div>
                    <div id="topic_cmd" class="mb-2"><?= h($base) ?>/<?= h($uid) ?>/pin/&lt;N&gt;/set</div>

                    <div class="text-success mb-1"># Status geral</div>
                    <div id="topic_status" class="mb-2"><?= h($base) ?>/<?= h($uid) ?>/status</div>

                    <div class="text-success mb-1"># HA Discovery</div>
                    <div id="topic_ha">homeassistant/binary_sensor/<?= h($uid) ?>_&lt;N&gt;/config</div>
                </div>

                <hr>
                <h6 class="fw-bold small mb-2"><i class="bi bi-info-circle me-1"></i>Notas</h6>
                <ul class="small text-muted mb-0">
                    <li>O payload de estado é <code>0</code> ou <code>1</code> para pinos digitais</li>
                    <li>Pinos analógicos publicam o valor ADC (0-4095)</li>
                    <li>QoS padrão: 0 (fire and forget)</li>
                    <li>Retain habilitado para tópicos de estado</li>
                </ul>
            </div>
        </div>
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

function updateTopicExamples() {
    const base = document.getElementById('topic_base').value || 'smcr';
    const uid = '<?= h($device['unique_id']) ?>';
    document.getElementById('topic_state').textContent  = base + '/' + uid + '/pin/<N>/state';
    document.getElementById('topic_cmd').textContent    = base + '/' + uid + '/pin/<N>/set';
    document.getElementById('topic_status').textContent = base + '/' + uid + '/status';
    document.getElementById('topic_ha').textContent     = 'homeassistant/binary_sensor/' + uid + '_<N>/config';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
