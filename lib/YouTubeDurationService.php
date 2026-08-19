<?php

declare(strict_types=1);

/**
 * Shared implementation used by both the FreshRSS hook and the CLI backfill.
 *
 * Keeping the YouTube/API/title logic in one class is important: the normal
 * importer and the backfill utility must produce exactly the same titles.
 */
final class YouTubeDurationService
{
    private const CACHE_FILE = '/var/www/freshrss/public/data/youtube-duration-cache.json';
    private const HTTP_TIMEOUT_SECONDS = 10;
    private const MAX_API_IDS_PER_REQUEST = 50;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $durationPosition = 'before',
        private readonly string $shortsMode = 'shorts',
    ) {
    }

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $videoId = $this->extractYouTubeVideoId($entry->link());
        if ($videoId === null) {
            return $entry;
        }

        $originalTitle = $this->removeExistingMarker($entry->title());
        $isShort = str_contains(strtolower($entry->link()), '/shorts/');

        // A Short marker does not require an API request. Likewise, if the
        // administrator chose not to mark Shorts, leave the title clean.
        if ($isShort && $this->shortsMode === 'shorts') {
            $entry->_title($this->applyMarker($originalTitle, '[Shorts]'));
            return $entry;
        }
        if ($isShort && $this->shortsMode === 'none') {
            $entry->_title($originalTitle);
            return $entry;
        }

        $duration = $this->getDurationFromCache($videoId);

        if ($duration === null) {
            $durations = $this->requestYouTubeDurations([$videoId]);
            if (!isset($durations[$videoId])) {
                return $entry;
            }
            $duration = $durations[$videoId];
            $this->saveDurationsToCache([$videoId => $duration]);
        }

        $marker = $this->buildMarker($duration, $isShort);
        if ($marker === '') {
            $entry->_title($originalTitle);
            return $entry;
        }

        $entry->_title($this->applyMarker($originalTitle, $marker));
        return $entry;
    }

    /**
     * Extract the canonical 11-character YouTube video ID.
     */
    public function extractYouTubeVideoId(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $patterns = [
            '~(?:https?://)?(?:www\.)?youtube\.com/watch\?[^#]*\bv=([A-Za-z0-9_-]{11})~i',
            '~(?:https?://)?youtu\.be/([A-Za-z0-9_-]{11})~i',
            '~(?:https?://)?(?:www\.)?youtube\.com/shorts/([A-Za-z0-9_-]{11})~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Return a title without a marker previously produced by this extension.
     */
    public function removeExistingMarker(string $title): string
    {
        $title = preg_replace('/^\[(?:\d{1,2}:\d{2}(?::\d{2})?|Shorts)\]\s+/u', '', $title) ?? $title;
        $title = preg_replace('/\s+\[(?:\d{1,2}:\d{2}(?::\d{2})?|Shorts)\]$/u', '', $title) ?? $title;
        return $title;
    }

    /**
     * Fetch durations for up to 50 IDs in one YouTube API request.
     *
     * @return array<string,int> video ID => duration in seconds
     */
    public function requestYouTubeDurations(array $videoIds): array
    {
        $videoIds = array_values(array_unique(array_filter($videoIds, static fn ($id): bool => is_string($id) && $id !== '')));
        if ($videoIds === [] || $this->apiKey === '') {
            return [];
        }

        $result = [];
        foreach (array_chunk($videoIds, self::MAX_API_IDS_PER_REQUEST) as $batch) {
            $apiUrl = 'https://www.googleapis.com/youtube/v3/videos'
                . '?part=contentDetails'
                . '&id=' . rawurlencode(implode(',', $batch))
                . '&key=' . rawurlencode($this->apiKey);

            $curlHandle = curl_init($apiUrl);
            if ($curlHandle === false) {
                continue;
            }

            curl_setopt_array($curlHandle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'FreshRSS-YouTubeDuration/2.0',
            ]);

            $response = curl_exec($curlHandle);
            $httpStatus = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
            curl_close($curlHandle);

            if ($response === false || $httpStatus !== 200) {
                error_log('YouTube Duration extension: YouTube API request failed with HTTP status ' . $httpStatus);
                continue;
            }

            $data = json_decode($response, true);
            if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
                continue;
            }

            foreach ($data['items'] as $item) {
                $id = $item['id'] ?? null;
                $isoDuration = $item['contentDetails']['duration'] ?? null;
                if (is_string($id) && is_string($isoDuration)) {
                    $seconds = $this->parseIso8601Duration($isoDuration);
                    if ($seconds !== null) {
                        $result[$id] = $seconds;
                    }
                }
            }
        }

        return $result;
    }

    public function getDurationFromCache(string $videoId): ?int
    {
        $cache = $this->readCache();
        if (!array_key_exists($videoId, $cache)) {
            return null;
        }
        $duration = $cache[$videoId];
        return is_int($duration) || ctype_digit((string) $duration) ? (int) $duration : null;
    }

    /**
     * @param array<string,int> $durations
     */
    public function saveDurationsToCache(array $durations): void
    {
        if ($durations === []) {
            return;
        }

        $cache = $this->readCache();
        foreach ($durations as $videoId => $duration) {
            $cache[$videoId] = (int) $duration;
        }

        $directory = dirname(self::CACHE_FILE);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $temporaryFile = self::CACHE_FILE . '.tmp';
        if (@file_put_contents($temporaryFile, $json . PHP_EOL) !== false) {
            @rename($temporaryFile, self::CACHE_FILE);
        }
    }

    public function buildMarker(int $durationSeconds, bool $isShort): string
    {
        if ($isShort && $this->shortsMode === 'shorts') {
            return '[Shorts]';
        }
        if ($isShort && $this->shortsMode === 'none') {
            return '';
        }
        return '[' . $this->formatDuration($durationSeconds) . ']';
    }

    public function applyMarker(string $title, string $marker): string
    {
        if ($marker === '') {
            return $title;
        }

        if ($this->durationPosition === 'after') {
            return $title . ' ' . $marker;
        }
        return $marker . ' ' . $title;
    }

    private function readCache(): array
    {
        if (!is_file(self::CACHE_FILE)) {
            return [];
        }
        $json = @file_get_contents(self::CACHE_FILE);
        if ($json === false || $json === '') {
            return [];
        }
        $cache = json_decode($json, true);
        return is_array($cache) ? $cache : [];
    }

    private function parseIso8601Duration(string $duration): ?int
    {
        if (!preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $matches)) {
            return null;
        }
        $hours = isset($matches[1]) ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function formatDuration(int $durationSeconds): string
    {
        $hours = intdiv($durationSeconds, 3600);
        $remainingSeconds = $durationSeconds % 3600;
        $minutes = intdiv($remainingSeconds, 60);
        $seconds = $remainingSeconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }
        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
