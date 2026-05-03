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
$f->setLabel('Breite (px) <small class="text-muted">(LQIP only)</small>');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_LQIP_WIDTH]);
$f->setAttribute('min', '4');
$f->setAttribute('max', '256');
$f->setNotice('Pixelbreite des intern erzeugten Mini-Bilds, das base64-encoded in die HTML eingebettet wird. Mehr Breite = größere Data-URI; 32 px reicht für die meisten Layouts.');

$f = $form->addInputField('number', Config::KEY_LQIP_BLUR);
$f->setLabel('Blur (0–100) <small class="text-muted">(LQIP only)</small>');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_LQIP_BLUR]);
$f->setAttribute('min', '0');
$f->setAttribute('max', '100');
$f->setNotice('Glide-Blur-Stärke. Höhere Werte glätten das Pixel-Raster der niedrigen Auflösung weiter aus; 5 ist der Default und meist genug.');

$f = $form->addInputField('number', Config::KEY_LQIP_QUALITY);
$f->setLabel('Qualität <small class="text-muted">(LQIP only)</small>');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_LQIP_QUALITY]);
$f->setAttribute('min', '1');
$f->setAttribute('max', '100');
$f->setNotice('WebP-Encoding-Qualität nur für den Placeholder. Niedrige Werte (40 Default) sind okay — der Placeholder wird beim Laden der Hauptvariante ohnehin überlagert.');

$form->addFieldset('Dominante Farbe (Background-Color Placeholder)');

$f = $form->addCheckboxField(Config::KEY_COLOR_ENABLED);
$f->setLabel('Dominante Farbe');
$f->addOption('Pro Bild eine repräsentative Hex-Farbe als <code>background-color</code> einbetten', 1);
$f->setNotice(
    'Berechnet aus dem Quellbild eine einzelne dominante Farbe (~7 Bytes statt ~600 Bytes für ein LQIP) und '
    . 'setzt sie als <code>background-color</code> im <code>style</code>-Attribut. Lässt sich '
    . '<strong>kombiniert mit LQIP</strong> einsetzen — die Farbe ist sofort sichtbar, das LQIP-Bild '
    . 'überlagert sie sobald dekodiert; die fertige Variante überschreibt beides. Erfordert die '
    . '<code>imagick</code>-PHP-Extension; ohne sie wird der Wert still übersprungen. Wird beim ersten '
    . 'Zugriff auf das jeweilige Bild generiert und gecached.'
);

$content = $form->getMessage() . $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Placeholder', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
