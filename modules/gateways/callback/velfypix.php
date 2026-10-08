<?php
declare(strict_types=1);

require_once __DIR__ . '/../velfypix/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new \Velfy\Pix\Fault('Use POST.', 405);
    }
    if (!preg_match('/^application\/json(?:\s*;|$)/i', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))) {
        throw new \Velfy\Pix\Fault('Use Content-Type application/json.', 415);
    }
    $body = file_get_contents('php://input', false, null, 0, 131073);
    if ($body === false || strlen($body) > 131072) {
        throw new \Velfy\Pix\Fault('Payload acima do limite.', 413);
    }
    $event = \Velfy\Pix\Json::decode($body);
    $token = $_GET['token'] ?? '';
    if (!is_string($token)) {
        throw new \Velfy\Pix\Fault('Token inválido.', 401);
    }
    require_once __DIR__ . '/../../../init.php';
    require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
    require_once __DIR__ . '/../../../includes/invoicefunctions.php';
    $params = getGatewayVariables('velfypix');
    if (empty($params['type'])) {
        throw new \Velfy\Pix\Fault('Gateway indisponível.', 503);
    }
    $gateway = \Velfy\Pix\Whmcs::gateway($params);
    $result = $gateway->webhook($event, $token, new \Velfy\Pix\Whmcs());
    http_response_code(200);
    echo \Velfy\Pix\Json::encode(['received' => true, 'result' => $result]);
} catch (\Velfy\Pix\Fault $exception) {
    http_response_code($exception->httpStatus);
    \Velfy\Pix\Whmcs::log('Callback rejected', ['reason' => $exception->getMessage(), 'http_status' => $exception->httpStatus]);
    echo \Velfy\Pix\Json::encode(['received' => false, 'message' => $exception->getMessage()]);
} catch (\Throwable $exception) {
    http_response_code(503);
    \Velfy\Pix\Whmcs::log('Callback error', ['error_type' => get_class($exception)]);
    echo \Velfy\Pix\Json::encode(['received' => false, 'message' => 'Falha temporária ao processar a notificação.']);
}

