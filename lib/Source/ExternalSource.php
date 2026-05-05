<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

/**
 * Source backed by an arbitrary HTTPS URL. Resolved by
 * {@see ExternalSourceFactory} which fetches the body via
 * {@see HttpFetcher} and persists it under
 * `cache/_external/<hash>/_origin.bin` together with a manifest sidecar.
 *
 * `$hash` is `xxh64($url)` truncated to 16 hex chars — the cache-bucket id.
 * `$fetchedAt` flips on every successful fetch (200 with new body) AND on
 * every TTL refresh (304 from conditional GET); both move the cache-buster
 * `&v=` so browsers revalidate. `$etag` and `$remoteLastModified` feed the
 * conditional-GET probe on the next refresh.
 */
final readonly class ExternalSource implements SourceInterface
{
    public function __construct(
        public string $url,
        public string $hash,
        public string $absolutePath,
        public int $fetchedAt,
        public ?string $etag,
        public ?int $remoteLastModified,
        public int $ttlSeconds,
    ) {
    }

    public function key(): string
    {
        return '_external/' . $this->hash;
    }

    public function absolutePath(): string
    {
        return $this->absolutePath;
    }

    public function cacheBust(): string
    {
        return (string) $this->fetchedAt;
    }

    public function isExternal(): bool
    {
        return true;
    }

    /**
     * `true` when `$fetchedAt + $ttlSeconds < now`. Used by
     * {@see ExternalSourceFactory} to decide whether to issue a conditional
     * GET on a re-resolve.
     */
    public function isExpired(?int $now = null): bool
    {
        $now ??= time();
        return ($this->fetchedAt + $this->ttlSeconds) <= $now;
    }

    /**
     * Compute the canonical hash for a URL. Used by callers that need the
     * hash before any fetch (e.g. {@see Endpoint} when reconstructing a
     * source from an `_external/<hash>` cache path).
     */
    public static function hashFor(string $url): string
    {
        return substr(hash('xxh64', $url), 0, 16);
    }
}
