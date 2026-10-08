<?php
declare(strict_types=1);

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "A extensão ZIP é necessária para empacotar.\n");
    exit(1);
}
$root = dirname(__DIR__);
$output = $root . '/dist';
if (!is_dir($output)) mkdir($output, 0755, true);
$zip = new ZipArchive();
$path = $output . '/velfy-pix-whmcs-0.1.6.zip';
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Falha ao criar o pacote.');
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/modules', FilesystemIterator::SKIP_DOTS));
$manifest = [];
foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $relative = substr($file->getPathname(), strlen($root) + 1);
    $zip->addFile($file->getPathname(), $relative);
    $manifest[$relative] = hash_file('sha256', $file->getPathname());
}
$zip->addFile($root . '/README.md', 'README.md');
$zip->addFile($root . '/docs/contrato-api.md', 'docs/contrato-api.md');
$zip->addFromString('SHA256SUMS.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$zip->close();
fwrite(STDOUT, $path . "\n");
