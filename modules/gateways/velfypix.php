<?php
declare(strict_types=1);

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Acesso direto não permitido.');
}

require_once __DIR__ . '/velfypix/bootstrap.php';

function velfypix_MetaData(): array
{
    return ['DisplayName' => 'Velfy PIX', 'APIVersion' => '1.1', 'DisableLocalCreditCardInput' => true];
}

function velfypix_config(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Velfy PIX'],
        'velfyApiKey' => ['FriendlyName' => 'Chave API (Bearer)', 'Type' => 'password', 'Size' => '60'],
        'velfyApiUrl' => ['FriendlyName' => 'Origem da API', 'Type' => 'text', 'Size' => '50', 'Default' => 'https://api.velfy.com.br', 'Description' => 'URL HTTPS, sem /api/v1. Confirme a origem com a Velfy.'],
        'velfyExpiresInDays' => ['FriendlyName' => 'Validade do PIX (dias)', 'Type' => 'text', 'Size' => '3', 'Default' => '1'],
        'velfyDocumentFieldId' => ['FriendlyName' => 'ID do campo CPF/CNPJ', 'Type' => 'text', 'Size' => '8', 'Description' => 'ID de um campo personalizado de cliente. Vazio usa o Tax ID do cadastro.'],
        'velfyReferencePrefix' => ['FriendlyName' => 'Prefixo da instalação', 'Type' => 'text', 'Size' => '48', 'Description' => 'Identificador único para este WHMCS. Vazio usa um hash da URL do WHMCS.'],
    ];
}

function velfypix_link(array $params): string
{
    try {
        $params = \Velfy\Pix\Whmcs::parameters($params);
        $gateway = \Velfy\Pix\Whmcs::gateway($params);
        $record = $gateway->generate($params, \Velfy\Pix\Whmcs::document($params));
        return \Velfy\Pix\Renderer::payment($record, rtrim((string) $params['systemurl'], '/') . '/modules/gateways/velfypix/assets');
    } catch (\Velfy\Pix\Fault $exception) {
        \Velfy\Pix\Whmcs::log('PIX unavailable', ['invoice_id' => (int) ($params['invoiceid'] ?? 0), 'reason' => $exception->getMessage()]);
        return \Velfy\Pix\Renderer::message($exception->getMessage());
    } catch (\Throwable $exception) {
        \Velfy\Pix\Whmcs::log('Module error', ['invoice_id' => (int) ($params['invoiceid'] ?? 0), 'error_type' => get_class($exception)]);
        return \Velfy\Pix\Renderer::message('Não foi possível disponibilizar o PIX agora. Tente novamente ou contate o financeiro.');
    }
}

