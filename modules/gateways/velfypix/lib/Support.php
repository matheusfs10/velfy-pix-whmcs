<?php
declare(strict_types=1);

namespace Velfy\Pix;

final class Fault extends \RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 422)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

final class Json
{
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new Fault('JSON inválido.', 400);
        }
        if (!is_array($decoded)) {
            throw new Fault('O JSON deve ser um objeto.', 400);
        }
        return $decoded;
    }
}

final class Money
{
    public const MAX_CENTS = 99999999999;

    public static function cents(string $decimal): int
    {
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/D', $decimal, $matches)) {
            throw new Fault('Valor monetário inválido. Use duas casas decimais e ponto.');
        }
        $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw new Fault('Valor acima do limite do módulo.');
        }
        return $cents;
    }

    public static function integer($value, bool $allowZero = false): int
    {
        if (!is_int($value) || $value < ($allowZero ? 0 : 1) || $value > self::MAX_CENTS) {
            throw new Fault('A API deve retornar o valor em centavos inteiros.', 502);
        }
        return $value;
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

final class Settings
{
    public string $apiUrl;
    public string $apiKey;
    public string $systemUrl;
    public string $referencePrefix;
    public int $expiresInDays;

    public function __construct(array $params)
    {
        $this->apiUrl = rtrim((string) ($params['velfyApiUrl'] ?? 'https://api.velfy.com.br'), '/');
        $this->systemUrl = rtrim((string) ($params['systemurl'] ?? ''), '/');
        self::httpsUrl($this->apiUrl);
        self::httpsUrl($this->systemUrl);
        $apiParts = parse_url($this->apiUrl);
        if (!empty($apiParts['path'])) {
            throw new Fault('A URL da API deve conter somente a origem, sem /api/v1.');
        }
        $this->apiKey = trim((string) ($params['velfyApiKey'] ?? ''));
        if ($this->apiKey === '' || preg_match('/[\x00-\x20\x7f]/', $this->apiKey)) {
            throw new Fault('Configure uma chave Bearer válida no gateway.');
        }
        $days = (string) ($params['velfyExpiresInDays'] ?? '1');
        if (!preg_match('/^[1-9]\d?$/D', $days)) {
            throw new Fault('A validade do PIX deve ser de 1 a 99 dias.');
        }
        $this->expiresInDays = (int) $days;
        $prefix = trim((string) ($params['velfyReferencePrefix'] ?? ''));
        $this->referencePrefix = $prefix !== '' ? $prefix : 'whmcs-' . substr(hash('sha256', $this->systemUrl), 0, 12);
        if (!preg_match('/^[A-Za-z0-9_-]{1,48}$/D', $this->referencePrefix)) {
            throw new Fault('O prefixo deve ter até 48 letras, números, hífens ou sublinhados.');
        }
    }

    public static function httpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new Fault('Configure uma URL HTTPS válida, sem credenciais, query ou fragmento.');
        }
    }
}

final class Customer
{
    public static function build(array $client, string $document): array
    {
        $name = trim((string) ($client['firstname'] ?? '') . ' ' . (string) ($client['lastname'] ?? ''));
        $email = trim((string) ($client['email'] ?? ''));
        if ($name === '' || strlen($name) > 200 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Fault('Atualize o nome e o e-mail do cadastro para pagar com PIX.');
        }
        $phone = preg_replace('/\D/', '', (string) ($client['phonenumber'] ?? ''));
        if ((strlen($phone) === 12 || strlen($phone) === 13) && strncmp($phone, '55', 2) === 0) {
            $phone = substr($phone, 2);
        }
        if (!preg_match('/^[1-9]\d{9,10}$/D', $phone)) {
            throw new Fault('Atualize o telefone com DDD no cadastro para pagar com PIX.');
        }
        $number = preg_replace('/[.\s\/-]/', '', $document);
        // Numeric CPF/CNPJ only until the Velfy document contract is confirmed.
        if (!preg_match('/^(?:\d{11}|\d{14})$/D', $number) || preg_match('/^(\d)\1+$/D', $number)
            || !self::validDocument($number)) {
            throw new Fault('Informe um CPF ou CNPJ numérico válido no cadastro.');
        }
        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'document' => ['type' => strlen($number) === 11 ? 'cpf' : 'cnpj', 'number' => $number],
        ];
    }

    private static function validDocument(string $number): bool
    {
        if (strlen($number) === 11) {
            for ($length = 9; $length <= 10; $length++) {
                $sum = 0;
                for ($index = 0; $index < $length; $index++) {
                    $sum += (int) $number[$index] * ($length + 1 - $index);
                }
                $digit = ($sum * 10) % 11;
                if (($digit === 10 ? 0 : $digit) !== (int) $number[$length]) {
                    return false;
                }
            }
            return true;
        }
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += (int) $number[$index] * $weight;
            }
            $remainder = $sum % 11;
            if (($remainder < 2 ? 0 : 11 - $remainder) !== (int) $number[count($weights)]) {
                return false;
            }
        }
        return true;
    }
}

