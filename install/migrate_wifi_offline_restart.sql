-- Adiciona coluna wifi_offline_restart_min à device_config
-- 0 = desabilitado, N = reinicia ESP após N minutos offline
ALTER TABLE device_config
    ADD COLUMN IF NOT EXISTS wifi_offline_restart_min SMALLINT UNSIGNED NOT NULL DEFAULT 30
    AFTER wifi_check_interval;
