<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/gateways/velfypix/bootstrap.php';
require_once __DIR__ . '/Fakes.php';

use Velfy\Pix\ApiClient;
use Velfy\Pix\CurlTransport;
use Velfy\Pix\Customer;
use Velfy\Pix\Fault;
use Velfy\Pix\Gateway;
use Velfy\Pix\Json;
use Velfy\Pix\Money;
use Velfy\Pix\Renderer;
use Velfy\Pix\Settings;
use Velfy\Pix\Storage;
use Velfy\Pix\Tests\FakePayments;
use Velfy\Pix\Tests\FakeTransport;

$passed = 0;
$failed = 0;

function same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function truth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $action, ?int $httpStatus = null): void
{
    try {
        $action();
    } catch (Fault $exception) {
        if ($httpStatus !== null) {
            same($httpStatus, $exception->httpStatus);
        }
        return;
    }
    throw new RuntimeException('Expected rejection.');
}

function test(string $label, callable $action): void
{
    global $passed, $failed;
    try {
        $action();
        $passed++;
        fwrite(STDOUT, "PASS $label\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL $label: {$exception->getMessage()}\n");
    }
}

function context(?string $databasePath = null): array
{
    $params = [
        'velfyApiKey' => 'vfy_local_fake_key',
        'velfyApiUrl' => 'https://api.velfy.test',
        'velfyReferencePrefix' => 'local',
        'systemurl' => 'https://whmcs.example.test/billing',
        'invoiceid' => 12345,
        'velfyClientId' => 7,
        'amount' => '100.00',
        'currency' => 'BRL',
        'description' => 'Fatura #12345',
        'clientdetails' => ['firstname' => 'Maria', 'lastname' => 'Silva', 'email' => 'maria@example.test', 'phonenumber' => '+55 (11) 99999-9999'],
    ];
    $settings = new Settings($params);
    $transport = new FakeTransport();
    $api = new ApiClient($settings, $transport);
    $pdo = new PDO($databasePath === null ? 'sqlite::memory:' : 'sqlite:' . $databasePath);
    $storage = new Storage($pdo);
    $storage->install();
    $gateway = new Gateway($settings, $api, $storage);
    $payments = new FakePayments();
    return compact('params', 'settings', 'transport', 'api', 'pdo', 'storage', 'gateway', 'payments');
}

function suppliedContext(): array
{
    $ctx = context();
    $fixture = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/transaction-created-velfy.json'));
    // Keep the supplied response shape, with the reference for this test invoice.
    $fixture['data']['externalId'] = 'local-invoice-12345';
    $ctx['transport']->transaction = $fixture['data'];
    return $ctx;
}

function suppliedWebhook(): array
{
    $event = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-paid-velfy.json'));
    $event['data']['externalRef'] = 'local-invoice-12345';
    return $event;
}

function issued(?array $ctx = null): array
{
    $ctx = $ctx ?? context();
    $ctx['record'] = $ctx['gateway']->generate($ctx['params'], '123.456.789-09');
    $request = Json::decode($ctx['transport']->requests[0]['body']);
    parse_str((string) parse_url($request['postbackUrl'], PHP_URL_QUERY), $query);
    $ctx['token'] = $query['token'] ?? '';
    $ctx['event'] = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-paid.json'));
    return $ctx;
}

function paid(array $ctx): array
{
    $ctx['transport']->transaction['status'] = 'paid';
    $ctx['transport']->transaction['paidAmount'] = 10000;
    $ctx['transport']->transaction['fee'] = ['netAmount' => 9700];
    return $ctx;
}

test('money: exact cent conversion, including decimal edge cases', function (): void {
    foreach (['0.01' => 1, '0.10' => 10, '1.01' => 101, '100' => 10000, '100.0' => 10000, '999999999.99' => 99999999999] as $decimal => $cents) {
        same($cents, Money::cents((string) $decimal));
        same($cents, Money::cents(Money::decimal($cents)));
    }
    foreach (['1,00', '-1.00', '1e3', '1.001', 'NaN', '', '1000000000'] as $invalid) {
        rejects(fn() => Money::cents($invalid));
    }
    rejects(fn() => Money::integer(100.0), 502);
});

test('customer: formatted CPF, numeric CNPJ and Brazilian phone normalization', function (): void {
    $ctx = context();
    $customer = Customer::build($ctx['params']['clientdetails'], '123.456.789-09');
    same('11999999999', $customer['phone']);
    same(['type' => 'cpf', 'number' => '12345678909'], $customer['document']);
    same('cnpj', Customer::build($ctx['params']['clientdetails'], '11.222.333/0001-81')['document']['type']);
    foreach (['11111111111', '12345678900', '11222333000180', 'ABC'] as $invalid) {
        rejects(fn() => Customer::build($ctx['params']['clientdetails'], $invalid));
    }
});

test('settings: HTTPS required and credential/header injection rejected', function (): void {
    $ctx = context();
    foreach (['http://api.velfy.test', 'https://user:pass@api.velfy.test', 'https://api.velfy.test?x=1', 'https://api.velfy.test/api/v1'] as $url) {
        $params = $ctx['params'];
        $params['velfyApiUrl'] = $url;
        rejects(fn() => new Settings($params));
    }
    $params = $ctx['params'];
    $params['velfyApiKey'] = "abc\r\nX-Injected: 1";
    rejects(fn() => new Settings($params));
    rejects(fn() => (new CurlTransport())->send('GET', 'http://127.0.0.1', [], null));
});

test('request: user payload, Bearer auth, amount and idempotency header', function (): void {
    $ctx = issued();
    $request = $ctx['transport']->requests[0];
    $payload = Json::decode($request['body']);
    same('POST', $request['method']);
    same('https://api.velfy.test/api/v1/transactions', $request['url']);
    truth(in_array('Authorization: Bearer vfy_local_fake_key', $request['headers'], true), 'Missing Bearer header');
    truth(in_array('Idempotency-Key: local-invoice-12345', $request['headers'], true), 'Missing idempotency header');
    same(10000, $payload['amount']);
    same('pix', $payload['paymentMethod']);
    same(10000, $payload['items'][0]['unitPrice']);
    same(1, $payload['items'][0]['quantity']);
    same(false, $payload['items'][0]['tangible']);
    same(['expiresInDays' => 1], $payload['pix']);
    same('whmcs', Json::decode($payload['metadata'])['origem']);
    same('https://whmcs.example.test/billing/modules/gateways/callback/velfypix.php', $payload['postbackUrl']);
    same(64, strlen($ctx['record']['callback_hash']));
    same(null, $ctx['record']['request_json']);
    truth(!str_contains($ctx['record']['pix_json'], '12345678909'), 'Customer document leaked into saved PIX data');
});

test('supplied 201: numeric ID, externalId, qrcode and millisecond UTC expiry', function (): void {
    $ctx = suppliedContext();
    $response = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/transaction-created-velfy.json'));
    $response['data'] = $ctx['transport']->transaction;
    $ctx['transport']->queue[] = ['status' => 201, 'body' => Json::encode($response)];
    $ctx = issued($ctx);
    same('900001', $ctx['record']['remote_id']);
    same('local-invoice-12345', $ctx['record']['external_ref']);
    $pix = Json::decode($ctx['record']['pix_json']);
    same('00020126...6304ABCD', $pix['code']);
    same('2030-01-02T12:00:00+00:00', $pix['expiration']);
    same('pending', $pix['remote_status']);
    $request = Json::decode($ctx['transport']->requests[0]['body']);
    same('local-invoice-12345', $request['externalRef']);
    truth(!array_key_exists('externalId', $request), 'Response alias changed the request contract');
    foreach (['customer', 'secureId', 'companyId', 'fees'] as $field) {
        truth(!array_key_exists($field, $pix), 'Unnecessary response data persisted');
    }
});

test('supplied 201: wrong, missing, invalid or conflicting external references rejected', function (): void {
    foreach ([['externalId' => 'wrong-ref'], ['externalId' => null], ['externalId' => 12345],
        ['externalRef' => 'wrong-ref']] as $change) {
        $ctx = suppliedContext();
        $data = array_replace($ctx['transport']->transaction, $change);
        $ctx['transport']->queue[] = ['status' => 201, 'body' => Json::encode(['success' => true, 'data' => $data])];
        rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 409);
        same(null, $ctx['storage']->invoice(12345)['remote_id']);
    }
    $ctx = suppliedContext();
    $ctx['transport']->transaction['externalRef'] = 'local-invoice-12345';
    same('900001', issued($ctx)['record']['remote_id']);
});

test('supplied response: authenticated GET supplies numeric ID and fees in cents', function (): void {
    $ctx = issued(suppliedContext());
    // Minimal event used to verify that payment facts come only from the GET.
    $event = ['type' => 'transaction', 'data' => ['id' => 900001, 'status' => 'paid', 'fees' => 1]];
    same('ignored', $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']));
    same([], $ctx['payments']->ledger);
    same('https://api.velfy.test/api/v1/transactions/900001', $ctx['transport']->requests[1]['url']);
    $ctx['transport']->transaction['status'] = 'paid';
    $ctx['transport']->transaction['paidAmount'] = 10000;
    same('paid', $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']));
    same('100.00', $ctx['payments']->ledger['velfypix:900001']['amount']);
    same('9.49', $ctx['payments']->ledger['velfypix:900001']['fee']);
    same('duplicate', $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']));
    same(1, count($ctx['payments']->ledger));
    same(3, count($ctx['transport']->requests));
});

test('fees: invalid or conflicting fee representations cannot credit the invoice', function (): void {
    foreach ([['fees' => -1], ['fees' => 10001], ['fees' => 949.0], ['fees' => '949'],
        ['fees' => null], ['fees' => []], ['fee' => ['netAmount' => 9700]]] as $change) {
        $ctx = issued(suppliedContext());
        $ctx['transport']->transaction = array_replace($ctx['transport']->transaction,
            ['status' => 'paid', 'paidAmount' => 10000], $change);
        $event = ['type' => 'transaction', 'data' => ['id' => 900001]];
        rejects(fn() => $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']), 502);
        same([], $ctx['payments']->ledger);
        same('pending', $ctx['storage']->invoice(12345)['state']);
    }
});

test('fees: zero, full-value and consistent legacy net amount are supported', function (): void {
    foreach ([['fees' => 0], ['fees' => 10000], ['fees' => 949, 'fee' => ['netAmount' => 9051]]] as $change) {
        $ctx = issued(suppliedContext());
        $ctx['transport']->transaction = array_replace($ctx['transport']->transaction,
            ['status' => 'paid', 'paidAmount' => 10000], $change);
        same('paid', $ctx['gateway']->webhook(['type' => 'transaction', 'data' => ['id' => 900001]], $ctx['token'], $ctx['payments']));
        same(Money::decimal($change['fees']), $ctx['payments']->ledger['velfypix:900001']['fee']);
    }
});

test('externalId: lost creation response recovers through reference and authenticated GET', function (): void {
    $ctx = suppliedContext();
    $ctx['transport']->queue[] = new Fault('Lost POST response', 503);
    rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 503);
    $request = Json::decode($ctx['transport']->requests[0]['body']);
    parse_str((string) parse_url($request['postbackUrl'], PHP_URL_QUERY), $query);
    $event = ['type' => 'transaction', 'data' => ['id' => 900001, 'externalId' => 'local-invoice-12345']];
    $ctx['transport']->transaction['status'] = 'paid';
    $ctx['transport']->transaction['paidAmount'] = 10000;
    same('paid', $ctx['gateway']->webhook($event, ($query['token'] ?? ''), $ctx['payments']));
    same('900001', $ctx['storage']->invoice(12345)['remote_id']);
    same(null, $ctx['storage']->invoice(12345)['request_json']);
    same(1, count($ctx['payments']->ledger));
});

test('supplied webhook: full event uses data.id and cannot override authenticated payment facts', function (): void {
    $ctx = issued(suppliedContext());
    $event = suppliedWebhook();
    // Simulated GET based on the supplied transaction shape, not a captured GET.
    $ctx['transport']->transaction = $event['data'];
    $event['data']['amount'] = 1;
    $event['data']['paidAmount'] = 1;
    $event['data']['paymentMethod'] = 'boleto';
    $event['data']['fee']['netAmount'] = 1;
    $event['data']['externalRef'] = 'forged';
    same('paid', $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']));
    same('100.00', $ctx['payments']->ledger['velfypix:900001']['amount']);
    same('9.49', $ctx['payments']->ledger['velfypix:900001']['fee']);
    same('GET', $ctx['transport']->requests[1]['method']);
    same('https://api.velfy.test/api/v1/transactions/900001', $ctx['transport']->requests[1]['url']);
    // A new delivery ID still refers to the same payment.
    $event['id'] = 600002;
    same('duplicate', $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']));
    same(1, count($ctx['payments']->ledger));
    same(2, count($ctx['transport']->requests));
});

test('supplied webhook: paid event cannot credit while GET is pending or returns 404', function (): void {
    $event = suppliedWebhook();
    $ctx = issued(suppliedContext());
    same('ignored', $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']));
    same([], $ctx['payments']->ledger);
    same('pending', $ctx['storage']->invoice(12345)['state']);
    $ctx['transport']->queue[] = ['status' => 404, 'body' => Json::encode(['success' => false, 'message' => 'Not found'])];
    rejects(fn() => $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']), 502);
    same([], $ctx['payments']->ledger);
    same('pending', $ctx['storage']->invoice(12345)['state']);
});

test('supplied webhook: body URLs and paid fields cannot authorize a payment', function (): void {
    $ctx = issued(suppliedContext());
    $event = suppliedWebhook();
    $event['url'] = 'https://untrusted.example.test/?token=' . str_repeat('a', 64);
    $event['data']['postbackUrl'] = $event['url'];
    rejects(fn() => $ctx['gateway']->webhook($event, str_repeat('0', 64), $ctx['payments']), 401);
    same(1, count($ctx['transport']->requests));
    same('ignored', $ctx['gateway']->webhook($event, '', $ctx['payments']));
    same('https://api.velfy.test/api/v1/transactions/900001', $ctx['transport']->requests[1]['url']);
    same([], $ctx['payments']->ledger);
});

test('supplied webhook: lost POST response recovers by externalRef and GET without PIX expiry', function (): void {
    $ctx = suppliedContext();
    $ctx['transport']->queue[] = new Fault('Lost POST response', 503);
    rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 503);
    $request = Json::decode($ctx['transport']->requests[0]['body']);
    parse_str((string) parse_url($request['postbackUrl'], PHP_URL_QUERY), $query);
    $event = suppliedWebhook();
    $ctx['transport']->transaction = $event['data'];
    same('paid', $ctx['gateway']->webhook($event, ($query['token'] ?? ''), $ctx['payments']));
    $record = $ctx['storage']->invoice(12345);
    same('900001', $record['remote_id']);
    same('paid', $record['state']);
    same(null, $record['request_json']);
    same(null, Json::decode($record['pix_json'])['expiration']);
    same('9.49', $ctx['payments']->ledger['velfypix:900001']['fee']);
    foreach (['customer', 'secureId', 'metadata', 'postbackUrl'] as $field) {
        truth(!array_key_exists($field, Json::decode($record['pix_json'])), 'Full webhook data persisted');
    }
});

test('observed webhooks: plain URL, APPROVED payment and late PIX_GENERATED delivery', function (): void {
    $approved = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-paid-observed.json'));
    $pending = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-pending-observed.json'));
    $response = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/transaction-paid-observed.json'));
    $ctx = context();
    $ctx['params']['amount'] = '5.00';
    $ctx['transport']->transaction = $pending['body']['data'];
    $ctx = issued($ctx);
    $ctx['transport']->transaction = $response['data'];
    same('APPROVED', $approved['headers']['x-velfy-event-type']);
    same('11', $pending['headers']['x-velfy-attempt']);
    same('paid', $ctx['gateway']->webhook($approved['body'], '', $ctx['payments']));
    same('5.00', $ctx['payments']->ledger['velfypix:900002']['amount']);
    same('0.15', $ctx['payments']->ledger['velfypix:900002']['fee']);
    same('https://api.velfy.test/api/v1/transactions/900002', $ctx['transport']->requests[1]['url']);
    same('duplicate', $ctx['gateway']->webhook($pending['body'], '', $ctx['payments']));
    same('duplicate', $ctx['gateway']->webhook($approved['body'], '', $ctx['payments']));
    same(1, count($ctx['payments']->ledger));
    same(2, count($ctx['transport']->requests));
    same('paid', $ctx['storage']->invoice(12345)['state']);
});

test('unsigned callback: a paid body cannot bypass API authentication failure', function (): void {
    $ctx = issued();
    $ctx['transport']->queue[] = ['status' => 401, 'body' => Json::encode(['success' => false])];
    rejects(fn() => $ctx['gateway']->webhook($ctx['event'], '', $ctx['payments']), 502);
    same([], $ctx['payments']->ledger);
    same('pending', $ctx['storage']->invoice(12345)['state']);
});

test('legacy callbacks: supplied token remains checked and plain notifications require API verification', function (): void {
    $ctx = issued();
    $legacyToken = str_repeat('a', 64);
    $statement = $ctx['pdo']->prepare('UPDATE mod_velfypix_charges SET callback_hash = ? WHERE invoice_id = ?');
    $statement->execute([hash('sha256', $legacyToken), 12345]);
    same('ignored', $ctx['gateway']->webhook($ctx['event'], $legacyToken, $ctx['payments']));
    rejects(fn() => $ctx['gateway']->webhook($ctx['event'], str_repeat('b', 64), $ctx['payments']), 401);
    same(2, count($ctx['transport']->requests));
    same('ignored', $ctx['gateway']->webhook($ctx['event'], '', $ctx['payments']));
    same([], $ctx['payments']->ledger);
    $ctx = paid($ctx);
    same('paid', $ctx['gateway']->webhook($ctx['event'], $legacyToken, $ctx['payments']));
    same('duplicate', $ctx['gateway']->webhook($ctx['event'], '', $ctx['payments']));
    same(1, count($ctx['payments']->ledger));
});

test('invoice refresh: reuses charge without a second API request', function (): void {
    $ctx = issued();
    $record = $ctx['gateway']->generate($ctx['params'], '12345678909');
    same($ctx['record']['remote_id'], $record['remote_id']);
    same(1, count($ctx['transport']->requests));
});

test('validation: currency, zero balance and bad customer never call the API', function (): void {
    $ctx = context();
    $params = $ctx['params'];
    $params['currency'] = 'USD';
    rejects(fn() => $ctx['gateway']->generate($params, '12345678909'));
    $params = $ctx['params'];
    $params['amount'] = '0.00';
    rejects(fn() => $ctx['gateway']->generate($params, '12345678909'));
    $params = $ctx['params'];
    $params['clientdetails']['email'] = 'invalid';
    rejects(fn() => $ctx['gateway']->generate($params, '12345678909'));
    same(0, count($ctx['transport']->requests));
});

test('changed invoice: frozen amount prevents generating another charge', function (): void {
    $ctx = issued();
    $params = $ctx['params'];
    $params['amount'] = '110.00';
    rejects(fn() => $ctx['gateway']->generate($params, '12345678909'), 409);
    same(1, count($ctx['transport']->requests));
});

test('timeout: retries same body and key even if customer details change', function (): void {
    $ctx = context();
    $ctx['transport']->queue[] = new Fault('Simulated timeout', 503);
    rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 503);
    $params = $ctx['params'];
    $params['clientdetails']['firstname'] = 'New Name';
    $ctx['gateway']->generate($params, '12345678909');
    same($ctx['transport']->requests[0], $ctx['transport']->requests[1]);
});

test('API errors: reject authentication, rate limiting, server failure and redirects', function (): void {
    foreach ([401, 422, 429, 500, 302] as $status) {
        $ctx = context();
        $ctx['transport']->queue[] = ['status' => $status, 'body' => '{"secret":"do-not-log"}'];
        rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 502);
        same('creating', $ctx['storage']->invoice(12345)['state']);
    }
});

test('API response: reject malformed JSON and unrecognized envelope', function (): void {
    foreach (['<html>error</html>', '{"success":false,"data":{}}', '{"id":"tx-1"}', 'null'] as $body) {
        $ctx = context();
        $ctx['transport']->queue[] = ['status' => 201, 'body' => $body];
        rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 502);
    }
});

test('API response: different amount, reference or payment method rejected', function (): void {
    foreach (['amount' => 9999, 'externalRef' => 'another-invoice', 'paymentMethod' => 'credit_card'] as $field => $value) {
        $ctx = context();
        $data = $ctx['transport']->transaction;
        $data[$field] = $value;
        $ctx['transport']->queue[] = ['status' => 201, 'body' => Json::encode(['success' => true, 'data' => $data])];
        rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 409);
    }
});

test('API response: missing copy code and executable image URL rejected', function (): void {
    foreach ([[], ['qrcode' => 'javascript:alert(1)']] as $pix) {
        $ctx = context();
        $data = $ctx['transport']->transaction;
        $data['pix'] = $pix;
        $ctx['transport']->queue[] = ['status' => 201, 'body' => Json::encode(['success' => true, 'data' => $data])];
        rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 502);
    }
});

test('webhook: forged paid status ignored when authenticated API says pending', function (): void {
    $ctx = issued();
    same('ignored', $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']));
    same([], $ctx['payments']->ledger);
    same('GET', $ctx['transport']->requests[1]['method']);
    same('https://api.velfy.test/api/v1/transactions/tx-local-001', $ctx['transport']->requests[1]['url']);
});

test('webhook: invalid token and unknown ID never query the API', function (): void {
    $ctx = issued();
    rejects(fn() => $ctx['gateway']->webhook($ctx['event'], str_repeat('0', 64), $ctx['payments']), 401);
    $event = $ctx['event'];
    $event['data']['id'] = 'unknown';
    unset($event['data']['externalRef']);
    rejects(fn() => $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']), 404);
    same(1, count($ctx['transport']->requests));
    same([], $ctx['payments']->ledger);
});

test('webhook: paid value and fee applied from API, ignoring forged event fields', function (): void {
    $ctx = paid(issued());
    $event = $ctx['event'];
    $event['data']['paidAmount'] = 1;
    $event['data']['externalRef'] = 'forged';
    same('paid', $ctx['gateway']->webhook($event, $ctx['token'], $ctx['payments']));
    same('100.00', $ctx['payments']->ledger['velfypix:tx-local-001']['amount']);
    same('3.00', $ctx['payments']->ledger['velfypix:tx-local-001']['fee']);
    same('paid', $ctx['storage']->invoice(12345)['state']);
});

test('webhook: duplicate delivery acknowledged without another credit or GET', function (): void {
    $ctx = paid(issued());
    $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']);
    same('duplicate', $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']));
    same(1, count($ctx['payments']->ledger));
    same(2, count($ctx['transport']->requests));
});

test('webhook: failure after WHMCS credit recovers without a second credit', function (): void {
    $ctx = paid(issued());
    $ctx['payments']->failAfterApply = true;
    rejects(fn() => $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']), 503);
    same('pending', $ctx['storage']->invoice(12345)['state']);
    same('duplicate', $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']));
    same(1, count($ctx['payments']->ledger));
    same('paid', $ctx['storage']->invoice(12345)['state']);
});

test('webhook: resolves a lost creation response by saved reference and authenticated GET', function (): void {
    $ctx = context();
    $ctx['transport']->queue[] = new Fault('Lost POST response', 503);
    rejects(fn() => $ctx['gateway']->generate($ctx['params'], '12345678909'), 503);
    $request = Json::decode($ctx['transport']->requests[0]['body']);
    parse_str((string) parse_url($request['postbackUrl'], PHP_URL_QUERY), $query);
    $ctx = paid($ctx);
    $event = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-paid.json'));
    same('paid', $ctx['gateway']->webhook($event, ($query['token'] ?? ''), $ctx['payments']));
    same('tx-local-001', $ctx['storage']->invoice(12345)['remote_id']);
    same(1, count($ctx['payments']->ledger));
});

test('webhook: wrong ID, reference, amount or method from API cannot credit', function (): void {
    foreach (['id' => 'wrong-id', 'externalRef' => 'wrong-ref', 'amount' => 1, 'paymentMethod' => 'boleto'] as $field => $value) {
        $ctx = paid(issued());
        $ctx['transport']->transaction[$field] = $value;
        rejects(fn() => $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']), 409);
        same([], $ctx['payments']->ledger);
    }
});

test('webhook: partial, excess, zero, float or missing paid amount cannot credit', function (): void {
    foreach ([1, 10001, 0, 10000.0, null] as $amount) {
        $ctx = paid(issued());
        $ctx['transport']->transaction['paidAmount'] = $amount;
        rejects(fn() => $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']));
        same([], $ctx['payments']->ledger);
    }
});

test('webhook: refund and invalid net fee cannot credit', function (): void {
    foreach ([['refundedAmount' => 1], ['fee' => ['netAmount' => 10001]], ['fee' => ['netAmount' => -1]]] as $change) {
        $ctx = paid(issued());
        $ctx['transport']->transaction = array_replace($ctx['transport']->transaction, $change);
        rejects(fn() => $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']));
        same([], $ctx['payments']->ledger);
    }
});

test('webhook: non-paid states never apply payment', function (): void {
    foreach (['pending', 'processing', 'approved', 'refused', 'refunded', 'chargedback', 'in_protest', 'expired'] as $status) {
        $ctx = paid(issued());
        $ctx['transport']->transaction['status'] = $status;
        same('ignored', $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']));
        same([], $ctx['payments']->ledger);
    }
});

test('webhook: cancelled/paid invoice, wrong client/currency or reduced balance cannot credit', function (): void {
    foreach ([['status' => 'Cancelled'], ['status' => 'Paid'], ['client_id' => 8], ['currency' => 'USD'], ['balance' => '99.99']] as $change) {
        $ctx = paid(issued());
        $ctx['payments']->currentInvoice = array_replace($ctx['payments']->currentInvoice, $change);
        rejects(fn() => $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']), 409);
        same([], $ctx['payments']->ledger);
    }
});

test('webhook: API outage preserves unpaid invoice for retry', function (): void {
    $ctx = paid(issued());
    $ctx['transport']->queue[] = new Fault('Simulated network outage', 503);
    rejects(fn() => $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']), 503);
    same([], $ctx['payments']->ledger);
    same('paid', $ctx['gateway']->webhook($ctx['event'], $ctx['token'], $ctx['payments']));
});

test('renderer: local QR assets, no credentials/CPF/token in customer HTML', function (): void {
    $ctx = issued();
    $html = Renderer::payment($ctx['record'], '/modules/gateways/velfypix/assets');
    truth(str_contains($html, 'R$ 100,00'), 'Wrong display amount');
    truth(str_contains($html, 'qrcode.min.js'), 'Missing local QR renderer');
    foreach (array_filter(['vfy_local_fake_key', '12345678909', $ctx['token']]) as $private) {
        truth(!str_contains($html, $private), 'Sensitive data in customer HTML');
    }
    $record = $ctx['record'];
    $pix = Json::decode($record['pix_json']);
    $pix['code'] = '</textarea><script>alert(1)</script>';
    $record['pix_json'] = Json::encode($pix);
    truth(!str_contains(Renderer::payment($record, '/assets'), '<script>alert(1)'), 'Unescaped HTML');
});

test('renderer: expired or recorded payment no longer displays payable PIX', function (): void {
    $ctx = issued();
    $record = $ctx['record'];
    $pix = Json::decode($record['pix_json']);
    $pix['expiration'] = '2000-01-01T00:00:00Z';
    $record['pix_json'] = Json::encode($pix);
    truth(!str_contains(Renderer::payment($record, '/assets'), 'textarea'), 'Expired code still payable');
    $record['state'] = 'paid';
    truth(str_contains(Renderer::payment($record, '/assets'), 'confirmado'), 'Missing paid confirmation');
});

test('concurrency: two independent processes create one charge', function (): void {
    if (!function_exists('pcntl_fork')) {
        fwrite(STDOUT, "SKIP concurrent-process assertion: pcntl unavailable\n");
        return;
    }
    $directory = sys_get_temp_dir() . '/velfypix-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $databasePath = $directory . '/test.sqlite';
    $ctx = context($databasePath);
    unset($ctx);
    $children = [];
    for ($index = 0; $index < 2; $index++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Cannot fork');
        if ($pid === 0) {
            try {
                $child = context($databasePath);
                $child['gateway']->generate($child['params'], '12345678909');
                file_put_contents($directory . '/calls-' . $index, (string) count($child['transport']->requests));
                exit(0);
            } catch (Throwable $exception) {
                fwrite(STDERR, $exception->getMessage());
                exit(1);
            }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        same(0, pcntl_wexitstatus($status));
    }
    $calls = (int) file_get_contents($directory . '/calls-0') + (int) file_get_contents($directory . '/calls-1');
    same(1, $calls);
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
});

require_once __DIR__ . '/WhmcsStubs.php';
define('WHMCS', true);
require_once __DIR__ . '/../modules/gateways/velfypix.php';

function whmcsContext(): array
{
    $ctx = context();
    \WHMCS\Database\Capsule::$pdo = $ctx['pdo'];
    $ctx['pdo']->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
    $ctx['pdo']->exec("INSERT INTO tblcurrencies VALUES (1, 'BRL'), (2, 'USD')");
    $ctx['pdo']->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INTEGER)');
    $ctx['pdo']->exec('INSERT INTO tblclients VALUES (7, 1)');
    $ctx['pdo']->exec('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT)');
    $ctx['pdo']->exec("INSERT INTO tblcustomfields VALUES (10, 'client'), (20, 'product')");
    $ctx['pdo']->exec('CREATE TABLE tblcustomfieldsvalues (fieldid INTEGER, relid INTEGER, value TEXT)');
    $ctx['pdo']->exec("INSERT INTO tblcustomfieldsvalues VALUES (10, 7, '11.222.333/0001-81')");
    $ctx['pdo']->exec('CREATE TABLE tblaccounts (invoiceid INTEGER, transid TEXT UNIQUE, amountin REAL, fees REAL, gateway TEXT)');
    $GLOBALS['whmcsInvoice'] = ['result' => 'success', 'userid' => 7, 'paymentmethod' => 'velfypix', 'status' => 'Unpaid', 'balance' => '100.00'];
    $GLOBALS['whmcsClient'] = array_merge($ctx['params']['clientdetails'], ['currency' => 1, 'tax_id' => '12345678909']);
    $GLOBALS['whmcsHelpers'] = [];
    $GLOBALS['whmcsLogs'] = [];
    return $ctx;
}

test('WHMCS boundary: invoice balance/client data and configured document field', function (): void {
    $ctx = whmcsContext();
    $params = $ctx['params'];
    $params['amount'] = '1.00';
    $params = \Velfy\Pix\Whmcs::parameters($params);
    same('100.00', $params['amount']);
    same(7, $params['velfyClientId']);
    same('12345678909', \Velfy\Pix\Whmcs::document($params));
    $params['velfyDocumentFieldId'] = '10';
    same('11.222.333/0001-81', \Velfy\Pix\Whmcs::document($params));
    $params['velfyDocumentFieldId'] = '20';
    rejects(fn() => \Velfy\Pix\Whmcs::document($params));
});

test('WHMCS boundary: paid invoice, different gateway and currency conversion rejected', function (): void {
    $ctx = whmcsContext();
    $GLOBALS['whmcsInvoice']['status'] = 'Paid';
    rejects(fn() => \Velfy\Pix\Whmcs::parameters($ctx['params']));
    $GLOBALS['whmcsInvoice']['status'] = 'Unpaid';
    $GLOBALS['whmcsInvoice']['paymentmethod'] = 'another-gateway';
    rejects(fn() => \Velfy\Pix\Whmcs::parameters($ctx['params']));
    $GLOBALS['whmcsInvoice']['paymentmethod'] = 'velfypix';
    $GLOBALS['whmcsClient']['currency'] = 2;
    rejects(fn() => \Velfy\Pix\Whmcs::parameters($ctx['params']));
});

test('WHMCS boundary: uses invoice/transaction helpers and records amount/fee once', function (): void {
    $ctx = whmcsContext();
    $params = \Velfy\Pix\Whmcs::parameters($ctx['params']);
    $ctx['gateway']->generate($params, \Velfy\Pix\Whmcs::document($params));
    $payload = Json::decode($ctx['transport']->requests[0]['body']);
    parse_str((string) parse_url($payload['postbackUrl'], PHP_URL_QUERY), $query);
    $ctx = paid($ctx);
    $event = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-paid.json'));
    $adapter = new \Velfy\Pix\Whmcs();
    same('paid', $ctx['gateway']->webhook($event, ($query['token'] ?? ''), $adapter));
    same(['checkCbInvoiceID', 'checkCbTransID', 'logTransaction', 'addInvoicePayment'], $GLOBALS['whmcsHelpers']);
    same('duplicate', $ctx['gateway']->webhook($event, ($query['token'] ?? ''), $adapter));
    same(1, (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
    same(100.0, (float) $ctx['pdo']->query('SELECT amountin FROM tblaccounts')->fetchColumn());
    same(3.0, (float) $ctx['pdo']->query('SELECT fees FROM tblaccounts')->fetchColumn());
    same('Paid', $GLOBALS['whmcsInvoice']['status']);
});

test('WHMCS boundary: full supplied webhook records gross amount and net-derived fee once', function (): void {
    $ctx = whmcsContext();
    $ctx['transport']->transaction = suppliedContext()['transport']->transaction;
    $ctx = issued($ctx);
    $event = suppliedWebhook();
    $ctx['transport']->transaction = $event['data'];
    $adapter = new \Velfy\Pix\Whmcs();
    same('paid', $ctx['gateway']->webhook($event, $ctx['token'], $adapter));
    same('duplicate', $ctx['gateway']->webhook($event, $ctx['token'], $adapter));
    same(1, (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
    same('velfypix:900001', $ctx['pdo']->query('SELECT transid FROM tblaccounts')->fetchColumn());
    same(100.0, (float) $ctx['pdo']->query('SELECT amountin FROM tblaccounts')->fetchColumn());
    same(9.49, (float) $ctx['pdo']->query('SELECT fees FROM tblaccounts')->fetchColumn());
    same('Paid', $GLOBALS['whmcsInvoice']['status']);
    $logs = Json::encode($GLOBALS['whmcsLogs']);
    foreach (array_filter(['maria@example.test', '12345678909', '11999999999', $event['data']['secureId'], $ctx['token']]) as $private) {
        truth(!str_contains($logs, $private), 'Sensitive webhook data in WHMCS logs');
    }
});

test('WHMCS boundary: observed unsigned webhook credits R$5 and R$0.15 fee once', function (): void {
    $approved = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-paid-observed.json'));
    $response = Json::decode((string) file_get_contents(__DIR__ . '/fixtures/transaction-paid-observed.json'));
    $ctx = whmcsContext();
    $ctx['params']['amount'] = '5.00';
    $GLOBALS['whmcsInvoice']['balance'] = '5.00';
    $ctx['transport']->transaction = $response['data'];
    $ctx['transport']->transaction['status'] = 'pending';
    $ctx['transport']->transaction['paidAmount'] = 0;
    $ctx = issued($ctx);
    $ctx['transport']->transaction = $response['data'];
    $adapter = new \Velfy\Pix\Whmcs();
    same('paid', $ctx['gateway']->webhook($approved['body'], '', $adapter));
    same('duplicate', $ctx['gateway']->webhook($approved['body'], '', $adapter));
    same(1, (int) $ctx['pdo']->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
    same(5.0, (float) $ctx['pdo']->query('SELECT amountin FROM tblaccounts')->fetchColumn());
    same(0.15, (float) $ctx['pdo']->query('SELECT fees FROM tblaccounts')->fetchColumn());
    same('velfypix:900002', $ctx['pdo']->query('SELECT transid FROM tblaccounts')->fetchColumn());
    same('Paid', $GLOBALS['whmcsInvoice']['status']);
});

test('WHMCS module: metadata/configuration and safe customer error output', function (): void {
    $ctx = whmcsContext();
    same('1.1', velfypix_MetaData()['APIVersion']);
    same('password', velfypix_config()['velfyApiKey']['Type']);
    $callbackParams = $ctx['params'];
    unset($callbackParams['systemurl']);
    truth(\Velfy\Pix\Whmcs::gateway($callbackParams) instanceof Gateway, 'Callback cannot load configured SystemURL');
    $ctx['params']['currency'] = 'USD';
    $html = velfypix_link($ctx['params']);
    truth(str_contains($html, 'BRL'), 'Missing supported-currency explanation');
    truth(!str_contains($html, $ctx['params']['velfyApiKey']), 'Secret in module error HTML');
    truth(!str_contains(Json::encode($GLOBALS['whmcsLogs']), $ctx['params']['velfyApiKey']), 'Secret in WHMCS log');
});

fwrite(STDOUT, "\n$passed passed, $failed failed (simulated Velfy and WHMCS).\n");
exit($failed === 0 ? 0 : 1);
