<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    exit("Usage: php build.php OUTPUT_DIRECTORY\n");
}
$output = $argv[1];
putenv('TZ=UTC');
date_default_timezone_set('UTC');
if (!is_dir($output) && !mkdir($output, 0755, true)) {
    throw new RuntimeException('Cannot create output directory');
}
$source = __DIR__ . '/extension';
$files = ['system/library/Feedback.php' => dirname(__DIR__) . '/common/Feedback.php',
    'LICENSE' => dirname(__DIR__) . '/LICENSE', 'README.md' => __DIR__ . '/README.md'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    $files[substr($file->getPathname(), strlen($source) + 1)] = $file->getPathname();
}
ksort($files);
$archive = new ZipArchive();
$path = rtrim($output, '/') . '/limecontrol_feedback.ocmod.zip';
if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot open ZIP');
}
foreach ($files as $name => $file) {
    if (!$archive->addFile($file, $name) || !$archive->setMtimeName($name, 946684800)
        || !$archive->setCompressionName($name, ZipArchive::CM_STORE)
        || !$archive->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
        throw new RuntimeException('Cannot include ' . $name);
    }
}
if (!$archive->close()) {
    throw new RuntimeException('Cannot save ZIP');
}
echo $path, PHP_EOL;
