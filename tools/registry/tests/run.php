<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use OmpPhp\Registry\RegistryBuilder;
use OmpPhp\Registry\RegistryException;
use OmpPhp\Registry\Release;
use OmpPhp\Registry\ReleaseSource;

final class FixtureSource implements ReleaseSource
{
    /**
     * @param array<string, list<Release>> $releaseMap
     * @param array<string, array<string, mixed>> $manifests
     */
    public function __construct(private array $releaseMap, private array $manifests) {}
    public function releases(string $repository): array { return $this->releaseMap[$repository] ?? []; }
    public function composerJson(string $archiveUrl, string $path): array { return $this->manifests[$archiveUrl] ?? throw new RuntimeException("Missing fixture: $archiveUrl"); }
}

function release(string $repository, string $tag, string $asset): Release
{
    return new Release($repository, $tag, false, false, [['name' => $asset, 'browser_download_url' => "https://example.test/$asset"]]);
}
function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function tempDirectory(): string
{
    $path = sys_get_temp_dir() . '/ompphp-registry-test-' . bin2hex(random_bytes(5));
    if (!mkdir($path)) throw new RuntimeException('Could not create test directory.');
    return $path;
}
function removeDirectory(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$sdk040 = 'https://example.test/ompphp-sdk_0.4.0.zip';
$sdk050 = 'https://example.test/ompphp-sdk_0.5.0.zip';
$map010 = 'https://example.test/ompphp-mapandreas-0.1.0.zip';
$source = new FixtureSource([
    RegistryBuilder::CORE_REPOSITORY => [
        release(RegistryBuilder::CORE_REPOSITORY, 'v0.4.0', basename($sdk040)),
        release(RegistryBuilder::CORE_REPOSITORY, 'v0.5.0', basename($sdk050)),
    ],
    RegistryBuilder::PACKAGES_REPOSITORY => [release(RegistryBuilder::PACKAGES_REPOSITORY, 'mapandreas-v0.1.0', basename($map010))],
], [
    $sdk040 => ['name' => 'ompphp/sdk', 'require' => ['php' => '>=8.2'], 'require-dev' => ['phpstan/phpstan' => '^2.1']],
    $sdk050 => ['name' => 'ompphp/sdk', 'require' => ['php' => '>=8.2']],
    $map010 => ['name' => 'ompphp/mapandreas', 'require' => ['php' => '>=8.2', 'ompphp/sdk' => '^0.4']],
]);

$directory = tempDirectory();
try {
    $builder = new RegistryBuilder($source);
    $builder->write($directory);
    $root = json_decode((string) file_get_contents("$directory/ompphp/packages.json"), true, flags: JSON_THROW_ON_ERROR);
    expect($root['metadata-url'] === '/ompphp/p2/%package%.json', 'Incorrect metadata URL.');
    expect($root['available-packages'] === ['ompphp/mapandreas', 'ompphp/sdk'], 'Packages must be sorted.');
    $sdk = json_decode((string) file_get_contents("$directory/ompphp/p2/ompphp/sdk.json"), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($sdk['packages']['ompphp/sdk'], 'version') === ['0.5.0', '0.4.0'], 'Versions must be newest first.');
    expect(!isset($sdk['packages']['ompphp/sdk'][1]['require-dev']), 'Development requirements must not leak into repository metadata.');
    expect($sdk['packages']['ompphp/sdk'][1]['dist']['url'] === $sdk040, 'Incorrect distribution URL.');
    $first = file_get_contents("$directory/ompphp/p2/ompphp/sdk.json");
    $builder->write($directory);
    expect(file_get_contents("$directory/ompphp/p2/ompphp/sdk.json") === $first, 'Output must be deterministic.');
} finally { removeDirectory($directory); }

$invalid = new FixtureSource([
    RegistryBuilder::CORE_REPOSITORY => [],
    RegistryBuilder::PACKAGES_REPOSITORY => [release(RegistryBuilder::PACKAGES_REPOSITORY, 'mapandreas-0.1.0', 'unused.zip')],
], []);
try { (new RegistryBuilder($invalid))->packages(); throw new RuntimeException('Invalid tag was accepted.'); }
catch (RegistryException $error) { expect(str_contains($error->getMessage(), 'Invalid package release tag'), 'Invalid tag error is unclear.'); }

$missing = new FixtureSource([
    RegistryBuilder::CORE_REPOSITORY => [new Release(RegistryBuilder::CORE_REPOSITORY, 'v0.4.0', false, false, [])],
    RegistryBuilder::PACKAGES_REPOSITORY => [],
], []);
try { (new RegistryBuilder($missing))->packages(); throw new RuntimeException('Missing asset was accepted.'); }
catch (RegistryException $error) { expect(str_contains($error->getMessage(), 'missing ompphp-sdk_0.4.0.zip'), 'Missing asset error is unclear.'); }

$secret = 'github_pat_example_secret_that_must_not_appear';
$github = new \OmpPhp\Registry\GitHubReleaseSource($secret);
try {
    $github->composerJson("https://user:$secret@attacker.example/archive.zip?token=$secret", 'composer.json');
    throw new RuntimeException('An untrusted release asset URL was accepted.');
} catch (RegistryException $error) {
    expect(!str_contains($error->getMessage(), $secret), 'A credential appeared in an asset URL error.');
    expect(str_contains($error->getMessage(), 'unexpected release asset URL'), 'Unexpected asset URL error is unclear.');
}

fwrite(STDOUT, "Registry tests passed.\n");
