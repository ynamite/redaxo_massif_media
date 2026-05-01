<?php

declare(strict_types=1);

use Ynamite\Media\Config;

$form = rex_config_form::factory(Config::ADDON);

$form->addFieldset('LQIP (Low-Quality Image Placeholder)');

$f = $form->addCheckboxField(Config::KEY_LQIP_ENABLED);
$f->setLabel('LQIP');
$f->addOption('Inline-Base64-JPEG-Placeholder einbetten', 1);
$f->setNotice(
    'Bettet pro Bild einen sehr kleinen, geblurrten Base64-JPEG-Schnipsel direkt im '
    . '<code>&lt;img&gt;</code>-Tag als <code>background-image</code> Inline-CSS ein. '
    . '<strong>Funktioniert ohne JavaScript</strong> — der Placeholder ist sofort sichtbar, während die '
    . 'Haupt-Variante lädt. Wird beim ersten Zugriff auf das jeweilige Bild generiert und auf Disk gecached.'
);

$f = $form->addInputField('number', Config::KEY_LQIP_WIDTH);
$f->setLabel('Breite (px)');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_LQIP_WIDTH]);
$f->setAttribute('min', '4');
$f->setAttribute('max', '256');

$f = $form->addInputField('number', Config::KEY_LQIP_BLUR);
$f->setLabel('Blur (0–100)');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_LQIP_BLUR]);
$f->setAttribute('min', '0');
$f->setAttribute('max', '100');

$f = $form->addInputField('number', Config::KEY_LQIP_QUALITY);
$f->setLabel('Qualität');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_LQIP_QUALITY]);
$f->setAttribute('min', '1');
$f->setAttribute('max', '100');

$form->addFieldset('Blurhash');

$f = $form->addCheckboxField(Config::KEY_BLURHASH_ENABLED);
$f->setLabel('Blurhash');
$f->addOption('Beim ersten Zugriff auf ein Bild berechnen und in der Asset-Metadata cachen', 1);
$f->setNotice(
    'Erzeugt pro Bild einen kompakten ~30-Zeichen-Hash '
    . '(z. B. <code>LEHV6nWB2yk8pyo0adR*.7kCMdnj</code>), aus dem ein JavaScript-Decoder client-seitig '
    . 'ein geblurrtes Vorschaubild rendern kann. Abrufbar als String über '
    . '<code>Image::blurhash($src)</code> (z. B. für JSON-APIs oder Galerien) oder als '
    . '<code>data-blurhash</code>-Attribut auf dem <code>&lt;img&gt;</code>-Tag über '
    . '<code>Image::for($src)-&gt;withBlurhashAttr()-&gt;render()</code>. '
    . '<strong>Unabhängig vom LQIP</strong> oben — beide können zusammen oder einzeln aktiv sein. '
    . 'LQIP rendert ohne JS sofort, Blurhash braucht einen JS-Decoder im Frontend, ist aber als '
    . 'Roh-String per API verfügbar.'
);

$intro = '<p class="alert alert-info">'
       . '<strong>Zwei unabhängige Strategien</strong> für Lade-Vorschauen: '
       . '<strong>LQIP</strong> als Inline-Base64-JPEG (kein JavaScript nötig, sofort sichtbar) und '
       . '<strong>Blurhash</strong> als kompakter Hash für Client-Side-Rendering oder API-Konsum. '
       . 'Beide können zusammen oder einzeln aktiv sein.'
       . '</p>';

$content = $intro . $form->getMessage() . $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Placeholder', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
