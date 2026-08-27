<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$failed = false;
foreach (glob($root . '/packages/*/composer.json') ?: [] as $file) {
    try { $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { fwrite(STDERR, "$file: {$error->getMessage()}\n"); $failed = true; continue; }
    $slug = basename(dirname($file));
    if (($data['name'] ?? null) !== "ompphp/$slug") { fwrite(STDERR, "$file: package name must be ompphp/$slug.\n"); $failed = true; }
}
if ($failed) exit(1);
fwrite(STDOUT, "Package manifests are valid.\n");
