<?php
declare(strict_types=1);

namespace Velfy\Pix;

interface Payments
{
    public function recorded(int $invoiceId, string $transactionId): bool;
    public function invoice(int $invoiceId): array;
    public function apply(int $invoiceId, string $transactionId, string $amount, string $fee): void;
}

final class Gateway
{
    private Settings $settings;
    private ApiClient $api;
    private Storage $storage;

    public function __construct(Settings $settings, ApiClient $api, Storage $storage)
    {
        $this->settings = $settings;
        $this->api = $api;
        $this->storage = $storage;
    }

    public function generate(array $params, string $document): array
    {
        $invoiceId = filter_var($params['invoiceid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $clientId = filter_var($params['velfyClientId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($invoiceId === false || $clientId === false) {
            throw new Fault('Fatura ou cliente inválido.');
        }
        if (($params['currency'] ?? '') !== 'BRL') {
            throw new Fault('O PIX Velfy está disponível somente para faturas em BRL.');
        }
        $amount = Money::cents((string) ($params['amount'] ?? ''));
        if ($amount === 0) {
            throw new Fault('Não há saldo a pagar nesta fatura.');
        }
        return $this->storage->withInvoiceLock($invoiceId, function () use ($params, $document, $invoiceId, $clientId, $amount): array {
            $record = $this->storage->invoice($invoiceId);
            if ($record !== null) {
                if ((int) $record['client_id'] !== $clientId || (int) $record['amount_cents'] !== $amount
                    || $record['api_origin'] !== $this->settings->apiUrl) {
                    throw new Fault('A fatura mudou após a emissão do PIX. Contate o financeiro para conciliar a cobrança.', 409);
                }
                if ($record['remote_id'] !== null) {
                    return $record;
                }
                // Retry the exact saved request, including legacy callback URLs.
                $payload = Json::decode((string) $record['request_json']);
            } else {
                $customer = Customer::build($params['clientdetails'] ?? [], $document);
                $externalRef = $this->settings->referencePrefix . '-invoice-' . $invoiceId;
                $callbackToken = bin2hex(random_bytes(32));
                $description = trim((string) ($params['description'] ?? ''));
                if ($description === '') {
                    $description = 'Fatura #' . $invoiceId;
                }
                if (strlen($description) > 500) {
                    $description = 'Fatura #' . $invoiceId;
                }
                $payload = [
                    'amount' => $amount,
                    'paymentMethod' => 'pix',
                    'externalRef' => $externalRef,
                    'postbackUrl' => $this->settings->systemUrl . '/modules/gateways/callback/velfypix.php',
                    'customer' => $customer,
                    'items' => [['title' => $description, 'unitPrice' => $amount, 'quantity' => 1, 'tangible' => false]],
                    'pix' => ['expiresInDays' => $this->settings->expiresInDays],
                    'metadata' => Json::encode(['origem' => 'whmcs', 'invoiceId' => $invoiceId]),
                ];
                $this->storage->insert([
                    'invoice_id' => $invoiceId,
                    'client_id' => $clientId,
                    'amount_cents' => $amount,
                    'external_ref' => $externalRef,
                    'api_origin' => $this->settings->apiUrl,
                    // Retain the existing schema for callbacks issued by earlier versions.
                    'callback_hash' => hash('sha256', $callbackToken),
                    'request_json' => Json::encode($payload),
                ]);
                $record = $this->storage->invoice($invoiceId);
            }
            $transaction = $this->api->create($payload, $record['external_ref']);
            self::assertTransaction($transaction, $record);
            $remoteId = ApiClient::transactionId($transaction['id'] ?? null);
            $pix = self::pix($transaction);
            $this->storage->created($invoiceId, $remoteId, $pix);
            return $this->storage->invoice($invoiceId);
        });
    }

    public function webhook(array $event, string $token, Payments $payments): string
    {
        if (($event['type'] ?? '') !== 'transaction' || !is_array($event['data'] ?? null)) {
            throw new Fault('Evento de transação inválido.', 400);
        }
        $remoteId = ApiClient::transactionId($event['data']['id'] ?? null);
        $record = $this->storage->remote($remoteId);
        // A payment notification can arrive after a successful POST whose response
        // was lost. Authenticate against the saved charge and verify the ID by GET.
        if ($record === null) {
            $reference = self::externalReference($event['data']);
            if ($reference !== null) {
                $record = $this->storage->reference($reference);
            }
        }
        if ($record === null) {
            throw new Fault('Transação não reconhecida.', 404);
        }
        // The observed Velfy deliveries use a plain callback URL and no signature.
        // A notification only triggers verification; authenticated GET supplies all
        // payment facts. Still reject an invalid legacy token when one is supplied.
        if ($token !== '' && (!preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($record['callback_hash'], hash('sha256', $token)))) {
            throw new Fault('Notificação não autenticada.', 401);
        }
        if ($record['api_origin'] !== $this->settings->apiUrl) {
            throw new Fault('A origem da API mudou. Conciliação manual necessária.', 409);
        }
        $invoiceId = (int) $record['invoice_id'];
        return $this->storage->withInvoiceLock($invoiceId, function () use ($remoteId, $invoiceId, $payments): string {
            $record = $this->storage->invoice($invoiceId);
            if ($record['remote_id'] !== null && $record['remote_id'] !== $remoteId) {
                throw new Fault('O identificador da transação não corresponde à cobrança.', 409);
            }
            $whmcsTransactionId = 'velfypix:' . $remoteId;
            if ($payments->recorded($invoiceId, $whmcsTransactionId)) {
                $this->storage->paid($invoiceId);
                return 'duplicate';
            }
            if ($record['state'] === 'paid') {
                throw new Fault('Pagamento local sem lançamento correspondente. Conciliação manual necessária.', 409);
            }
            // The webhook supplies only the ID. All payment facts come from authenticated GET.
            $transaction = $this->api->get($remoteId);
            self::assertTransaction($transaction, $record);
            if (ApiClient::transactionId($transaction['id'] ?? null) !== $remoteId) {
                throw new Fault('A API retornou uma transação diferente.', 409);
            }
            if (($transaction['status'] ?? '') !== 'paid') {
                return 'ignored';
            }
            $amount = Money::integer($transaction['paidAmount'] ?? null);
            if ($amount !== (int) $record['amount_cents']) {
                throw new Fault('Valor pago diferente do valor da cobrança. Conciliação manual necessária.', 409);
            }
            if (Money::integer($transaction['refundedAmount'] ?? 0, true) !== 0) {
                throw new Fault('A transação possui estorno. Conciliação manual necessária.', 409);
            }
            if ($record['remote_id'] === null) {
                $this->storage->created($invoiceId, $remoteId, self::pix($transaction));
            }
            $invoice = $payments->invoice($invoiceId);
            if (($invoice['currency'] ?? '') !== 'BRL' || (int) ($invoice['client_id'] ?? 0) !== (int) $record['client_id']
                || ($invoice['status'] ?? '') !== 'Unpaid' || Money::cents((string) ($invoice['balance'] ?? '')) < $amount) {
                throw new Fault('A fatura não permite aplicar esse pagamento. Conciliação manual necessária.', 409);
            }
            $fee = self::fee($transaction, $amount);
            $payments->apply($invoiceId, $whmcsTransactionId, Money::decimal($amount), Money::decimal($fee));
            // If persistence fails after WHMCS has applied the payment, the next retry
            // recovers through recorded() without crediting the invoice again.
            if (!$payments->recorded($invoiceId, $whmcsTransactionId)) {
                throw new Fault('O WHMCS não confirmou o lançamento do pagamento.', 503);
            }
            $this->storage->paid($invoiceId);
            return 'paid';
        });
    }

    private static function assertTransaction(array $transaction, array $record): void
    {
        if (strtolower((string) ($transaction['paymentMethod'] ?? '')) !== 'pix'
            || self::externalReference($transaction) !== $record['external_ref']
            || Money::integer($transaction['amount'] ?? null) !== (int) $record['amount_cents']) {
            throw new Fault('Os dados da transação não correspondem à fatura.', 409);
        }
    }

    private static function externalReference(array $transaction): ?string
    {
        $reference = null;
        // The supplied POST response calls the request's externalRef externalId.
        // Keep support for externalRef, but never accept contradictory aliases.
        foreach (['externalId', 'externalRef'] as $field) {
            if (!array_key_exists($field, $transaction) || $transaction[$field] === null) {
                continue;
            }
            $value = $transaction[$field];
            if (!is_string($value) || $value === '' || ($reference !== null && $value !== $reference)) {
                throw new Fault('A referência da transação está ausente, inválida ou divergente.', 409);
            }
            $reference = $value;
        }
        return $reference;
    }

    private static function fee(array $transaction, int $amount): int
    {
        $fee = null;
        if (array_key_exists('fees', $transaction)) {
            $fee = Money::integer($transaction['fees'], true);
            if ($fee > $amount) {
                throw new Fault('A taxa da transação é maior que o valor pago.', 502);
            }
        }
        if (array_key_exists('fee', $transaction) && $transaction['fee'] !== null) {
            if (!is_array($transaction['fee']) || !array_key_exists('netAmount', $transaction['fee'])) {
                throw new Fault('A taxa da transação está fora do contrato esperado.', 502);
            }
            $net = Money::integer($transaction['fee']['netAmount'], true);
            if ($net > $amount) {
                throw new Fault('O valor líquido da transação é inválido.', 502);
            }
            $derivedFee = $amount - $net;
            if ($fee !== null && $fee !== $derivedFee) {
                throw new Fault('Os campos de taxa da transação são divergentes.', 502);
            }
            $fee = $derivedFee;
        }
        return $fee ?? 0;
    }

    private static function pix(array $transaction): array
    {
        $status = (string) ($transaction['status'] ?? '');
        if (!in_array($status, ['pending', 'processing', 'paid'], true)) {
            throw new Fault('A Velfy não retornou uma cobrança PIX utilizável.', 502);
        }
        $pix = $transaction['pix'] ?? [];
        if (!is_array($pix)) {
            throw new Fault('Os dados do PIX estão fora do contrato esperado.', 502);
        }
        $code = $pix['qrcodeText'] ?? $pix['qrcode'] ?? '';
        if (!is_string($code) || ($code !== '' && (!str_starts_with($code, '000201') || strlen($code) > 4096))) {
            throw new Fault('A Velfy não retornou o código PIX copia e cola esperado.', 502);
        }
        if ($code === '' && $status !== 'paid') {
            throw new Fault('A Velfy não retornou o código PIX copia e cola esperado.', 502);
        }
        $expiration = $pix['expirationDate'] ?? null;
        if ($expiration !== null) {
            if (!is_string($expiration) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $expiration)) {
                throw new Fault('A validade do PIX está fora do contrato esperado.', 502);
            }
            try {
                $expiration = (new \DateTimeImmutable($expiration))->format(DATE_ATOM);
            } catch (\Exception $exception) {
                throw new Fault('A validade do PIX está fora do contrato esperado.', 502);
            }
        }
        return ['code' => $code, 'expiration' => $expiration, 'remote_status' => $status];
    }
}
