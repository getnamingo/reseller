CREATE TABLE IF NOT EXISTS panel_domain_operations (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    batch_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    domainname VARCHAR(253) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
    data_nonce VARBINARY(24) NOT NULL,
    data_cipher LONGBLOB NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_panel_domain_batch (reseller_id, batch_id),
    INDEX idx_panel_domain_object (reseller_id, registry_id, domainname, created_at),
    INDEX idx_panel_domain_progress (reseller_id, status),
    CONSTRAINT fk_panel_domain_owner FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    CONSTRAINT fk_panel_domain_registry FOREIGN KEY (registry_id) REFERENCES registries(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (version) VALUES ('007_panel_domains');
