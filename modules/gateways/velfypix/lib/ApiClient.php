<?php
declare(strict_types=1);

namespace Velfy\Pix;

interface Transport
{
    public function send(string $method, string $url, array $headers, ?string $body): array;
}

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        Settings::httpsUrl($url);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new Fault('Não foi possível iniciar a conexão com a Velfy.', 503);
        }
        $responseBody = '';
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        try {
            curl_setopt_array($handle, $options);
            if (curl_exec($handle) === false) {
                // No upstream body, URL, credentials or personal data in errors.
                throw new Fault('Falha na comunicação com a Velfy (cURL ' . curl_errno($handle) . '). Tente novamente.', 503);
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $responseBody];
        } finally {
            curl_close($handle);
        }
    }
}

final class ApiClient
{
    private Settings $settings;
    private Transport $transport;

    public function __construct(Settings $settings, Transport $transport)
    {
        $this->settings = $settings;
        $this->transport = $transport;
    }

    public function create(array $payload, string $idempotencyKey): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $idempotencyKey)) {
            throw new Fault('Chave de idempotência inválida.');
        }
        return $this->request('POST', '/api/v1/transactions', $payload, ['Idempotency-Key: ' . $idempotencyKey]);
    }

    public function get(string $transactionId): array
    {
        self::transactionId($transactionId);
        return $this->request('GET', '/api/v1/transactions/' . rawurlencode($transactionId), null);
    }

    public static function transactionId($id): string
    {
        if ((!is_string($id) && !is_int($id)) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', (string) $id)) {
            throw new Fault('Identificador da transação inválido.', 400);
        }
        return (string) $id;
    }

    private function request(string $method, string $path, ?array $payload, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $this->settings->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);
        $response = $this->transport->send(
            $method, $this->settings->apiUrl . $path, $headers, $payload === null ? null : Json::encode($payload)
        );
        $status = $response['status'] ?? 0;
        if (!in_array($status, [200, 201], true)) {
            throw new Fault('A Velfy não confirmou a operação (HTTP ' . (int) $status . ').', 502);
        }
        try {
            $envelope = Json::decode((string) ($response['body'] ?? ''));
        } catch (Fault $exception) {
            throw new Fault('A Velfy retornou uma resposta JSON inválida.', 502);
        }
        if (($envelope['success'] ?? null) !== true || !is_array($envelope['data'] ?? null)) {
            throw new Fault('Resposta da Velfy fora do contrato esperado.', 502);
        }
        return $envelope['data'];
    }
}

