SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS resellers (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    company VARCHAR(255) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    phone VARCHAR(32) NOT NULL DEFAULT '',
    address VARCHAR(2000) NOT NULL DEFAULT '',
    country_code CHAR(2) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_reseller_role CHECK (role IN (0, 1)),
    CONSTRAINT chk_reseller_currency CHECK (currency REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wallets (
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    available_balance DECIMAL(19,4) UNSIGNED NOT NULL DEFAULT 0.0000,
    held_balance DECIMAL(19,4) UNSIGNED NOT NULL DEFAULT 0.0000,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_wallet_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    CONSTRAINT chk_wallet_currency CHECK (currency REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash BINARY(32) NOT NULL UNIQUE,
    expires_at DATETIME(6) NOT NULL,
    last_used_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_token_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    INDEX idx_token_expiry (expires_at),
    INDEX idx_token_reseller (reseller_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS registries (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    profile VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    config_nonce BINARY(24) NOT NULL,
    config_cipher MEDIUMBLOB NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_registry_enabled (enabled)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reseller_registries (
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (reseller_id, registry_id),
    CONSTRAINT fk_rr_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    CONSTRAINT fk_rr_registry FOREIGN KEY (registry_id) REFERENCES registries(id)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS registry_prices (
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
    command VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tld VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    period TINYINT UNSIGNED NOT NULL DEFAULT 0,
    amount DECIMAL(19,4) UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (registry_id, reseller_id, command, tld, period),
    CONSTRAINT fk_price_registry FOREIGN KEY (registry_id) REFERENCES registries(id),
    CONSTRAINT chk_price_currency CHECK (currency REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS object_ownership (
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_type ENUM('domain', 'contact', 'host') NOT NULL,
    object_name VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    domain_state ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
    next_check_at DATETIME(6) NULL,
    check_until DATETIME(6) NULL,
    job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (registry_id, object_type, object_name),
    INDEX idx_ownership_job (job_id),
    INDEX idx_ownership_check (domain_state, next_check_at, check_until),
    CONSTRAINT fk_ownership_registry FOREIGN KEY (registry_id) REFERENCES registries(id),
    CONSTRAINT fk_ownership_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS epp_jobs (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    request_nonce BINARY(24) NOT NULL,
    request_cipher MEDIUMBLOB NOT NULL,
    response_nonce BINARY(24) NULL,
    response_cipher MEDIUMBLOB NULL,
    status ENUM('queued', 'processing', 'succeeded', 'rejected', 'unknown', 'pending') NOT NULL DEFAULT 'queued',
    epp_code SMALLINT UNSIGNED NULL,
    parent_job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    dispatch_until DATETIME(6) NULL,
    reconcile_at DATETIME(6) NULL,
    last_error VARCHAR(255) NULL,
    price DECIMAL(19,4) UNSIGNED NOT NULL DEFAULT 0.0000,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    UNIQUE KEY uq_job_idempotency (reseller_id, idempotency_key),
    INDEX idx_job_dispatch (status, next_attempt_at, dispatch_until),
    INDEX idx_job_reconcile (status, reconcile_at, dispatch_until),
    INDEX idx_job_parent (parent_job_id),
    INDEX idx_job_registry_status (registry_id, status),
    INDEX idx_job_reseller_created (reseller_id, created_at),
    CONSTRAINT fk_job_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    CONSTRAINT fk_job_registry FOREIGN KEY (registry_id) REFERENCES registries(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS deposit_requests (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(19,4) UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    decided_at DATETIME(6) NULL,
    UNIQUE KEY uq_deposit_reference (reseller_id, client_reference),
    INDEX idx_deposit_status (status, created_at),
    CONSTRAINT fk_deposit_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    CONSTRAINT chk_deposit_amount CHECK (amount > 0),
    CONSTRAINT chk_deposit_currency CHECK (currency REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    deposit_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    kind ENUM('deposit', 'hold', 'charge', 'release', 'adjustment') NOT NULL,
    available_delta DECIMAL(19,4) NOT NULL,
    held_delta DECIMAL(19,4) NOT NULL,
    available_after DECIMAL(19,4) UNSIGNED NOT NULL,
    held_after DECIMAL(19,4) UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_ledger_job_kind (job_id, kind),
    UNIQUE KEY uq_ledger_deposit_kind (deposit_id, kind),
    INDEX idx_ledger_reseller_created (reseller_id, created_at),
    CONSTRAINT fk_ledger_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    CONSTRAINT fk_ledger_job FOREIGN KEY (job_id) REFERENCES epp_jobs(id),
    CONSTRAINT fk_ledger_deposit FOREIGN KEY (deposit_id) REFERENCES deposit_requests(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    target_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ip_hash BINARY(32) NULL,
    metadata_json JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_audit_reseller_created (reseller_id, created_at),
    INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_initial');

INSERT IGNORE INTO schema_migrations (version) VALUES ('002_ownership_pricing');

INSERT IGNORE INTO schema_migrations (version) VALUES ('003_foundry_panel');

INSERT IGNORE INTO schema_migrations (version) VALUES ('004_lifecycle_queue');
INSERT IGNORE INTO schema_migrations (version) VALUES ('005_account_details');

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
