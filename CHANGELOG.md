# Changelog

Alle nennenswerten Änderungen am Addon werden in dieser Datei dokumentiert.
Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Fixed

- Number-Inputs auf den Settings-Tabs (AVIF/WebP/JPG-Qualität, LQIP-Maße, Cache-TTLs) fehlte die `form-control` CSS-Klasse — REDAXO's `addTextField` injiziert sie automatisch, `addInputField` jedoch nicht. Inputs werden jetzt konsistent mit den Text-Feldern gerendert.
- Breite der Number-Inputs auf 100 px (Qualität / LQIP) bzw. 140 px (TTLs) begrenzt — `form-control` setzt sonst 100 % Container-Breite, was bei 1–3-stelligen Werten unverhältnismäßig wirkt.

## [1.0.0] — 2026-05-01

Erstes Release. MASSIF Media wurde aus dem `redaxo-massif` Sammel-Addon ausgelagert und auf eine moderne, eigenständige Pipeline umgebaut. Alte `Ynamite\Massif\Media\…` Aufrufe in bestehenden Projekten bleiben unverändert nutzbar (das alte Addon koexistiert); neuer Code verwendet `Ynamite\Media\…`.

### Added

- **`<picture>` Markup** mit AVIF, WebP und JPG Sources. Browser-seitige Format-Negotiation via `<source type="…">` — kein Accept-Header-Sniffing mehr.
- **On-demand Resizing** über [`league/glide`](https://glide.thephpleague.com/) (Imagick-Driver bevorzugt). Kein Pre-Warming.
- **HMAC-SHA256 signierte URLs** verhindern Disk-Filling-Angriffe.
- **Apache-direkt-Auslieferung** auf Cache-Hits via `.htaccess`-Rewrite. PHP läuft nur beim ersten Request einer Variante.
- **Custom `ColorProfile` Glide-Manipulator** normalisiert Imagick-Colorspace auf sRGB (fixt Display P3 / Adobe RGB Washout).
- **LQIP** (Low-Quality Image Placeholder) als Inline-Base64-JPEG (32 px, Blur 40, Q 40) als `background-image` — JS-frei.
- **Blurhash**-Generierung über `kornrunner/blurhash`, gecached in `_meta/`-Sidecars. Abrufbar via `Image::blurhash($src)` oder als opt-in `data-blurhash` Attribut.
- **Focal-Point**-Unterstützung über das optionale [`focuspoint`](https://github.com/yakamara/redaxo_focuspoint) Addon (`med_focuspoint` Feld). Per-Call-Override über `focal:` Argument.
- **Preload** für Above-the-fold-Bilder via `<link rel="preload" as="image">` Injection in den `<head>` über `OUTPUT_FILTER`.
- **SVG/GIF Pass-through** — keine Transformation, schlichtes `<img>` mit intrinsischen Maßen.
- **CDN-Override** (ImageKit / Cloudinary / Imgix-kompatibel) via `cdn_base` + `cdn_url_template` mit `{w}/{q}/{fm}/{src}` Tokens.
- **Hybrid Public API**: statisches `Image::picture(...)` als One-Liner für den Standardfall + fluentes `Image::for($src)->...->render()` für komplexere Fälle. Analoges Design für `Video`. `Pic` als Statamic-style Kurzalias.
- **`REX_PIC[src="..." alt="..." …]` Placeholder** für Inhaltspflege in Textfeldern / WYSIWYG, parsed über `OUTPUT_FILTER` — gleiche Pipeline wie die PHP-API.
- **Backend-Settings-Seite** mit vier Tabs: Allgemein, Placeholder, CDN, Sicherheit & Cache.
- **Dokumentations-Tab** im Backend rendert die `README.md` direkt (`subPath: README.md`).
- **`CACHE_DELETED` Hook** leert den Addon-Cache automatisch beim REDAXO-Cache-Reset.
- **Backend-Cache-Reset-Button** auf der Sicherheit-Seite für gezieltes Leeren ohne kompletten REDAXO-Cache-Clear.
- **Sign-Key-Regenerate-Button** für gezielte Invalidierung aller bisher signierten URLs.

### Pipeline architecture

- `Pipeline/ImageResolver` (rex_media → ResolvedImage)
- `Pipeline/MetadataReader` (intrinsische Maße + Blurhash + Focal, gecached in `meta.json` Sidecars per Asset-mtime)
- `Pipeline/ResolvedImage` (readonly Value Object)
- `Pipeline/SrcsetBuilder` (next/image dual-pool: `device_sizes` + `image_sizes`, capped at min(intrinsisch, width))
- `Pipeline/UrlBuilder` (signierter Glide-URL oder CDN-URL)
- `Pipeline/Placeholder` (Glide-generated Base64 LQIP)
- `Pipeline/Preloader` (Static-Queue, gedrained vom OUTPUT_FILTER)
- `View/PictureRenderer` + `View/PassthroughRenderer`
- `Glide/{Server,Endpoint,Signature,ColorProfile}`
- `Parser/REXPicParser`

### Requirements

- REDAXO 5.13+
- PHP 8.2+
- Imagick (empfohlen, nötig für AVIF-Output)
- Optional: `focuspoint` Addon für visuelle Focal-Point-Pflege

### Notes

- Migriert nicht von `Ynamite\Massif\Media`. Dieses Addon koexistiert mit `redaxo-massif`; Aufrufer im neuen Code wechseln zum neuen Namespace.
- Art Direction (multiple `<source media="…">`), Image Warming, externe URL-Quellen und ein visueller Focal-Point-Picker sind für v2 vorgesehen.

[Unreleased]: https://github.com/ynamite/massif_media/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ynamite/massif_media/releases/tag/v1.0.0
