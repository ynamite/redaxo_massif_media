# MASSIF Media

REDAXO-Addon für moderne, responsive Bild- und Video-Auslieferung.

- **`<picture>`-Markup** mit AVIF, WebP und JPG Sources — der Browser entscheidet selbst, welches Format er lädt (kein Accept-Header-Sniffing).
- **On-demand Resizing** über [league/glide](https://glide.thephpleague.com/) — nur die tatsächlich benötigten Varianten werden generiert.
- **Self-contained, ohne Server-Konfiguration**: ein `PACKAGES_INCLUDED`-Hook fängt Cache-URLs in REDAXOs Frontend ab und liefert die Variante aus — funktioniert überall (Apache, nginx, Laravel Herd, Valet) ohne `.htaccess`/nginx-Tweaks oder Valet-Driver-Patches. Optional: für Cache-**Hits** liefert das mitgelieferte `assets/.htaccess` (Apache) bzw. `assets/nginx.conf.example` (Standalone-nginx) den Fastpath, der PHP komplett umgeht.
- **HMAC-signierte URLs** — verhindert, dass beliebige Größen-/Qualitätskombinationen den Speicher fluten können.
- **LQIP** (Low-Quality Image Placeholder) als inline Base64-JPEG im `background-image` — JS-frei.
- **Focal-Point-Unterstützung** über das optionale [`focuspoint`](https://github.com/yakamara/redaxo_focuspoint) Addon (`med_focuspoint` Feld).
- **Preload** für Above-the-fold-Bilder via `<link rel="preload">`-Injektion in den `<head>`.
- **SVG/GIF Pass-through** — keine Transformation, nur ein einfaches `<img>`.
- **CDN-Override** (ImageKit, Cloudinary, Imgix-kompatibel) als optionale Konfiguration.
- **REDAXO-natives `REX_PIC[…]` Placeholder** für Inhaltspflege in Textfeldern / WYSIWYG.
- **Backend-Settings mit Tabs** (Allgemein, Placeholder, CDN, Sicherheit & Cache) und integrierter **Dokumentations-Seite** — diese README wird unter **AddOns → MASSIF Media → Dokumentation** direkt im Backend gerendert.

Inspiriert vom [Statamic Responsive Images Addon](https://github.com/statamic/responsive-images).

## Anforderungen

- REDAXO 5.13+
- PHP 8.2+
- **Imagick** (empfohlen). Für AVIF-Output zusätzlich **libheif/libavif** in der Imagick-Build. GD funktioniert auch, kann aber kein AVIF und liefert qualitativ schwächere Skalierungen.
- Optional: [`focuspoint`](https://github.com/yakamara/redaxo_focuspoint) Addon für visuelle Focal-Point-Pflege.
- Für lokale Entwicklung: PHPUnit ^11 via `composer install` (require-dev). Tests laufen mit `composer test` (alle), `composer test:unit` (schnell) oder `composer test:integration` (Glide + Temp-FS). **Vor jedem Commit von `vendor/`-Änderungen** `composer install --no-dev` ausführen, dann `bin/check-vendor` zur Sicherheit.

## Installation

1. Addon ins REDAXO-System hochladen oder über Connect installieren.
2. Aktivieren — der HMAC Sign-Key und das Cache-Verzeichnis werden automatisch eingerichtet.
3. Optional: Einstellungen unter **AddOns → MASSIF Media → Einstellungen** anpassen. Die Tabs gruppieren die Optionen:
    - **Allgemein** — Formate, Qualität pro Format, Breakpoint-Pools, Default-`sizes`-Attribut.
    - **Placeholder** — LQIP-Tuning.
    - **CDN** — optionale CDN-Auslieferung mit Template.
    - **Sicherheit & Cache** — Sign-Key-Anzeige + Regenerieren, Cache leeren, Cache-TTLs.

### Webserver-Konfiguration

Das Addon ist **self-contained** — es funktioniert auf jedem Webserver, der REDAXO selbst zum Laufen bringt (Apache, nginx, Laravel Herd, Valet, …) **ohne zusätzliche Server-Konfiguration**. Ein `PACKAGES_INCLUDED`-Extension-Point fängt Cache-URLs der Form `/assets/addons/massif_media/cache/…` in REDAXOs Frontend ab, generiert die Variante on-demand und liefert sie aus, bevor `yrewrite` oder Article-Rendering läuft. Pattern aus REDAXOs eigenem `media_manager` adaptiert.

Optional: für **Cache-Hits** (Anfragen für bereits generierte Varianten) bringen die mitgelieferten Server-Snippets einen Fastpath, der PHP komplett umgeht.

| Setup | Was passiert ohne Snippet | Was der Snippet bringt |
|---|---|---|
| Apache | Cache-Hit + Cache-Miss laufen über REDAXO. | `assets/.htaccess` ist standardmäßig aktiv und liefert Hits direkt aus Apache. Long-lived `Cache-Control: max-age=31536000, immutable`. Funktioniert ohne weiteres Zutun. |
| Standalone nginx | Cache-Hit + Cache-Miss laufen über REDAXO. | `assets/nginx.conf.example` einbinden (`include` im `server { … }` Block, vorausgesetzt der Site-Block setzt `root` auf das Public-Verzeichnis). Hits werden direkt von nginx ausgeliefert. Anschließend `nginx -s reload`. |
| Laravel Herd / Valet | **Cache-Hits** werden bereits direkt aus dem Filesystem geliefert (Valet erkennt Static-Files in `isStaticFile()`). **Cache-Misses** routen über REDAXOs Frontend. Funktioniert ohne weiteres Zutun. | — |

Falls AVIF-Dateien als `application/octet-stream` ausgeliefert werden (alte nginx-Builds): Mime-Type ergänzen — Hinweis ganz unten in `nginx.conf.example`.

## Schnellstart

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

In Textfeldern / Modulen für Redakteure: das `REX_PIC[…]` Placeholder. Siehe nächster Abschnitt.

## REX_PIC — Placeholder für Inhaltspflege

Für REDAXO-Redakteure und WYSIWYG-Workflows: das `REX_PIC[…]` Placeholder ist als **natives REDAXO-`rex_var`** registriert und kann direkt in Slice-Inhalten / Modul-Output verwendet werden. Beim Rebuild des Article-Caches expandiert REDAXO jeden Treffer zu PHP-Code, der beim Rendern der Seite das vollständige `<picture>`-Markup erzeugt — gleiche Pipeline wie der PHP-Aufruf, nur deklarativ und ohne PHP-Kenntnisse.

### Beispiele

**Einfachster Fall — nur Quelle und Alt-Text:**

```
REX_PIC[src="hero.jpg" alt="Aussicht über das Tal"]
```

**Mit Render-Breite und `sizes`-Attribut:**

```
REX_PIC[src="hero.jpg" alt="Aussicht" width="1440" sizes="100vw"]
```

**Mit Aspect-Ratio (CLS-sichere Layout-Reservierung):**

```
REX_PIC[src="banner.jpg" alt="Promo-Banner" width="1920" ratio="21:9"]
```

`ratio` akzeptiert `16:9`, `16/9` oder einen Dezimalwert wie `1.7777`.

**Innerhalb eines responsiven Layouts:**

```
REX_PIC[src="card.jpg" alt="Produktbild" width="600" ratio="4:3" sizes="(min-width: 768px) 33vw, 100vw"]
```

**Above-the-fold mit Preload (LCP-Optimierung):**

```
REX_PIC[src="hero.jpg" alt="Aussicht" width="1920" preload="true"]
```

`preload="true"` injiziert ein `<link rel="preload" as="image">` in den `<head>` über den `OUTPUT_FILTER`.

**Eager-Loading + hohe Fetch-Priorität (für das LCP-Bild):**

```
REX_PIC[src="hero.jpg" alt="Aussicht" width="1920" loading="eager" fetchpriority="high"]
```

**Mit Focal-Point (Komposition bei `object-fit: cover`):**

```
REX_PIC[src="portrait.jpg" alt="Portrait" width="800" ratio="3:4" focal="40% 30%"]
```

`focal` überschreibt für diesen einen Aufruf den Wert vom optionalen `focuspoint`-Addon.

**Mit CSS-Klasse:**

```
REX_PIC[src="hero.jpg" alt="Aussicht" width="1440" class="rounded shadow-lg"]
```

**SVG-Logo (Pass-through, kein Resizing):**

```
REX_PIC[src="logo.svg" alt="Firmenlogo" width="240" height="60"]
```

SVG und GIF werden direkt durchgereicht — kein `<picture>`, nur ein einfaches `<img>`.

**Innerhalb eines Markdown-Textfeldes:**

```markdown
## Über uns

Hier eine Aufnahme aus unserem Atelier:

REX_PIC[src="atelier.jpg" alt="Blick ins Atelier" width="1200" ratio="3:2"]

Lorem ipsum …
```

Der Editor sieht im WYSIWYG nur den `REX_PIC[…]` String; gerendert wird daraus ein vollständiges `<picture>`-Element mit allen Sources, LQIP und Layout-Reservierung.

### Verfügbare Attribute

| Attribut | Typ | Default | Beschreibung |
|---|---|---|---|
| `src` | string | — (Pflicht) | Dateiname im REDAXO-Mediapool. |
| `alt` | string | leer → `aria-hidden="true"` | Alt-Text. Fehlend / leer setzt das Bild semantisch als dekorativ. |
| `width` | int | intrinsische Breite | Render-Breite in px für das HTML-`width`-Attribut (Layout-Reservierung, CLS-Schutz). **Begrenzt das `srcset` nicht** — der Browser wählt aus der vollen Breakpoint-Auswahl, damit HiDPI-Screens (2×, 3×) eine schärfere Variante laden können (analog zu `next/image`). Ohne `width` wird die intrinsische Breite des Originals als Layout-Hinweis genutzt. Wer das `srcset` explizit eingrenzen will, nutzt die PHP-API: `Image::for($src)->widths([320, 640, 800])->render()`. |
| `height` | int | aus `width` × `ratio`, sonst intrinsisch | Render-Höhe in px. Wird normalerweise nicht direkt gesetzt — stattdessen `ratio` mit `width` verwenden. |
| `ratio` | string | intrinsisches Seitenverhältnis | Aspect-Ratio: `16:9`, `16/9` oder Dezimalwert wie `1.7777`. Berechnet `height` aus `width`. |
| `sizes` | string | aus den Settings (`default_sizes`, siehe Konfiguration) | `sizes`-Attribut für die responsive Auswahl der Variante. |
| `loading` | string | `lazy` | `lazy` oder `eager`. |
| `decoding` | string | `async` | `async`, `sync`, oder `auto`. |
| `fetchpriority` | string | `auto` | `auto`, `high`, oder `low`. |
| `focal` | string | aus `focuspoint`-Addon (sonst `50% 50%`) | `X% Y%` oder `0.5,0.3` — überschreibt für diesen Aufruf den asset-level Focal-Point. |
| `preload` | bool | `false` | `"true"` injiziert ein `<link rel="preload">` in den `<head>`. |
| `class` | string | — (kein `class`-Attribut) | CSS-Klasse(n) für das `<img>` bzw. `<picture>`. |
| `fit` | string | `cover` (wenn `ratio`/`height` gesetzt), sonst ignoriert | `cover` (Default, fokuspunkt-bewusster Center-Crop), `contain` (verkleinert proportional in die Box), `stretch` (verzerrt zur Box-Form), `none` (kein Crop, nur Layout-Reservierung — Verhalten vor diesem Release). Greift nur, wenn ein Ziel-Format via `ratio` oder `height` gesetzt ist; ohne Ziel-Box wird das Attribut still ignoriert. |

### Cropping (`fit`)

Sobald `ratio` oder `height` gesetzt ist, wird das gerenderte Bild standardmäßig **gecroppt**, damit es exakt in die angeforderte Layout-Box passt. Default-Modus ist `cover` — fokuspunkt-bewusster Center-Crop, wobei der Fokuspunkt entweder vom optionalen `focuspoint`-Addon kommt oder per Aufruf via `focal="X% Y%"` gesetzt wird.

| Modus | Verhalten |
|---|---|
| `cover` | Box ausfüllen, Überstand cropen, Fokuspunkt zentriert (Default). |
| `contain` | Bild proportional verkleinern bis es vollständig in die Box passt — eine Dimension trifft die Box, die andere ist kleiner. **Kein Padding / Letterboxing** (Glide setzt bei `contain` keinen Hintergrund). |
| `stretch` | Auf Box-Maße verzerren (selten gewollt, aber unterstützt). |
| `none` | Kein Crop. Image behält intrinsisches Seitenverhältnis, Box wird nur layout-mäßig reserviert (Verhalten vor diesem Release). |

**Beispiele:**

```
REX_PIC[src="hero.jpg" width="800" ratio="1:1"]                             // implizites cover
REX_PIC[src="hero.jpg" width="800" ratio="1:1" focal="30% 70%"]             // cover mit Fokuspunkt
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="contain"]               // proportional in 800x800 verkleinert
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="none"]                  // wie früher: nur Layout-Box, keine Bild-Manipulation
```

Wenn `ratio` exakt mit dem intrinsischen Seitenverhältnis der Quelle übereinstimmt, überspringt das Addon den Crop (kein zusätzlicher Cache-Eintrag). Bei `cover` und `contain` wird die `srcset`-Auswahl zusätzlich auf die Crop-Dimensionen begrenzt — bei einem 5712×4284 Quellbild und `ratio="9:16"` endet `srcset` z. B. bei 2409 w (= ⌊4284 × 9/16⌋), damit Glide nicht hochskalieren muss. `stretch` ignoriert diesen Cap (kann verzerrt auf jede Größe).

### Filter

Glide-basierte Image-Filter sind als REX_PIC-Attribute verfügbar — sowohl klassische Color-Tweaks (Brightness, Contrast, Gamma) als auch Sharpen / Blur, Color-Presets (`greyscale`, `sepia`), Background-Color, Border, Flip / Orient sowie Watermark.

| Attribut | Wert | Beschreibung |
|---|---|---|
| `brightness` | -100..100 | Helligkeit; 0 = unverändert. |
| `contrast` | -100..100 | Kontrast. |
| `gamma` | 0.1..9.99 | Gamma-Korrektur. |
| `sharpen` | 0..100 | Schärfung. |
| `blur` | 0..100 | Weichzeichner. |
| `pixelate` | 0..1000 | Pixelblock-Größe. |
| `filter` | `greyscale` \| `sepia` | Color-Preset. |
| `bg` | 6 Hex (z. B. `ffffff`) | Hintergrundfarbe — relevant z. B. wenn `fit="contain"` Lücken erzeugt. |
| `border` | `width,color,method` | Rahmen, z. B. `border="2,000000,expand"`. `method` = `overlay` / `shadow` / `expand`. |
| `flip` | `h` \| `v` \| `both` | Spiegelung. |
| `orient` | `auto` \| `0` \| `90` \| `180` \| `270` | Rotation. |

Numerische Werte ausserhalb des Ranges werden automatisch geclampt (z. B. `brightness="200"` → `100`). Ungültige Hex-Werte werden ignoriert. Unbekannte String-Werte (`filter="nonsense"`) werden an Glide durchgereicht; Glide ignoriert nicht-erkannte Filter still.

**Beispiele:**

```
REX_PIC[src="hero.jpg" width="800" filter="sepia"]
REX_PIC[src="hero.jpg" width="800" brightness="10" sharpen="20"]
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="contain" bg="ffffff"]
REX_PIC[src="hero.jpg" width="800" flip="h" orient="90"]
```

### Watermark

Wasserzeichen via 8 separate Attribute:

| Attribut | Wert | Beschreibung |
|---|---|---|
| `mark` | string (Pflicht für Watermark) | Pfad im REDAXO-Mediapool. |
| `marks` | 0.0..1.0 | Relative Größe (0.25 = 25 % der Bildbreite). |
| `markw` | int | Pixel-Breite (overrides `marks`). |
| `markh` | int | Pixel-Höhe (overrides `marks`). |
| `markpos` | siehe Glide | `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`. |
| `markpad` | int | Abstand zum Rand in px. |
| `markalpha` | 0..100 | Deckkraft. |
| `markfit` | siehe Glide | Wie das Watermark in seine Box eingepasst wird (`contain` / `max` / `fill` / `stretch` / `crop`). |

```
REX_PIC[src="hero.jpg" width="1200" mark="logos/brand.png" marks="0.2" markpos="bottom-right" markpad="20" markalpha="70"]
```

PHP-API:

```php
echo Image::for('hero.jpg')
    ->width(1200)
    ->watermark('logos/brand.png', size: 0.2, position: 'bottom-right', padding: 20, alpha: 70)
    ->render();
```

Existiert die Watermark-Datei nicht im Mediapool, wird der betroffene Variant-Request mit 404 beantwortet — der `<picture>` Tag enthält dann u. U. broken-image-Glyphs für diese Variante. Das Watermark-Attribut idealerweise an Templates / Module heften, wo der Pfad kontrolliert ist.

### Scope und Performance

`REX_PIC` ist ein natives REDAXO-`rex_var` — die Substitution greift in Slice-Content (Modul-Output, Modul-Input, Templates) und wird bei der Article-Cache-Generierung in PHP-Code übersetzt. Pro Render entsteht damit kein Regex-Overhead — der Article-Cache ruft direkt `\\Ynamite\\Media\\Image::picture(…)` auf.

`REX_PIC` greift **nicht** außerhalb von Slice-Content (z. B. nicht in arbiträren Custom-Feldern, Metainfo-Texten, oder im rohen `tt_news`-Output, sofern diese nicht durch `replaceObjectVars()` laufen). In solchen Kontexten direkt die PHP-API benutzen: `Image::picture(…)` oder `Image::for(…)->render()`.

Wenn nach einem Addon-Update ein Slice mit `REX_PIC[…]` plötzlich nicht mehr rendert: REDAXO-Cache leeren, damit der Article-Cache neu gebaut wird. `rex_var`-Substitution ist Article-Cache-bound; geändertes `getOutput()` greift erst nach Cache-Rebuild.

## REX_VIDEO — Placeholder für Video-Content

Analog zu `REX_PIC`, aber für `<video>`-Markup. Auch als natives `rex_var` registriert; gleicher Article-Cache-Substitutionsweg, gleiche Scope-Regeln.

### Beispiele

**Hero-Loop ohne Sound (Standard-Pattern für autoplaying Hintergrund-Videos):**

```
REX_VIDEO[src="hero.mp4" poster="hero.jpg" autoplay="true" muted="true" loop="true" playsinline="true"]
```

**Mit Layout-Reservierung (CLS-sicher) und Klassen:**

```
REX_VIDEO[src="hero.mp4" poster="hero.jpg" width="1920" height="1080" class="hero-video"]
```

**Editor-kontrolliertes Video (Standard-Controls, kein Autoplay):**

```
REX_VIDEO[src="interview.mp4" poster="thumb.jpg" alt="Interview mit Hans Müller"]
```

**Aggressives Preload für above-the-fold Player:**

```
REX_VIDEO[src="hero.mp4" poster="hero.jpg" preload="auto" loading="eager"]
```

### Verfügbare Attribute

| Attribut | Typ | Default | Beschreibung |
|---|---|---|---|
| `src` | string | — (Pflicht) | Dateiname im REDAXO-Mediapool. |
| `poster` | string | — | Pfad zum Poster-Bild (typischerweise ein Standbild aus dem Video). |
| `width` | int | — | `width`-HTML-Attribut für Layout-Reservierung. |
| `height` | int | — | `height`-HTML-Attribut für Layout-Reservierung. |
| `alt` | string | — | Wird als `aria-label` ausgegeben (HTML-`<video>` hat kein natives `alt`). |
| `class` | string | — | CSS-Klasse(n) für das `<video>`-Element. |
| `preload` | string | `metadata` | `none`, `metadata`, oder `auto`. Andere Werte fallen auf `metadata` zurück. |
| `loading` | string | `lazy` | `lazy` oder `eager`. |
| `autoplay` | bool | `false` | Browser starten den Stream automatisch. **Erfordert in der Praxis `muted="true"`** — sonst blockt der Browser das Autoplay. |
| `muted` | bool | `false` | Tonspur stumm. Praktisch immer mit `autoplay` kombiniert. |
| `loop` | bool | `false` | Video läuft endlos. |
| `controls` | bool | `true` | Standard-Browser-Controls anzeigen (Play, Pause, Lautstärke, …). |
| `playsinline` | bool | `true` | iOS-Video läuft inline statt Fullscreen-Takeover. Bei autoplaying Hero-Loops typischerweise `true` lassen. |

Bool-Attribute akzeptieren `"true"` / `"false"` / `"1"` / `"0"` / `"yes"` / `"no"` (PHP `FILTER_VALIDATE_BOOLEAN`).

Fehlt ein Attribut komplett, greift der Default aus `Video::render()` — bewusst _nicht_ aus `REX_VIDEO`, damit ein API-Default-Wechsel sich nahtlos auch auf bestehende Slice-Inhalte auswirkt.

### Scope und Performance

Identisch zu `REX_PIC`: Substitution während Article-Cache-Build, kein Regex auf jedem Render, geänderte Defaults erfordern Cache-Rebuild (`Cache leeren`).

## Erzeugtes Markup

```html
<picture>
  <source type="image/avif" srcset="…1080.avif 1080w, …" sizes="…">
  <source type="image/webp" srcset="…1080.webp 1080w, …" sizes="…">
  <img
    src="…1080.jpg"
    srcset="…1080.jpg 1080w, …"
    sizes="…"
    width="1440"
    height="810"
    alt="Aussicht"
    loading="lazy"
    decoding="async"
    style="background-size:cover;background-image:url('data:image/jpeg;base64,…')">
</picture>
```

SVG / GIF → schlichtes `<img>` ohne `srcset` / Sources.

## Placeholder (LQIP)

Für jedes raster-basierte Bild rendert das Addon einen **LQIP** (Low-Quality Image Placeholder): ein 32 px Mini-WebP, leicht geblurrt, als Base64-Data-URL inline im `style="background-image:url('data:image/webp;base64,…')"` Attribut des `<img>`. Der Browser dekodiert nativ — kein JavaScript, keine zusätzlichen Roundtrips. Default-Tuning: 32 px Breite, Blur 5, Qualität 40 — alles über die Settings-Seite anpassbar.

Vor dem Encoden wird die EXIF / XMP / IPTC / ICC-Profil-Metadaten der Quelle gestrippt — und zwar für **jede** generierte Variante, nicht nur die LQIPs. iPhone-Captures bringen typischerweise 20+ KB an Face-Detection-JSON, Depth-Maps, Display-P3-ICC, GPS-Koordinaten und XMP-Face-Regionen mit, die für die Web-Auslieferung keinen Mehrwert haben (Bandbreite + Privacy). Implementiert in `lib/Glide/StripMetadata.php` (Imagick `stripImage`), läuft als zusätzlicher Manipulator nach `ColorProfile`. Da `ColorProfile` Pixel bereits via `transformImageColorspace()` zu sRGB normalisiert, ist das eingebettete ICC-Profil danach ohnehin stale und wird vom Strip entfernt — Browser-Default ist sRGB und matched die Pixel.

## Konfiguration

Alle Einstellungen sind über die Backend-Seite **AddOns → MASSIF Media → Einstellungen** erreichbar. Sinnvolle Defaults sind gesetzt; die meisten Installationen müssen die Seite nicht anfassen.

| Schlüssel | Default | Zweck |
|---|---|---|
| `sign_key` | (autogen.) | HMAC-Geheimnis für signierte URLs |
| `formats` | `['avif','webp','jpg']` | Source-Reihenfolge im `<picture>`; letztes = Fallback |
| `quality` | `{avif:50, webp:75, jpg:80}` | Qualität pro Format |
| `device_sizes` | `[640, 750, 828, 1080, 1200, 1920, 2048, 3840]` | Große Breakpoints (next/image) |
| `image_sizes` | `[16, 32, 48, 64, 96, 128, 256, 384]` | Kleine Breakpoints (next/image) |
| `default_sizes` | `(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw` | Default `sizes` Attribut |
| `lqip_*` | aktiviert, 32 px, blur 5, q 40 | LQIP-Tuning |
| `cdn_*` | deaktiviert | CDN-Override (Base + Template) |

## URL-Schema

Generierte Varianten werden hier abgelegt — vier Cache-Pfad-Formen, asset-keyed (alle Varianten einer Quelle leben in einem Verzeichnis):

```
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{q}.{ext}                              (kein Crop, keine Filter)
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}.{ext}               (mit Crop)
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{q}-f{hash}.{ext}                      (mit Filtern)
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}-f{hash}.{ext}       (Crop + Filter)

z. B.  assets/addons/massif_media/cache/hero.jpg/avif-1080-50.avif
       assets/addons/massif_media/cache/hero.jpg/avif-800-800-cover-50-50-50.avif
       assets/addons/massif_media/cache/hero.jpg/jpg-800-80-fa1b2c3d4.jpg
       assets/addons/massif_media/cache/gallery/2024/atelier.jpg/avif-1920-1920-cover-30-70-50.avif
```

`fitToken` ist eines von: `cover-{focalX}-{focalY}` (mit fokuspunkt-bewusstem Crop), `contain` oder `stretch`. `f{hash}` enthält die ersten 8 Hex-Chars von `md5(json_encode(ksort(filterParams)))`. Source-Subdirectories aus dem Mediapool bleiben erhalten.

Ausgelieferte URL:

```
/assets/addons/massif_media/cache/hero.jpg/avif-1080-50.avif?s={HMAC}&v={mtime}
/assets/addons/massif_media/cache/hero.jpg/jpg-800-80-fa1b2c3d4.jpg?s={HMAC}&v={mtime}&f={base64url(json)}
```

- **Cache-Hit**: Apache (mit mitgeliefertem `.htaccess`), nginx (mit Snippet) oder Valet/Herd (nativ über `isStaticFile()`) liefert die Datei direkt aus — PHP läuft nicht.
- **Cache-Miss**: Request landet in REDAXOs Frontend `index.php`. Der `PACKAGES_INCLUDED`-Hook fängt die Cache-URL ab, verifiziert die HMAC, Glide generiert die Variante, der Hook sendet die Bytes und beendet die Request-Verarbeitung. Ab sofort liegt die Variante auf Disk und der nächste Request ist ein Hit.

`?s=` ist eine HMAC-SHA256-Signatur gegen `sign_key` aus den Einstellungen. Bei Filter-Anfragen deckt sie `path|f` zusammen ab — Filter-Werte können nicht ohne Signatur-Bruch manipuliert werden.
`?v=` ist der `mtime` des Quellbildes — sorgt nur für Browser-/CDN-Cache-Invalidierung.
`&f=` ist der vollständige Filter-Blob als base64url-kodiertes JSON (nur bei Filter-Anfragen vorhanden).

## Cache-Invalidierung

- **Backend "Cache leeren"** (UI oder `console cache:clear`): das Addon hängt sich an `CACHE_DELETED` und leert den eigenen Cache mit.
- **"Addon Cache jetzt leeren"** auf dem **Sicherheit & Cache**-Tab: gezielt nur unseren Cache.
- **Quelländerung**: REDAXO ändert den `mtime`, dadurch ändert sich der `?v=` Parameter — Browser/CDN holen die neue URL. Das Disk-File ist dann zwar noch da, aber Anfragen mit neuem `?v=` bleiben Cache-Hits, weil `?v=` nicht Teil des Datei-Pfades ist. Bei Bedarf das Addon-Cache leeren oder REDAXO-Cache leeren.

## Sicherheit

URLs sind HMAC-signiert. Ohne den Sign-Key kann niemand Generierungen für beliebige Breiten/Qualitäten anstoßen — Disk-Filling-Angriffe sind damit ausgeschlossen.

Wenn der Sign-Key neu generiert wird, werden alle bisher signierten URLs ungültig. Bestehende Cache-Files bleiben jedoch erreichbar (Apache prüft die Signatur nicht erneut beim direkten Ausliefern).

## Lizenz

MIT — siehe `LICENSE`.
