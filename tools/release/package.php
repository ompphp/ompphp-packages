<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tag = $argv[1] ?? '';
$output = $argv[2] ?? "$root/dist";
if (!preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)*)-v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $tag, $match)) {
    fwrite(STDERR, "Invalid package tag. Expected <package>-vX.Y.Z.\n"); exit(1);
}
$slug = $match[1];
$version = "$match[2].$match[3].$match[4]";
$package = "$root/packages/$slug";
$manifestPath = "$package/composer.json";
if (!is_file($manifestPath)) { fwrite(STDERR, "Package does not exist: $slug\n"); exit(1); }
try { $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR); }
catch (JsonException $error) { fwrite(STDERR, "Invalid package composer.json: {$error->getMessage()}\n"); exit(1); }
if (($manifest['name'] ?? null) !== "ompphp/$slug") { fwrite(STDERR, "Package name must be ompphp/$slug.\n"); exit(1); }
if (!is_dir($output) && !mkdir($output, 0777, true)) { fwrite(STDERR, "Could not create $output.\n"); exit(1); }
$target = "$output/ompphp-$slug-$version.zip";
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "Could not create $target.\n"); exit(1); }
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isLink()) { fwrite(STDERR, "Package archives cannot contain symbolic links.\n"); $zip->close(); @unlink($target); exit(1); }
    if (!$file->isFile() || str_contains($file->getPathname(), '/tests/')) continue;
    $relative = substr($file->getPathname(), strlen($package) + 1);
    $zip->addFile($file->getPathname(), $relative);
}
$zip->close();
fwrite(STDOUT, "$target\n");
