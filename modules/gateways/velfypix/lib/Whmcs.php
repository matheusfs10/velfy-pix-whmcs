<?php
declare(strict_types=1);

namespace Velfy\Pix;

use WHMCS\Database\Capsule;

final class Whmcs implements Payments
{
    public static function gateway(array $params): Gateway
    {
        if (empty($params['systemurl'])) {
            $params['systemurl'] = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        }
        $settings = new Settings($params);
        $storage = new Storage(Capsule::connection()->getPdo());
        $storage->install();
        return new Gateway($settings, new ApiClient($settings, new CurlTransport()), $storage);
    }

    public static function parameters(array $params): array
    {
        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($result['result'] ?? '') !== 'success' || ($result['paymentmethod'] ?? '') !== 'velfypix') {
            throw new Fault('Fatura indisponível para o gateway Velfy PIX.');
        }
        if (($result['status'] ?? '') !== 'Unpaid') {
            throw new Fault('Esta fatura não está aberta para pagamento.');
        }
        $clientId = (int) ($result['userid'] ?? 0);
        $clientResult = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        $client = $clientResult['client'] ?? null;
        if (($clientResult['result'] ?? '') !== 'success' || !is_array($client)) {
            throw new Fault('Não foi possível obter o cadastro do cliente.');
        }
        $currency = Capsule::table('tblcurrencies')->where('id', (int) ($client['currency'] ?? 0))->value('code');
        if ($currency !== 'BRL' || ($params['currency'] ?? '') !== 'BRL') {
            throw new Fault('O PIX Velfy está disponível somente para faturas em BRL, sem conversão de moeda.');
        }
        $params['velfyClientId'] = $clientId;
        $params['amount'] = (string) ($result['balance'] ?? '');
        $params['clientdetails'] = $client;
        return $params;
    }

    public static function document(array $params): string
    {
        $fieldId = trim((string) ($params['velfyDocumentFieldId'] ?? ''));
        if ($fieldId === '') {
            return (string) ($params['clientdetails']['tax_id'] ?? '');
        }
        if (!ctype_digit($fieldId) || (int) $fieldId < 1) {
            throw new Fault('Configure um ID numérico para o campo CPF/CNPJ.');
        }
        $field = Capsule::table('tblcustomfields')->where('id', (int) $fieldId)->where('type', 'client')->first();
        if ($field === null) {
            throw new Fault('O campo CPF/CNPJ configurado não é um campo de cliente válido.');
        }
        return (string) (Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', (int) $fieldId)->where('relid', $params['velfyClientId'])->value('value') ?? '');
    }

    public function recorded(int $invoiceId, string $transactionId): bool
    {
        $payment = Capsule::table('tblaccounts')->where('transid', $transactionId)->first();
        if ($payment === null) {
            return false;
        }
        if ((int) $payment->invoiceid !== $invoiceId || $payment->gateway !== 'velfypix') {
            throw new Fault('O identificador do pagamento está associado a outro lançamento.', 409);
        }
        return true;
    }

    public function invoice(int $invoiceId): array
    {
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($invoice['result'] ?? '') !== 'success') {
            throw new Fault('Fatura não encontrada.', 404);
        }
        $clientId = (int) ($invoice['userid'] ?? 0);
        $currency = Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
            ->where('tblclients.id', $clientId)->value('tblcurrencies.code');
        return [
            'client_id' => $clientId,
            'currency' => $currency,
            'status' => $invoice['status'] ?? '',
            'balance' => (string) ($invoice['balance'] ?? ''),
        ];
    }

    public function apply(int $invoiceId, string $transactionId, string $amount, string $fee): void
    {
        checkCbInvoiceID($invoiceId, 'Velfy PIX');
        checkCbTransID($transactionId);
        logTransaction('velfypix', ['invoice_id' => $invoiceId, 'transaction_id' => $transactionId, 'amount' => $amount, 'fee' => $fee], 'Verified');
        addInvoicePayment($invoiceId, $transactionId, (float) $amount, (float) $fee, 'velfypix');
    }

    public static function log(string $status, array $context): void
    {
        if (function_exists('logTransaction')) {
            try {
                logTransaction('velfypix', $context, $status);
            } catch (\Throwable $exception) {
                // Logging must never change payment or callback error handling.
            }
        }
    }
}
