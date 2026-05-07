<?php

declare(strict_types=1);

use Ynamite\Media\Backend\ConfigForm;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\CacheStats;

$csrfToken = rex_csrf_token::factory('massif_media_security');

// Action handlers
if (rex_request_method() === 'post' && (string) rex_post('massif_media_action', 'string', '') !== '') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('CSRF Token ungültig — Formular bitte erneut absenden.');
    } else {
        $action = (string) rex_post('massif_media_action', 'string', '');
        if ($action === 'regenerate_key') {
            Config::set(Config::KEY_SIGN_KEY, bin2hex(random_bytes(32)));
            echo rex_view::success('Sign Key neu generiert. Bestehende URLs sind ungültig.');
        } elseif ($action === 'clear_cache') {
            $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
            if (is_dir($cacheDir)) {
                rex_dir::delete($cacheDir, false);
            }
            // Bump the cache-generation token alongside the wipe so already-
            // emitted browser cache entries (immutable Cache-Control) get a
            // new `&g=` segment on the next render and refetch fresh.
            // Without this, browsers happily serve the deleted variant from
            // their local cache and the server never sees a regen request.
            Config::bumpCacheGeneration();
            echo rex_view::success('Addon Cache geleert.');
        } elseif ($action === 'refresh_stats') {
            (new CacheStats())->compute(forceRefresh: true);
            echo rex_view::success('Cache-Statistik neu berechnet.');
        }
    }
}

$action = rex_url::currentBackendPage();
$hidden = $csrfToken->getHiddenField();

// Sicherheit panel
$signKey = Config::signKey();
$body = '<p>HMAC Sign-Key (beim Aktivieren des Addons automatisch erzeugt). '
      . 'Wird zur Signierung der Bild-URLs verwendet.</p>'
      . '<pre style="white-space:pre-wrap;word-break:break-all"><code>'
      . htmlspecialchars($signKey !== '' ? $signKey : '(nicht gesetzt)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
      . '</code></pre>'
      . '<form action="' . htmlspecialchars($action, ENT_QUOTES) . '" method="post" class="form-inline">'
      . $hidden
      . '<input type="hidden" name="massif_media_action" value="regenerate_key">'
      . '<button type="submit" class="btn btn-warning">'
      . '<i class="rex-icon fa-refresh"></i> Sign Key neu generieren'
      . '</button>'
      . '</form>'
      . '<p class="help-block" style="margin-top:10px">'
      . 'Beim Regenerieren werden alle bisher signierten URLs ungültig. Cached Files bleiben aber erreichbar — der Cache muss bei Bedarf separat geleert werden.'
      . '</p>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'info', false);
$fragment->setVar('title', 'Sicherheit', false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// Cache panel — stats + clear button. Always-fresh walk on page load: the
// 5-minute memo in CacheStats was over-cautious for a backend-only page,
// and the user observation was that the lag was actively misleading after
// cache clears or new variant generation. Sub-second walk on a multi-thousand-
// file cache is fine for this surface.
$stats = (new CacheStats())->compute(forceRefresh: true);

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    do {
        $bytes /= 1024;
        $i++;
    } while ($bytes >= 1024 && $i < count($units) - 1);
    return sprintf('%.1f %s', $bytes, $units[$i]);
};

$kindLabels = [
    'variants' => 'Varianten (avif/webp/jpg)',
    'animated' => 'Animated WebP',
    'external' => 'Externe URLs (Origin + Varianten)',
    'lqip'     => 'LQIP (Inline Base64)',
    'color'    => 'Dominante Farbe',
    'meta'     => 'Metadata-Sidecars',
];

$rows = '';
foreach ($kindLabels as $kind => $label) {
    $count = (int) $stats['by_kind'][$kind]['count'];
    $bytes = (int) $stats['by_kind'][$kind]['bytes'];
    $rows .= sprintf(
        '<tr><td>%s</td><td class="text-right">%s</td><td class="text-right">%s</td></tr>',
        htmlspecialchars($label, ENT_QUOTES),
        number_format($count, 0, ',', '.'),
        $formatBytes($bytes),
    );
}

$oldestStr = $stats['oldest_mtime'] !== null
    ? date('Y-m-d H:i', (int) $stats['oldest_mtime'])
    : '–';
$newestStr = $stats['newest_mtime'] !== null
    ? date('Y-m-d H:i', (int) $stats['newest_mtime'])
    : '–';
$computedStr = date('Y-m-d H:i:s', (int) $stats['computed_at']);

$body = '<p>Generierte Bildvarianten werden unter <code>assets/addons/' . htmlspecialchars(Config::ADDON, ENT_QUOTES) . '/cache/</code> abgelegt. '
      . 'Beim regulären REDAXO-Cache-Reset wird dieser Addon-Cache automatisch mit geleert.</p>'
      . '<table class="table table-striped table-bordered" style="margin-top:15px;max-width:600px">'
      . '<thead><tr><th>Kategorie</th><th class="text-right">Dateien</th><th class="text-right">Größe</th></tr></thead>'
      . '<tbody>' . $rows . '</tbody>'
      . '<tfoot><tr><th>Gesamt</th>'
      . '<th class="text-right">' . number_format((int) $stats['file_count'], 0, ',', '.') . '</th>'
      . '<th class="text-right">' . $formatBytes((int) $stats['total_bytes']) . '</th>'
      . '</tr></tfoot>'
      . '</table>'
      . '<p class="text-muted" style="font-size:12px;margin-top:10px">'
      . 'Älteste Datei: ' . htmlspecialchars($oldestStr, ENT_QUOTES) . ' · '
      . 'Neueste Datei: ' . htmlspecialchars($newestStr, ENT_QUOTES) . ' · '
      . 'Berechnet: ' . htmlspecialchars($computedStr, ENT_QUOTES)
      . '</p>'
      . '<form action="' . htmlspecialchars($action, ENT_QUOTES) . '" method="post" class="form-inline" style="margin-top:10px">'
      . $hidden
      . '<input type="hidden" name="massif_media_action" value="refresh_stats">'
      . '<button type="submit" class="btn btn-default">'
      . '<i class="rex-icon fa-refresh"></i> Statistik neu berechnen'
      . '</button>'
      . ' '
      . '</form>'
      . '<form action="' . htmlspecialchars($action, ENT_QUOTES) . '" method="post" class="form-inline" style="margin-top:10px">'
      . $hidden
      . '<input type="hidden" name="massif_media_action" value="clear_cache">'
      . '<button type="submit" class="btn btn-warning">'
      . '<i class="rex-icon fa-trash"></i> Addon Cache jetzt leeren'
      . '</button>'
      . '</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'info', false);
$fragment->setVar('title', 'Cache', false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// TTLs panel (rex_config_form via our subclass — picks up auto-clear-on-content-affecting-save)
$form = ConfigForm::factory(Config::ADDON);
$form->addFieldset('Cache TTLs (Sekunden)');

$f = $form->addInputField('number', Config::KEY_METADATA_TTL_SECONDS);
$f->setLabel('Metadata TTL');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 140px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_METADATA_TTL_SECONDS]);
$f->setAttribute('min', '60');
$f->setNotice('Wie lange Asset-Metadaten (intrinsische Maße, Mime, Focal-Point) gecached bleiben.');

$f = $form->addInputField('number', Config::KEY_SENTINEL_TTL_SECONDS);
$f->setLabel('Sentinel TTL');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 140px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_SENTINEL_TTL_SECONDS]);
$f->setAttribute('min', '5');
$f->setNotice('Kurze TTL für fehlgeschlagene Reads (verhindert Hammering bei kaputten Assets).');

$form->addFieldset('Externe URL-Quellen');

$f = $form->addInputField('number', Config::KEY_EXTERNAL_TTL_SECONDS);
$f->setLabel('External URL TTL');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 140px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_EXTERNAL_TTL_SECONDS]);
$f->setAttribute('min', '60');
$f->setNotice('Wie lange ein extern geladenes Bild als frisch gilt, bevor ein Conditional GET (304-Probe) auf das Original ausgelöst wird. Default: 86400 (24h).');

$f = $form->addInputField('number', Config::KEY_EXTERNAL_TIMEOUT_SECONDS);
$f->setLabel('External fetch timeout');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_EXTERNAL_TIMEOUT_SECONDS]);
$f->setAttribute('min', '1');
$f->setAttribute('max', '120');
$f->setNotice('Inaktivitäts-Timeout pro Fetch in Sekunden. Default: 15.');

$f = $form->addInputField('number', Config::KEY_EXTERNAL_MAX_BYTES);
$f->setLabel('External max bytes');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 140px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_EXTERNAL_MAX_BYTES]);
$f->setAttribute('min', '1024');
$f->setNotice('Maximalgröße pro externer Quelle. Bei Überschreitung wird der Fetch abgebrochen. Default: 26214400 (25 MB).');

$f = $form->addTextAreaField(Config::KEY_EXTERNAL_HOST_ALLOWLIST);
$f->setLabel('External host allowlist');
$f->setAttribute('rows', '4');
$f->setAttribute('placeholder', "^images\\.example\\.com$\n^cdn\\.example\\.org$");
$f->setNotice('Optional: Eine Regex pro Zeile (anchored). Ist die Liste leer, sind alle Hosts erlaubt. Bei nicht-leerer Liste muss der Hostname mindestens eines Pattern matchen.');

$content = $form->getMessage() . $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Cache TTLs & externe Quellen', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
