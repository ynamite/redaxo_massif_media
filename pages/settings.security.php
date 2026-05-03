<?php

declare(strict_types=1);

use Ynamite\Media\Config;

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
            echo rex_view::success('Addon Cache geleert.');
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

// Cache panel
$body = '<p>Generierte Bildvarianten werden unter <code>assets/addons/' . htmlspecialchars(Config::ADDON, ENT_QUOTES) . '/cache/</code> abgelegt. '
      . 'Beim regulären REDAXO-Cache-Reset wird dieser Addon-Cache automatisch mit geleert.</p>'
      . '<form action="' . htmlspecialchars($action, ENT_QUOTES) . '" method="post" class="form-inline">'
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

// TTLs panel (rex_config_form)
$form = rex_config_form::factory(Config::ADDON);
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

$content = $form->getMessage() . $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Cache TTLs', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
