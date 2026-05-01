<?php

declare(strict_types=1);

namespace Ynamite\Media\BE;

use rex_config_form;
use rex_csrf_token;
use rex_dir;
use rex_fragment;
use rex_path;
use rex_url;
use rex_view;
use Ynamite\Media\Config;

final class SettingsPage
{
    private const CSRF_TOKEN = 'massif_media_settings';

    public static function render(): void
    {
        echo self::handleActionPost();
        echo self::renderSecuritySection();
        echo self::renderCacheSection();
        echo self::renderConfigForm();
    }

    private static function handleActionPost(): string
    {
        if (rex_request_method() !== 'post') {
            return '';
        }
        $action = (string) rex_post('massif_media_action', 'string', '');
        if ($action === '') {
            return '';
        }
        if (!rex_csrf_token::factory(self::CSRF_TOKEN)->isValid()) {
            return rex_view::error('CSRF Token ungültig — Formular bitte erneut absenden.');
        }
        if ($action === 'regenerate_key') {
            Config::set(Config::KEY_SIGN_KEY, bin2hex(random_bytes(32)));
            return rex_view::success('Sign Key neu generiert. Bestehende URLs sind ungültig.');
        }
        if ($action === 'clear_cache') {
            $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
            if (is_dir($cacheDir)) {
                rex_dir::delete($cacheDir, false);
            }
            return rex_view::success('Addon Cache geleert.');
        }
        return '';
    }

    private static function renderSecuritySection(): string
    {
        $signKey = Config::signKey();
        $hidden = rex_csrf_token::factory(self::CSRF_TOKEN)->getHiddenField();
        $action = rex_url::currentBackendPage();

        $body = '<p>HMAC Sign-Key (beim Aktivieren des Addons automatisch erzeugt). '
              . 'Wird zur Signierung der Bild-URLs verwendet.</p>'
              . '<pre style="white-space:pre-wrap;word-break:break-all"><code>'
              . self::esc($signKey !== '' ? $signKey : '(nicht gesetzt)')
              . '</code></pre>'
              . '<form action="' . self::esc($action) . '" method="post" class="form-inline">'
              . $hidden
              . '<input type="hidden" name="massif_media_action" value="regenerate_key">'
              . '<button type="submit" class="btn btn-warning">'
              . '<i class="rex-icon fa-refresh"></i> Sign Key neu generieren'
              . '</button>'
              . '</form>'
              . '<p class="help-block" style="margin-top:10px">Beim Regenerieren werden alle bisher signierten URLs ungültig. Cached Files bleiben aber erreichbar — der Cache muss bei Bedarf separat geleert werden.</p>';

        return self::section('Sicherheit', $body, 'info');
    }

    private static function renderCacheSection(): string
    {
        $hidden = rex_csrf_token::factory(self::CSRF_TOKEN)->getHiddenField();
        $action = rex_url::currentBackendPage();

        $body = '<p>Generierte Bildvarianten werden unter <code>assets/addons/' . self::esc(Config::ADDON) . '/cache/</code> abgelegt. '
              . 'Beim regulären REDAXO-Cache-Reset wird dieser Addon-Cache automatisch mit geleert.</p>'
              . '<form action="' . self::esc($action) . '" method="post" class="form-inline">'
              . $hidden
              . '<input type="hidden" name="massif_media_action" value="clear_cache">'
              . '<button type="submit" class="btn btn-warning">'
              . '<i class="rex-icon fa-trash"></i> Addon Cache jetzt leeren'
              . '</button>'
              . '</form>';

        return self::section('Cache', $body, 'info');
    }

    private static function renderConfigForm(): string
    {
        $form = rex_config_form::factory(Config::ADDON);

        // Formate
        $form->addFieldset('Formate');

        $f = $form->addTextField(Config::KEY_FORMATS);
        $f->setLabel('Formate');
        $f->setAttribute('placeholder', 'avif,webp,jpg');
        $f->setNotice('Komma-separiert. Reihenfolge entspricht der Browser-Präferenz im &lt;picture&gt; — letztes = Fallback.');

        $f = $form->addInputField('number', Config::KEY_QUALITY_AVIF);
        $f->setLabel('AVIF Qualität');
        $f->setAttribute('min', '1');
        $f->setAttribute('max', '100');

        $f = $form->addInputField('number', Config::KEY_QUALITY_WEBP);
        $f->setLabel('WebP Qualität');
        $f->setAttribute('min', '1');
        $f->setAttribute('max', '100');

        $f = $form->addInputField('number', Config::KEY_QUALITY_JPG);
        $f->setLabel('JPG Qualität');
        $f->setAttribute('min', '1');
        $f->setAttribute('max', '100');

        // Breakpoints
        $form->addFieldset('Breakpoints (next/image dual-pool)');

        $f = $form->addTextField(Config::KEY_DEVICE_SIZES);
        $f->setLabel('Device Sizes');
        $f->setAttribute('placeholder', '640,750,828,1080,1200,1920,2048,3840');
        $f->setNotice('Komma-separiert. Große Breakpoints für Layout-Pixel.');

        $f = $form->addTextField(Config::KEY_IMAGE_SIZES);
        $f->setLabel('Image Sizes');
        $f->setAttribute('placeholder', '16,32,48,64,96,128,256,384');
        $f->setNotice('Komma-separiert. Kleine Breakpoints für Thumbnails.');

        $f = $form->addTextField(Config::KEY_DEFAULT_SIZES);
        $f->setLabel('Default <code>sizes</code>');
        $f->setAttribute('placeholder', '(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw');
        $f->setNotice('Wird genutzt, wenn beim Aufruf kein <code>sizes</code> übergeben wird.');

        // LQIP
        $form->addFieldset('Placeholder (LQIP)');

        $f = $form->addCheckboxField(Config::KEY_LQIP_ENABLED);
        $f->setLabel('LQIP');
        $f->addOption('Inline-Placeholder erzeugen', 1);

        $f = $form->addInputField('number', Config::KEY_LQIP_WIDTH);
        $f->setLabel('Breite (px)');
        $f->setAttribute('min', '4');
        $f->setAttribute('max', '256');

        $f = $form->addInputField('number', Config::KEY_LQIP_BLUR);
        $f->setLabel('Blur (0–100)');
        $f->setAttribute('min', '0');
        $f->setAttribute('max', '100');

        $f = $form->addInputField('number', Config::KEY_LQIP_QUALITY);
        $f->setLabel('Qualität');
        $f->setAttribute('min', '1');
        $f->setAttribute('max', '100');

        // Blurhash
        $form->addFieldset('Blurhash');

        $f = $form->addCheckboxField(Config::KEY_BLURHASH_ENABLED);
        $f->setLabel('Blurhash');
        $f->addOption('Bei der Metadaten-Erstellung berechnen', 1);
        $f->setNotice('Abrufbar via <code>Image::blurhash($src)</code> oder als <code>data-blurhash</code> Attribut über den Builder.');

        // CDN
        $form->addFieldset('CDN (optional)');

        $f = $form->addCheckboxField(Config::KEY_CDN_ENABLED);
        $f->setLabel('CDN aktiviert');
        $f->addOption('Lokale Glide-Pipeline überspringen, CDN-URLs ausgeben', 1);

        $f = $form->addTextField(Config::KEY_CDN_BASE);
        $f->setLabel('CDN Base');
        $f->setAttribute('placeholder', 'https://ik.imagekit.io/abc123');

        $f = $form->addTextField(Config::KEY_CDN_URL_TEMPLATE);
        $f->setLabel('URL Template');
        $f->setAttribute('placeholder', 'tr:w-{w},q-{q},f-{fm}/{src}');
        $f->setNotice('Tokens: <code>{w}</code>, <code>{q}</code>, <code>{fm}</code>, <code>{src}</code>.');

        // Cache TTLs
        $form->addFieldset('Cache TTLs (Sekunden)');

        $f = $form->addInputField('number', Config::KEY_METADATA_TTL_SECONDS);
        $f->setLabel('Metadata TTL');
        $f->setAttribute('min', '60');

        $f = $form->addInputField('number', Config::KEY_SENTINEL_TTL_SECONDS);
        $f->setLabel('Sentinel TTL');
        $f->setAttribute('min', '5');

        $body = $form->getMessage() . $form->get();
        return self::section('Einstellungen', $body, 'edit');
    }

    private static function section(string $title, string $body, string $class): string
    {
        $fragment = new rex_fragment();
        $fragment->setVar('class', $class, false);
        $fragment->setVar('title', $title, false);
        $fragment->setVar('body', $body, false);
        return $fragment->parse('core/page/section.php');
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
