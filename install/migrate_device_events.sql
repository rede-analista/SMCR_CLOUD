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
