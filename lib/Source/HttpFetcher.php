<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use Ynamite\Media\Exception\ImageNotFoundException;

/**
 * Thin symfony/http-client wrapper for external-URL fetching.
 *
 * Responsibilities:
 *   - Streaming GET into a `tmp` file with atomic rename to the final origin path.
 *   - Conditional GET (If-None-Match / If-Modified-Since) — 304 short-circuits
 *     the body transfer.
 *   - Total wall-clock + body-size cap (mid-stream abort via TransportException).
 *   - DNS pinning to an SSRF-validated IP through symfony's `resolve` option
 *     (mapped to libcurl `CURLOPT_RESOLVE`).
 *
 * Pure transport — never touches the cache layout, never reads/writes manifest
 * sidecars. {@see ExternalSourceFactory} owns the layout and orchestrates.
 *
 * Built around symfony's HttpClient because the equivalent raw-curl wrapper
 * would re-implement timeout / progress / streaming / redirect handling that
 * the library already covers, while costing the same vendor footprint.
 */
final class HttpFetcher
{
    /**
     * Optional override (tests inject MockHttpClient). Null = lazy-create
     * via HttpClient::create() with our default options.
     */
    public function __construct(private ?HttpClientInterface $client = null)
    {
    }

    /**
     * Fetch the URL into `$destPath`, streaming chunks to disk.
     *
     * Conditional GET: pass `$etag` / `$lastModified` from a prior fetch.
     * Returns a {@see HttpFetchResult} carrying the new etag / lastModified
     * for manifest persistence and a `notModified` flag the caller uses to
     * skip the body-replace when the upstream answered 304.
     *
     * @throws ImageNotFoundException on transport / status / size failure
     */
    public function fetch(
        string $url,
        string $resolvedIp,
        string $destPath,
        ?string $etag = null,
        ?int $lastModified = null,
        int $timeoutSeconds = 15,
        int $maxBytes = 25 * 1024 * 1024,
    ): HttpFetchResult {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            throw new ImageNotFoundException('External URL host parse failed: ' . $url);
        }

        $headers = [];
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null && $lastModified > 0) {
            $headers['If-Modified-Since'] = gmdate('D, d M Y H:i:s', $lastModified) . ' GMT';
        }

        $client = $this->client ?? \Symfony\Component\HttpClient\HttpClient::create();

        try {
            $response = $client->request('GET', $url, [
                'timeout' => $timeoutSeconds,
                'max_duration' => $timeoutSeconds + 5,
                'max_redirects' => 3,
                'headers' => $headers,
                'resolve' => [$host => $resolvedIp],
                // Body-size cap: throw mid-stream if we exceed maxBytes.
                'on_progress' => static function (int $dlNow, int $dlSize, array $info) use ($maxBytes): void {
                    if ($dlNow > $maxBytes) {
                        throw new TransportException(sprintf(
                            'External body exceeds max %d bytes (got %d)',
                            $maxBytes,
                            $dlNow,
                        ));
                    }
                },
            ]);

            $status = $response->getStatusCode();
            if ($status === 304) {
                return new HttpFetchResult(
                    notModified: true,
                    etag: $etag,
                    lastModified: $lastModified,
                );
            }
            if ($status < 200 || $status >= 300) {
                throw new ImageNotFoundException(sprintf('External fetch %s returned HTTP %d', $url, $status));
            }

            // Stream chunks into a tmp file, atomic rename on success.
            $tmpPath = $destPath . '.tmp';
            self::ensureDir(dirname($destPath));
            $fh = @fopen($tmpPath, 'wb');
            if ($fh === false) {
                throw new ImageNotFoundException('External fetch could not open tmp file: ' . $tmpPath);
            }
            try {
                foreach ($client->stream($response) as $chunk) {
                    if ($chunk->isTimeout()) {
                        throw new TransportException('External fetch timed out');
                    }
                    if ($chunk->isLast()) {
                        break;
                    }
                    $bytes = $chunk->getContent();
                    if ($bytes !== '') {
                        fwrite($fh, $bytes);
                    }
                }
            } finally {
                fclose($fh);
            }
            if (!@rename($tmpPath, $destPath)) {
                @unlink($tmpPath);
                throw new ImageNotFoundException('External fetch could not finalize: ' . $destPath);
            }

            $newEtag = self::firstHeader($response->getHeaders(false), 'etag');
            $newLastModified = self::parseHttpDate(
                self::firstHeader($response->getHeaders(false), 'last-modified'),
            );

            return new HttpFetchResult(
                notModified: false,
                etag: $newEtag,
                lastModified: $newLastModified,
            );
        } catch (TransportExceptionInterface $e) {
            throw new ImageNotFoundException('External fetch transport error: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            if ($e instanceof ImageNotFoundException) {
                throw $e;
            }
            throw new ImageNotFoundException('External fetch failure: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function firstHeader(array $headers, string $name): ?string
    {
        $name = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $name && is_array($v) && $v !== []) {
                $val = (string) $v[0];
                return $val !== '' ? $val : null;
            }
        }
        return null;
    }

    private static function parseHttpDate(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : null;
    }
}
