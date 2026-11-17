CREATE TABLE IF NOT EXISTS domain_transfers (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    observation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    request_job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    latest_job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tr_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    requested_at VARCHAR(64) NOT NULL DEFAULT '',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_transfers_owner (reseller_id, created_at, id),
    INDEX idx_transfers_domain (registry_id, reseller_id, domainname, status),
    INDEX idx_transfers_job (latest_job_id),
    INDEX idx_transfers_request (request_job_id),
    CONSTRAINT fk_transfer_registry FOREIGN KEY (registry_id) REFERENCES registries(id),
    CONSTRAINT fk_transfer_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (version) VALUES ('008_transfers');
