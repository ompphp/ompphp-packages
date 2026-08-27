<?php
declare(strict_types=1);

namespace OmpPhp\Registry;

final class GitHubReleaseSource implements ReleaseSource
{
    public function __construct(private readonly ?string $token = null) {}

    public function releases(string $repository): array
    {
        $page = 1;
        $result = [];
        do {
            $values = $this->json("https://api.github.com/repos/$repository/releases?per_page=100&page=$page");
            if (!array_is_list($values)) throw new RegistryException("GitHub returned invalid release data for $repository.");
            foreach ($values as $value) {
                if (!is_array($value)) throw new RegistryException("GitHub returned an invalid release for $repository.");
                $assets = [];
                foreach (($value['assets'] ?? []) as $asset) {
                    if (is_array($asset) && isset($asset['name'], $asset['browser_download_url'])) {
                        $assets[] = ['name' => (string) $asset['name'], 'browser_download_url' => (string) $asset['browser_download_url']];
                    }
                }
                $result[] = new Release($repository, (string) ($value['tag_name'] ?? ''), (bool) ($value['draft'] ?? false), (bool) ($value['prerelease'] ?? false), $assets);
            }
            $page++;
        } while (count($values) === 100);
        return $result;
    }

    public function composerJson(string $archiveUrl, string $path): array
    {
        $this->assertReleaseAssetUrl($archiveUrl);
        $temporary = tempnam(sys_get_temp_dir(), 'ompphp-registry-');
        if ($temporary === false) throw new RegistryException('Could not create a temporary archive file.');
        try {
            // Release assets are public. Never send credentials through their redirect chain.
            file_put_contents($temporary, $this->request($archiveUrl, authenticated: false));
            $archive = new \ZipArchive();
            if ($archive->open($temporary) !== true) throw new RegistryException('Release asset is not a readable ZIP: ' . self::safeUrl($archiveUrl));
            $path = trim($path, '/');
            $matches = [];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);
                if ($name === $path || str_ends_with($name, "/$path")) $matches[] = $name;
            }
            if (count($matches) !== 1) throw new RegistryException("Expected one $path in release asset, found " . count($matches) . '.');
            $contents = $archive->getFromName($matches[0]);
            $archive->close();
            if ($contents === false) throw new RegistryException("Could not read $path from release asset.");
            return self::decode($contents, $path);
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array<int|string, mixed> */
    private function json(string $url): array { return self::decode($this->request($url, authenticated: true), self::safeUrl($url)); }

    /** @return array<int|string, mixed> */
    private static function decode(string $json, string $source): array
    {
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RegistryException("Malformed JSON from $source: {$error->getMessage()}", previous: $error); }
        if (!is_array($value)) throw new RegistryException("Expected a JSON object or array from $source.");
        return $value;
    }

    private function request(string $url, bool $authenticated): string
    {
        $headers = ["Accept: application/vnd.github+json", "User-Agent: ompphp-registry", "X-GitHub-Api-Version: 2022-11-28"];
        if ($authenticated) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'api.github.com') {
                throw new RegistryException('Refusing to send GitHub credentials to a non-API URL.');
            }
            if ($this->token !== null && $this->token !== '') $headers[] = "Authorization: Bearer {$this->token}";
        }
        $context = stream_context_create(['http' => [
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
            'ignore_errors' => true,
            'follow_location' => $authenticated ? 0 : 1,
        ]]);
        $http_response_header = [];
        $contents = @file_get_contents($url, false, $context);
        $status = '';
        foreach ($http_response_header as $header) {
            if (str_starts_with($header, 'HTTP/')) $status = $header;
        }
        if ($contents === false || !preg_match('/\s2\d\d\s/', $status)) throw new RegistryException("GitHub request failed ($status): " . self::safeUrl($url));
        return $contents;
    }

    private function assertReleaseAssetUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if ($scheme !== 'https' || $host !== 'github.com' || !is_string($path)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('#^/ompphp/(?:ompphp|ompphp-packages)/releases/download/[^/]+/[^/]+$#', $path)) {
            throw new RegistryException('GitHub returned an unexpected release asset URL: ' . self::safeUrl($url));
        }
    }

    private static function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return '[invalid URL]';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port . '/[path omitted]';
    }
}
