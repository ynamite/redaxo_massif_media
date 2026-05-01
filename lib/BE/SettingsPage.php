<?php

declare(strict_types=1);

namespace Ynamite\Media\BE;

use rex_csrf_token;
use rex_dir;
use rex_path;
use rex_url;
use rex_view;
use Ynamite\Media\Config;

final class SettingsPage
{
    private const CSRF_TOKEN = 'massif_media_settings';

    public static function render(): void
    {
        $csrf = rex_csrf_token::factory(self::CSRF_TOKEN);
        $messages = self::handlePost($csrf);

        foreach ($messages as $msg) {
            echo $msg;
        }

        echo self::renderForm($csrf);
    }

    /**
     * @return list<string> rendered notification HTML strings
     */
    private static function handlePost(rex_csrf_token $csrf): array
    {
        $messages = [];
        if (rex_request_method() !== 'post') {
            return $messages;
        }
        if (!$csrf->isValid()) {
            $messages[] = rex_view::error('CSRF Token ungültig — Formular bitte erneut absenden.');
            return $messages;
        }

        $action = (string) rex_post('action', 'string', 'save');

        switch ($action) {
            case 'regenerate_key':
                Config::set(Config::KEY_SIGN_KEY, bin2hex(random_bytes(32)));
                $messages[] = rex_view::success('Sign Key neu generiert.');
                break;
            case 'clear_cache':
                $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
                if (is_dir($cacheDir)) {
                    rex_dir::delete($cacheDir, false);
                }
                $messages[] = rex_view::success('Addon Cache geleert.');
                break;
            default:
                self::saveSettings();
                $messages[] = rex_view::success('Einstellungen gespeichert.');
        }
        return $messages;
    }

    private static function saveSettings(): void
    {
        $formats = (array) rex_post('formats', 'array', []);
        $formats = array_values(array_filter(array_map('strval', $formats), static fn ($f) => in_array($f, ['avif', 'webp', 'jpg'], true)));
        if ($formats === []) {
            $formats = ['avif', 'webp', 'jpg'];
        }
        Config::set(Config::KEY_FORMATS, $formats);

        Config::set(Config::KEY_QUALITY, [
            'avif' => self::clampInt(rex_post('quality_avif', 'int', 50), 1, 100),
            'webp' => self::clampInt(rex_post('quality_webp', 'int', 75), 1, 100),
            'jpg' => self::clampInt(rex_post('quality_jpg', 'int', 80), 1, 100),
        ]);

        Config::set(Config::KEY_DEVICE_SIZES, self::parseIntList((string) rex_post('device_sizes', 'string', '')));
        Config::set(Config::KEY_IMAGE_SIZES, self::parseIntList((string) rex_post('image_sizes', 'string', '')));

        Config::set(Config::KEY_DEFAULT_SIZES, trim((string) rex_post('default_sizes', 'string', '')));

        Config::set(Config::KEY_LQIP_ENABLED, (bool) rex_post('lqip_enabled', 'boolean', false));
        Config::set(Config::KEY_LQIP_WIDTH, self::clampInt(rex_post('lqip_width', 'int', 32), 4, 256));
        Config::set(Config::KEY_LQIP_BLUR, self::clampInt(rex_post('lqip_blur', 'int', 40), 0, 100));
        Config::set(Config::KEY_LQIP_QUALITY, self::clampInt(rex_post('lqip_quality', 'int', 40), 1, 100));

        Config::set(Config::KEY_BLURHASH_ENABLED, (bool) rex_post('blurhash_enabled', 'boolean', false));

        Config::set(Config::KEY_CDN_ENABLED, (bool) rex_post('cdn_enabled', 'boolean', false));
        Config::set(Config::KEY_CDN_BASE, trim((string) rex_post('cdn_base', 'string', '')));
        Config::set(Config::KEY_CDN_URL_TEMPLATE, trim((string) rex_post('cdn_url_template', 'string', '')));

        Config::set(Config::KEY_METADATA_TTL_SECONDS, self::clampInt(rex_post('metadata_ttl_seconds', 'int', 7_776_000), 60, 31_536_000));
        Config::set(Config::KEY_SENTINEL_TTL_SECONDS, self::clampInt(rex_post('sentinel_ttl_seconds', 'int', 60), 5, 3600));
    }

    private static function renderForm(rex_csrf_token $csrf): string
    {
        $formats = Config::formats();
        $quality = (array) Config::get(Config::KEY_QUALITY);
        $signKey = Config::signKey();

        ob_start();
        ?>
        <form action="<?= rex_url::currentBackendPage() ?>" method="post">
            <?= $csrf->getHiddenField() ?>

            <fieldset>
                <legend>Sicherheit</legend>
                <p>HMAC Sign-Key (auto-generiert beim Aktivieren des Addons). Wird zur
                Signierung der Bild-URLs verwendet.</p>
                <p><code><?= self::esc($signKey ?: '(nicht gesetzt)') ?></code></p>
                <button type="submit" name="action" value="regenerate_key" class="btn btn-warning">
                    Sign Key neu generieren
                </button>
                <small> &mdash; bestehende URLs werden ungültig, neue Cache-Generierungen erforderlich.</small>
            </fieldset>

            <fieldset>
                <legend>Cache</legend>
                <p>Generierte Bildvarianten werden in <code>assets/addons/massif_media/cache/</code> abgelegt.</p>
                <button type="submit" name="action" value="clear_cache" class="btn btn-warning">
                    Addon Cache leeren
                </button>
            </fieldset>

            <fieldset>
                <legend>Formate</legend>
                <p>Reihenfolge entspricht der Browser-Präferenz. Letztes Format = Fallback.</p>
                <?php foreach (['avif', 'webp', 'jpg'] as $f): ?>
                    <label>
                        <input type="checkbox" name="formats[]" value="<?= $f ?>" <?= in_array($f, $formats, true) ? 'checked' : '' ?>>
                        <?= strtoupper($f) ?>
                    </label>
                <?php endforeach; ?>

                <p>
                    <label>AVIF Qualität: <input type="number" name="quality_avif" value="<?= self::esc((string) ($quality['avif'] ?? 50)) ?>" min="1" max="100" class="form-control"></label>
                    <label>WebP Qualität: <input type="number" name="quality_webp" value="<?= self::esc((string) ($quality['webp'] ?? 75)) ?>" min="1" max="100" class="form-control"></label>
                    <label>JPG Qualität: <input type="number" name="quality_jpg" value="<?= self::esc((string) ($quality['jpg'] ?? 80)) ?>" min="1" max="100" class="form-control"></label>
                </p>
            </fieldset>

            <fieldset>
                <legend>Breakpoints (next/image dual-pool)</legend>
                <p>Komma-separierte Listen.</p>
                <p>
                    <label>Device Sizes (große Breakpoints):
                        <input type="text" name="device_sizes" value="<?= self::esc(implode(', ', Config::deviceSizes())) ?>" class="form-control">
                    </label>
                </p>
                <p>
                    <label>Image Sizes (kleine Breakpoints):
                        <input type="text" name="image_sizes" value="<?= self::esc(implode(', ', Config::imageSizes())) ?>" class="form-control">
                    </label>
                </p>
                <p>
                    <label>Default <code>sizes</code> Attribut:
                        <input type="text" name="default_sizes" value="<?= self::esc(Config::defaultSizes()) ?>" class="form-control">
                    </label>
                </p>
            </fieldset>

            <fieldset>
                <legend>Placeholder (LQIP)</legend>
                <p>
                    <label>
                        <input type="checkbox" name="lqip_enabled" value="1" <?= Config::lqipEnabled() ? 'checked' : '' ?>>
                        LQIP aktiviert
                    </label>
                </p>
                <p>
                    <label>Breite (px): <input type="number" name="lqip_width" value="<?= Config::lqipWidth() ?>" min="4" max="256"></label>
                    <label>Blur (0–100): <input type="number" name="lqip_blur" value="<?= Config::lqipBlur() ?>" min="0" max="100"></label>
                    <label>Qualität: <input type="number" name="lqip_quality" value="<?= Config::lqipQuality() ?>" min="1" max="100"></label>
                </p>
            </fieldset>

            <fieldset>
                <legend>Blurhash</legend>
                <p>
                    <label>
                        <input type="checkbox" name="blurhash_enabled" value="1" <?= Config::blurhashEnabled() ? 'checked' : '' ?>>
                        Blurhash bei der Metadaten-Erstellung berechnen
                    </label>
                </p>
            </fieldset>

            <fieldset>
                <legend>CDN (optional)</legend>
                <p>Wenn aktiviert, werden statt lokaler Glide-URLs CDN-URLs ausgegeben (lokales Resizing wird übersprungen).</p>
                <p>
                    <label>
                        <input type="checkbox" name="cdn_enabled" value="1" <?= Config::cdnEnabled() ? 'checked' : '' ?>>
                        CDN aktiviert
                    </label>
                </p>
                <p>
                    <label>CDN Base URL:
                        <input type="text" name="cdn_base" value="<?= self::esc(Config::cdnBase()) ?>" placeholder="https://ik.imagekit.io/abc123" class="form-control">
                    </label>
                </p>
                <p>
                    <label>URL Template:
                        <input type="text" name="cdn_url_template" value="<?= self::esc(Config::cdnUrlTemplate()) ?>" placeholder="tr:w-{w},q-{q},f-{fm}/{src}" class="form-control">
                    </label>
                    <small>Tokens: <code>{w}</code>, <code>{q}</code>, <code>{fm}</code>, <code>{src}</code>.</small>
                </p>
            </fieldset>

            <fieldset>
                <legend>Cache TTLs (Sekunden)</legend>
                <p>
                    <label>Metadata TTL: <input type="number" name="metadata_ttl_seconds" value="<?= (int) Config::get(Config::KEY_METADATA_TTL_SECONDS) ?>" min="60"></label>
                    <label>Sentinel TTL: <input type="number" name="sentinel_ttl_seconds" value="<?= (int) Config::get(Config::KEY_SENTINEL_TTL_SECONDS) ?>" min="5"></label>
                </p>
            </fieldset>

            <button type="submit" name="action" value="save" class="btn btn-save">Speichern</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        $i = (int) $value;
        if ($i < $min) {
            return $min;
        }
        if ($i > $max) {
            return $max;
        }
        return $i;
    }

    /**
     * @return int[]
     */
    private static function parseIntList(string $input): array
    {
        $parts = preg_split('/[,\s;]+/', trim($input)) ?: [];
        $ints = [];
        foreach ($parts as $p) {
            $i = (int) $p;
            if ($i > 0) {
                $ints[] = $i;
            }
        }
        $ints = array_values(array_unique($ints));
        sort($ints);
        return $ints;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
