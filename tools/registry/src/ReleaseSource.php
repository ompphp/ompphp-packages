<?php
declare(strict_types=1);

namespace OmpPhp\Registry;

interface ReleaseSource
{
    /** @return list<Release> */
    public function releases(string $repository): array;

    /** @return array<string, mixed> */
    public function composerJson(string $archiveUrl, string $path): array;
}
