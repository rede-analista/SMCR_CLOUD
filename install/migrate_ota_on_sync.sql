-- Migração: adiciona flag ota_update_on_sync à tabela device_config
-- Executar uma única vez no banco existente

ALTER TABLE device_config
    ADD COLUMN IF NOT EXISTS ota_update_on_sync TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Quando 1, o ESP32 busca e instala o firmware mais recente do GitHub ao sincronizar. Auto-desativado após o sync.';
