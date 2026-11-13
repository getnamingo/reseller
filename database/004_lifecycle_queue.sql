ALTER TABLE object_ownership
    ADD COLUMN domain_state ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
    ADD COLUMN next_check_at DATETIME(6) NULL,
    ADD COLUMN check_until DATETIME(6) NULL,
    ADD INDEX idx_ownership_check (domain_state, next_check_at, check_until);
ALTER TABLE epp_jobs
    MODIFY COLUMN status ENUM('queued', 'processing', 'succeeded', 'rejected', 'unknown', 'pending') NOT NULL DEFAULT 'queued',
    ADD COLUMN parent_job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ADD COLUMN dispatch_until DATETIME(6) NULL,
    ADD COLUMN reconcile_at DATETIME(6) NULL,
    ADD COLUMN last_error VARCHAR(255) NULL,
    ADD INDEX idx_job_dispatch (status, next_attempt_at, dispatch_until),
    ADD INDEX idx_job_reconcile (status, reconcile_at, dispatch_until),
    ADD INDEX idx_job_parent (parent_job_id);
-- 1001 explicitly means registry-accepted pending work; uncertain writes stay unknown.
UPDATE epp_jobs SET status = 'pending', reconcile_at = UTC_TIMESTAMP(6), finished_at = NULL
    WHERE status = 'unknown' AND epp_code = 1001;
INSERT INTO schema_migrations (version) VALUES ('004_lifecycle_queue');
