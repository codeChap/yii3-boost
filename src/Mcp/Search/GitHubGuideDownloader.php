<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Search;

/**
 * Downloads the Yii3 guide from GitHub and caches it locally.
 *
 * Uses the GitHub Contents API to list files, then fetches raw content
 * from raw.githubusercontent.com. Cached files persist across updates.
 */
final class GitHubGuideDownloader
{
    private const API_URL = 'https://api.github.com/repos/yiisoft/docs/contents/guide/en';
    private const RAW_URL = 'https://raw.githubusercontent.com/yiisoft/docs/master/guide/en/';

    /** @var callable|null Custom HTTP fetcher for testing */
    private $httpFetcher = null;

    public function __construct(
        private readonly string $cachePath,
        private readonly int $timeout = 5,
    ) {
    }

    public function setHttpFetcher(callable $fetcher): void
    {
        $this->httpFetcher = $fetcher;
    }

    /**
     * @return array{downloaded: int, skipped: int, failed: int, errors: array<string>}
     */
    public function download(): array
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        $result = ['downloaded' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        $fileList = $this->fetchFileList();
        if ($fileList === null) {
            $result['errors'][] = 'Failed to fetch file list from GitHub API';
            return $result;
        }

        foreach ($fileList as $file) {
            if (!isset($file['name']) || !str_ends_with($file['name'], '.md')) {
                continue;
            }

            $filename = $file['name'];
            $localPath = $this->cachePath . '/' . $filename;

            if (isset($file['sha']) && file_exists($localPath)) {
                if ($this->getFileSha($localPath) === $file['sha']) {
                    $result['skipped']++;
                    continue;
                }
            }

            $content = $this->fetchFile(self::RAW_URL . $filename);
            if ($content === false) {
                $result['failed']++;
                $result['errors'][] = "Failed to download: $filename";
                continue;
            }

            file_put_contents($localPath, $content);
            $result['downloaded']++;
        }

        return $result;
    }

    /** @return array<string> */
    public function getCachedFiles(): array
    {
        if (!is_dir($this->cachePath)) {
            return [];
        }
        return glob($this->cachePath . '/*.md') ?: [];
    }

    public function hasCachedFiles(): bool
    {
        return !empty($this->getCachedFiles());
    }

    public static function mapCategory(string $filename): string
    {
        $basename = basename($filename, '.md');

        $prefixMap = [
            'db-' => 'guide_db',
            'security-' => 'guide_security',
            'start-' => 'guide_start',
            'structure-' => 'guide_structure',
            'runtime-' => 'guide_runtime',
            'input-' => 'guide_input',
            'output-' => 'guide_output',
            'caching-' => 'guide_caching',
            'rest-' => 'guide_rest',
            'test-' => 'guide_testing',
            'tutorial-' => 'guide_tutorial',
            'middleware-' => 'guide_middleware',
            'concept-' => 'guide_concept',
        ];

        foreach ($prefixMap as $prefix => $category) {
            if (str_starts_with($basename, $prefix)) {
                return $category;
            }
        }

        $parts = explode('-', $basename, 2);
        return count($parts) > 1 ? 'guide_' . $parts[0] : 'guide_general';
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    private function fetchFileList(): ?array
    {
        $content = $this->fetchFile(self::API_URL);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function fetchFile(string $url): string|false
    {
        if ($this->httpFetcher !== null) {
            return ($this->httpFetcher)($url);
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'header' => "User-Agent: yii3-boost\r\n",
            ],
        ]);

        return @file_get_contents($url, false, $context) ?: false;
    }

    private function getFileSha(string $filePath): string
    {
        $content = file_get_contents($filePath);
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }
}
