-- Migração: adiciona flag ativo à tabela devices
-- Executar uma única vez no banco existente

ALTER TABLE devices
    ADD COLUMN IF NOT EXISTS ativo TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Quando 0, o dispositivo não recebe sync e não é monitorado.';
