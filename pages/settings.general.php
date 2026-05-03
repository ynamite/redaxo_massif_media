<?php

declare(strict_types=1);

use Ynamite\Media\Config;

$form = rex_config_form::factory(Config::ADDON);

$form->addFieldset('Formate');

$f = $form->addTextField(Config::KEY_FORMATS);
$f->setLabel('Formate');
$f->setAttribute('placeholder', 'avif,webp,jpg');
$f->setNotice('Komma-separiert. Reihenfolge entspricht der Browser-Präferenz im &lt;picture&gt; — letztes = Fallback.');

$f = $form->addInputField('number', Config::KEY_QUALITY_AVIF);
$f->setLabel('AVIF Qualität');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_QUALITY_AVIF]);
$f->setAttribute('min', '1');
$f->setAttribute('max', '100');
$f->setNotice('1–100. AVIF erlaubt sehr aggressive Kompression bei wenig sichtbarem Verlust — der Default 50 ist ein guter Kompromiss.');

$f = $form->addInputField('number', Config::KEY_QUALITY_WEBP);
$f->setLabel('WebP Qualität');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_QUALITY_WEBP]);
$f->setAttribute('min', '1');
$f->setAttribute('max', '100');
$f->setNotice('1–100. Default 75. Darunter werden Details schnell sichtbar weicher.');

$f = $form->addInputField('number', Config::KEY_QUALITY_JPG);
$f->setLabel('JPG Qualität');
$f->setAttribute('class', 'form-control');
$f->setAttribute('style', 'width: 100px');
$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_QUALITY_JPG]);
$f->setAttribute('min', '1');
$f->setAttribute('max', '100');
$f->setNotice('1–100. Fallback-Format für ältere Browser. Default 80 ist Sweet-Spot zwischen Größe und Qualität.');

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

$content = $form->getMessage() . $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Allgemein', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
