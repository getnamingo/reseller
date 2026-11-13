ALTER TABLE resellers
    ADD COLUMN role TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD CONSTRAINT chk_reseller_role CHECK (role IN (0, 1));

CREATE TABLE IF NOT EXISTS registry_tlds (
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tld VARCHAR(247) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (registry_id, tld),
    CONSTRAINT fk_tld_registry FOREIGN KEY (registry_id) REFERENCES registries(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reseller_tlds (
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tld VARCHAR(247) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (reseller_id, registry_id, tld),
    CONSTRAINT fk_rt_access FOREIGN KEY (reseller_id, registry_id) REFERENCES reseller_registries(reseller_id, registry_id),
    CONSTRAINT fk_rt_tld FOREIGN KEY (registry_id, tld) REFERENCES registry_tlds(registry_id, tld)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_login_limits (
    ip_hash BINARY(32) PRIMARY KEY,
    window_started DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_login_window (window_started)
) ENGINE=InnoDB;

-- Preserve existing domain access for known, explicitly priced zones. Unpriced zones
-- must be added and granted by an administrator before domain operations resume.
INSERT IGNORE INTO registry_tlds (registry_id, tld)
    SELECT DISTINCT registry_id, tld FROM registry_prices WHERE tld <> '' AND CHAR_LENGTH(tld) <= 247;
INSERT IGNORE INTO reseller_tlds (reseller_id, registry_id, tld)
    SELECT rr.reseller_id, t.registry_id, t.tld FROM reseller_registries rr
    JOIN registry_tlds t ON t.registry_id = rr.registry_id;
INSERT INTO schema_migrations (version) VALUES ('003_foundry_panel');
