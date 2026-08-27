<?php
declare(strict_types=1);

namespace OmpPhp\Registry;

final class RegistryBuilder
{
    public const CORE_REPOSITORY = 'ompphp/ompphp';
    public const PACKAGES_REPOSITORY = 'ompphp/ompphp-packages';

    public function __construct(private readonly ReleaseSource $source) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function packages(): array
    {
        $packages = [];
        foreach ($this->source->releases(self::CORE_REPOSITORY) as $release) {
            if ($release->draft || $release->prerelease) continue;
            if (!preg_match('/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $release->tag, $match)) continue;
            $version = "$match[1].$match[2].$match[3]";
            $assetName = "ompphp-sdk_$version.zip";
            $url = $release->asset($assetName) ?? throw new RegistryException("Release {$release->repository}@{$release->tag} is missing $assetName.");
            $metadata = $this->source->composerJson($url, 'composer.json');
            $packages['ompphp/sdk'][] = $this->version($metadata, 'ompphp/sdk', $version, $url, $release);
        }
        foreach ($this->source->releases(self::PACKAGES_REPOSITORY) as $release) {
            if ($release->draft || $release->prerelease) continue;
            if (!preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)*)-v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $release->tag, $match)) {
                throw new RegistryException("Invalid package release tag: {$release->tag}");
            }
            $slug = $match[1];
            $version = "$match[2].$match[3].$match[4]";
            $name = "ompphp/$slug";
            $assetName = "ompphp-$slug-$version.zip";
            $url = $release->asset($assetName) ?? throw new RegistryException("Release {$release->repository}@{$release->tag} is missing $assetName.");
            $metadata = $this->source->composerJson($url, 'composer.json');
            $packages[$name][] = $this->version($metadata, $name, $version, $url, $release);
        }
        ksort($packages, SORT_STRING);
        foreach ($packages as &$versions) usort($versions, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
        return $packages;
    }

    public function write(string $directory): void
    {
        $packages = $this->packages();
        $root = rtrim($directory, '/') . '/ompphp';
        self::directory($root . '/p2/ompphp');
        self::json($root . '/packages.json', [
            'metadata-url' => '/ompphp/p2/%package%.json',
            'available-packages' => array_keys($packages),
        ]);
        foreach ($packages as $name => $versions) self::json("$root/p2/$name.json", ['packages' => [$name => $versions]]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function version(array $metadata, string $name, string $version, string $url, Release $release): array
    {
        if (($metadata['name'] ?? null) !== $name) throw new RegistryException("Package name in {$release->tag} must be $name.");
        unset($metadata['version'], $metadata['repositories'], $metadata['require-dev'], $metadata['scripts']);
        $metadata['version'] = $version;
        $metadata['dist'] = ['type' => 'zip', 'url' => $url];
        $metadata['source'] = ['type' => 'git', 'url' => "https://github.com/{$release->repository}.git", 'reference' => $release->tag];
        ksort($metadata, SORT_STRING);
        return $metadata;
    }

    private static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) throw new RegistryException("Could not create $path.");
    }

    /** @param array<string, mixed> $value */
    private static function json(string $path, array $value): void
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json) === false) throw new RegistryException("Could not write $path.");
    }
}
