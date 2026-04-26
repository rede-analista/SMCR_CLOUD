CREATE TABLE IF NOT EXISTS device_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    event ENUM('online', 'offline') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_device_events (device_id, created_at),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

ALTER TABLE device_status ADD COLUMN IF NOT EXISTS sketch_size INT UNSIGNED DEFAULT 0;
ALTER TABLE device_status ADD COLUMN IF NOT EXISTS sketch_free INT UNSIGNED DEFAULT 0;

-- v2.3.14: Histórico de acionamentos persistente
CREATE TABLE IF NOT EXISTS device_action_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    gpio_origem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    gpio_destino SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tipo TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ocorrido_em DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_event (device_id, gpio_origem, gpio_destino, tipo, ocorrido_em),
    KEY idx_device_time (device_id, ocorrido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
