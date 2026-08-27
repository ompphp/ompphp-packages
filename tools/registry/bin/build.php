<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use OmpPhp\Registry\GitHubReleaseSource;
use OmpPhp\Registry\RegistryBuilder;

$output = $argv[1] ?? dirname(__DIR__, 3) . '/public';
try {
    $token = getenv('GITHUB_TOKEN') ?: null;
    (new RegistryBuilder(new GitHubReleaseSource($token)))->write($output);
    if ($token !== null && strlen($token) >= 20) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), $token)) {
                unlink($file->getPathname());
                throw new RuntimeException('A generated registry file contained the GitHub token and was removed.');
            }
        }
    }
    fwrite(STDOUT, "Composer repository written to $output/ompphp.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Registry build failed: {$error->getMessage()}\n");
    exit(1);
}
