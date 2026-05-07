<?php

declare(strict_types=1);

use Ynamite\Media\Backend\ConfigForm;
use Ynamite\Media\Config;

$form = ConfigForm::factory(Config::ADDON);

$form->addFieldset('CDN');

$f = $form->addCheckboxField(Config::KEY_CDN_ENABLED);
$f->setLabel('CDN aktiviert');
$f->addOption('Lokale Glide-Pipeline überspringen, CDN-URLs ausgeben', 1);
$f->setNotice('Wenn aktiviert, werden statt lokaler Glide-Endpoints CDN-URLs ausgegeben. Lokales Resizing entfällt; das CDN übernimmt Format-Negotiation und Auslieferung.');

$f = $form->addTextField(Config::KEY_CDN_BASE);
$f->setLabel('CDN Base');
$f->setAttribute('placeholder', 'https://ik.imagekit.io/abc123');
$f->setNotice('Basis-URL ohne abschließendes Slash.');

$f = $form->addTextField(Config::KEY_CDN_URL_TEMPLATE);
$f->setLabel('URL Template');
$f->setAttribute('placeholder', 'tr:w-{w},q-{q},f-{fm}/{src}');
$f->setNotice('Tokens: <code>{w}</code> (Breite), <code>{q}</code> (Qualität), <code>{fm}</code> (Format), <code>{src}</code> (Quell-Pfad). Beispiel oben für ImageKit.');

$content = $form->getMessage() . $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'CDN', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
