CREATE TABLE IF NOT EXISTS panel_contacts (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    data_nonce VARBINARY(24) NULL,
    data_cipher LONGBLOB NULL,
    registries JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_panel_contacts_owner (reseller_id, created_at),
    CONSTRAINT fk_panel_contact_owner FOREIGN KEY (reseller_id) REFERENCES resellers(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (version) VALUES ('006_contacts');
