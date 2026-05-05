# MASSIF Media

**MASSIF Media** ist ein REDAXO-Addon für moderne, schnelle und responsive Bild- und Video-Auslieferung.

Es hilft dabei, Bilder automatisch in passenden Grössen und modernen Formaten auszuliefern — ohne dass Redakteurinnen, Redakteure oder Entwickler jedes Bild manuell vorbereiten müssen.

Kurz gesagt:

- Bilder laden schneller.
- Moderne Formate wie AVIF und WebP werden automatisch genutzt.
- Der Browser bekommt die passende Bildgrösse.
- Layout-Verschiebungen werden reduziert.
- Platzhalter verbessern die wahrgenommene Ladezeit.
- Redakteure können Bilder und Videos direkt über einfache Platzhalter einfügen.
- Technische Details bleiben kontrollierbar und sicher.

Inspiriert von [Next/Image](https://nextjs.org/docs/api-reference/next/image).

---

## Was macht MASSIF Media?

Webseiten brauchen heute Bilder in verschiedenen Grössen und Formaten. Ein grosses Hero-Bild auf einem Desktop braucht eine andere Variante als dasselbe Bild auf einem Smartphone. Gleichzeitig unterstützen moderne Browser Formate wie AVIF oder WebP, die oft deutlich kleinere Dateien liefern als klassische JPGs.

MASSIF Media übernimmt diese Arbeit automatisch.

Du legst ein Bild wie gewohnt im REDAXO-Mediapool ab und verwendest es im Template, Modul oder Textfeld. Das Addon erzeugt daraus bei Bedarf passende Varianten und liefert dem Browser ein modernes `<picture>`-Markup.

Der Browser entscheidet anschliessend selbst, welches Format und welche Grösse am besten passt.

---

## Die wichtigsten Vorteile

### Schnellere Bilder

MASSIF Media erzeugt responsive Bildvarianten mit AVIF, WebP und JPG.

Der Browser lädt automatisch die beste verfügbare Variante. Moderne Browser bekommen moderne Formate, ältere Browser bekommen einen sicheren Fallback.

```html
<picture>
  <source
    type="image/avif"
    srcset="…" />
  <source
    type="image/webp"
    srcset="…" />
  <img
    src="…"
    alt="…" />
</picture>
```

Es findet **kein Accept-Header-Sniffing** statt. Der Browser entscheidet selbst anhand des `<picture>`-Markup.

---

### Varianten werden nur bei Bedarf erzeugt

Das Addon nutzt [league/glide](https://glide.thephpleague.com/) für das Resizing.

Bildvarianten werden **on demand** erzeugt. Das bedeutet: Eine Variante entsteht erst dann, wenn sie wirklich angefragt wird.

Das spart Speicherplatz und verhindert unnötige Vorarbeit.

---

### Funktioniert ohne spezielle Server-Konfiguration

MASSIF Media ist **self-contained**.

Ein `PACKAGES_INCLUDED`-Hook fängt Cache-URLs im REDAXO-Frontend ab und liefert die passende Bildvariante aus.

Das funktioniert unter anderem mit:

- Apache
- nginx
- Laravel Herd
- Valet

Es sind keine `.htaccess`-Anpassungen, nginx-Tweaks oder Valet-Driver-Patches nötig.

Optional können Cache-Hits über mitgelieferte Server-Snippets direkt vom Webserver ausgeliefert werden. Das ist schneller, weil PHP dann komplett umgangen wird.

Mitgeliefert sind:

- `assets/.htaccess` für Apache
- `assets/nginx.conf.example` für Standalone-nginx

---

### Schutz vor missbräuchlichen Bildanfragen

Alle Varianten-URLs sind HMAC-signiert.

Dadurch kann niemand einfach beliebige Grössen, Qualitäten oder Filterkombinationen anfragen und damit den Speicher fluten.

---

### Bessere Lade-Wahrnehmung durch Platzhalter

Für Bilder kann automatisch ein **LQIP** erzeugt werden.

LQIP bedeutet **Low-Quality Image Placeholder**. Dabei wird ein sehr kleines, unscharfes Vorschaubild direkt inline eingebettet. Das Bild wirkt dadurch früher sichtbar, obwohl die finale Variante noch lädt.

Zusätzlich kann eine **dominante Farbe** berechnet werden. Diese wird als Hintergrundfarbe gesetzt und ist sofort sichtbar.

Beides funktioniert ohne JavaScript.

---

### Fokuspunkt-Unterstützung

Wenn das optionale [`focuspoint`](https://github.com/yakamara/redaxo_focuspoint) Addon installiert ist, kann MASSIF Media dessen Fokuspunkt verwenden.

Das ist besonders hilfreich bei Crops, zum Beispiel bei Portraits oder Hero-Bildern. Der wichtige Bildbereich bleibt sichtbar.

Ein Fokuspunkt kann auch pro Aufruf manuell überschrieben werden.

---

### Für Redakteure geeignet

Mit `REX_PIC[…]` und `REX_VIDEO[…]` können Bilder und Videos direkt in REDAXO-Inhalten verwendet werden.

Beispiel:

```text
REX_PIC[src="hero.jpg" alt="Aussicht über das Tal" width="1440" ratio="16:9"]
```

Daraus erzeugt MASSIF Media automatisch das vollständige responsive Bild-Markup.

Redakteure müssen dafür kein PHP schreiben.

---

### Optionaler CDN-Override

MASSIF Media kann optional so konfiguriert werden, dass Bild-URLs über ein CDN laufen.

Der CDN-Override ist kompatibel mit Diensten wie:

- ImageKit
- Cloudinary
- Imgix

---

### Backend mit Einstellungen und Dokumentation

Das Addon bringt Backend-Settings mit Tabs mit:

- Allgemein
- Placeholder
- CDN
- Sicherheit & Cache

Zusätzlich wird diese README direkt im REDAXO-Backend gerendert:

**AddOns → MASSIF Media → Dokumentation**

---

## Anforderungen

- REDAXO 5.13+
- PHP 8.2+
- **Imagick** empfohlen
- Für AVIF-Output zusätzlich **libheif/libavif** in der Imagick-Build
- GD funktioniert auch, kann aber kein AVIF und liefert qualitativ schwächere Skalierungen
- Optional: [`focuspoint`](https://github.com/yakamara/redaxo_focuspoint) Addon für visuelle Focal-Point-Pflege

Für lokale Entwicklung:

```bash
composer install
```

Tests:

```bash
composer test
composer test:unit
composer test:integration
```

Hinweis für Entwicklung am Addon:

Vor jedem Commit von `vendor/`-Änderungen:

```bash
composer install --no-dev
bin/check-vendor
```

---

## Installation

1. Addon ins REDAXO-System hochladen oder über REDAXO Connect installieren.
2. Addon aktivieren.
3. HMAC Sign-Key und Cache-Verzeichnis werden automatisch eingerichtet.
4. Optional Einstellungen anpassen unter:

**AddOns → MASSIF Media → Einstellungen**

Die Einstellungen sind in Tabs gruppiert:

| Tab                | Inhalt                                                                   |
| ------------------ | ------------------------------------------------------------------------ |
| Allgemein          | Formate, Qualität pro Format, Breakpoint-Pools, Default-`sizes`-Attribut |
| Placeholder        | LQIP-Tuning und dominante Farbe                                          |
| CDN                | Optionale CDN-Auslieferung mit Template                                  |
| Sicherheit & Cache | Sign-Key anzeigen/regenerieren, Cache leeren, Cache-TTLs                 |

Die Defaults sind sinnvoll gesetzt. Die meisten Installationen müssen die Einstellungen nicht anfassen.

---

## Schnellstart für Entwickler

```php
use Ynamite\Media\Image;
use Ynamite\Media\Video;
use Ynamite\Media\Enum\Loading;

// Standardfall — eine Zeile
echo Image::picture(
    src:   'hero.jpg',
    alt:   'Aussicht',
    width: 1440,
    ratio: 16 / 9,
    sizes: '(min-width: 1024px) 50vw, 100vw',
);

// Komplexere Fälle — Builder
echo Image::for('portrait.jpg')
    ->alt('Portrait')
    ->width(800)
    ->ratio(3, 4)
    ->preload()
    ->focal('40% 30%')
    ->widths([320, 640, 800, 1200])
    ->quality(['avif' => 50, 'webp' => 75, 'jpg' => 80])
    ->render();

// Video — analoges API-Design
echo Video::render(
    src:      'clip.mp4',
    poster:   'thumb.jpg',
    autoplay: true,
    muted:    true,
    loop:     true,
);

echo Video::for('clip.mp4')
    ->poster('thumb.jpg')
    ->autoplay()->muted()->loop()
    ->render();
```

Für Redakteure und Textfelder gibt es zusätzlich die Platzhalter `REX_PIC[…]` und `REX_VIDEO[…]`.

---

# Bilder mit REX_PIC

`REX_PIC[…]` ist ein REDAXO-nativer Platzhalter für Bilder.

Er kann direkt in Slice-Inhalten, Modul-Output, Modul-Input und Templates verwendet werden.

Beim Rebuild des Article-Caches wandelt REDAXO den Platzhalter in PHP-Code um. Beim Rendern der Seite entsteht daraus das vollständige `<picture>`-Markup.

Der Vorteil:

- Redakteure können Bilder einfach einfügen.
- Entwickler behalten die volle Kontrolle über Markup und Performance.
- Es gibt keinen Regex-Overhead pro Seitenaufruf.

---

## REX_PIC Beispiele

### Einfachster Fall

```text
REX_PIC[src="hero.jpg" alt="Aussicht über das Tal"]
```

---

### Mit Render-Breite und `sizes`

```text
REX_PIC[src="hero.jpg" alt="Aussicht" width="1440" sizes="100vw"]
```

---

### Mit Aspect-Ratio

```text
REX_PIC[src="banner.jpg" alt="Promo-Banner" width="1920" ratio="21:9"]
```

`ratio` akzeptiert:

- `16:9`
- `16/9`
- Dezimalwerte wie `1.7777`

Das hilft, Layout-Verschiebungen zu vermeiden.

---

### Innerhalb eines responsiven Layouts

```text
REX_PIC[src="card.jpg" alt="Produktbild" width="600" ratio="4:3" sizes="(min-width: 768px) 33vw, 100vw"]
```

---

### Above-the-fold mit Preload

```text
REX_PIC[src="hero.jpg" alt="Aussicht" width="1920" preload="true"]
```

`preload="true"` injiziert ein `<link rel="preload" as="image">` in den `<head>` über den `OUTPUT_FILTER`.

Das ist vor allem für wichtige Hero- oder LCP-Bilder gedacht.

---

### Eager Loading und hohe Fetch-Priorität

```text
REX_PIC[src="hero.jpg" alt="Aussicht" width="1920" loading="eager" fetchpriority="high"]
```

Das ist sinnvoll für das wichtigste Above-the-fold-Bild.

---

### Mit Focal-Point

```text
REX_PIC[src="portrait.jpg" alt="Portrait" width="800" ratio="3:4" focal="40% 30%"]
```

`focal` überschreibt für diesen einen Aufruf den Wert aus dem optionalen `focuspoint`-Addon.

---

### Mit CSS-Klasse

```text
REX_PIC[src="hero.jpg" alt="Aussicht" width="1440" class="rounded shadow-lg"]
```

---

### SVG-Logo

```text
REX_PIC[src="logo.svg" alt="Firmenlogo" width="240" height="60"]
```

SVG und GIF werden direkt durchgereicht.

Bei SVG und nicht animierten GIFs gibt es kein `<picture>` und kein Resizing, sondern ein einfaches `<img>`.

---

### Innerhalb eines Markdown-Textfeldes

```markdown
## Über uns

Hier eine Aufnahme aus unserem Atelier:

REX_PIC[src="atelier.jpg" alt="Blick ins Atelier" width="1200" ratio="3:2"]

Lorem ipsum …
```

Der Editor sieht im WYSIWYG nur den `REX_PIC[…]` String. Gerendert wird daraus ein vollständiges `<picture>`-Element mit Sources, LQIP und Layout-Reservierung.

---

## REX_PIC Attribute

| Attribut        | Typ    | Default                                         | Beschreibung                                                        |
| --------------- | ------ | ----------------------------------------------- | ------------------------------------------------------------------- |
| `src`           | string | — Pflicht                                       | Dateiname im REDAXO-Mediapool                                       |
| `alt`           | string | leer → `aria-hidden="true"`                     | Alt-Text. Fehlend oder leer setzt das Bild semantisch als dekorativ |
| `width`         | int    | intrinsische Breite                             | Render-Breite in px für das HTML-`width`-Attribut                   |
| `height`        | int    | aus `width` × `ratio`, sonst intrinsisch        | Render-Höhe in px                                                   |
| `ratio`         | string | intrinsisches Seitenverhältnis                  | Aspect-Ratio wie `16:9`, `16/9` oder `1.7777`                       |
| `sizes`         | string | aus Settings `default_sizes`                    | `sizes`-Attribut für responsive Bildauswahl                         |
| `loading`       | string | `lazy`                                          | `lazy` oder `eager`                                                 |
| `decoding`      | string | `async`                                         | `async`, `sync` oder `auto`                                         |
| `fetchpriority` | string | `auto`                                          | `auto`, `high` oder `low`                                           |
| `focal`         | string | aus `focuspoint`, sonst `50% 50%`               | `X% Y%` oder `0.5,0.3`                                              |
| `preload`       | bool   | `false`                                         | `"true"` injiziert ein `<link rel="preload">` in den `<head>`       |
| `class`         | string | —                                               | CSS-Klasse(n) für `<img>` beziehungsweise `<picture>`               |
| `fit`           | string | `cover`, wenn `ratio` oder `height` gesetzt ist | `cover`, `contain`, `stretch` oder `none`                           |

Wichtig zu `width`:

`width` begrenzt das `srcset` nicht. Der Browser kann weiterhin aus der vollen Breakpoint-Auswahl wählen, damit HiDPI-Screens mit 2x oder 3x eine schärfere Variante laden können.

Wer das `srcset` explizit eingrenzen will, nutzt die PHP-API:

```php
Image::for($src)->widths([320, 640, 800])->render();
```

---

# Cropping mit `fit`

Sobald `ratio` oder `height` gesetzt ist, wird das Bild standardmässig so gecroppt, dass es exakt in die gewünschte Layout-Box passt.

Der Default-Modus ist `cover`.

Dabei wird der Fokuspunkt berücksichtigt:

- aus dem optionalen `focuspoint`-Addon
- oder manuell via `focal="X% Y%"`

| Modus     | Verhalten                                                          |
| --------- | ------------------------------------------------------------------ |
| `cover`   | Box ausfüllen, Überstand cropen, Fokuspunkt zentrieren             |
| `contain` | Bild proportional verkleinern, bis es vollständig in die Box passt |
| `stretch` | Bild auf Box-Masse verzerren                                       |
| `none`    | Kein Crop, nur Layout-Reservierung                                 |

Beispiele:

```text
REX_PIC[src="hero.jpg" width="800" ratio="1:1"]
REX_PIC[src="hero.jpg" width="800" ratio="1:1" focal="30% 70%"]
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="contain"]
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="none"]
```

Wenn `ratio` exakt mit dem intrinsischen Seitenverhältnis der Quelle übereinstimmt, überspringt das Addon den Crop. Dadurch entsteht kein zusätzlicher Cache-Eintrag.

Bei `cover` und `contain` wird die `srcset`-Auswahl zusätzlich auf die Crop-Dimensionen begrenzt. Beispiel:

Ein Quellbild mit `5712×4284` und `ratio="9:16"` endet im `srcset` bei `2409w`, also `floor(4284 × 9 / 16)`. Dadurch muss Glide nicht hochskalieren.

`stretch` ignoriert diesen Cap, weil es bewusst auf jede Grösse verzerren kann.

---

# Bildfilter

Glide-basierte Image-Filter können direkt als `REX_PIC`-Attribute genutzt werden.

Unterstützt werden:

- klassische Color-Tweaks
- Sharpen
- Blur
- Color-Presets
- Background-Color
- Border
- Flip
- Orient
- Watermark

| Attribut     | Wert                            | Beschreibung                             |
| ------------ | ------------------------------- | ---------------------------------------- |
| `brightness` | -100..100                       | Helligkeit, 0 = unverändert              |
| `contrast`   | -100..100                       | Kontrast                                 |
| `gamma`      | 0.1..9.99                       | Gamma-Korrektur                          |
| `sharpen`    | 0..100                          | Schärfung                                |
| `blur`       | 0..100                          | Weichzeichner                            |
| `pixelate`   | 0..1000                         | Pixelblock-Grösse                        |
| `filter`     | `greyscale` oder `sepia`        | Color-Preset                             |
| `bg`         | 6 Hex, z. B. `ffffff`           | Hintergrundfarbe                         |
| `border`     | `width,color,method`            | Rahmen, z. B. `border="2,000000,expand"` |
| `flip`       | `h`, `v` oder `both`            | Spiegelung                               |
| `orient`     | `auto`, `0`, `90`, `180`, `270` | Rotation                                 |

Numerische Werte ausserhalb des erlaubten Bereichs werden automatisch geclampt.

Beispiele:

```text
brightness="200"
```

wird zu:

```text
brightness="100"
```

Ungültige Hex-Werte werden ignoriert.

Unbekannte String-Werte wie `filter="nonsense"` werden an Glide durchgereicht. Glide ignoriert nicht erkannte Filter still.

Beispiele:

```text
REX_PIC[src="hero.jpg" width="800" filter="sepia"]
REX_PIC[src="hero.jpg" width="800" brightness="10" sharpen="20"]
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="contain" bg="ffffff"]
REX_PIC[src="hero.jpg" width="800" flip="h" orient="90"]
```

---

# Watermark

Wasserzeichen können über acht Attribute gesetzt werden.

| Attribut    | Wert           | Beschreibung                                          |
| ----------- | -------------- | ----------------------------------------------------- |
| `mark`      | string         | Pfad im REDAXO-Mediapool, Pflicht für Watermark       |
| `marks`     | 0.0..1.0       | Relative Grösse, z. B. `0.25` für 25 % der Bildbreite |
| `markw`     | int            | Pixel-Breite, überschreibt `marks`                    |
| `markh`     | int            | Pixel-Höhe, überschreibt `marks`                      |
| `markpos`   | Glide-Position | Position des Wasserzeichens                           |
| `markpad`   | int            | Abstand zum Rand in px                                |
| `markalpha` | 0..100         | Deckkraft                                             |
| `markfit`   | Glide-Fit      | Einpassung des Wasserzeichens in seine Box            |

Unterstützte Positionen für `markpos`:

- `top-left`
- `top`
- `top-right`
- `left`
- `center`
- `right`
- `bottom-left`
- `bottom`
- `bottom-right`

Beispiel:

```text
REX_PIC[src="hero.jpg" width="1200" mark="logos/brand.png" marks="0.2" markpos="bottom-right" markpad="20" markalpha="70"]
```

PHP-API:

```php
echo Image::for('hero.jpg')
    ->width(1200)
    ->watermark('logos/brand.png', size: 0.2, position: 'bottom-right', padding: 20, alpha: 70)
    ->render();
```

Wenn die Watermark-Datei nicht im Mediapool existiert, wird der betroffene Variant-Request mit 404 beantwortet. Der `<picture>` Tag kann dann broken-image-Glyphs für diese Variante enthalten.

Watermark-Attribute sollten deshalb idealerweise nur in Templates oder Modulen verwendet werden, in denen der Pfad kontrolliert ist.

---

# Scope und Performance von REX_PIC

`REX_PIC` ist ein natives REDAXO-`rex_var`.

Die Substitution greift in:

- Slice-Content
- Modul-Output
- Modul-Input
- Templates

Beim Erzeugen des Article-Caches wird `REX_PIC[…]` in PHP-Code übersetzt. Pro Render entsteht deshalb kein Regex-Overhead.

Der Article-Cache ruft direkt auf:

```php
\Ynamite\Media\Image::picture(...)
```

`REX_PIC` greift nicht automatisch in beliebigen Custom-Feldern, Metainfo-Texten oder rohem `tt_news`-Output, sofern diese nicht durch `replaceObjectVars()` laufen.

In solchen Kontexten direkt die PHP-API nutzen:

```php
Image::picture(...)
```

oder:

```php
Image::for(...)->render()
```

Wenn nach einem Addon-Update ein Slice mit `REX_PIC[…]` nicht mehr rendert:

REDAXO-Cache leeren.

Die `rex_var`-Substitution ist an den Article-Cache gebunden. Geändertes `getOutput()` greift erst nach einem Cache-Rebuild.

---

# Videos mit REX_VIDEO

`REX_VIDEO[…]` funktioniert ähnlich wie `REX_PIC`, aber für `<video>`-Markup.

Auch `REX_VIDEO` ist als natives `rex_var` registriert.

Es nutzt denselben Article-Cache-Substitutionsweg und dieselben Scope-Regeln.

---

## REX_VIDEO Beispiele

### Hero-Loop ohne Sound

Typisches Pattern für automatisch startende Hintergrundvideos:

```text
REX_VIDEO[src="hero.mp4" poster="hero.jpg" autoplay="true" muted="true" loop="true" playsinline="true"]
```

---

### Mit Layout-Reservierung und Klasse

```text
REX_VIDEO[src="hero.mp4" poster="hero.jpg" width="1920" height="1080" class="hero-video"]
```

---

### Editor-kontrolliertes Video mit Controls

```text
REX_VIDEO[src="interview.mp4" poster="thumb.jpg" alt="Interview mit Hans Müller"]
```

---

### Aggressives Preload für Above-the-fold Player

```text
REX_VIDEO[src="hero.mp4" poster="hero.jpg" preload="auto" loading="eager"]
```

---

## REX_VIDEO Attribute

| Attribut      | Typ    | Default    | Beschreibung                                   |
| ------------- | ------ | ---------- | ---------------------------------------------- |
| `src`         | string | — Pflicht  | Dateiname im REDAXO-Mediapool                  |
| `poster`      | string | —          | Pfad zum Poster-Bild                           |
| `width`       | int    | —          | `width`-HTML-Attribut für Layout-Reservierung  |
| `height`      | int    | —          | `height`-HTML-Attribut für Layout-Reservierung |
| `alt`         | string | —          | Wird als `aria-label` ausgegeben               |
| `class`       | string | —          | CSS-Klasse(n) für das `<video>`-Element        |
| `preload`     | string | `metadata` | `none`, `metadata` oder `auto`                 |
| `loading`     | string | `lazy`     | `lazy` oder `eager`                            |
| `autoplay`    | bool   | `false`    | Browser starten den Stream automatisch         |
| `muted`       | bool   | `false`    | Tonspur stumm                                  |
| `loop`        | bool   | `false`    | Video läuft endlos                             |
| `controls`    | bool   | `true`     | Standard-Browser-Controls anzeigen             |
| `playsinline` | bool   | `true`     | iOS-Video läuft inline statt Fullscreen        |

Wichtig:

Autoplay wird von Browsern in der Praxis meistens nur erlaubt, wenn das Video stumm ist:

```text
autoplay="true" muted="true"
```

Bool-Attribute akzeptieren:

- `"true"`
- `"false"`
- `"1"`
- `"0"`
- `"yes"`
- `"no"`

Technisch wird dafür PHP `FILTER_VALIDATE_BOOLEAN` verwendet.

Fehlt ein Attribut komplett, greift der Default aus `Video::render()`. Das ist bewusst nicht direkt in `REX_VIDEO` verdoppelt, damit API-Default-Wechsel nahtlos auch auf bestehende Slice-Inhalte wirken können.

---

## Scope und Performance von REX_VIDEO

Identisch zu `REX_PIC`.

Die Substitution passiert beim Article-Cache-Build. Pro Render wird kein Regex ausgeführt.

Wenn geänderte Defaults nicht sichtbar werden:

REDAXO-Cache leeren.

---

# Erzeugtes Markup

Ein typisches Bild wird ungefähr so gerendert:

```html
<picture>
  <source
    type="image/avif"
    srcset="…1080.avif 1080w, …"
    sizes="…" />
  <source
    type="image/webp"
    srcset="…1080.webp 1080w, …"
    sizes="…" />
  <img
    src="…1080.jpg"
    srcset="…1080.jpg 1080w, …"
    sizes="…"
    width="1440"
    height="810"
    alt="Aussicht"
    loading="lazy"
    decoding="async"
    style="background-size:cover;background-image:url('data:image/jpeg;base64,…')" />
</picture>
```

SVG und GIF werden schlicht als `<img>` ausgegeben, ohne `srcset` und ohne Sources.

Animierte GIFs sind eine Ausnahme und werden weiter unten separat beschrieben.

---

# Placeholder: LQIP

Für jedes raster-basierte Bild rendert das Addon einen **LQIP**.

LQIP steht für **Low-Quality Image Placeholder**.

Dabei wird ein kleines Mini-Bild erzeugt:

- 32 px breit
- leicht geblurrt
- als Base64-Data-URL inline im `style`-Attribut
- ohne JavaScript
- ohne zusätzlichen Request

Beispiel:

```html
style="background-image:url('data:image/webp;base64,…')"
```

Default-Tuning:

| Einstellung | Default |
| ----------- | ------- |
| Breite      | 32 px   |
| Blur        | 5       |
| Qualität    | 40      |

Alle Werte können über die Settings-Seite angepasst werden.

---

## Metadaten werden entfernt

Vor dem Encoden entfernt MASSIF Media EXIF-, XMP-, IPTC- und ICC-Profil-Metadaten aus jeder generierten Variante.

Das gilt nicht nur für LQIPs, sondern für alle generierten Varianten.

Warum?

iPhone-Bilder enthalten oft 20+ KB Metadaten, zum Beispiel:

- Face-Detection-JSON
- Depth-Maps
- Display-P3-ICC
- GPS-Koordinaten
- XMP-Face-Regionen

Für die Web-Auslieferung bringen diese Daten meistens keinen Mehrwert. Sie kosten Bandbreite und können aus Privacy-Sicht heikel sein.

Technische Umsetzung:

- Implementiert in `lib/Glide/StripMetadata.php`
- Nutzt Imagick `stripImage`
- Läuft als zusätzlicher Manipulator nach `ColorProfile`
- `ColorProfile` normalisiert Pixel bereits via `transformImageColorspace()` zu sRGB
- Das eingebettete ICC-Profil ist danach stale und wird entfernt
- Browser-Default ist sRGB und matched die Pixel

---

# Animierte GIFs als animiertes WebP

Animierte GIFs sind typischerweise 2–3× so gross wie das äquivalente animierte WebP.

Wenn das Quellbild ein animiertes GIF ist, rendert MASSIF Media ein `<picture>` mit WebP-Source und GIF-Fallback.

Beispiel:

```html
<picture>
  <source
    type="image/webp"
    srcset="/.../spinner.gif/animated.webp?s=…" />
  <img
    src="/media/spinner.gif"
    alt="…"
    width="200"
    height="200"
    loading="lazy"
    decoding="async" />
</picture>
```

Moderne Browser laden die WebP-Variante. Ältere Browser bekommen das GIF.

Technische Details:

- Imagick erkennt animierte GIFs beim Metadaten-Read
- Encoding läuft beim ersten Zugriff über `Imagick::coalesceImages() + writeImages($path, true)`
- Glides Standard-Encoder behält nur das erste Frame, deshalb läuft dieser Pfad an Glide vorbei
- Die WebP-Variante wird einmalig pro Source generiert
- Kein Multi-Width-Pool für animierte WebPs
- Animierte WebPs werden in voller Quellgrösse erzeugt
- Cache liegt unter `cache/{src}/animated.webp`
- Erfordert Imagick mit WebP-Delegate
- Ohne WebP-Delegate wird die WebP-Source still weggelassen
- Im CDN-Modus entfällt der Wrapper komplett, weil das CDN die Encoding-Arbeit nicht für uns macht

---

# Dominante Farbe

Auf neuen Installationen ist **Dominante Farbe** standardmässig aktiv.

Dabei wird aus dem Quellbild eine einzelne repräsentative Hex-Farbe berechnet und als `background-color` im `style`-Attribut gesetzt.

Vorteile:

- ca. 7 Bytes statt ca. 600 Bytes pro Bild gegenüber reinem LQIP
- kein Decode-Roundtrip
- sofort sichtbar
- der Browser zeichnet die Farbe vor dem ersten Repaint

Die dominante Farbe kann mit LQIP kombiniert werden. Das ist der Default.

Ablauf:

1. Die Hintergrundfarbe wird zuerst sichtbar.
2. Das LQIP überlagert die Farbe, sobald es dekodiert ist.
3. Die finale Bildvariante überschreibt beides.

Die Reihenfolge im `style`-Attribut wird vom Renderer garantiert.

Deaktivieren:

**AddOns → MASSIF Media → Einstellungen → Placeholder**

Technische Berechnung:

- Imagick `quantizeImage(1, COLORSPACE_SRGB)`
- Working-Copy mit 50 px Breite
- sub-20ms auf üblichen Foto-Grössen
- Ergebnis ist ein 1-Pixel-äquivalenter Mittelwert
- Cache liegt unter `cache/_color/<2-char>/<xxh64>.txt`
- Erfordert die `imagick` PHP-Extension
- Ohne Imagick wird die Farbe still übersprungen

---

# Konfiguration

Alle Einstellungen sind erreichbar unter:

**AddOns → MASSIF Media → Einstellungen**

| Schlüssel       | Default                                                    | Zweck                                                          |
| --------------- | ---------------------------------------------------------- | -------------------------------------------------------------- |
| `sign_key`      | automatisch generiert                                      | HMAC-Geheimnis für signierte URLs                              |
| `formats`       | `['avif','webp','jpg']`                                    | Source-Reihenfolge im `<picture>`; letztes Format ist Fallback |
| `quality`       | `{avif:50, webp:75, jpg:80}`                               | Qualität pro Format                                            |
| `device_sizes`  | `[640, 750, 828, 1080, 1200, 1920, 2048, 3840]`            | Grosse Breakpoints nach `next/image`                           |
| `image_sizes`   | `[16, 32, 48, 64, 96, 128, 256, 384]`                      | Kleine Breakpoints nach `next/image`                           |
| `default_sizes` | `(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw` | Default `sizes`-Attribut                                       |
| `lqip_*`        | aktiviert, 32 px, blur 5, q 40                             | LQIP-Tuning                                                    |
| `color_enabled` | aktiviert                                                  | Dominante Farbe als `background-color`                         |
| `cdn_*`         | deaktiviert                                                | CDN-Override mit Base und Template                             |

---

# Webserver-Konfiguration

MASSIF Media funktioniert ohne zusätzliche Webserver-Konfiguration.

Ein `PACKAGES_INCLUDED`-Extension-Point fängt Cache-URLs dieser Form ab:

```text
/assets/addons/massif_media/cache/…
```

Die Bildvariante wird on demand generiert und ausgeliefert, bevor `yrewrite` oder Article-Rendering läuft.

Das Pattern ist von REDAXOs eigenem `media_manager` adaptiert.

Optional können Cache-Hits direkt vom Webserver ausgeliefert werden.

| Setup                | Ohne Snippet                                                                                     | Mit Snippet                                                                                                                                                |
| -------------------- | ------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Apache               | Cache-Hit und Cache-Miss laufen über REDAXO                                                      | `assets/.htaccess` ist standardmässig aktiv und liefert Hits direkt aus Apache. Dazu `Cache-Control: max-age=31536000, immutable`                          |
| Standalone nginx     | Cache-Hit und Cache-Miss laufen über REDAXO                                                      | `assets/nginx.conf.example` im `server { … }` Block einbinden. Voraussetzung: Site-Block setzt `root` auf das Public-Verzeichnis. Danach `nginx -s reload` |
| Laravel Herd / Valet | Cache-Hits werden direkt aus dem Filesystem geliefert. Cache-Misses routen über REDAXOs Frontend | Kein weiteres Snippet nötig                                                                                                                                |

Falls AVIF-Dateien als `application/octet-stream` ausgeliefert werden, handelt es sich wahrscheinlich um einen alten nginx-Build. In diesem Fall den Mime-Type ergänzen. Ein Hinweis dazu steht unten in `nginx.conf.example`.

---

# URL-Schema

Generierte Varianten werden asset-keyed abgelegt. Alle Varianten einer Quelle leben in einem eigenen Verzeichnis.

Es gibt vier Cache-Pfad-Formen:

```text
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{q}.{ext}
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}.{ext}
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{q}-f{hash}.{ext}
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}-f{hash}.{ext}
```

Bedeutung:

| Form                   | Zweck                             |
| ---------------------- | --------------------------------- |
| ohne Crop, ohne Filter | einfache Variante                 |
| mit Crop               | Variante mit Ziel-Box             |
| mit Filtern            | Variante mit Bildfiltern          |
| Crop + Filter          | Variante mit Ziel-Box und Filtern |

Beispiele:

```text
assets/addons/massif_media/cache/hero.jpg/avif-1080-50.avif
assets/addons/massif_media/cache/hero.jpg/avif-800-800-cover-50-50-50.avif
assets/addons/massif_media/cache/hero.jpg/jpg-800-80-fa1b2c3d4.jpg
assets/addons/massif_media/cache/gallery/2024/atelier.jpg/avif-1920-1920-cover-30-70-50.avif
```

`fitToken` ist eines von:

```text
cover-{focalX}-{focalY}
contain
stretch
```

`f{hash}` enthält die ersten 8 Hex-Zeichen von:

```php
md5(json_encode(ksort(filterParams)))
```

Subdirectories aus dem REDAXO-Mediapool bleiben erhalten.

---

## Ausgelieferte URL

Beispiele:

```text
/assets/addons/massif_media/cache/hero.jpg/avif-1080-50.avif?s={HMAC}&v={mtime}
/assets/addons/massif_media/cache/hero.jpg/jpg-800-80-fa1b2c3d4.jpg?s={HMAC}&v={mtime}&f={base64url(json)}
```

Parameter:

| Parameter | Zweck                                                        |
| --------- | ------------------------------------------------------------ |
| `?s=`     | HMAC-SHA256-Signatur gegen `sign_key`                        |
| `?v=`     | `mtime` des Quellbildes für Browser-/CDN-Cache-Invalidierung |
| `&f=`     | vollständiger Filter-Blob als base64url-kodiertes JSON       |

Bei Filter-Anfragen deckt die Signatur `path|f` zusammen ab. Filter-Werte können deshalb nicht manipuliert werden, ohne die Signatur zu brechen.

---

## Cache-Hit und Cache-Miss

### Cache-Hit

Die Variante existiert bereits auf Disk.

Je nach Setup liefert sie direkt aus:

- Apache mit mitgelieferter `.htaccess`
- nginx mit Snippet
- Valet/Herd nativ über `isStaticFile()`

PHP läuft dann nicht.

### Cache-Miss

Die Variante existiert noch nicht.

Der Request landet in REDAXOs Frontend `index.php`.

Dann passiert Folgendes:

1. `PACKAGES_INCLUDED`-Hook erkennt die Cache-URL.
2. HMAC wird geprüft.
3. Glide generiert die Variante.
4. Der Hook sendet die Bytes.
5. Die Request-Verarbeitung endet.
6. Die Variante liegt ab sofort auf Disk.
7. Der nächste Request ist ein Cache-Hit.

---

# Cache-Statistik

Im Backend gibt es auf dem Tab **Sicherheit & Cache** eine Cache-Übersicht.

Sie zeigt, wie viel Speicher der Addon-Cache aktuell belegt.

Aufgeschlüsselt wird nach:

- Varianten
- Animated WebP
- LQIP
- Dominante Farbe
- Metadata-Sidecars

Zusätzlich werden die `mtime`-Werte der ältesten und neuesten Datei angezeigt.

Das ist nützlich bei Webseiten mit vielen Bildern, um zu sehen, ob ein Cache-Leeren sinnvoll ist.

Technische Details:

- Berechnung läuft rekursiv über das Cache-Verzeichnis
- Ergebnis wird 5 Minuten in `cache/_stats.json` gespeichert
- Via Button "Statistik neu berechnen" kann die Statistik erneuert werden
- Die Statistik ist read-only
- Gezieltes Löschen einzelner Varianten ist nicht vorgesehen

---

# Cache-Invalidierung

## REDAXO-Cache leeren

Wenn der Backend-Cache geleert wird, zum Beispiel über UI oder:

```bash
console cache:clear
```

hängt sich das Addon an `CACHE_DELETED` und leert den eigenen Cache mit.

---

## Nur Addon-Cache leeren

Auf dem Tab **Sicherheit & Cache** gibt es zusätzlich:

**Addon Cache jetzt leeren**

Damit wird gezielt nur der MASSIF-Media-Cache geleert.

---

## Wenn sich ein Quellbild ändert

Wenn ein Quellbild im Mediapool geändert wird, ändert REDAXO dessen `mtime`.

Dadurch ändert sich der `?v=` Parameter in der URL.

Browser und CDN holen dadurch die neue URL.

Das Disk-File ist zwar noch vorhanden, aber `?v=` ist nicht Teil des Datei-Pfades. Anfragen mit neuem `?v=` bleiben deshalb Cache-Hits.

Bei Bedarf:

- Addon-Cache leeren
- oder REDAXO-Cache leeren

---

# Sicherheit

MASSIF Media signiert Varianten-URLs mit HMAC.

Ohne Sign-Key kann niemand Generierungen für beliebige Breiten, Qualitäten oder Filterkombinationen anstossen.

Damit sind Disk-Filling-Angriffe ausgeschlossen.

Wenn der Sign-Key neu generiert wird:

- alle bisher signierten URLs werden ungültig
- bestehende Cache-Files bleiben aber erreichbar
- Apache prüft beim direkten Ausliefern die Signatur nicht erneut

---

# Troubleshooting

## Bild oder Video taucht nicht auf

Wenn ein Bild oder Video nicht erscheint, obwohl der Slice gepflegt ist, ist meistens die Quelldatei nicht vorhanden.

Typische Gründe:

- Tippfehler im Dateinamen
- Datei wurde aus dem Mediapool entfernt

MASSIF Media loggt jeden fehlenden Source-File via `rex_logger`.

Wenn REDAXO Debug aktiv ist:

```yaml
debug: true
```

rendert das Addon zusätzlich einen HTML-Kommentar an der Stelle, an der das Bild oder Video stehen sollte.

Beispiel:

```html
<!-- massif_media: src not found "hero-imag.jpg" -->
```

Im Browser-Inspector oder über "View Source" sieht man dadurch den vermutlich falsch geschriebenen Dateinamen direkt.

In Production:

```yaml
debug: false
```

wird ein leerer String ausgegeben.

Endbenutzer sehen keine Fehlermeldung, der `rex_logger`-Eintrag bleibt aber erhalten.

---

# Technische Details

Dieser Abschnitt richtet sich an Entwicklerinnen und Entwickler, die genauer wissen möchten, wie MASSIF Media intern funktioniert.

---

## Bild-Pipeline

MASSIF Media erzeugt responsive Bilder mit einem `<picture>`-Element.

Die Standard-Reihenfolge der Formate ist:

```php
['avif', 'webp', 'jpg']
```

Das letzte Format ist der Fallback.

Die Qualitätswerte sind standardmässig:

```php
[
    'avif' => 50,
    'webp' => 75,
    'jpg' => 80,
]
```

Der Browser wählt anhand von `type`, `srcset` und `sizes` selbst die passende Variante.

Es gibt kein Accept-Header-Sniffing.

---

## Breakpoint-Pools

MASSIF Media nutzt zwei Breakpoint-Pools, analog zu `next/image`.

Grosse Breakpoints:

```php
[640, 750, 828, 1080, 1200, 1920, 2048, 3840]
```

Kleine Breakpoints:

```php
[16, 32, 48, 64, 96, 128, 256, 384]
```

Das Default-`sizes`-Attribut lautet:

```text
(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw
```

---

## Self-contained Routing

Die Cache-URLs liegen unter:

```text
/assets/addons/massif_media/cache/…
```

Bei einem Cache-Miss läuft der Request über REDAXOs Frontend.

Der `PACKAGES_INCLUDED`-Hook greift sehr früh und verhindert, dass `yrewrite` oder Article-Rendering unnötig starten.

Dadurch funktioniert das Addon ohne projektspezifische Webserver-Regeln.

---

## Server-Fastpath

Für Cache-Hits können statische Dateien direkt vom Webserver ausgeliefert werden.

Apache:

- `assets/.htaccess` ist standardmässig aktiv
- liefert vorhandene Cache-Dateien direkt aus
- setzt `Cache-Control: max-age=31536000, immutable`

nginx:

- `assets/nginx.conf.example` kann eingebunden werden
- `root` muss auf das Public-Verzeichnis zeigen
- nach Anpassung nginx neu laden:

```bash
nginx -s reload
```

Herd und Valet:

- Cache-Hits werden bereits direkt aus dem Filesystem geliefert
- Cache-Misses laufen über REDAXOs Frontend

---

## Signaturen

Jede Varianten-URL enthält eine HMAC-SHA256-Signatur.

Die Signatur basiert auf dem `sign_key`.

Bei Filter-Anfragen wird nicht nur der Pfad signiert, sondern `path|f`.

Dadurch kann niemand Filterwerte, Breiten oder Qualitätswerte manipulieren.

---

## Filter-Hash

Wenn Filter verwendet werden, enthält der Dateiname einen Hash:

```text
f{hash}
```

Dieser Hash besteht aus den ersten 8 Hex-Zeichen von:

```php
md5(json_encode(ksort(filterParams)))
```

Der vollständige Filter-Blob wird zusätzlich als base64url-kodiertes JSON in `&f=` übertragen.

---

## Crop-Token

Bei Crops wird der Fit-Modus Teil des Dateinamens.

Mögliche Tokens:

```text
cover-{focalX}-{focalY}
contain
stretch
```

`cover` enthält zusätzlich den Fokuspunkt.

---

## Metadaten und Farbraum

Die Pipeline normalisiert Bilddaten zu sRGB und entfernt danach eingebettete Metadaten.

Dafür werden verwendet:

- `ColorProfile`
- `StripMetadata`
- Imagick `stripImage`

Das reduziert Dateigrössen und entfernt potenziell sensible Metadaten.

---

## Animated GIF Path

Animierte GIFs werden gesondert behandelt, weil Glides Standard-Encoder nur das erste Frame behalten würde.

MASSIF Media nutzt deshalb:

```php
Imagick::coalesceImages()
writeImages($path, true)
```

Das erzeugt ein animiertes WebP pro Quelle.

Der Cache-Pfad lautet:

```text
cache/{src}/animated.webp
```

Im CDN-Modus wird dieser Wrapper nicht erzeugt.

---

## Article-Cache und rex_var

`REX_PIC` und `REX_VIDEO` sind native REDAXO-`rex_var`.

Sie werden während des Article-Cache-Builds ersetzt und nicht bei jedem Request neu geparst.

Das ist performant, hat aber eine Konsequenz:

Wenn sich die rex_var-Ausgabe ändert, muss der REDAXO-Cache geleert werden.

---

# Lizenz

MIT — siehe `LICENSE`.
