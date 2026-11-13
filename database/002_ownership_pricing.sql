-- Stop the API and reconcile queued/processing/unknown jobs before upgrading.
ALTER TABLE registry_prices
    ADD COLUMN tld VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    ADD COLUMN period TINYINT UNSIGNED NOT NULL DEFAULT 0,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (registry_id, reseller_id, command, tld, period);
CREATE TABLE IF NOT EXISTS object_ownership (
    registry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_type ENUM('domain', 'contact', 'host') NOT NULL,
    object_name VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reseller_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (registry_id, object_type, object_name),
    INDEX idx_ownership_job (job_id),
    CONSTRAINT fk_ownership_registry FOREIGN KEY (registry_id) REFERENCES registries(id),
    CONSTRAINT fk_ownership_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (version) VALUES ('002_ownership_pricing');
