<?php
declare(strict_types=1);

// Visual preview only. In an installed WHMCS, whmcs.json drives the native UI.
$metadata = json_decode((string) file_get_contents(dirname(__DIR__) . '/modules/gateways/velfypix/whmcs.json'), true, 32, JSON_THROW_ON_ERROR);
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$description = $metadata['description'];
$category = ['payments' => 'Payments'][$metadata['category']] ?? $metadata['category'];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Velfy PIX · prévia da ativação no WHMCS</title>
    <style>
        *{box-sizing:border-box}body{margin:0;color:#4a4a4a;font:16px/1.55 Arial,Helvetica,sans-serif;background:#e8ebef}.preview-banner{position:relative;z-index:3;padding:10px 20px;background:#283340;color:#fff;text-align:center;font-size:12px}.apps-header{height:86px;background:white;padding:25px 5vw;border-bottom:1px solid #d8dde2}.apps-header strong{display:block;font-size:22px;color:#343a40}.apps-header span{font-size:12px;color:#777}.apps-background{position:absolute;top:160px;left:5vw;right:5vw;display:grid;grid-template-columns:repeat(3,1fr);gap:22px}.app-placeholder{height:150px;padding:28px;border:1px solid #d5d8dd;background:#fff;border-radius:4px;color:#868b93}.backdrop{position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:1}.preview-modal{position:relative;z-index:2;max-width:1100px;margin:42px auto 36px;width:calc(100% - 48px);border-radius:6px;box-shadow:0 6px 22px #0003;background:#fff;overflow:hidden}.preview-close{position:absolute;top:18px;right:24px;font-size:26px;color:#b3b3b3;text-decoration:none;line-height:1}.preview-body{display:grid;grid-template-columns:minmax(0,1fr) 282px;gap:54px;padding:60px}.module-logo{display:block;width:260px;max-width:100%;height:auto;margin:0 0 32px}.tagline{font-size:22px;line-height:1.55;margin:0 0 23px;font-weight:400}.long-description{font-size:17px;line-height:1.6;margin:0 0 26px}.feature-heading{font-size:22px;font-weight:400;margin:0 0 9px}.feature-list{font-size:16px;line-height:1.65;margin:0;padding-left:24px}.module-info{align-self:start;background:#f6f6f6;padding:24px}.module-info h1{font-size:28px;font-weight:400;line-height:1.35;margin:0 0 24px;color:#3f3f3f}.module-info h2{font-size:16px;margin:22px 0 0;font-weight:700}.module-info p{margin:0;font-size:15px;overflow-wrap:anywhere}.module-info a{color:#354b82;text-decoration:none}.module-info a:hover{text-decoration:underline}.module-action{display:block;width:100%;margin-top:25px;padding:10px;border:0;border-radius:4px;background:#54b453;color:white!important;text-align:center;font-size:15px;font-weight:700;text-decoration:none!important}.preview-footer{border-top:1px solid #e0e0e0;background:#f7f8f9;padding:18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.preview-footer p{margin:0;color:#777;font-size:11px;line-height:1.55}.close-button{flex-shrink:0;padding:8px 14px;color:#444;border:1px solid #ccc;border-radius:4px;background:#fff;text-decoration:none;font-size:15px}@media(max-width:900px){.preview-body{padding:46px 34px;gap:30px;grid-template-columns:minmax(0,1fr) 240px}.tagline{font-size:20px}.long-description{font-size:16px}.feature-list{font-size:15px}.module-info{padding:20px}}@media(max-width:680px){.preview-modal{margin:22px auto;width:calc(100% - 24px)}.preview-body{display:flex;flex-direction:column;gap:28px;padding:44px 24px 28px}.module-logo{width:235px;margin-bottom:22px}.tagline{font-size:20px}.module-info{width:100%}.module-info h1{font-size:26px;margin-bottom:16px}.module-info h2{margin-top:14px}.preview-footer{padding:15px}.preview-banner{font-size:10px}.apps-header{padding:22px}.apps-background{grid-template-columns:1fr}.preview-close{right:18px;top:14px}}
    </style>
</head>
<body>
<div class="preview-banner">PRÉVIA LOCAL · APPS &amp; INTEGRATIONS · A ATIVAÇÃO REAL É FEITA NO WHMCS</div>
<header class="apps-header"><strong>Apps &amp; Integrations</strong><span><?= $escape($category) ?></span></header>
<div class="apps-background" aria-hidden="true"><div class="app-placeholder">Pagamentos</div><div class="app-placeholder">Transferência bancária</div><div class="app-placeholder">Outros gateways</div></div>
<div class="backdrop" aria-hidden="true"></div>
<main class="preview-modal" aria-labelledby="module-title">
    <a class="preview-close" href="/" aria-label="Fechar prévia">×</a>
    <div class="preview-body">
        <div class="module-description">
            <img class="module-logo" src="/modules/gateways/velfypix/<?= $escape($metadata['logo']['filename']) ?>?v=0.1.6" alt="Velfy" width="384" height="73">
            <p class="tagline"><?= $escape($description['tagline']) ?></p>
            <p class="long-description"><?= $escape($description['long']) ?></p>
            <h2 class="feature-heading">Recursos</h2>
            <ul class="feature-list">
                <?php foreach ($description['features'] as $feature): ?><li><?= $escape($feature) ?></li><?php endforeach ?>
            </ul>
        </div>
        <aside class="module-info">
            <h1 id="module-title"><?= $escape($description['name']) ?></h1>
            <h2>Categoria</h2><p><?= $escape($category) ?></p>
            <h2>Suporte</h2><p><a href="<?= $escape($metadata['support']['homepage']) ?>" target="_blank" rel="noopener noreferrer">Homepage</a></p>
            <h2>Desenvolvimento da integração</h2><p><?= $escape($metadata['authors'][0]['name']) ?></p>
            <a class="module-action" href="/">Ver demonstração PIX</a>
        </aside>
    </div>
    <footer class="preview-footer"><p>Prévia visual com os metadados do módulo. O WHMCS controla o layout e os botões Ativar/Gerenciar na instalação real.</p><a class="close-button" href="/">Fechar</a></footer>
</main>
</body>
</html>
