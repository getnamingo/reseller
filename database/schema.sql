SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS resellers (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
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

CREATE TABLE IF NOT EXISTS registry_prices (
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
    command VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(19,4) UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (registry_id, reseller_id, command),
    CONSTRAINT fk_price_registry FOREIGN KEY (registry_id) REFERENCES registries(id),
    CONSTRAINT chk_price_currency CHECK (currency REGEXP '^[A-Z]{3}$')
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
    status ENUM('queued', 'processing', 'succeeded', 'rejected', 'unknown') NOT NULL DEFAULT 'queued',
    epp_code SMALLINT UNSIGNED NULL,
    price DECIMAL(19,4) UNSIGNED NOT NULL DEFAULT 0.0000,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    UNIQUE KEY uq_job_idempotency (reseller_id, idempotency_key),
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
