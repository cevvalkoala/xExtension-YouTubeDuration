# FreshRSS cache path and PHP 8.5 cURL

## Assessment

- `lib/YouTubeDurationService.php:13,45-54,181-205,247-257` hard-codes a deployment-specific path and suppresses mkdir, read, write and rename errors. A failed write leaves subsequent lookups uncached, so repeated processing can repeat YouTube requests. This is not limited to cPanel. `DATA_PATH` fixes the location, but does not guarantee that the PHP process has permission to write it.
- FreshRSS 1.30.4's `constants.php` defines `DATA_PATH` from the environment or local configuration, defaulting to `<FreshRSS>/data`, and describes it as writable. The web entry point loads those constants before the extension. FreshRSS 1.20.0 also defines `DATA_PATH`; the repository does not declare a minimum FreshRSS version, so do not claim compatibility with every historical release. The service's comment mentions a CLI backfill, but none is present in this repository.
- `curl_close()` in `requestYouTubeDurations()` is a no-op since PHP 8.0 and deprecated in PHP 8.5, according to the PHP manual. Removing it is safe for this code: its promoted readonly properties already require PHP 8.1. The extension does not declare a supported PHP version range.

## Implementation

1. In `lib/YouTubeDurationService.php`, derive the existing cache filename directly from `DATA_PATH`, e.g. `private const CACHE_FILE = DATA_PATH . '/youtube-duration-cache.json';`. Use the same path for reads, temporary writes and rename; keep the existing JSON representation, shared cache scope, and cache-miss behavior. Do not use FreshRSS's `CACHE_PATH`, whose normal cleanup can expire files.
2. Keep cache failures non-fatal and suppress direct PHP warnings, but check and log directory creation, temporary file write and rename failures using the existing `error_log` convention. Handle the directory-creation race by rechecking `is_dir()` after a failed `mkdir`. Return on failure without changing the current entry-processing behavior. If an existing cache file cannot be read, log the read failure; a missing file is a normal cache miss. Do not introduce locks or refactor cache concurrency in this change.
3. Remove `curl_close()` after `curl_getinfo()`; release the handle with `unset($curlHandle)` at the same point if immediate release is desired. Preserve all request options, batching and error handling.
4. In `README.md`, document the new cache location, its write-permission requirement, and the selected rollout: do not import the old hard-coded cache; it will rebuild and may temporarily consume additional API quota. Qualify the existing claim that each video is queried only once because failed lookups or writes can be retried. No automatic legacy-path access or migration.

## Validation

- No existing automated tests or dependency manifest were found. Add a small self-contained PHP regression test if appropriate for the repository, using a temporary directory and defining `DATA_PATH` before loading the service. Verify a missing cache is a miss, saving and reading values across service instances works, a second save retains existing entries, and the JSON is written beneath the configured data directory.
- Exercise an unwritable/invalid data path and a rename/write failure where feasible, checking that failures are logged without PHP warnings or fatal errors and the service remains usable. Use a reliably invalid path rather than permission bits when running as a privileged user or on Windows.
- Run PHP syntax checks and the cache test on PHP 8.1 and PHP 8.5 when available. With cURL installed, exercise the request path under PHP 8.5 with deprecations enabled and confirm no `curl_close()` deprecation; do not require a real YouTube API key. Record any unavailable runtime or integration environment rather than claiming a test passed.
- Check a FreshRSS web installation with a non-default `DATA_PATH`: after a successful fetch, confirm the cache file appears there and a repeat lookup is served from it. Verify logging when the web PHP user cannot write the data directory. Existing concurrent read-modify-write and fixed temporary-file races remain out of scope.

## References

- https://raw.githubusercontent.com/FreshRSS/FreshRSS/1.30.4/constants.php
- https://raw.githubusercontent.com/FreshRSS/FreshRSS/1.30.4/p/index.php
- https://www.php.net/manual/en/function.curl-close.php
