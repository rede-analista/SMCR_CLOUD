ALTER TABLE device_config
    ADD COLUMN IF NOT EXISTS pending_cloud_migration TINYINT(1) NOT NULL DEFAULT 0;
