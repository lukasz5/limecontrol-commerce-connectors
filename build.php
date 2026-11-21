<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    exit("Usage: php commerce-connectors/build.php OUTPUT_DIRECTORY\n");
}
$out = $argv[1];
// ZIP DOS timestamps are local wall time. Normalize the builder environment for reproducibility.
putenv('TZ=UTC');
date_default_timezone_set('UTC');
if (!is_dir($out) && !mkdir($out, 0755, true)) {
    throw new RuntimeException('Unable to create output directory');
}
$hosted = [
    'shopify' => ['Shopify.php', 'bootstrap.php', 'worker.php', 'public/index.php'],
    'shoper' => ['Shoper.php', 'bootstrap.php', 'cli.php', 'public/index.php'],
];
foreach (['woocommerce','prestashop','shopify','shoper'] as $source) {
    $name = $source === 'prestashop' ? 'limecontrolfeedback' : 'limecontrol-feedback-' . $source;
    $path = $out . '/limecontrol-feedback-' . $source . '-0.1.0.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create archive');
    }
    $entries = ['LICENSE' => __DIR__ . '/LICENSE', 'common/Feedback.php' => __DIR__ . '/common/Feedback.php'];
    if (isset($hosted[$source])) {
        $entries['hosted-runtime/RemoteConnector.php'] = __DIR__ . '/hosted-runtime/RemoteConnector.php';
        foreach (array_merge(['README.md'], $hosted[$source]) as $file) {
            $entries[$file] = __DIR__ . '/' . $source . '/' . $file;
        }
    } else {
        $entries['README.md'] = __DIR__ . '/docs/woocommerce-prestashop-0.1.0.md';
        $root = __DIR__ . '/' . $source . '/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $entries[substr($file->getPathname(), strlen($root))] = $file->getPathname();
            }
        }
    }
    ksort($entries, SORT_STRING);
    foreach ($entries as $relative => $file) {
        $entry = $name . '/' . $relative;
        if (!$zip->addFile($file, $entry)
            || !$zip->setMtimeName($entry, 946684800)
            || !$zip->setCompressionName($entry, ZipArchive::CM_STORE)
            || !$zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
            throw new RuntimeException('Unable to package ' . $entry);
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('Unable to finish archive');
    }
    echo $path, PHP_EOL;
}
// OpenCart derives its extension code from the installer archive's basename.
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/opencart/build.php') . ' ' . escapeshellarg($out), $status);
exit($status);
