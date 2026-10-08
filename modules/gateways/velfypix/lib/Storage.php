<?php
declare(strict_types=1);

namespace Velfy\Pix;

final class Storage
{
    private \PDO $pdo;
    private string $driver;
    private string $lockNamespace;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($this->driver === 'mysql') {
            $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        } elseif ($this->driver === 'sqlite') {
            $databases = $pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC);
            $database = (string) ($databases[0]['file'] ?? '');
            $database = $database !== '' ? $database : spl_object_hash($pdo);
            $pdo->exec('PRAGMA busy_timeout = 10000');
        } else {
            throw new Fault('Banco de dados não suportado pelo módulo.', 503);
        }
        $this->lockNamespace = substr(hash('sha256', $database), 0, 24);
    }

    public function install(): void
    {
        $id = $this->driver === 'mysql' ? 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS mod_velfypix_charges (
            id $id,
            invoice_id BIGINT NOT NULL UNIQUE,
            client_id BIGINT NOT NULL,
            amount_cents BIGINT NOT NULL,
            external_ref VARCHAR(100) NOT NULL UNIQUE,
            api_origin VARCHAR(255) NOT NULL,
            callback_hash VARCHAR(64) NOT NULL,
            request_json TEXT NULL,
            remote_id VARCHAR(100) NULL UNIQUE,
            state VARCHAR(24) NOT NULL,
            pix_json TEXT NULL,
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL
        )$tail");
    }

    public function invoice(int $invoiceId): ?array
    {
        return $this->one('SELECT * FROM mod_velfypix_charges WHERE invoice_id = ?', [$invoiceId]);
    }

    public function remote(string $remoteId): ?array
    {
        return $this->one('SELECT * FROM mod_velfypix_charges WHERE remote_id = ?', [$remoteId]);
    }

    public function reference(string $externalRef): ?array
    {
        return $this->one('SELECT * FROM mod_velfypix_charges WHERE external_ref = ?', [$externalRef]);
    }

    private function one(string $sql, array $values): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function insert(array $record): void
    {
        $statement = $this->pdo->prepare('INSERT INTO mod_velfypix_charges
            (invoice_id, client_id, amount_cents, external_ref, api_origin, callback_hash, request_json, state, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = gmdate('c');
        $statement->execute([
            $record['invoice_id'], $record['client_id'], $record['amount_cents'], $record['external_ref'],
            $record['api_origin'], $record['callback_hash'], $record['request_json'], 'creating', $now, $now,
        ]);
    }

    public function created(int $invoiceId, string $remoteId, array $pix): void
    {
        $statement = $this->pdo->prepare('UPDATE mod_velfypix_charges
            SET remote_id = ?, pix_json = ?, state = ?, request_json = NULL, updated_at = ? WHERE invoice_id = ?');
        $statement->execute([$remoteId, Json::encode($pix), 'pending', gmdate('c'), $invoiceId]);
    }

    public function paid(int $invoiceId): void
    {
        $statement = $this->pdo->prepare('UPDATE mod_velfypix_charges SET state = ?, updated_at = ? WHERE invoice_id = ?');
        $statement->execute(['paid', gmdate('c'), $invoiceId]);
    }

    public function withInvoiceLock(int $invoiceId, callable $work)
    {
        $name = 'velfypix:' . $this->lockNamespace . ':' . $invoiceId;
        if ($this->driver === 'mysql') {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
            $statement->execute([$name]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new Fault('A fatura está sendo processada. Tente novamente.', 503);
            }
            try {
                return $work();
            } finally {
                $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$name]);
            }
        }
        // SQLite is used only by the local harness; production uses database locks.
        $handle = fopen(sys_get_temp_dir() . '/' . str_replace(':', '-', $name) . '.lock', 'c');
        if ($handle === false) {
            throw new Fault('Não foi possível obter o bloqueio da fatura.', 503);
        }
        try {
            $deadline = microtime(true) + 10;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new Fault('A fatura está sendo processada. Tente novamente.', 503);
                }
                usleep(20000);
            }
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
