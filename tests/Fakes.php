<?php
declare(strict_types=1);

namespace Velfy\Pix\Tests;

use Velfy\Pix\Fault;
use Velfy\Pix\Json;
use Velfy\Pix\Payments;
use Velfy\Pix\Transport;

final class FakeTransport implements Transport
{
    public array $requests = [];
    public array $transaction;
    public array $queue = [];

    public function __construct()
    {
        $fixture = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/transaction-created.json'));
        $this->transaction = $fixture['data'];
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->queue !== []) {
            $result = array_shift($this->queue);
            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;
        }
        if ($method === 'POST') {
            $payload = Json::decode((string) $body);
            $this->transaction['amount'] = $payload['amount'];
            $referenceField = array_key_exists('externalId', $this->transaction) ? 'externalId' : 'externalRef';
            $this->transaction[$referenceField] = $payload['externalRef'];
        }
        return ['status' => $method === 'POST' ? 201 : 200, 'body' => json_encode(['success' => true, 'data' => $this->transaction], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
    }
}

class FakePayments implements Payments
{
    public array $ledger = [];
    public array $currentInvoice = ['status' => 'Unpaid', 'client_id' => 7, 'currency' => 'BRL', 'balance' => '100.00'];
    public bool $failAfterApply = false;

    public function recorded(int $invoiceId, string $transactionId): bool
    {
        return isset($this->ledger[$transactionId]);
    }

    public function invoice(int $invoiceId): array
    {
        return $this->currentInvoice;
    }

    public function apply(int $invoiceId, string $transactionId, string $amount, string $fee): void
    {
        $this->ledger[$transactionId] = compact('invoiceId', 'transactionId', 'amount', 'fee');
        $this->currentInvoice['status'] = 'Paid';
        if ($this->failAfterApply) {
            throw new Fault('Simulated interruption after WHMCS ledger write.', 503);
        }
    }
}
