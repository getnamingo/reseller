<?php

declare(strict_types=1);

namespace Namingo\FossBilling;

use PDO;
use PDOException;
use RuntimeException;

/** Stored in FOSSBilling's database and encrypted with its installation salt. */
class Store
{
    public function __construct(private PDO $pdo, private \Box_Crypt $crypt)
    {
        if ($pdo->inTransaction()) { throw new RuntimeException('Namingo request storage requires a separate autocommit database connection.'); }
        try { $pdo->query('SELECT request_key FROM namingoregistrar_request LIMIT 0'); }
        catch (PDOException $error) {
            if ($error->getCode() !== '42S02') { throw $error; }
            // This is a dedicated autocommit connection, separate from billing transactions.
            $pdo->exec('CREATE TABLE IF NOT EXISTS namingoregistrar_request (
                request_key CHAR(64) NOT NULL PRIMARY KEY,
                payload MEDIUMTEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
    }

    public function lock(string $key): void
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute(['namingo-fb:' . substr($key, 0, 48)]);
        if ((string) $query->fetchColumn() !== '1') {
            throw new RuntimeException('Another Namingo action is running for this domain. Retry shortly.');
        }
    }

    public function unlock(string $key): void
    {
        $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute(['namingo-fb:' . substr($key, 0, 48)]);
    }

    public function get(string $key): ?array
    {
        $query = $this->pdo->prepare('SELECT payload FROM namingoregistrar_request WHERE request_key = ?');
        $query->execute([$key]);
        $cipher = $query->fetchColumn();
        if ($cipher === false) { return null; }
        $plain = $this->crypt->decrypt($cipher);
        if (!is_string($plain)) { throw new RuntimeException('Cannot decrypt the saved Namingo request. Restore the FOSSBilling installation salt.'); }
        $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new RuntimeException('Invalid saved Namingo request.'); }
        return $payload;
    }

    public function put(string $key, array $payload): void
    {
        $query = $this->pdo->prepare('INSERT INTO namingoregistrar_request (request_key, payload) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE payload = VALUES(payload)');
        $query->execute([$key, $this->crypt->encrypt(json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    public function remove(string $key): void
    {
        $query = $this->pdo->prepare('DELETE FROM namingoregistrar_request WHERE request_key = ?');
        $query->execute([$key]);
    }
}
