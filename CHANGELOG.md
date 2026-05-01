# Changelog

Alle nennenswerten Änderungen am Addon werden in dieser Datei dokumentiert.
Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- **Self-contained Cache-URL-Routing** via `PACKAGES_INCLUDED`-Hook (`lib/Glide/RequestHandler.php`, registered at `rex_extension::EARLY`). Cache-URLs der Form `/assets/addons/massif_media/cache/…`, die in REDAXOs Frontend `index.php` landen, werden vor `yrewrite` und Article-Rendering abgefangen, die Variante wird on-demand generiert und ausgeliefert, dann `exit`. Pattern aus REDAXOs eigenem `media_manager` adaptiert. Wirkung: das Addon funktioniert jetzt **ohne** addon-spezifische `.htaccess`-Rewrites, nginx-Snippets oder LocalValetDriver-Patches — auf Apache, standalone nginx, Laravel Herd und Valet.
- README-Abschnitt **Placeholder-Strategien: LQIP vs. Blurhash** mit Tabelle, die beide Strategien gegeneinander stellt (Speicherort, Größe in HTML, Decode-Pfad inkl. server-seitiger PHP-Decode-Möglichkeit über `kornrunner/blurhash`, visueller Charakter, Use-Cases). Antwort auf die Frage „sind die nicht redundant?" — sind sie nicht; LQIP ist der direkte Weg für Server-Rendered HTML, Blurhash spielt seine Stärke in JSON-APIs aus.
- nginx Support: `assets/nginx.conf.example` als optionaler Performance-Snippet für standalone nginx (per-Site `server`-Block mit eigenem `root`) — `try_files`-Fastpath für Cache-Hits, Long-lived `Cache-Control`. Funktioniert nur als Optimierung; das Addon liefert auch ohne diese Datei aus.

### Changed

- **REX_PIC migriert von OUTPUT_FILTER zu nativem `rex_var`**: neue `lib/Var/RexPic.php` extends `rex_var`, registered as `REX_PIC`. Substitution passiert beim Article-Cache-Rebuild (statt Regex auf jedem Page-Render gegen den fertigen HTML-Output). Konsequenzen: (1) Performance — kein Regex-Overhead pro Render, der Article-Cache ruft direkt `Image::picture(…)` auf. (2) Scope — `REX_PIC` greift jetzt nur in Slice-Content, nicht mehr in beliebigem Output. Damit ist auch Folgeproblem (3) gelöst: der Backend-Dokumentations-Tab zeigt `REX_PIC[…]` Code-Beispiele in Markdown-Codeblöcken jetzt als Code, weil `rex_var` auf Backend-Pages nicht feuert. Bestehende Slices mit `REX_PIC[…]` müssen einmal „Cache leeren" durchlaufen, damit der Article-Cache mit der neuen Variable neu gebaut wird.
- README **Webserver-Konfiguration** Section komplett umstrukturiert: vorher drei Subsections (Apache out-of-box / Standalone nginx / Laravel Herd Driver-Patch) mit schrittweisen How-Tos. Jetzt eine kompakte Tabelle, die festhält, dass das Addon überall self-contained läuft und die Snippets eine optionale Performance-Optimierung sind.
- README: Cache-Hit-Bullet und URL-Schema-Section beschreiben jetzt den `PACKAGES_INCLUDED`-Hook als kanonischen Cache-Miss-Path. `.htaccess` und nginx-Snippet sind nur noch für den Cache-Hit-Fastpath relevant.
- README: REX_PIC-Attribut-Tabelle um eine **Default**-Spalte erweitert. Jeder Wert (Loading `lazy`, Decoding `async`, FetchPriority `auto`, Sizes aus `default_sizes`, Focal aus dem `focuspoint`-Addon, Width/Height intrinsisch usw.) ist explizit dokumentiert, damit Redakteure auf einen Blick sehen, was bei Weglassen eines Attributs greift.
- Placeholder-Settings-Tab klargestellt: LQIP und Blurhash sind zwei unabhängige Strategien (LQIP = Inline-Base64-JPEG, JS-frei; Blurhash = kompakter Hash für Client-Side-Rendering oder API). Intro-Hinweis am Panel-Anfang, ausführlichere Notice-Texte unter beiden Toggles. Der Blurhash-Option-Label nennt jetzt explizit „Beim ersten Zugriff auf ein Bild berechnen und in der Asset-Metadata cachen".
- README: dedizierter Abschnitt **REX_PIC — Placeholder für Inhaltspflege** mit neun Beispielen (minimal, mit `sizes`, mit `ratio`, responsive, mit Preload, mit Focal-Point, mit CSS-Klasse, SVG-Pass-through, eingebettet in Markdown), vollständiger Attribut-Tabelle und Performance-Hinweis. Vorher nur einzeiliger Erwähnung.

### Removed

- `lib/Parser/REXPicParser.php` und der zugehörige `OUTPUT_FILTER`-Substitution-Pass für `REX_PIC` (ersetzt durch `lib/Var/RexPic.php`, siehe Changed). Der OUTPUT_FILTER-Hook bleibt für die `<link rel="preload">` Injection in den `<head>` bestehen — das ist ein anderes Problem, das nicht von Code-Block-Pollution betroffen ist.
- `assets/LocalValetDriver.snippet.php` (in Unreleased nie veröffentlicht): der `frontControllerPath()`-Interceptor war nur nötig, weil REDAXOs Frontend `index.php` in Herd ohne nginx-Rewrites nicht von der Cache-URL erreicht wurde. Mit dem `PACKAGES_INCLUDED`-Hook ist Herd jetzt nativ abgedeckt — Cache-Misses laufen über Valets default `frontControllerPath()` → REDAXO-Frontend → Hook fängt ab.
- `assets/herd.conf.snippet` (in Unreleased nie veröffentlicht): der Server-Level-Rewrite-Ansatz funktioniert in Herd nicht — Valets `server.php` ignoriert nginx-`rewrite`-Direktiven, weil sie `$request_uri` nicht anfassen.

### Fixed

- Glide cache-path callable schlug nach dem static-closure-Fix mit „Call to undefined method `League\Glide\Server::cachePath()`" fehl, sobald `Image::picture()` einen ersten Cache-Miss verarbeitete. Glide ruft `Closure::bind($callable, $this, static::class)` und rebindet damit nicht nur `$this`, sondern auch `self::` / `static::` auf seine eigene Server-Klasse. Die Closure verwendete `self::cachePath()` und resolvte deshalb zur Laufzeit gegen `League\Glide\Server` statt gegen `Ynamite\Media\Glide\Server`. Behoben durch Klassennamen-Referenz (`Server::cachePath(…)`) — wird zur Compile-Zeit über die File-Namespace-Auflösung resolvt und ist von der bound scope der Closure unabhängig.
- `Image::picture()` löste „Cannot bind an instance to a static closure" in `vendor/league/glide/src/Server.php` aus. Glide ruft `Closure::bind($callable, $this, static::class)` auf der Cache-Path-Callable auf — das schlägt bei `static fn (…)` fehl, weil statische Closures kein `$this` zulassen. `static` aus `Glide\Server::cachePathCallable()` entfernt; der Body nutzt sowieso nur `self::cachePath()`, das Binden ist also faktisch ein No-Op.
- Number-Inputs auf den Settings-Tabs (AVIF/WebP/JPG-Qualität, LQIP-Maße, Cache-TTLs) fehlte die `form-control` CSS-Klasse — REDAXO's `addTextField` injiziert sie automatisch, `addInputField` jedoch nicht. Inputs werden jetzt konsistent mit den Text-Feldern gerendert.
- Breite der Number-Inputs auf 100 px (Qualität / LQIP) bzw. 140 px (TTLs) begrenzt — `form-control` setzt sonst 100 % Container-Breite, was bei 1–3-stelligen Werten unverhältnismäßig wirkt.
- Default-Werte werden auf den Number-Inputs (Qualität / LQIP / TTLs) als `placeholder` angezeigt, damit auf frischen Installationen ohne gespeicherten `rex_config`-Wert ersichtlich ist, was bei leerem Feld als Default greift. Quelle ist `Config::DEFAULTS`, damit Code- und UI-Default nicht auseinanderlaufen.

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
