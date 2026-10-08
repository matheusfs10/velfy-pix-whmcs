<?php
declare(strict_types=1);

foreach (['Support', 'ApiClient', 'Storage', 'Gateway', 'Renderer', 'Whmcs'] as $velfyLibrary) {
    require_once __DIR__ . '/lib/' . $velfyLibrary . '.php';
}
unset($velfyLibrary);

