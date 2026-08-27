<?php
declare(strict_types=1);

namespace OmpPhp\Registry;

final readonly class Release
{
    /** @param list<array{name: string, browser_download_url: string}> $assets */
    public function __construct(
        public string $repository,
        public string $tag,
        public bool $draft,
        public bool $prerelease,
        public array $assets,
    ) {}

    public function asset(string $name): ?string
    {
        foreach ($this->assets as $asset) {
            if ($asset['name'] === $name) return $asset['browser_download_url'];
        }
        return null;
    }
}
