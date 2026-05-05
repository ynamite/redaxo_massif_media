<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

/**
 * Outcome of a single {@see HttpFetcher::fetch()} call.
 *
 * `notModified` is true when the upstream answered 304 — the caller skips the
 * body replacement and just bumps the manifest's `fetchedAt`. `etag` and
 * `lastModified` are the values to persist in the manifest for the next
 * conditional-GET probe.
 */
final readonly class HttpFetchResult
{
    public function __construct(
        public bool $notModified,
        public ?string $etag,
        public ?int $lastModified,
    ) {
    }
}
