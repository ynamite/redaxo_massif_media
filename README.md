# MASSIF Media

REDAXO-Addon für moderne, responsive Bild- und Video-Auslieferung.

- **`<picture>`-Markup** mit AVIF, WebP und JPG Sources — der Browser entscheidet selbst, welches Format er lädt (kein Accept-Header-Sniffing).
- **On-demand Resizing** über [league/glide](https://glide.thephpleague.com/) — nur die tatsächlich benötigten Varianten werden generiert.
- **Apache-direkt-Auslieferung** auf Cache-Hits (PHP läuft nur beim ersten Request einer Variante).
- **HMAC-signierte URLs** — verhindert, dass beliebige Größen-/Qualitätskombinationen den Speicher fluten können.
- **LQIP** (Low-Quality Image Placeholder) als inline Base64-JPEG im `background-image` — JS-frei.
- **Blurhash**-Generierung als Sidecar in den Asset-Metadaten — abrufbar via `Image::blurhash($src)` für Galerien / JSON-APIs, optional auch als `data-blurhash` Attribut.
- **Focal-Point-Unterstützung** über das optionale [`focuspoint`](https://github.com/yakamara/redaxo_focuspoint) Addon (`med_focuspoint` Feld).
- **Preload** für Above-the-fold-Bilder via `<link rel="preload">`-Injektion in den `<head>`.
- **SVG/GIF Pass-through** — keine Transformation, nur ein einfaches `<img>`.
- **CDN-Override** (ImageKit, Cloudinary, Imgix-kompatibel) als optionale Konfiguration.
- **REDAXO-natives `REX_PIC[…]` Placeholder** für Inhaltspflege in Textfeldern / WYSIWYG.

Inspiriert vom [Statamic Responsive Images Addon](https://github.com/statamic/responsive-images).

## Anforderungen

- REDAXO 5.13+
- PHP 8.2+
- **Imagick** (empfohlen). Für AVIF-Output zusätzlich **libheif/libavif** in der Imagick-Build. GD funktioniert auch, kann aber kein AVIF und liefert qualitativ schwächere Skalierungen.
- Optional: [`focuspoint`](https://github.com/yakamara/redaxo_focuspoint) Addon für visuelle Focal-Point-Pflege.

## Installation

1. Addon ins REDAXO-System hochladen oder über Connect installieren.
2. Aktivieren — der HMAC Sign-Key und das Cache-Verzeichnis werden automatisch eingerichtet.
3. Optional: Einstellungen unter **AddOns → MASSIF Media → Einstellungen** anpassen.

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

In Textfeldern / Modulen für Redakteure:

```
REX_PIC[src="hero.jpg" alt="Aussicht" width="1440" sizes="100vw"]
```

Wird vom OUTPUT_FILTER zu vollständigem `<picture>`-Markup expandiert.

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

- **Cache-Hit**: Apache liefert direkt aus (PHP wird nicht ausgeführt).
- **Cache-Miss**: `.htaccess`-Rewrite leitet auf `_img/index.php` um — HMAC wird verifiziert, Glide generiert die Variante, ab sofort liegt sie auf Disk und wird beim nächsten Request direkt geliefert.

`?s=` ist eine HMAC-SHA256-Signatur über den Cache-Pfad gegen `sign_key` aus den Einstellungen.
`?v=` ist der `mtime` des Quellbildes — sorgt nur für Browser-/CDN-Cache-Invalidierung.

## Cache-Invalidierung

- **Backend "Cache leeren"** (UI oder `console cache:clear`): das Addon hängt sich an `CACHE_DELETED` und leert den eigenen Cache mit.
- **"Addon Cache leeren"** auf der Settings-Seite: gezielt nur unseren Cache.
- **Quelländerung**: REDAXO ändert den `mtime`, dadurch ändert sich der `?v=` Parameter — Browser/CDN holen die neue URL. Das Disk-File ist dann zwar noch da, aber Anfragen mit neuem `?v=` bleiben Cache-Hits, weil `?v=` nicht Teil des Datei-Pfades ist. Bei Bedarf das Addon-Cache leeren oder REDAXO-Cache leeren.

## Sicherheit

URLs sind HMAC-signiert. Ohne den Sign-Key kann niemand Generierungen für beliebige Breiten/Qualitäten anstoßen — Disk-Filling-Angriffe sind damit ausgeschlossen.

Wenn der Sign-Key neu generiert wird, werden alle bisher signierten URLs ungültig. Bestehende Cache-Files bleiben jedoch erreichbar (Apache prüft die Signatur nicht erneut beim direkten Ausliefern).

## Lizenz

MIT — siehe `LICENSE`.
