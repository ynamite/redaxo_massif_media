<?php

declare(strict_types=1);

use Ynamite\Media\Config;

$form = rex_config_form::factory(Config::ADDON);

$form->addFieldset('LQIP');

$f = $form->addCheckboxField(Config::KEY_LQIP_ENABLED);
$f->setLabel('LQIP');
$f->addOption('Inline-Placeholder erzeugen (32 px Base64-JPEG, JS-frei)', 1);

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

$form->addFieldset('Blurhash');

$f = $form->addCheckboxField(Config::KEY_BLURHASH_ENABLED);
$f->setLabel('Blurhash');
$f->addOption('Bei der Metadaten-Erstellung berechnen', 1);
$f->setNotice('Abrufbar via <code>Image::blurhash($src)</code> oder als <code>data-blurhash</code> Attribut über den Builder.');

$content = $form->getMessage() . $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Placeholder', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
