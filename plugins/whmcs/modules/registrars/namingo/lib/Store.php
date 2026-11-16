<?php

declare(strict_types=1);

namespace Namingo\Whmcs;

use PDO;
use RuntimeException;

/** WHMCS database, never the registrar database. Payloads include PII and are encrypted. */
class Store
{
    public function __construct(private PDO $pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS mod_namingo_requests (
            request_key CHAR(64) NOT NULL PRIMARY KEY,
            payload MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function lock(string $key): void
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute(['namingo:' . substr($key, 0, 48)]);
        if ((string) $query->fetchColumn() !== '1') {
            throw new RuntimeException('Another Namingo action is running for this domain. Retry shortly.');
        }
    }

    public function unlock(string $key): void
    {
        $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute(['namingo:' . substr($key, 0, 48)]);
    }

    public function get(string $key): ?array
    {
        $query = $this->pdo->prepare('SELECT payload FROM mod_namingo_requests WHERE request_key = ?');
        $query->execute([$key]);
        $cipher = $query->fetchColumn();
        if ($cipher === false) { return null; }
        $payload = json_decode(decrypt($cipher), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new RuntimeException('Cannot decrypt the saved Namingo request. Restore the WHMCS encryption key.'); }
        return $payload;
    }

    public function put(string $key, array $payload): void
    {
        $query = $this->pdo->prepare('INSERT INTO mod_namingo_requests (request_key, payload) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE payload = VALUES(payload)');
        $query->execute([$key, encrypt(json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    public function remove(string $key): void
    {
        $query = $this->pdo->prepare('DELETE FROM mod_namingo_requests WHERE request_key = ?');
        $query->execute([$key]);
    }
}
