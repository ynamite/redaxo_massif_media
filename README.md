# MASSIF Media

REDAXO-Addon für moderne, responsive Bild- und Video-Auslieferung.

- **`<picture>`-Markup** mit AVIF, WebP und JPG Sources — der Browser entscheidet selbst, welches Format er lädt (kein Accept-Header-Sniffing).
- **On-demand Resizing** über [league/glide](https://glide.thephpleague.com/) — nur die tatsächlich benötigten Varianten werden generiert.
- **Self-contained, ohne Server-Konfiguration**: ein `PACKAGES_INCLUDED`-Hook fängt Cache-URLs in REDAXOs Frontend ab und liefert die Variante aus — funktioniert überall (Apache, nginx, Laravel Herd, Valet) ohne `.htaccess`/nginx-Tweaks oder Valet-Driver-Patches. Optional: für Cache-**Hits** liefert das mitgelieferte `assets/.htaccess` (Apache) bzw. `assets/nginx.conf.example` (Standalone-nginx) den Fastpath, der PHP komplett umgeht.
- **HMAC-signierte URLs** — verhindert, dass beliebige Größen-/Qualitätskombinationen den Speicher fluten können.
- **LQIP** (Low-Quality Image Placeholder) als inline Base64-JPEG im `background-image` — JS-frei.
- **Blurhash**-Generierung als Sidecar in den Asset-Metadaten — abrufbar via `Image::blurhash($src)` für Galerien / JSON-APIs, optional auch als `data-blurhash` Attribut.
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

## Installation

1. Addon ins REDAXO-System hochladen oder über Connect installieren.
2. Aktivieren — der HMAC Sign-Key und das Cache-Verzeichnis werden automatisch eingerichtet.
3. Optional: Einstellungen unter **AddOns → MASSIF Media → Einstellungen** anpassen. Die Tabs gruppieren die Optionen:
    - **Allgemein** — Formate, Qualität pro Format, Breakpoint-Pools, Default-`sizes`-Attribut.
    - **Placeholder** — LQIP-Tuning, Blurhash-Toggle.
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
    ->withBlurhashAttr()
    ->render();

// API-Helper für Galerien / JSON-APIs
$hash = Image::blurhash('hero.jpg');

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
| `width` | int | intrinsische Breite | Render-Breite in px. Begrenzt das `srcset`, setzt das HTML-`width`-Attribut. Ohne `width` wird die intrinsische Breite des Originals verwendet. |
| `height` | int | aus `width` × `ratio`, sonst intrinsisch | Render-Höhe in px. Wird normalerweise nicht direkt gesetzt — stattdessen `ratio` mit `width` verwenden. |
| `ratio` | string | intrinsisches Seitenverhältnis | Aspect-Ratio: `16:9`, `16/9` oder Dezimalwert wie `1.7777`. Berechnet `height` aus `width`. |
| `sizes` | string | aus den Settings (`default_sizes`, siehe Konfiguration) | `sizes`-Attribut für die responsive Auswahl der Variante. |
| `loading` | string | `lazy` | `lazy` oder `eager`. |
| `decoding` | string | `async` | `async`, `sync`, oder `auto`. |
| `fetchpriority` | string | `auto` | `auto`, `high`, oder `low`. |
| `focal` | string | aus `focuspoint`-Addon (sonst `50% 50%`) | `X% Y%` oder `0.5,0.3` — überschreibt für diesen Aufruf den asset-level Focal-Point. |
| `preload` | bool | `false` | `"true"` injiziert ein `<link rel="preload">` in den `<head>`. |
| `class` | string | — (kein `class`-Attribut) | CSS-Klasse(n) für das `<img>` bzw. `<picture>`. |

### Scope und Performance

`REX_PIC` ist ein natives REDAXO-`rex_var` — die Substitution greift in Slice-Content (Modul-Output, Modul-Input, Templates) und wird bei der Article-Cache-Generierung in PHP-Code übersetzt. Pro Render entsteht damit kein Regex-Overhead — der Article-Cache ruft direkt `\\Ynamite\\Media\\Image::picture(…)` auf.

`REX_PIC` greift **nicht** außerhalb von Slice-Content (z. B. nicht in arbiträren Custom-Feldern, Metainfo-Texten, oder im rohen `tt_news`-Output, sofern diese nicht durch `replaceObjectVars()` laufen). In solchen Kontexten direkt die PHP-API benutzen: `Image::picture(…)` oder `Image::for(…)->render()`.

Wenn nach einem Addon-Update ein Slice mit `REX_PIC[…]` plötzlich nicht mehr rendert: REDAXO-Cache leeren, damit der Article-Cache neu gebaut wird. `rex_var`-Substitution ist Article-Cache-bound; geändertes `getOutput()` greift erst nach Cache-Rebuild.

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

## Placeholder-Strategien: LQIP vs. Blurhash

Das Addon erzeugt für jedes Bild **zwei** unabhängige Placeholder-Repräsentationen — beide sind kleine, unscharfe Vorschauen, decken aber unterschiedliche Use-Cases ab. Beide sind defaultmäßig aktiv und benötigen keine Anpassung.

| Aspekt | LQIP | Blurhash |
|---|---|---|
| Was ist es? | Ein 32 px Mini-JPEG (geblurrt, Q40), als Base64-Data-URL inline ausgeliefert. | Eine ~30-Byte ASCII-Repräsentation (DCT-Approximation der Bildfarben/-struktur). |
| Wie kommt es zum Browser? | Inline im `style="background-image:url('data:image/jpeg;base64,…')"` Attribut des `<img>`. ~2 KB pro Bild im HTML. | Als `data-blurhash="…"` Attribut (opt-in über `->withBlurhashAttr()`) oder als Rückgabewert von `Image::blurhash($src)` für JSON-APIs. |
| Wer entschlüsselt? | Browser-nativ (data: URL). Kein JS, keine CPU-Kosten. | JavaScript-Decoder im Browser (Canvas-Rendering) — **oder** server-seitig in PHP via `\\kornrunner\\Blurhash\\Blurhash::decode($hash, $w, $h)`, das eine Pixel-Matrix zurückgibt, die man zu JPEG/PNG enkodieren kann. |
| Speicherort | Cache-Datei pro Asset unter `cache/_lqip/…`, plus Verweis in der `meta.json` Sidecar. ~2 KB pro Asset auf Disk. | Nur `meta.json` Sidecar pro Asset. ~30 Bytes pro Asset. |
| Visueller Charakter | Echte Pixel-Reduktion des Originals → farb- und strukturtreu. | Parametrische DCT-Approximation → designed-blur Look, weniger Detail. |

Konzeptionell sind beide kleine Vorschau-Bilder — der Unterschied liegt in **Speicherung** und **Decode-Pfad**. Für klassisches Server-Rendering von HTML ist LQIP der direktere Weg (Browser dekodiert nativ, keine Roundtrips). Blurhash spielt seine Stärke aus, sobald man eine **JSON-API** baut und einem JS-Client einen 30-Byte-Hash schickt, statt 2 KB Base64 — dort übernimmt der Client das Rendering.

Beide Strategien laufen unabhängig und additiv. Default: LQIP rendert inline, Blurhash wird nur berechnet (für `Image::blurhash($src)`) und nicht im HTML mitgeliefert. Soll der Hash auch als `data-blurhash` Attribut erscheinen, dann `->withBlurhashAttr()` in der Builder-Kette aufrufen.

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
| `lqip_*` | aktiviert, 32 px, blur 40, q 40 | LQIP-Tuning |
| `blurhash_enabled` | `true` | Blurhash bei Metadatenerzeugung berechnen |
| `cdn_*` | deaktiviert | CDN-Override (Base + Template) |

## URL-Schema

Generierte Varianten werden hier abgelegt:

```
assets/addons/massif_media/cache/{fmt}-{w}-{q}/{filename}.{out_ext}

z. B.  assets/addons/massif_media/cache/avif-1080-50/hero.jpg.avif
       assets/addons/massif_media/cache/webp-640-75/hero.jpg.webp
```

Ausgelieferte URL:

```
/assets/addons/massif_media/cache/avif-1080-50/hero.jpg.avif?s={HMAC}&v={mtime}
```

- **Cache-Hit**: Apache (mit mitgeliefertem `.htaccess`), nginx (mit Snippet) oder Valet/Herd (nativ über `isStaticFile()`) liefert die Datei direkt aus — PHP läuft nicht.
- **Cache-Miss**: Request landet in REDAXOs Frontend `index.php`. Der `PACKAGES_INCLUDED`-Hook fängt die Cache-URL ab, verifiziert die HMAC, Glide generiert die Variante, der Hook sendet die Bytes und beendet die Request-Verarbeitung. Ab sofort liegt die Variante auf Disk und der nächste Request ist ein Hit.

`?s=` ist eine HMAC-SHA256-Signatur über den Cache-Pfad gegen `sign_key` aus den Einstellungen.
`?v=` ist der `mtime` des Quellbildes — sorgt nur für Browser-/CDN-Cache-Invalidierung.

## Cache-Invalidierung

- **Backend "Cache leeren"** (UI oder `console cache:clear`): das Addon hängt sich an `CACHE_DELETED` und leert den eigenen Cache mit.
- **"Addon Cache jetzt leeren"** auf dem **Sicherheit & Cache**-Tab: gezielt nur unseren Cache.
- **Quelländerung**: REDAXO ändert den `mtime`, dadurch ändert sich der `?v=` Parameter — Browser/CDN holen die neue URL. Das Disk-File ist dann zwar noch da, aber Anfragen mit neuem `?v=` bleiben Cache-Hits, weil `?v=` nicht Teil des Datei-Pfades ist. Bei Bedarf das Addon-Cache leeren oder REDAXO-Cache leeren.

## Sicherheit

URLs sind HMAC-signiert. Ohne den Sign-Key kann niemand Generierungen für beliebige Breiten/Qualitäten anstoßen — Disk-Filling-Angriffe sind damit ausgeschlossen.

Wenn der Sign-Key neu generiert wird, werden alle bisher signierten URLs ungültig. Bestehende Cache-Files bleiben jedoch erreichbar (Apache prüft die Signatur nicht erneut beim direkten Ausliefern).

## Lizenz

MIT — siehe `LICENSE`.
