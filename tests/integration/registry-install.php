<?php
declare(strict_types=1);

if (!class_exists(ZipArchive::class)) { fwrite(STDERR, "The zip extension is required.\n"); exit(1); }
$root = dirname(__DIR__, 2);
$temporary = sys_get_temp_dir() . '/ompphp-composer-e2e-' . bin2hex(random_bytes(5));
mkdir("$temporary/site/ompphp/p2/ompphp", 0777, true);
mkdir("$temporary/sdk", 0777, true);
mkdir("$temporary/component", 0777, true);
file_put_contents("$temporary/sdk/composer.json", json_encode(['name' => 'ompphp/sdk', 'version' => '0.4.0', 'require' => ['php' => '>=8.2']], JSON_PRETTY_PRINT));
file_put_contents("$temporary/component/composer.json", json_encode(['name' => 'ompphp/fixture-component', 'version' => '0.1.0', 'require' => ['ompphp/sdk' => '^0.4']], JSON_PRETTY_PRINT));
foreach (['sdk' => 'ompphp-sdk-0.4.0.zip', 'component' => 'ompphp-fixture-component-0.1.0.zip'] as $folder => $archiveName) {
    $zip = new ZipArchive(); $zip->open("$temporary/site/$archiveName", ZipArchive::CREATE); $zip->addFile("$temporary/$folder/composer.json", 'composer.json'); $zip->close();
}
$port = random_int(20000, 45000);
$base = "http://127.0.0.1:$port";
$metadata = [
    'ompphp/sdk' => [[
        'name' => 'ompphp/sdk',
        'version' => '0.4.0',
        'require' => ['php' => '>=8.2'],
        'dist' => ['type' => 'zip', 'url' => "$base/ompphp-sdk-0.4.0.zip"],
    ]],
    'ompphp/fixture-component' => [[
        'name' => 'ompphp/fixture-component',
        'version' => '0.1.0',
        'require' => ['ompphp/sdk' => '^0.4'],
        'dist' => ['type' => 'zip', 'url' => "$base/ompphp-fixture-component-0.1.0.zip"],
    ]],
];
file_put_contents("$temporary/site/ompphp/packages.json", json_encode(['metadata-url' => '/ompphp/p2/%package%.json', 'available-packages' => array_keys($metadata)]));
foreach ($metadata as $name => $versions) file_put_contents("$temporary/site/ompphp/p2/$name.json", json_encode(['packages' => [$name => $versions]]));
mkdir("$temporary/project");
$command = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg("$temporary/site"));
$process = proc_open($command, [['pipe', 'r'], ['file', "$temporary/server.log", 'a'], ['file', "$temporary/server.log", 'a']], $pipes);
if (!is_resource($process)) { fwrite(STDERR, "Could not start local registry server.\n"); exit(1); }
try {
    usleep(300000);
    $env = array_merge($_ENV, [
        'COMPOSER_ALLOW_SUPERUSER' => '1',
        'COMPOSER_CACHE_DIR' => "$temporary/cache",
        'COMPOSER_HOME' => "$temporary/composer-home",
    ]);
    $commands = [
        ['composer', 'init', '--no-interaction', '--name=ompphp/e2e'],
        ['composer', 'config', 'secure-http', 'false'],
        ['composer', 'repo', 'add', 'ompphp', 'composer', "$base/ompphp"],
        ['composer', 'require', 'ompphp/sdk:^0.4', '--no-interaction'],
        ['composer', 'require', 'ompphp/fixture-component:^0.1', '--no-interaction'],
    ];
    foreach ($commands as $arguments) {
        $child = proc_open($arguments, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $io, "$temporary/project", $env);
        $output = stream_get_contents($io[1]) . stream_get_contents($io[2]); fclose($io[1]); fclose($io[2]);
        if (proc_close($child) !== 0) throw new RuntimeException(implode(' ', $arguments) . " failed:\n$output");
    }
    if (!is_file("$temporary/project/vendor/ompphp/sdk/composer.json") || !is_file("$temporary/project/vendor/ompphp/fixture-component/composer.json")) throw new RuntimeException('Composer did not install both packages.');
    fwrite(STDOUT, "Composer registry installation test passed.\n");
} finally {
    proc_terminate($process); proc_close($process);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($temporary);
}
