<?php
declare(strict_types=1);

// Minimal WHMCS boundary simulation. Does not replace tests in licensed WHMCS.
namespace WHMCS\Database {
    final class Capsule
    {
        public static \PDO $pdo;

        public static function connection(): object
        {
            return new class {
                public function getPdo(): \PDO { return Capsule::$pdo; }
            };
        }

        public static function table(string $table): Query
        {
            return new Query($table);
        }
    }

    final class Query
    {
        private string $table;
        private array $conditions = [];
        private array $values = [];
        private array $joins = [];

        public function __construct(string $table) { $this->table = $table; }

        public function where(string $column, $value): self
        {
            $this->conditions[] = $column . ' = ?';
            $this->values[] = $value;
            return $this;
        }

        public function join(string $table, string $left, string $operator, string $right): self
        {
            $this->joins[] = ' JOIN ' . $table . ' ON ' . $left . ' ' . $operator . ' ' . $right;
            return $this;
        }

        private function select(string $columns): \PDOStatement
        {
            $sql = 'SELECT ' . $columns . ' FROM ' . $this->table . implode('', $this->joins);
            if ($this->conditions !== []) $sql .= ' WHERE ' . implode(' AND ', $this->conditions);
            $statement = Capsule::$pdo->prepare($sql . ' LIMIT 1');
            $statement->execute($this->values);
            return $statement;
        }

        public function first(): ?object
        {
            $row = $this->select('*')->fetch(\PDO::FETCH_OBJ);
            return $row === false ? null : $row;
        }

        public function value(string $column)
        {
            $value = $this->select($column)->fetchColumn();
            return $value === false ? null : $value;
        }
    }
}

namespace WHMCS\Config {
    final class Setting
    {
        public static function getValue(string $key): ?string
        {
            return $key === 'SystemURL' ? 'https://whmcs.example.test/billing' : null;
        }
    }
}

namespace {
    function localAPI(string $command, array $params): array
    {
        if ($command === 'GetInvoice' && ($params['invoiceid'] ?? 0) === 12345) {
            return $GLOBALS['whmcsInvoice'];
        }
        if ($command === 'GetClientsDetails' && ($params['clientid'] ?? 0) === 7) {
            return ['result' => 'success', 'client' => $GLOBALS['whmcsClient']];
        }
        return ['result' => 'error'];
    }

    function checkCbInvoiceID(int $invoiceId, string $gatewayName): int
    {
        if ($invoiceId !== 12345) throw new \Velfy\Pix\Fault('Invoice not found', 404);
        $GLOBALS['whmcsHelpers'][] = 'checkCbInvoiceID';
        return $invoiceId;
    }

    function checkCbTransID(string $transactionId): void
    {
        $statement = \WHMCS\Database\Capsule::$pdo->prepare('SELECT transid FROM tblaccounts WHERE transid = ?');
        $statement->execute([$transactionId]);
        if ($statement->fetchColumn() !== false) throw new \Velfy\Pix\Fault('Duplicate transaction', 409);
        $GLOBALS['whmcsHelpers'][] = 'checkCbTransID';
    }

    function logTransaction(string $gateway, array $data, string $status): void
    {
        $GLOBALS['whmcsLogs'][] = compact('gateway', 'data', 'status');
        $GLOBALS['whmcsHelpers'][] = 'logTransaction';
    }

    function addInvoicePayment(int $invoiceId, string $transactionId, float $amount, float $fee, string $gateway): void
    {
        $statement = \WHMCS\Database\Capsule::$pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, fees, gateway) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$invoiceId, $transactionId, $amount, $fee, $gateway]);
        $GLOBALS['whmcsInvoice']['status'] = 'Paid';
        $GLOBALS['whmcsInvoice']['balance'] = '0.00';
        $GLOBALS['whmcsHelpers'][] = 'addInvoicePayment';
    }
}
