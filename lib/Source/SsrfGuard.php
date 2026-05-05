<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

use Ynamite\Media\Exception\ImageNotFoundException;

/**
 * Pre-flight URL validation for external fetches.
 *
 * Two layers:
 *   1. Scheme + structure check (only `http://` / `https://`, hostname present).
 *   2. DNS resolution + IP block-list check (rejects loopback, private, link-
 *      local, reserved IPv4/IPv6 ranges).
 *
 * Why DNS resolution: a public hostname can resolve to a private IP via
 * malicious DNS or an attacker-controlled subdomain pointing at internal
 * services (`internal.example.com → 10.0.0.5`). Without resolving here the
 * check is meaningless. The validated IP is also returned so the caller can
 * pass it to symfony's `'resolve'` option (curl `CURLOPT_RESOLVE`) and pin
 * the connection — defeats DNS rebinding between the check and the connect.
 *
 * Optional config layer: `EXTERNAL_HOST_ALLOWLIST` holds one regex per line.
 * Empty = allow any. Non-empty = host must match one of the regexes (anchored
 * with `~^...$~`). Used when admins want defence-in-depth on top of signing.
 */
final class SsrfGuard
{
    /**
     * Validate a URL and resolve its host to a public IPv4 address.
     *
     * Returns `[hostname, ipv4]` on success. Throws on any failure
     * (bad scheme, no DNS, blocked IP, allowlist mismatch).
     *
     * IPv6 is intentionally dropped here — the symfony/http-client `resolve`
     * option pins to a specific IP, and IPv4 resolution is universally
     * available. If only AAAA records exist for a host we treat it as
     * unreachable rather than try to extend the block-list to IPv6 range
     * arithmetic; the practical hit rate is ~0% for image-hosting CDNs.
     *
     * @param list<string> $hostAllowlistRegexes
     * @return array{0: string, 1: string} [hostname, ipv4]
     * @throws ImageNotFoundException with a human-readable reason on rejection
     */
    public static function validate(string $url, array $hostAllowlistRegexes = []): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ImageNotFoundException('External URL malformed: ' . $url);
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ImageNotFoundException('External URL scheme not allowed: ' . $scheme);
        }
        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            throw new ImageNotFoundException('External URL host empty');
        }

        if ($hostAllowlistRegexes !== [] && !self::hostAllowed($host, $hostAllowlistRegexes)) {
            throw new ImageNotFoundException('External host not in allowlist: ' . $host);
        }

        $ips = @gethostbynamel($host);
        if (!is_array($ips) || $ips === []) {
            throw new ImageNotFoundException('External host DNS resolution failed: ' . $host);
        }
        // Accept the first IP, validate it. If there are multiple A records,
        // we can't pin all of them, and rotating IPs across requests would be
        // fragile. The first record is the typical answer; if the upstream
        // fails over, the next fetch picks the new one.
        $ip = $ips[0];
        if (self::isBlockedIpv4($ip)) {
            throw new ImageNotFoundException('External URL resolves to blocked IP: ' . $host . ' → ' . $ip);
        }

        return [$host, $ip];
    }

    /**
     * @param list<string> $regexes
     */
    private static function hostAllowed(string $host, array $regexes): bool
    {
        foreach ($regexes as $regex) {
            $pattern = '~^' . str_replace('~', '\\~', trim($regex)) . '$~i';
            if (@preg_match($pattern, $host) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reject loopback (127/8, 0/8), private (10/8, 172.16/12, 192.168/16),
     * link-local (169.254/16), CGNAT (100.64/10), broadcast/multicast (224/4,
     * 255.255.255.255), and any IPv6 (we resolve via gethostbynamel which is
     * IPv4-only, so IPv6 strings here mean a misconfigured host file or a
     * future gethostbyname2 path — reject safely).
     */
    private static function isBlockedIpv4(string $ip): bool
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return true; // not an IPv4 address — reject
        }
        $a = (int) $parts[0];
        $b = (int) $parts[1];
        if ($a === 0 || $a === 10 || $a === 127) {
            return true;
        }
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return true;
        }
        if ($a === 192 && $b === 168) {
            return true;
        }
        if ($a === 169 && $b === 254) {
            return true;
        }
        if ($a === 100 && $b >= 64 && $b <= 127) {
            return true;
        }
        if ($a >= 224) {
            // 224/4 multicast through 255 broadcast — never a legit fetch target
            return true;
        }
        return false;
    }
}
