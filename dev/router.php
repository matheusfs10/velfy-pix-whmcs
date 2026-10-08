<?php
declare(strict_types=1);

// Local demonstration only. Excluded from the installation package.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Demonstração disponível somente em localhost.');
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/ativacao') {
    require __DIR__ . '/activation.php';
    return;
}
// Optional private diagnostic; the public checkout serves only simulated data.
if ($path === '/teste-real' && is_file(__DIR__ . '/live-preview.php')) {
    require __DIR__ . '/live-preview.php';
    return;
}
if ($path === '/modules/gateways/velfypix/logo.png') {
    header('Content-Type: image/png');
    readfile(dirname(__DIR__) . '/modules/gateways/velfypix/logo.png');
    return;
}
if ($path === '/viewinvoice.php' && ($_GET['id'] ?? '') === '12345') {
    header('Location: /', true, 303);
    exit;
}
if (is_string($path) && preg_match('~^/modules/gateways/velfypix/assets/(pix\.css|pix\.js|qrcode\.min\.js)$~D', $path, $matches)) {
    header('Content-Type: ' . ($matches[1] === 'pix.css' ? 'text/css' : 'application/javascript'));
    readfile(dirname(__DIR__) . $path);
    return;
}
if (!in_array($path, ['/', '/fatura', '/simulate-payment', '/reset-demo'], true)) {
    http_response_code(404);
    exit('Página não encontrada.');
}
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
$_SESSION['velfy_csrf'] = $_SESSION['velfy_csrf'] ?? bin2hex(random_bytes(32));
if (!in_array($path, ['/', '/fatura'], true) && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['velfy_csrf'], $_POST['csrf']))) {
    http_response_code(403);
    exit('Ação não autorizada.');
}

require_once dirname(__DIR__) . '/modules/gateways/velfypix/bootstrap.php';
require_once dirname(__DIR__) . '/tests/Fakes.php';

use Velfy\Pix\ApiClient;
use Velfy\Pix\Gateway;
use Velfy\Pix\Json;
use Velfy\Pix\Renderer;
use Velfy\Pix\Settings;
use Velfy\Pix\Storage;
use Velfy\Pix\Tests\FakePayments;
use Velfy\Pix\Tests\FakeTransport;

$directory = dirname(__DIR__) . '/var';
if (!is_dir($directory)) mkdir($directory, 0700, true);
$databasePath = $directory . '/demo-' . ($path === '/fatura' ? 'invoice-' : '') . hash('sha256', session_id()) . '.sqlite';
if ($path === '/reset-demo') {
    if (is_file($databasePath)) unlink($databasePath);
    unset($_SESSION['velfy_payments'], $_SESSION['velfy_callback_token']);
    header('Location: /', true, 303);
    exit;
}
$params = [
    'velfyApiUrl' => 'https://api.velfy.test',
    'velfyApiKey' => 'vfy_local_fake_key',
    'velfyReferencePrefix' => 'local',
    'systemurl' => 'https://whmcs.example.test',
    'invoiceid' => 12345,
    'velfyClientId' => 7,
    'amount' => $path === '/fatura' ? '74.90' : '100.00',
    'currency' => 'BRL',
    'description' => 'Fatura #12345',
    'clientdetails' => ['firstname' => 'Maria', 'lastname' => 'Silva', 'email' => 'maria@example.test', 'phonenumber' => '11999999999'],
];
$settings = new Settings($params);
$transport = new FakeTransport();
$transport->transaction['pix']['expirationDate'] = (new DateTimeImmutable('+1 day'))->format(DATE_ATOM);
$storage = new Storage(new PDO('sqlite:' . $databasePath));
$storage->install();
$gateway = new Gateway($settings, new ApiClient($settings, $transport), $storage);
$payments = new FakePayments();
if (isset($_SESSION['velfy_payments'])) {
    $payments->ledger = $_SESSION['velfy_payments'];
    if ($payments->ledger !== []) $payments->currentInvoice['status'] = 'Paid';
}
$record = $gateway->generate($params, '12345678909');
if ($transport->requests !== []) {
    $payload = Json::decode($transport->requests[0]['body']);
    parse_str((string) parse_url($payload['postbackUrl'], PHP_URL_QUERY), $query);
    $_SESSION['velfy_callback_token'] = $query['token'] ?? '';
}
if ($path === '/simulate-payment') {
    $transport->transaction['status'] = 'paid';
    $transport->transaction['paidAmount'] = 10000;
    $transport->transaction['fee'] = ['netAmount' => 9700];
    $event = Json::decode((string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/webhook-paid.json'));
    $gateway->webhook($event, $_SESSION['velfy_callback_token'], $payments);
    $_SESSION['velfy_payments'] = $payments->ledger;
    header('Location: /', true, 303);
    exit;
}
$html = Renderer::payment($record, '/modules/gateways/velfypix/assets');
$csrf = htmlspecialchars($_SESSION['velfy_csrf'], ENT_QUOTES, 'UTF-8');
$status = $path !== '/fatura' && $payments->ledger !== [] ? 'Paga' : 'Em aberto';
if ($path === '/fatura') {
    $html = str_replace('viewinvoice.php?id=12345', '/fatura', $html);
    require __DIR__ . '/invoice-preview.php';
    return;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Velfy PIX · demonstração local</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f6f6fa;color:#282633;font-family:system-ui,-apple-system,sans-serif}.demo-banner{padding:12px 16px;background:#30263e;color:#fff;text-align:center;font-size:11px;letter-spacing:.3px}.demo-header{max-width:960px;margin:auto;padding:24px 28px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e3e2ec}.demo-header{gap:12px;flex-wrap:wrap}.demo-header .demo-logo{display:block;width:130px;max-width:100%;height:auto;flex-shrink:0}.demo-header span{font-size:12px;color:#737081}.demo-main{max-width:900px;margin:36px auto;padding:0 24px;display:grid;grid-template-columns:1fr 460px;gap:40px;align-items:start}.demo-invoice{padding-top:45px}.demo-overline{color:#867c98;font-size:10px;font-weight:650;letter-spacing:1.4px}.demo-invoice h1{font-size:32px;letter-spacing:-1px;margin:12px 0}.demo-invoice>p{font-size:13px;line-height:1.8;color:#7a7486}.demo-detail{border-top:1px solid #dedce9;padding:16px 0;display:flex;justify-content:space-between;font-size:12px}.demo-detail strong{font-weight:650}.demo-controls{margin:30px 0;padding:20px;border:1px dashed #cbc4dd;border-radius:12px}.demo-controls p{margin:0 0 14px;color:#81788c;font-size:11px;line-height:1.6}.demo-controls form{margin:8px 0}.demo-controls button{cursor:pointer;border:1px solid #d2c8e9;border-radius:7px;padding:10px 14px;background:#eee8fb;color:#5934a4;font-size:11px}.demo-payment .velfypix-card{margin:0}.demo-payment .alert{background:#e9f4ec;border:1px solid #b4d3bb;padding:24px;border-radius:12px;font-size:14px;line-height:1.6}.demo-footer{text-align:center;margin:28px 20px 36px;color:#9992a4;font-size:10px}@media(max-width:780px){.demo-main{display:flex;flex-direction:column;gap:20px;max-width:500px;margin:16px auto;padding:0 18px}.demo-invoice{padding-top:0;width:100%}.demo-invoice h1{font-size:26px}.demo-controls{margin:14px 0}.demo-payment{width:100%}.demo-header{padding:20px}.demo-detail{padding:10px 0}}
    </style>
</head>
<body>
<div class="demo-banner">DEMONSTRAÇÃO LOCAL · DADOS SIMULADOS · SEM COBRANÇA REAL</div>
<header class="demo-header"><img class="demo-logo" src="/modules/gateways/velfypix/logo.png?v=0.1.6" width="384" height="73" alt="Velfy"><span><a href="/ativacao">Prévia da ativação</a> · Área do cliente / Faturas</span></header>
<main class="demo-main">
    <div class="demo-invoice">
        <div class="demo-overline">PAGAMENTO DA FATURA</div>
        <h1>Fatura #12345</h1>
        <p>Olá, Maria. Você pode pagar esta fatura com PIX pelo aplicativo do seu banco.</p>
        <div class="demo-detail"><span>Cliente</span><strong>Maria Silva</strong></div>
        <div class="demo-detail"><span>Valor da fatura</span><strong>R$ 100,00</strong></div>
        <div class="demo-detail"><span>Status</span><strong><?= $status ?></strong></div>
        <div class="demo-controls">
            <p>Controles da demonstração. O QR Code é fictício. Nenhuma chamada é enviada para a Velfy.</p>
            <form action="/simulate-payment" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button type="submit">Simular confirmação do pagamento</button></form>
            <form action="/reset-demo" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button type="submit">Reiniciar demonstração</button></form>
        </div>
    </div>
    <div class="demo-payment"><?= $html ?></div>
</main>
<footer class="demo-footer">Módulo Velfy PIX para WHMCS · Versão 0.1.6</footer>
</body>
</html>
