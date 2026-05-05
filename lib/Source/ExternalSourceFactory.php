<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

use rex_logger;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Exception\ImageNotFoundException;

/**
 * Resolves an HTTPS URL into an {@see ExternalSource} backed by a local
 * fetched copy. Owns the full external-fetch orchestration:
 *
 *   1. SSRF-validate the URL ({@see SsrfGuard::validate()}).
 *   2. Compute the canonical bucket hash ({@see ExternalSource::hashFor()}).
 *   3. Read any existing manifest sidecar ({@see ExternalManifest::read()}).
 *   4. If the cached body is fresh ({@see ExternalSource::isExpired()}) and
 *      `_origin.bin` exists on disk, skip the fetch.
 *   5. Otherwise issue a conditional GET via {@see HttpFetcher::fetch()};
 *      304 → bump fetchedAt only; 200 → replace body + ETag/Last-Modified.
 *   6. Persist the updated manifest, return the resolved source.
 *
 * `resolveByHash()` is the read-side companion used by {@see Endpoint} when
 * reconstructing a source from an `_external/<hash>` cache path: it returns
 * whatever the manifest says without ever touching the network.
 */
final class ExternalSourceFactory
{
    public function __construct(
        private ?HttpFetcher $fetcher = null,
    ) {
    }

    /**
     * @throws ImageNotFoundException on SSRF / fetch / write failure
     */
    public function resolve(string $url): ExternalSource
    {
        [$host, $ip] = SsrfGuard::validate($url, Config::externalHostAllowlist());

        $hash = ExternalSource::hashFor($url);
        $manifest = ExternalManifest::read($hash);
        $originPath = ExternalManifest::originPath($hash);
        $ttl = Config::externalTtlSeconds();

        $now = time();
        $bodyExists = is_file($originPath) && filesize($originPath) > 0;
        $fresh = $manifest !== null
            && $bodyExists
            && ($manifest['fetchedAt'] + ($manifest['ttl'] > 0 ? $manifest['ttl'] : $ttl)) > $now;

        if ($fresh) {
            return new ExternalSource(
                url: $manifest['url'],
                hash: $hash,
                absolutePath: $originPath,
                fetchedAt: $manifest['fetchedAt'],
                etag: $manifest['etag'],
                remoteLastModified: $manifest['lastModified'],
                ttlSeconds: $manifest['ttl'] > 0 ? $manifest['ttl'] : $ttl,
            );
        }

        $etag = $manifest['etag'] ?? null;
        $lastModified = $manifest['lastModified'] ?? null;

        $fetcher = $this->fetcher ?? new HttpFetcher();
        $result = $fetcher->fetch(
            url: $url,
            resolvedIp: $ip,
            destPath: $originPath,
            etag: $bodyExists ? $etag : null,        // only use conditional GET when body exists
            lastModified: $bodyExists ? $lastModified : null,
            timeoutSeconds: Config::externalTimeoutSeconds(),
            maxBytes: Config::externalMaxBytes(),
        );

        // 304: keep body, just refresh fetchedAt so the &v= cache-buster moves
        //       and downstream readers see "still fresh" for another TTL window.
        // 200: HttpFetcher already replaced _origin.bin atomically.
        $newFetchedAt = $now;
        $newEtag = $result->etag;
        $newLastModified = $result->lastModified;

        $data = [
            'url' => $url,
            'etag' => $newEtag,
            'lastModified' => $newLastModified,
            'fetchedAt' => $newFetchedAt,
            'ttl' => $ttl,
        ];
        ExternalManifest::write($hash, $data);

        return new ExternalSource(
            url: $url,
            hash: $hash,
            absolutePath: $originPath,
            fetchedAt: $newFetchedAt,
            etag: $newEtag,
            remoteLastModified: $newLastModified,
            ttlSeconds: $ttl,
        );
    }

    /**
     * Reconstruct an {@see ExternalSource} from an existing manifest, without
     * any network IO. Used by {@see Endpoint::makeExternal()} when it has
     * only the `_external/<hash>` cache-path source. Returns null if the
     * manifest is missing — the caller treats that as a 404.
     */
    public function resolveByHash(string $hash): ?ExternalSource
    {
        $manifest = ExternalManifest::read($hash);
        if ($manifest === null) {
            return null;
        }
        $originPath = ExternalManifest::originPath($hash);

        return new ExternalSource(
            url: $manifest['url'],
            hash: $hash,
            absolutePath: $originPath,
            fetchedAt: $manifest['fetchedAt'],
            etag: $manifest['etag'],
            remoteLastModified: $manifest['lastModified'],
            ttlSeconds: $manifest['ttl'] > 0 ? $manifest['ttl'] : Config::externalTtlSeconds(),
        );
    }
}
