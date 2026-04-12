-- Migração: adiciona flag reboot_on_sync à tabela device_config
-- Executar uma única vez no banco existente

ALTER TABLE device_config
    ADD COLUMN IF NOT EXISTS reboot_on_sync TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Quando 1, o ESP32 faz reboot ao buscar a config. Auto-desativado após o sync.';
