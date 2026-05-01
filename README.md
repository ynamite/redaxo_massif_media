# MASSIF Media

REDAXO-Addon für moderne, responsive Bild- und Video-Auslieferung.

- **`<picture>`-Markup** mit AVIF, WebP und JPG Sources — der Browser entscheidet selbst, welches Format er lädt (kein Accept-Header-Sniffing).
- **On-demand Resizing** über [league/glide](https://glide.thephpleague.com/) — nur die tatsächlich benötigten Varianten werden generiert.
- **Webserver-direkt-Auslieferung** auf Cache-Hits (PHP läuft nur beim ersten Request einer Variante). Apache out of the box via `.htaccess`; nginx / Laravel Herd via mitgelieferter `assets/nginx.conf.example`.
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

### nginx

Out of the box liefert das Addon ein `assets/.htaccess` mit — Apache-Setups (XAMPP, MAMP, klassisches Apache+PHP-FPM) funktionieren ohne weiteres Zutun. Auf nginx wird `.htaccess` nicht ausgewertet; ohne zusätzliche Konfiguration liefern Cache-URLs `404` aus (oder fallen auf den Frontend-Index zurück), weil das URL → `_img/index.php`-Rewriting fehlt.

Das Addon enthält zwei nginx-Snippets — welches passt, hängt vom Setup ab:

#### Standalone nginx (Production)

`assets/nginx.conf.example` ist das 1:1-Pendant zum `.htaccess`. Inhalt in den `server { … }` Block der Site einbinden — entweder per `include`:

```nginx
server {
    # … bestehende Direktiven, inkl. per-Site `root /pfad/zu/public;` …

    include /absoluter/pfad/zu/redaxo/src/addons/massif_media/assets/nginx.conf.example;
}
```

… oder die zwei `location`-Blöcke direkt hineinkopieren. Anschließend `nginx -s reload`.

Logik: Cache-Hits werden direkt von nginx ausgeliefert (PHP läuft nicht), Cache-Misses über `try_files` an `_img/index.php?p=…` weitergeleitet — Query-String (HMAC `s=…`, `v=…`) bleibt erhalten. Long-lived `Cache-Control: public, max-age=31536000, immutable` wird auf Hits gesetzt.

Voraussetzung: der Server-Block hat ein per-Site `root` auf das Public-Verzeichnis gesetzt — sonst kann `try_files $uri` die Cache-Datei nicht finden.

#### Laravel Herd

Herd routet alle geparkten Sites durch Valets `server.php`, das anhand von `$_SERVER['REQUEST_URI']` dispatcht. nginx-Level-Rewrites in `herd.conf` aktualisieren diese Variable **nicht** — eine `rewrite … last;` Zeile bleibt dort wirkungslos. Der korrekte Hook ist die per-Site `LocalValetDriver.php` Ihrer REDAXO-Installation.

In der bestehenden `frontControllerPath()`-Methode den Cache-Pfad-Treffer abfangen, **nach** der `$docRoot = …` Zeile und **vor** dem Candidates-Loop:

```php
$docRoot = rtrim($this->getPublicPath($sitePath), '/');

// MASSIF Media: route cache-miss URLs through the addon's Glide shim.
// Cache hits sind durch isStaticFile() abgedeckt und landen nie hier.
if (preg_match('#^/assets/addons/massif_media/cache/(.+)$#', $uri, $m)) {
    $shim = $docRoot . '/assets/addons/massif_media/_img/index.php';
    if ($this->isActualFile($shim)) {
        $_GET['p'] = $m[1];
        $_SERVER['SCRIPT_FILENAME'] = $shim;
        $_SERVER['SCRIPT_NAME'] = '/assets/addons/massif_media/_img/index.php';
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        return $shim;
    }
}

// … bestehender Candidates-Loop bleibt unverändert …
```

Das Snippet liegt zur Copy-Paste-Verfügbarkeit auch unter `assets/LocalValetDriver.snippet.php`. Anschließend `herd restart`.

Logik: Cache-**Hits** werden weiterhin von Valets `serveStaticFile()` direkt ausgeliefert (PHP läuft nicht), weil `isStaticFile()` die Datei auf Disk findet — der Direkt-Auslieferungs-Fastpath bleibt erhalten. Cache-**Misses** routen wir hier explizit zum Glide-Shim mit injektiertem `$_GET['p']`; die ursprünglichen Query-Parameter (`s`, `v`) bleiben unverändert verfügbar.

#### AVIF-Mime-Type

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

Für REDAXO-Redakteure und WYSIWYG-Workflows: das `REX_PIC[…]` Placeholder kann direkt in jedem Textfeld, Markdown-Bereich oder Editor verwendet werden. Beim Rendern der Seite expandiert ein `OUTPUT_FILTER` jeden Treffer zu vollständigem `<picture>`-Markup — gleiche Pipeline wie der PHP-Aufruf, nur deklarativ und ohne PHP-Kenntnisse.

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

| Attribut | Typ | Beschreibung |
|---|---|---|
| `src` | string | **Pflicht.** Dateiname im REDAXO-Mediapool. |
| `alt` | string | Alt-Text. Leer oder fehlend → `aria-hidden="true"` wird gesetzt. |
| `width` | int | Render-Breite in px. Begrenzt das `srcset`, setzt das HTML-`width`-Attribut, reserviert Layout-Box (in Kombination mit `ratio` oder `height`). |
| `height` | int | Render-Höhe in px. Alternativ `ratio`. |
| `ratio` | string | Aspect-Ratio: `16:9`, `16/9` oder Dezimalwert wie `1.7777`. Berechnet `height` aus `width`. |
| `sizes` | string | `sizes`-Attribut für die responsive Auswahl der Variante. Default aus den Settings. |
| `loading` | string | `lazy` (Default) oder `eager`. |
| `decoding` | string | `async` (Default), `sync`, `auto`. |
| `fetchpriority` | string | `auto` (Default), `high`, `low`. |
| `focal` | string | `X% Y%` oder `0.5,0.3` — überschreibt den asset-level Focal-Point vom `focuspoint`-Addon. |
| `preload` | bool | `"true"` injiziert ein `<link rel="preload">` in den `<head>`. |
| `class` | string | CSS-Klasse(n) für das `<img>` bzw. `<picture>`. |

### Performance-Hinweis

`REX_PIC` wird über `OUTPUT_FILTER` auf jedem Seiten-Render gegen den fertigen HTML-Output regex-matched. Für Seiten mit vielen Treffern kann das spürbar werden. Bei Performance-kritischen Listen / Schleifen ist der direkte PHP-Aufruf (`Image::picture(…)` oder `Image::for(…)->render()`) effizienter — `REX_PIC` ist als deklarative Schreibweise für Redakteure gedacht, nicht als Ersatz für die PHP-API in template-/modul-Code.

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

- **Cache-Hit**: Apache (oder nginx, siehe Installation) liefert direkt aus — PHP wird nicht ausgeführt.
- **Cache-Miss**: `.htaccess`-Rewrite (Apache) bzw. `try_files`-Fallback (nginx) leitet auf `_img/index.php` um — HMAC wird verifiziert, Glide generiert die Variante, ab sofort liegt sie auf Disk und wird beim nächsten Request direkt geliefert.

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
