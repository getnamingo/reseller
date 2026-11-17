<?php
// Resumable DDL for both MariaDB and MySQL; existing account data is preserved.
foreach (\Namingo\ResellerApi\BillingProfile::COLUMNS as $column => $definition) {
    $check = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $check->execute(['resellers', $column]);
    if (!$check->fetchColumn()) { $pdo->exec("ALTER TABLE resellers ADD COLUMN {$column} {$definition}"); }
}
$pdo->exec(file_get_contents(__DIR__ . '/009_invoicing.sql'));
$pdo->exec("INSERT IGNORE INTO schema_migrations (version) VALUES ('009_invoicing')");
