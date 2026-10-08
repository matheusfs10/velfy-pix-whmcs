<?php
declare(strict_types=1);

// Simulated invoice with the same header insertion point used by WHMCS themes.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Velfy PIX · prévia da fatura</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f1f1f1;color:#292d31;font:14px/1.5 Arial,Helvetica,sans-serif}.preview-banner{padding:9px 16px;text-align:center;background:#30263e;color:#fff;font-size:11px}.invoice-container{max-width:850px;margin:20px auto;padding:70px;border:1px solid #ccc;border-radius:6px;background:#fff}.row{display:flex;margin:0 -15px}.invoice-col{width:50%;padding:0 15px}.text-center{text-align:center}.invoice-header h2{margin:0 0 8px;font-size:28px;line-height:1.3}.invoice-header h3{margin:0;font-size:24px;line-height:1.4}.invoice-status{margin:14px 0 3px;font-size:24px;font-weight:700;color:#c00}.small-text{font-size:14px}.invoice-details{padding:24px 0}.invoice-details strong{display:block;margin-bottom:4px}.invoice-items{margin:20px 0;border:1px solid #ddd;border-radius:5px;overflow:hidden}.invoice-items h3{margin:0;padding:12px 16px;background:#f6f6f6;border-bottom:1px solid #ddd;font-size:18px}.invoice-items table{width:100%;border-collapse:collapse}.invoice-items th,.invoice-items td{padding:12px 16px;text-align:left}.invoice-items th{background:#fafafa}.invoice-items th:last-child,.invoice-items td:last-child{text-align:right;white-space:nowrap}.invoice-items tfoot{border-top:1px solid #ddd;font-weight:700}.invoice-note{font-size:12px;color:#777}hr{margin:20px 0;border:0;border-top:1px solid #ddd}@media(max-width:767px){.invoice-container{margin:16px;padding:26px 22px}.row{display:block;margin:0}.invoice-col{width:100%;padding:0}.invoice-header .invoice-col:first-child{text-align:center}.invoice-header h2{font-size:24px}.invoice-header h3{font-size:20px}.invoice-status{margin-top:22px}.invoice-details .invoice-col+.invoice-col{margin-top:18px}.invoice-items th,.invoice-items td{padding:10px;font-size:13px}}
    </style>
</head>
<body>
    <div class="preview-banner">PRÉVIA LOCAL · FATURA SIMULADA · SEM COBRANÇA REAL</div>
    <main class="container-fluid invoice-container">
        <div class="row invoice-header">
            <div class="col-12 col-sm-6 invoice-col"><h2>Central do Cliente</h2><h3>Fatura Proforma #12345</h3></div>
            <div class="col-12 col-sm-6 invoice-col text-center">
                <div class="invoice-status">EM ABERTO</div>
                <div class="small-text">Vencimento: 8 de outubro de 2026</div>
                <div class="payment-btn-container d-print-none" align="center"><?= $html ?></div>
            </div>
        </div>
        <hr>
        <div class="row invoice-details">
            <div class="col-12 col-sm-6 invoice-col"><strong>Faturado para</strong>Cliente de demonstração</div>
            <div class="col-12 col-sm-6 invoice-col"><strong>Método de pagamento</strong>Velfy PIX</div>
        </div>
        <section class="invoice-items" aria-label="Itens da fatura">
            <h3>Itens da fatura</h3>
            <table><thead><tr><th>Descrição</th><th>Valor</th></tr></thead><tbody><tr><td>Serviço de demonstração</td><td>R$ 74,90</td></tr></tbody><tfoot><tr><td>Total</td><td>R$ 74,90</td></tr></tfoot></table>
        </section>
        <p class="invoice-note">Esta página reproduz a posição do botão de pagamento no cabeçalho para conferir o layout. O QR Code e os dados são fictícios.</p>
    </main>
</body>
</html>
