CREATE DATABASE IF NOT EXISTS smcr_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smcr_cloud;

CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(64)  NOT NULL PRIMARY KEY,
    value   TEXT         NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Default auto-register token (random, will be regenerated on first access)
INSERT IGNORE INTO settings (`key`, value) VALUES ('register_token', LOWER(HEX(RANDOM_BYTES(16))));

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin user (password: admin123)
INSERT IGNORE INTO users (username, password_hash) VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unique_id VARCHAR(50) NOT NULL UNIQUE COMMENT 'e.g. smcr_XXXXXXXXXXXX',
    name VARCHAR(100) DEFAULT '',
    api_token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NULL DEFAULT NULL,
    online TINYINT(1) DEFAULT 0
);

CREATE TABLE IF NOT EXISTS device_status (
    device_id INT PRIMARY KEY,
    ip VARCHAR(45) DEFAULT '',
    hostname VARCHAR(64) DEFAULT '',
    firmware_version VARCHAR(20) DEFAULT '',
    free_heap INT DEFAULT 0,
    uptime_ms BIGINT DEFAULT 0,
    wifi_rssi INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS device_config (
    device_id INT PRIMARY KEY,
    hostname VARCHAR(64) DEFAULT 'esp32modularx',
    wifi_ssid VARCHAR(64) DEFAULT '',
    wifi_pass VARCHAR(128) DEFAULT '',
    wifi_attempts SMALLINT UNSIGNED DEFAULT 15,
    wifi_check_interval INT UNSIGNED DEFAULT 15000,
    ap_ssid VARCHAR(64) DEFAULT 'SMCR_AP_SETUP',
    ap_pass VARCHAR(128) DEFAULT 'senha1234',
    ap_fallback_enabled TINYINT(1) DEFAULT 1,
    ntp_server1 VARCHAR(64) DEFAULT 'pool.ntp.br',
    gmt_offset_sec INT DEFAULT -10800,
    daylight_offset_sec INT DEFAULT 0,
    status_pinos_enabled TINYINT(1) DEFAULT 1,
    inter_modulos_enabled TINYINT(1) DEFAULT 0,
    cor_com_alerta VARCHAR(10) DEFAULT '#ff0000',
    cor_sem_alerta VARCHAR(10) DEFAULT '#00ff00',
    tempo_refresh SMALLINT UNSIGNED DEFAULT 15,
    show_analog_history TINYINT(1) DEFAULT 1,
    show_digital_history TINYINT(1) DEFAULT 1,
    serial_debug_enabled TINYINT(1) DEFAULT 0,
    log_flags INT UNSIGNED DEFAULT 0,
    watchdog_enabled TINYINT(1) DEFAULT 0,
    tempo_watchdog_us INT UNSIGNED DEFAULT 8000000,
    clock_esp32_mhz SMALLINT UNSIGNED DEFAULT 240,
    qtd_pinos TINYINT UNSIGNED DEFAULT 16,
    web_server_port SMALLINT UNSIGNED DEFAULT 8080,
    auth_enabled TINYINT(1) DEFAULT 0,
    web_username VARCHAR(64) DEFAULT 'admin',
    web_password VARCHAR(128) DEFAULT 'admin1234',
    dashboard_auth_required TINYINT(1) DEFAULT 0,
    mqtt_enabled TINYINT(1) DEFAULT 0,
    mqtt_server VARCHAR(128) DEFAULT '',
    mqtt_port SMALLINT UNSIGNED DEFAULT 1883,
    mqtt_user VARCHAR(64) DEFAULT '',
    mqtt_password VARCHAR(128) DEFAULT '',
    mqtt_topic_base VARCHAR(64) DEFAULT 'smcr',
    mqtt_publish_interval SMALLINT UNSIGNED DEFAULT 60,
    mqtt_ha_discovery TINYINT(1) DEFAULT 1,
    mqtt_ha_batch TINYINT UNSIGNED DEFAULT 4,
    mqtt_ha_interval_ms SMALLINT UNSIGNED DEFAULT 100,
    mqtt_ha_repeat_sec INT UNSIGNED DEFAULT 900,
    intermod_enabled TINYINT(1) DEFAULT 0,
    intermod_healthcheck SMALLINT UNSIGNED DEFAULT 60,
    intermod_max_failures TINYINT UNSIGNED DEFAULT 3,
    intermod_auto_discovery TINYINT(1) DEFAULT 0,
    telegram_enabled TINYINT(1) DEFAULT 0,
    telegram_token VARCHAR(128) DEFAULT '',
    telegram_chatid VARCHAR(64) DEFAULT '',
    telegram_interval SMALLINT UNSIGNED DEFAULT 30,
    cloud_url VARCHAR(128) DEFAULT 'smcr.pensenet.com.br',
    cloud_port SMALLINT UNSIGNED DEFAULT 8765,
    cloud_sync_enabled TINYINT(1) DEFAULT 0,
    cloud_sync_interval_min SMALLINT UNSIGNED DEFAULT 5,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS device_pins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    nome VARCHAR(64) DEFAULT '',
    pino SMALLINT UNSIGNED NOT NULL,
    tipo SMALLINT UNSIGNED DEFAULT 0,
    modo TINYINT UNSIGNED DEFAULT 0,
    xor_logic TINYINT(1) DEFAULT 0,
    tempo_retencao INT UNSIGNED DEFAULT 0,
    nivel_acionamento_min SMALLINT UNSIGNED DEFAULT 0,
    nivel_acionamento_max SMALLINT UNSIGNED DEFAULT 1,
    classe_mqtt VARCHAR(50) DEFAULT '',
    icone_mqtt VARCHAR(50) DEFAULT '',
    UNIQUE KEY uq_device_pino (device_id, pino),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS device_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    pino_origem SMALLINT UNSIGNED NOT NULL,
    numero_acao TINYINT UNSIGNED NOT NULL,
    pino_destino SMALLINT UNSIGNED DEFAULT 0,
    acao SMALLINT UNSIGNED DEFAULT 0,
    tempo_on SMALLINT UNSIGNED DEFAULT 0,
    tempo_off SMALLINT UNSIGNED DEFAULT 0,
    pino_remoto SMALLINT UNSIGNED DEFAULT 0,
    envia_modulo VARCHAR(64) DEFAULT '',
    telegram TINYINT(1) DEFAULT 0,
    assistente TINYINT(1) DEFAULT 0,
    UNIQUE KEY uq_device_action (device_id, pino_origem, numero_acao),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS device_intermod (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    module_id VARCHAR(64) NOT NULL,
    hostname VARCHAR(64) DEFAULT '',
    ip VARCHAR(45) DEFAULT '',
    porta SMALLINT UNSIGNED DEFAULT 8080,
    pins_offline VARCHAR(255) DEFAULT '',
    offline_alert_enabled TINYINT(1) DEFAULT 0,
    offline_flash_ms SMALLINT UNSIGNED DEFAULT 200,
    pins_healthcheck VARCHAR(255) DEFAULT '',
    healthcheck_alert_enabled TINYINT(1) DEFAULT 0,
    healthcheck_flash_ms SMALLINT UNSIGNED DEFAULT 500,
    UNIQUE KEY uq_device_module (device_id, module_id),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);
