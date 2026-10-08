<?php
declare(strict_types=1);

namespace Velfy\Pix;

final class Renderer
{
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function message(string $message): string
    {
        return '<div class="alert alert-info" role="status">' . self::escape($message) . '</div>';
    }

    public static function payment(array $record, string $assetBase): string
    {
        if ($record['state'] === 'paid') {
            return self::message('Pagamento PIX confirmado. Atualize a fatura para ver o status.');
        }
        $pix = Json::decode((string) $record['pix_json']);
        if (($pix['remote_status'] ?? '') === 'paid') {
            return self::message('Pagamento recebido. A confirmação na fatura está em processamento.');
        }
        $expiration = $pix['expiration'] ?? null;
        if ($expiration !== null && new \DateTimeImmutable($expiration) <= new \DateTimeImmutable()) {
            return self::message('Este PIX venceu. Contate o financeiro para emitir outra cobrança.');
        }
        $assetBase = rtrim($assetBase, '/');
        $amount = number_format((int) $record['amount_cents'] / 100, 2, ',', '.');
        $invoiceId = (int) $record['invoice_id'];
        $id = 'velfypix-' . $invoiceId;
        $expiryHtml = $expiration === null ? '' : '<p class="velfypix-expiry">Válido até <time datetime="'
            . self::escape($expiration) . '">' . self::escape((new \DateTimeImmutable($expiration))->format('d/m/Y H:i T')) . '</time></p>';
        $card = '<section class="velfypix-card" data-velfypix aria-label="Pagamento PIX Velfy">'
            . '<div class="velfypix-heading"><span class="velfypix-brand"><img class="velfypix-logo" src="'
            . self::escape($assetBase . '/../logo.png') . '" alt="Velfy" width="384" height="73"><span>PIX</span></span>'
            . '<span class="velfypix-status" role="status">Aguardando pagamento</span></div>'
            . '<h3 id="' . $id . '-title">Pague com PIX</h3><p class="velfypix-subtitle">Escaneie o QR Code no app do seu banco ou copie o código abaixo.</p>'
            . '<div class="velfypix-payment-body"><div class="velfypix-scan"><strong class="velfypix-amount">R$ ' . $amount . '</strong>'
            . '<div class="velfypix-qr" role="img" aria-label="QR Code para pagamento" data-velfypix-qr></div>'
            . '<noscript><p>Use o código copia e cola para pagar.</p></noscript>' . $expiryHtml . '</div>'
            . '<div class="velfypix-instructions"><label for="' . $id . '">PIX copia e cola</label>'
            . '<textarea id="' . $id . '" class="velfypix-code" rows="3" readonly spellcheck="false">'
            . self::escape((string) $pix['code']) . '</textarea>'
            . '<button type="button" class="velfypix-copy" data-velfypix-copy>Copiar código PIX</button>'
            . '<p class="velfypix-feedback" aria-live="polite" data-velfypix-feedback></p>'
            . '<p class="velfypix-footnote">Após pagar, a confirmação será registrada nesta fatura.</p>'
            . '<a class="velfypix-refresh" href="viewinvoice.php?id=' . $invoiceId . '">Atualizar fatura</a>'
            . '</div></div></section>';
        // WHMCS inserts paymentbutton in the invoice header, which must stay compact.
        // Native details remains usable if JavaScript or the dialog API is unavailable.
        return '<link rel="stylesheet" href="' . self::escape($assetBase . '/pix.css?v=0.1.6') . '">'
            . '<details class="velfypix-widget" data-velfypix-widget><summary class="velfypix-launch">'
            . '<img src="' . self::escape($assetBase . '/../logo.png') . '" alt="Velfy" width="384" height="73">'
            . '<span>Pagar com PIX</span></summary><p class="velfypix-launch-hint">QR Code e PIX copia e cola</p>'
            . $card . '</details><script defer src="' . self::escape($assetBase . '/qrcode.min.js') . '"></script>'
            . '<script defer src="' . self::escape($assetBase . '/pix.js?v=0.1.6') . '"></script>';
    }
}
