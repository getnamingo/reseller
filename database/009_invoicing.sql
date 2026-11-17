CREATE TABLE IF NOT EXISTS invoice_sequences (
    series VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invoice_number VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    issued_at DATETIME(6) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    total DECIMAL(19,4) UNSIGNED NOT NULL,
    snapshot_nonce BINARY(24) NOT NULL,
    snapshot_cipher MEDIUMBLOB NOT NULL,
    status ENUM('prepared','issued') NOT NULL DEFAULT 'prepared',
    pdf_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    email_status ENUM('pending','sending','sent','unknown') NOT NULL DEFAULT 'pending',
    email_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    emailed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_invoice_period (reseller_id, period_start),
    INDEX idx_invoice_period (period_start, reseller_id),
    CONSTRAINT fk_invoice_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id)
) ENGINE=InnoDB;
