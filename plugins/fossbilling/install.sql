-- Optional upfront installation in the FOSSBilling database (never the registrar database).
-- Recommended before processing orders; the adapter can also create it outside transactions.
CREATE TABLE IF NOT EXISTS namingoregistrar_request (
    request_key CHAR(64) NOT NULL PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
