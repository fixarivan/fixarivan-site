<?php
/**
 * CLI: zip critical storage (SQLite + JSON backups + company profile + invoices + analytics for AI).
 * Usage: php tools/backup.php
 * Optional: php tools/backup.php /path/to/outdir
 *
 * Requires PHP zip extension for best results.
 */
declare(strict_types=1);

/**
 * @return int number of files added
 */
function backup_add_directory(ZipArchive $zip, string $absDir, string $zipPrefix): int
{
    $absDir = realpath($absDir);
    if ($absDir === false || !is_dir($absDir)) {
        return 0;
    }
    $absDir = str_replace('\\', '/', $absDir);
    $zipPrefix = rtrim(str_replace('\\', '/', $zipPrefix), '/');
    $added = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $full = str_replace('\\', '/', $file->getPathname());
        $suffix = ltrim(substr($full, strlen($absDir)), '/');
        $rel = $zipPrefix === '' ? $suffix : $zipPrefix . '/' . $suffix;
        $zip->addFile($file->getPathname(), $rel);
        $added++;
    }

    return $added;
}

$root = dirname(__DIR__);
$storage = $root . DIRECTORY_SEPARATOR . 'storage';
$outDir = $argv[1] ?? $root;
$stamp = date('Y-m-d_His');
$zipName = 'fixarivan_backup_' . $stamp . '.zip';
$zipPath = rtrim($outDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $zipName;

$dirPaths = [
    [$storage . DIRECTORY_SEPARATOR . 'orders', 'storage/orders'],
    [$storage . DIRECTORY_SEPARATOR . 'receipts', 'storage/receipts'],
    [$storage . DIRECTORY_SEPARATOR . 'reports', 'storage/reports'],
    [$storage . DIRECTORY_SEPARATOR . 'orders_tokens', 'storage/orders_tokens'],
    [$storage . DIRECTORY_SEPARATOR . 'receipts_tokens', 'storage/receipts_tokens'],
    [$storage . DIRECTORY_SEPARATOR . 'invoices', 'storage/invoices'],
    [$storage . DIRECTORY_SEPARATOR . 'invoices_media', 'storage/invoices_media'],
];

if (!extension_loaded('zip')) {
    fwrite(STDERR, "PHP zip extension not loaded. Install php-zip or enable extension.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create zip: {$zipPath}\n");
    exit(1);
}

$added = 0;

$sqlite = $storage . DIRECTORY_SEPARATOR . 'fixarivan.sqlite';
if (is_file($sqlite)) {
    $zip->addFile($sqlite, 'storage/fixarivan.sqlite');
    $added++;
}

$profile = $storage . DIRECTORY_SEPARATOR . 'company_profile.json';
if (is_file($profile)) {
    $zip->addFile($profile, 'storage/company_profile.json');
    $added++;
}

foreach ($dirPaths as [$dir, $prefix]) {
    $added += backup_add_directory($zip, $dir, $prefix);
}

$analyticsDir = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup_analytics';
$added += backup_add_directory($zip, $analyticsDir, 'docs/backup_analytics');

$zip->close();

echo "OK: {$zipPath} ({$added} files added)\n";
