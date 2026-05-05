<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use rex_media;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Glide\FilterParams;

/**
 * One entry of the art-direction variant list — a self-contained source
 * definition with its own media query, image src, and optional crop / focal
 * / filter overrides.
 *
 * The renderer ({@see PictureRenderer}) emits one
 * `<source media="…" type="…" srcset="…">` per format per variant before the
 * default format-keyed sources. Browsers iterate `<source>`s top-to-bottom
 * and pick the first match — `media` filters by viewport, `type` filters by
 * format support — so art-direction sources placed first take precedence.
 *
 * Builder-level filters (`->blur(5)->art([…])`) intentionally do NOT bleed
 * into variants: each variant carries its own `filterParams`, leaving the
 * default desktop variant as the only consumer of the builder chain. That
 * keeps "different crop on mobile" and "different filter on mobile"
 * orthogonal — which matches how designers think about art direction.
 *
 * `src` accepts the same `string|rex_media` shape as `Image::picture()` /
 * `->for()` — a mediapool filename, a `rex_media` instance, or an HTTPS URL
 * (handled uniformly via the source-polymorphism layer; URL-shaped strings
 * route to the external fetch pipeline).
 */
final readonly class ArtDirectionVariant
{
    /**
     * @param array<string, scalar> $filterParams Glide-keyed filter params
     *        (already normalized — see {@see FilterParams::normalize()}).
     */
    public function __construct(
        public string $media,
        public string|rex_media $src,
        public ?int $width = null,
        public ?int $height = null,
        public ?float $ratio = null,
        public ?string $focal = null,
        public ?Fit $fit = null,
        public array $filterParams = [],
    ) {
    }

    /**
     * Loose-shape constructor for the DX-friendly array form:
     *
     *   ['media' => '(max-width: 600px)', 'src' => 'hero-mobile.jpg', 'ratio' => 1]
     *
     * Recognised keys: `media`, `src`, `width`, `height`, `ratio`, `focal`,
     * `fit`, `filters` (friendly-keyed; normalized via FilterParams::normalize).
     * Unknown keys are silently dropped.
     *
     * `media` and `src` are required; missing either throws a TypeError so
     * the developer notices a typo rather than getting a silently-broken
     * variant.
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $media = isset($a['media']) ? trim((string) $a['media']) : '';
        if ($media === '') {
            throw new \InvalidArgumentException('ArtDirectionVariant: missing or empty "media"');
        }

        $src = $a['src'] ?? null;
        if (!is_string($src) && !($src instanceof rex_media)) {
            throw new \InvalidArgumentException('ArtDirectionVariant: missing or invalid "src"');
        }

        $width = isset($a['width']) ? (int) $a['width'] : null;
        if ($width !== null && $width <= 0) {
            $width = null;
        }
        $height = isset($a['height']) ? (int) $a['height'] : null;
        if ($height !== null && $height <= 0) {
            $height = null;
        }

        $ratio = null;
        if (isset($a['ratio'])) {
            $r = $a['ratio'];
            if (is_string($r)) {
                $ratio = self::parseRatio($r);
            } elseif (is_numeric($r)) {
                $f = (float) $r;
                $ratio = $f > 0 ? $f : null;
            }
        }

        $focal = isset($a['focal']) && $a['focal'] !== '' ? (string) $a['focal'] : null;

        $fit = null;
        if (isset($a['fit'])) {
            $fit = $a['fit'] instanceof Fit
                ? $a['fit']
                : (is_string($a['fit']) ? Fit::tryFrom($a['fit']) : null);
        }

        $filterParams = [];
        if (isset($a['filters']) && is_array($a['filters'])) {
            $filterParams = FilterParams::normalize($a['filters']);
        } elseif (isset($a['filterParams']) && is_array($a['filterParams'])) {
            // Pre-normalized Glide-keyed shape — pass through.
            $filterParams = $a['filterParams'];
        }

        return new self(
            media: $media,
            src: $src,
            width: $width,
            height: $height,
            ratio: $ratio,
            focal: $focal,
            fit: $fit,
            filterParams: $filterParams,
        );
    }

    /**
     * Mirrors `RexPic::parseRatio` — accepts "16:9" / "16/9" / "1.777".
     */
    private static function parseRatio(string $value): ?float
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)\s*$/', $value, $m)) {
            $h = (float) $m[2];
            return $h > 0 ? ((float) $m[1]) / $h : null;
        }
        $f = (float) $value;
        return $f > 0 ? $f : null;
    }
}
