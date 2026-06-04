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

- REDAXO 5.18+
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

## Einzel-URL ohne `<picture>`-Markup

`Image::picture(...)` und `Image::for($src)->render()` liefern das vollständige `<picture>`-Markup mit `<source>`-Tags pro Format und Fallback-`<img>`. Es gibt aber Kontexte, in denen der Browser nur **eine** URL braucht — kein Markup, kein `srcset`:

- `<video poster="…">` (HTML5 hat kein `srcset` für Poster)
- Open Graph / Twitter-Card-`<meta>`-Tags (Validatoren stolpern teilweise über `<picture>`-spezifische Markups)
- CSS `background-image`
- JS-getriebene Canvas / Sprite-Logik

Für genau diese Fälle gibt es `Image::url(...)`:

```php
use Ynamite\Media\Image;

// Single signed URL via die volle Glide-Pipeline:
$posterUrl = Image::url(
    src:    'hero-still.jpg',
    width:  1280,
    format: 'webp',     // optional — Default: erstes Format aus Config::formats()
    fit:    'cover',
    focal:  '40% 30%',
);

// Verwendung als Video-Poster:
echo Video::render(
    src:    'hero.mp4',
    poster: $posterUrl,
    width:  1920,
    height: 1080,
);
```

Die zurückgegebene URL geht **durch denselben Glide-Cache** wie `picture()` — ein `Image::url(width: 1280, format: 'webp')` Aufruf teilt sich seine On-Disk-Cache-Datei mit der passenden `<picture>`-Variante derselben Breite / Format / Qualität. Kein zweiter Cache, keine Duplikation.

`width` ist optional — ohne Angabe wird der Median des `effectiveMaxWidth`-gecappten Width-Pools gewählt (dieselbe Logik wie `PictureRenderer`s Fallback-`<img src>`).

**SVG / GIF (Passthrough):** Es wird die rohe Mediapool-URL zurückgegeben — `width` / `format` / Filter werden still ignoriert. Animierte GIFs liefern die statische GIF-URL, **nicht** die animierte WebP-Variante (das `<video poster>` + animated-WebP-Konstrukt ist per HTML5 undefiniert; CSS `background-image` mit animiertem WebP loopt automatisch — selten gewollt).

---

## Externe URL-Quellen

Jede Bild-API (`Image::picture`, `Image::url`, `Image::for($src)->...`) und `Video::render` / `Video::for` akzeptieren neben Mediapool-Filenames auch beliebige HTTPS-URLs:

```php
echo Image::picture(
    src:   'https://images.example.com/hero.jpg',
    alt:   'Hero',
    ratio: 16 / 9,
);

// Single-URL für CSS / Open Graph:
$ogImage = Image::url(
    src:    'https://cdn.example.com/banner.jpg',
    width:  1200,
    format: 'jpg',
);

// Externe Quelle als Video-Poster:
echo Video::render(
    src:    'hero.mp4',
    poster: Image::url(src: 'https://cdn.example.com/still.jpg', width: 1280),
);

// Externes Video direkt:
echo Video::render(src: 'https://stream.example.com/clip.mp4', autoplay: true, muted: true, loop: true);
```

Beim ersten Render lädt das Addon das Original einmal via `symfony/http-client` herunter, schreibt es atomar nach `cache/_external/<urlHash>/_origin.bin` und schickt es danach durch dieselbe Glide-Pipeline wie ein Mediapool-Bild — `<source srcset>`-Generation, Format-Negotiation, AVIF/WebP/JPG-Encoding, Crop, Filter und LQIP funktionieren identisch. Die emittierten URLs zeigen auf den lokalen Cache (`/assets/addons/massif_media/cache/_external/<hash>/<spec>.<ext>`); der Original-URL leakt nirgends ins gerenderte HTML.

**TTL-Refresh** statt Hammering: Default `external_ttl_seconds = 86400` (24 h). Nach Ablauf sendet der nächste Render einen Conditional GET (`If-None-Match` / `If-Modified-Since`) — 304 bumpt nur den `fetchedAt`-Timestamp (das `&v=`-Cache-Buster-Token bewegt sich, der Body bleibt); 200 mit neuem Body schreibt die Origin-Datei atomar neu und gibt das frische ETag in den Manifest. TTL ist im Backend-Tab **Sicherheit & Cache** unter *Externe URL-Quellen* einstellbar.

**SSRF-Schutz**: Vor jedem Fetch wird der Hostname aufgelöst und gegen eine Block-Liste geprüft — Loopback (127/8, 0/8), Private (10/8, 172.16/12, 192.168/16), Link-Local (169.254/16), CGNAT (100.64/10), Multicast (224/4) und Broadcast (255.255.255.255) werden abgelehnt. Die validierte IP wird über Symfonys `'resolve'`-Option (= libcurl `CURLOPT_RESOLVE`) auf die Connection gepinnt — DNS-Rebinding zwischen Check und Connect ist damit ausgeschlossen.

**Optionaler Host-Allowlist** (Defence-in-Depth zusätzlich zur HMAC-Signatur): `external_host_allowlist` als Textarea, ein Regex pro Zeile (anchored mit `^…$`). Default leer = alle Hosts erlaubt. Beispiel:

```
^images\.example\.com$
^cdn\.example\.org$
```

**Body-Size-Cap** (Default 25 MB, `external_max_bytes`): mid-stream Abort wenn überschritten. **Connect-Timeout** Default 15 s (`external_timeout_seconds`).

**Cache-Layout** für externe Quellen:

```
cache/_external/<xxh64(url)[:16]>/
  _origin.bin           # geladenes Original (Glide liest hier)
  _manifest.json        # { url, etag, lastModified, fetchedAt, ttl }
  webp-1280-80.webp     # Glide-erzeugte Varianten — flache Spec ohne Path-Prefix
  avif-1920-1080-cover-50-30-50.avif
  ...
```

Der `_external/`-Präfix ist reserviert; Mediapool-Filenames können nicht mit `_` beginnen (gleiche Konvention wie die existierenden `_meta/`, `_lqip/`, `_color/`-Verzeichnisse).

**CDN-Modus wird übersprungen.** Wenn `cdn_enabled` an ist, gehen Mediapool-Quellen wie gehabt durch das CDN-Template; externe Quellen routen aber weiter über den `_external/<hash>`-Pfad — der Upstream ist schon ein CDN, ihn nochmal durch unseren zu schicken wäre sinnlos. Externe `<video>`-Quellen werden dagegen direkt als `<video src="https://…">` emittiert (kein Proxy, kein Fetch).

**Animated WebP** und **Art Direction** funktionieren für externe Bilder genauso wie für Mediapool-Bilder. Filter, Focal-Point per Setter (`->focal('50% 30%')`) und `fit`-Token funktionieren ebenfalls — externe Quellen haben keinen `med_focuspoint`-Wert auf einer rex_media-Row, aber per-Call-Override über `focal:` ist möglich.

**Cache-Invalidierung** für externe URLs läuft via TTL-Refresh oder manuell:

```php
\Ynamite\Media\Pipeline\CacheInvalidator::invalidateUrl('https://images.example.com/hero.jpg');
```

— löscht den ganzen `cache/_external/<hash>/`-Bucket inklusive aller Varianten und der Sidecars. "Cache leeren" auf dem **Sicherheit & Cache**-Tab räumt alles inkl. externer Buckets weg.

**Was nicht durch die externe Pipeline geht:** Animated-GIF-zu-WebP-Wrap (das ist eine Mediapool-Editor-Optimierung, externe Quellen werden im `<picture>` direkt verlinkt), und das `med_focuspoint`-Feld vom `focuspoint`-Addon (existiert nur auf Mediapool-Records).

---

## Art Direction

Manchmal soll auf Mobile ein anderer Bild-**Crop** oder sogar eine ganz andere **Quelle** geladen werden als auf Desktop — ein Hochformat-Crop fürs Smartphone, das Wide-Angle-Original auf dem Laptop. Genau dafür gibt es Art Direction: mehrere `<source>`-Tags mit `media=`-Query, jede mit eigenem `srcset`.

Im Builder:

```php
echo Image::for('hero-landscape.jpg')
    ->alt('Hero')
    ->ratio(16, 9)               // Default-Variante (Desktop): 16:9
    ->art([
        [
            'media' => '(max-width: 600px)',
            'src'   => 'hero-portrait.jpg',
            'ratio' => 1,             // Mobile: quadratisch
            'focal' => '50% 30%',     // anderes Focal
        ],
        [
            'media' => '(max-width: 1024px)',
            'src'   => 'hero-tablet.jpg',
            'ratio' => 4 / 3,         // Tablet: 4:3
        ],
    ])
    ->render();
```

`->art([...])` nimmt eine Liste von Variant-Maps. Pflichtfelder: `media` (eine beliebige CSS-Media-Query) und `src` (Mediapool-Filename, `rex_media`-Instanz oder externe HTTPS-URL — externe Quellen gehen transparent durch die externe Pipeline). Optional: `width`, `height`, `ratio` (`'16:9'` / `'4/3'` / `1.7777` werden alle akzeptiert), `focal` (`'50% 30%'`), `fit` (`'cover'` / `'contain'` / `'stretch'` / `'none'`), `filters` (friendly-keyed; wird durch `FilterParams::normalize` validiert).

**Builder-Level-Filter (`->blur(5)`) gelten nur für die Default-Variante.** Jede `art`-Variante ist self-describing — eigenes `filterParams`. Das hält "anderer Crop auf Mobile" und "anderer Filter auf Mobile" sauber orthogonal:

```php
echo Image::for('hero.jpg')
    ->blur(8)                 // Default-Variante: blur 8
    ->art([
        ['media' => '(max-width: 600px)', 'src' => 'hero-mobile.jpg', 'ratio' => 1],
        // mobile variant: KEIN blur, weil sie ihre eigenen filterParams ([]) hat
    ])
    ->render();
```

**Browser-Cascade-Reihenfolge**: Art-Direction-`<source>`s werden **vor** den Format-keyed Default-Sources emittiert. Browsers iterieren `<source>` top-to-bottom und nehmen den ersten matchenden — `media` filtert by Viewport, `type` by Format-Support. Eine Variante bekommt einen eigenen `<source>` pro Format (also bei `formats: ['avif', 'webp', 'jpg']` und 2 Varianten = 6 Variant-Sources + 2 Default-Sources + 1 Fallback-`<img>`).

**Das Fallback-`<img>`** bleibt single-Variant (Default-Source). HTML5 verlangt ein einziges `<img>`-Fallback für Browser ohne `<picture>`-Support; ohne Variant-Differenzierung ist das fine — Browsers, die mit `<picture>` umgehen können, nehmen sowieso eine Variante.

**`<video>` bekommt kein Art Direction.** `<video><source media>` existiert technisch, aber UAs re-evaluieren die Quelle nicht beim Viewport-Resize, und der Use-Case (mobile vs. desktop crop) ist nicht das, was das Element vorsieht. Wer responsive Video-Posters braucht, geht via `Image::url()` für das Poster (siehe oben).

### REX_PIC: Art Direction in Slice-Inhalten

Im Editor-Slice via JSON-Attribut `art='…'`. Der natürlichste Shape sind **komma-separierte Variant-Objekte** ohne Outer-Wrapper — sieht aus wie eine Liste, ohne die `[…]` die REDAXO im Tag-Kontext ohnehin nicht akzeptiert:

```
REX_PIC[
  src="hero.jpg"
  alt="Hero"
  ratio="16:9"
  art='
    {"media":"(max-width: 600px)", "ratio":1, "focal":"50% 30%"},
    {"media":"(max-width: 1024px)", "ratio":"4/3"}
  '
]
```

**`src` in einer Variante ist optional**: fehlt es (oder ist leer), erbt die Variante automatisch das `src` der übergeordneten `REX_PIC`. Das obige Beispiel nutzt das — beide Varianten croppen das gleiche `hero.jpg` unterschiedlich. Für den Fall "anderes Bild pro Breakpoint" einfach `"src":"hero-mobile.jpg"` o.ä. ergänzen.

> **Warum nicht einfach ein JSON-Array?** REDAXOs `rex_var`-Tokenizer (`var.php::getMatches`) verbietet unescapte `[`/`]` innerhalb eines REX_VAR-Tags — `art='[…]'` würde dazu führen, dass das gesamte `REX_PIC[…]` nicht als Tag erkannt wird. Geschweifte `{…}` sind unproblematisch, daher die Komma-Separator-Shorthand. Beim direkten PHP-Aufruf (`Image::picture(art: [...])`) gilt die Einschränkung nicht; dort ist die normale Listenform die natürliche Wahl.

`RexPic::buildArtArg` akzeptiert insgesamt vier äquivalente JSON-Shapes:

1. **Komma-separierte Variants** (oben, slice-idiomatic): `{"media":…},{"media":…}`. Wird intern via `[…]`-Wrap als Liste geparst.
2. **JSON-Objekt** mit free-form Keys: `{"sm":{"media":…},"md":{"media":…}}`. Praktisch wenn man stable IDs für die Varianten möchte (Reihenfolge wird via `json_decode` aus der Source erhalten).
3. **Single Bare Variant**: `{"media":…,"src":…}` — eine einzelne Variante als Object ohne Wrapper.
4. **JSON-Liste**: `[{"media":…},{"media":…}]` — nur für direkte PHP-Aufrufe, nicht in `REX_PIC[]` (siehe Tokenizer-Einschränkung oben).

Allowlist-Keys pro Variante: `media`, `src`, `width`, `height`, `ratio`, `focal`, `fit`, `filters`. Unbekannte Keys werden silently gedroppt; fehlt `media` (oder ist `src` weder in der Variante noch in der Parent gesetzt), wird die Variante übersprungen. Emittiert wird `art: json_decode(<json>, true)` als PHP-Literal in den Article-Cache; `Image::picture` re-validiert jede Entry zur Render-Zeit via `ArtDirectionVariant::fromArray`.

**Bei Parse-Fehler** (kaputtes JSON, fehlende Pflicht-Keys) loggt das Addon eine Warning via `rex_logger::factory()->log('warning', …)` und rendert das `<picture>` ohne Art Direction — eine kaputte JSON im Slice wirft die Seite nicht in einen 500.

`REX_VIDEO` hat **kein** `art`-Attribut (siehe oben — `<video>` ist semantisch single-source).

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

### Single-URL-Modus mit `as="url"`

Manchmal braucht man nicht das volle `<picture>`-Markup, sondern nur **eine einzelne URL** auf eine bestimmte Variante — zum Beispiel als `poster` für ein `REX_VIDEO`, in einem Open-Graph-`<meta>`-Tag, in einem CSS `background-image` oder in einer JS-Sprite-Logik.

Mit dem Attribut `as="url"` wird statt `<picture>`-Markup nur die signierte URL einer einzelnen Variante zurückgegeben:

```text
REX_PIC[src="hero.jpg" width="1280" as="url"]
```

Wird zu (gekürzt):

```text
/assets/addons/massif_media/cache/hero.jpg/avif-1280-50.avif?s=…&v=…
```

Alle Crop- und Filter-Attribute funktionieren analog zum normalen Modus — `width`, `height`, `ratio`, `fit`, `focal`, `format`, `quality` und sämtliche Bildfilter (Brightness, Sharpen, Watermark etc.). Render-Attribute wie `alt`, `sizes`, `loading`, `decoding`, `fetchpriority`, `preload` und `class` werden im URL-Modus ignoriert (sie betreffen nur das `<img>`-Element).

**Verschachtelung mit `REX_VIDEO`:** Der Platzhalter kann direkt als Wert eines anderen `REX_VAR`-Attributs verwendet werden — REDAXO löst verschachtelte `rex_var`-Aufrufe rekursiv auf. Damit lässt sich das HTML5-Limit "kein `srcset` für Video-Poster" elegant umgehen, indem aus der responsiven Pipeline eine sinnvolle Einzel-URL gewählt wird. **Hinweis:** Verschachtelung funktioniert nur im Cache-Build-Pfad (Modul-Templates, Modul-Output, Templates), nicht im Scan-Pfad (Editor-Inhalte / WYSIWYG) — siehe *Scope und Performance von REX_PIC* weiter unten.

```text
REX_VIDEO[
  src="hero.mp4"
  poster="REX_PIC[src='hero-still.jpg' width='1280' as='url']"
  width="1920"
  height="1080"
]
```

**Wichtig zum Cache-Rebuild:** `REX_PIC[…]` wird beim Article-Cache-Build in PHP-Code übersetzt und im Cache abgelegt. Bestehende Slices, die `REX_PIC` verwenden, profitieren erst vom neuen `as="url"`-Verhalten, nachdem der Article-Cache neu aufgebaut wurde — entweder über **System → Cache leeren** im Backend oder durch ein Versions-Update des Addons (das den Cache implizit invalidiert).

**Cache-Sharing mit `<picture>`:** Die zurückgelieferte URL geht durch denselben Glide-Cache wie das `<picture>`-Markup — `as="url"` mit denselben Parametern wie eine Variante im normalen Modus liefert die identische On-Disk-Cache-Datei. Kein zweiter Cache, keine Duplikation.

**SVG / GIF:** Wie im normalen Modus liefert das Addon für Passthrough-Quellen die rohe Mediapool-URL zurück — `width`, `format` und Filter werden still ignoriert.

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
| `as`            | string | — (volles `<picture>`-Markup)                   | Auf `"url"` setzen → liefert nur die signierte URL einer Variante zurück (siehe **Single-URL-Modus**) |
| `format`        | string | erstes Format aus Settings → typischerweise AVIF | nur in Verbindung mit `as="url"`: `avif`, `webp` oder `jpg`        |
| `quality`       | int    | aus Format-Settings                             | nur in Verbindung mit `as="url"`: `1..100`                          |

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

| Attribut    | Wert                | Beschreibung                                                                   |
| ----------- | ------------------- | ------------------------------------------------------------------------------ |
| `mark`      | string              | Mediapool-Filename, HTTPS-URL oder `/`-präfixierter Projekt-Pfad (siehe unten) |
| `marks`     | 0.0..1.0            | Relative Grösse, z. B. `0.25` für 25 % der Bildbreite                          |
| `markw`     | int oder `Nw`/`Nh`  | Pixel-Breite oder Prozent (`20w` = 20 % Bildbreite, `20h` = 20 % Bildhöhe)     |
| `markh`     | int oder `Nw`/`Nh`  | Pixel-Höhe oder Prozent — analog zu `markw`                                    |
| `markpos`   | Glide-Position      | Position des Wasserzeichens                                                    |
| `markpad`   | int                 | Abstand zum Rand in px                                                         |
| `markalpha` | 0..100              | Deckkraft                                                                      |
| `markfit`   | `cover` / `contain` / `stretch` | Einpassung in die `markw`×`markh`-Box (nur relevant, wenn beide gesetzt sind) |

**`mark`-Quellen** — drei akzeptierte Shapes:

- **Mediapool-Filename** (`"logo.png"`, `"subdir/logo.png"`) — Standard-Fall.
- **HTTPS-URL** (`"https://example.com/logo.png"`) — geht durch dieselbe External-Source-Pipeline wie externe Haupt-Bilder (SSRF-Guard, TTL, Conditional GET, einmal-pro-TTL-Fetch). Failures (Bad URL, SSRF-Block, Network-Error) werden geloggt; das Bild rendert dann ohne Watermark statt 500.
- **Projekt-Pfad mit führendem `/`** (z. B. `/assets/addons/foo/img.webp`) — wird relativ zu `rex_path::base()` interpretiert, Query-String wird abgeschnitten. Hauptzweck: nested `REX_PIC[…, as='url']` als Watermark-Source. Caveat: die Datei muss **schon existieren** wenn die Watermark-Variante gerendert wird — Glide-Cache-URLs zeigen erst auf eine echte Datei, nachdem die Variante einmal generiert wurde. Für saubere Garantie lieber den Mediapool-Filename direkt benutzen.

**Grössen-Tipp** — bei grossen Source-Bildern (z. B. 5712 × 3213 px) wirkt `markw="100"` (100 px absolut) winzig. Für proportionale Watermark-Grösse entweder die Prozent-Syntax (`markw="20w"` = 20 % der Bildbreite) oder die relative `marks`-Angabe nutzen (`marks="0.2"` = 20 % der Bildbreite, Höhe seitenverhältnis-treu) — beide skalieren mit der Variante.

**Schärfe** — der Mark wird mit Imagicks Lanczos-Resampling herunterskaliert und alpha-korrekt (`COMPOSITE_OVER`, sRGB-normalisiert) eingesetzt. Auch hochauflösende Logo-PNGs kommen dadurch scharf heraus statt matschig/treppig. (Imagick-only; auf reinen GD-Installs greift Glide's Standard-Resampling.)

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

Wenn die Watermark-Datei nicht aufgelöst werden kann (Mediapool-Tippfehler, SSRF-blockierte URL, externer Fetch-Fehler, fehlender nested-`REX_PIC`-Cache-File), rendert das Bild **ohne** Watermark — kein 404, kein 500, kein broken-image-Glyph. Der Failure wird via `rex_logger` als Warning protokolliert, sodass Editor-Tippfehler im System-Log auffindbar bleiben statt das Frontend zu zerschiessen.

---

# Scope und Performance von REX_PIC

`REX_PIC` wird über zwei verschiedene Pfade in `<picture>`-Markup übersetzt — beide funktionieren transparent für den Editor, unterscheiden sich aber in Edge Cases.

**1. Cache-Build-Pfad** (Modul-Output, Modul-Input, Templates):

REDAXO ruft `rex_var::parse()` auf den Modul-/Template-PHP-Code auf. `REX_PIC[…]` wird in PHP-Code übersetzt und im Article-Cache abgelegt — pro Render entsteht kein Regex-Overhead, der Article-Cache ruft direkt `\Ynamite\Media\Image::picture(...)` auf. Verschachtelte `REX_VAR`-Aufrufe (z. B. `REX_VIDEO[poster="REX_PIC[…, as='url']"]`) werden hier rekursiv aufgelöst.

**2. Post-Render-Scan** (Slice-Content / WYSIWYG, Rich-Text-Felder):

REDAXOs `rex_var::parse()` läuft nicht auf gespeicherten Slice-Werten — nur auf Modul-/Template-PHP-Code. Damit `REX_PIC[…]` auch in Editor-Inhalten funktioniert, scannt der Addon im `OUTPUT_FILTER` das gerenderte HTML nach `REX_PIC[…]` / `REX_VIDEO[…]` und ersetzt die Treffer. Der Scan überspringt Seiten ohne entsprechende Marker per `stripos`-Prüfung (Kosten ≈ 0); auf Seiten mit Markern wird pro Tag ein `Image::picture()` / `Video::render()` aufgerufen.

Limitierungen des Scan-Pfads gegenüber dem Cache-Build-Pfad:

- **Keine verschachtelten `REX_VAR`s in Attributwerten.** Beim Output-Filter sind alle Template-Level-`rex_var`s bereits aufgelöst — eine Verschachtelung im Editor-Input wäre nie durch den Cache-Build gelaufen. Wer Verschachtelung braucht, setzt `REX_PIC[…]` ins Modul-Template, nicht in den WYSIWYG.
- **Keine `[`/`]` in Attributwerten.** Gleiche Einschränkung wie REDAXOs Tokenizer.
- **`<pre>` / `<code>`-Blöcke werden ebenfalls ersetzt.** Wer literale `REX_PIC[…]`-Code-Beispiele anzeigen will (z. B. in Doku-Slices), muss `&#91;` / `&#93;` für die eckigen Klammern verwenden.

Bei Render-Fehlern im Scan-Pfad (fehlendes `src`, Mediapool-Datei nicht auflösbar, leere Renderausgabe) bleibt der literale `REX_PIC[…]`-String stehen — so sieht der Editor das Problem direkt im Frontend, statt einen leeren Platz an der Stelle des Bilds. Fehler werden zusätzlich via `rex_logger` als Warning protokolliert.

Für Custom-Felder, Metainfo-Texte oder rohen `tt_news`-Output, die weder durch `replaceObjectVars()` noch durch `OUTPUT_FILTER` laufen, direkt die PHP-API nutzen:

```php
Image::picture(...)
```

oder:

```php
Image::for(...)->render()
```

Wenn nach einem Addon-Update ein Slice mit `REX_PIC[…]` nicht mehr rendert:

REDAXO-Cache leeren.

Die `rex_var`-Substitution im Cache-Build-Pfad ist an den Article-Cache gebunden. Geändertes `getOutput()` greift erst nach einem Cache-Rebuild. Der Scan-Pfad arbeitet pro Request und ist davon nicht betroffen.

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

### Hero-Video als LCP-Kandidat (mit `<link rel="preload">`)

Wenn das Video selbst die grösste sichtbare Komponente im Viewport ist (z.B. autoplay-Hintergrundloop), kann zusätzlich ein `<link rel="preload">` in den `<head>` injiziert werden, damit der Browser das Video bereits während des Head-Parsings zu laden beginnt:

```text
REX_VIDEO[
  src="hero.mp4"
  poster="REX_PIC[src='hero-still.jpg' width='1280' as='url']"
  width="1920"
  height="1080"
  autoplay="true"
  muted="true"
  loop="true"
  preload="auto"
  linkpreload="true"
]
```

Das ergibt zwei Preload-Links im `<head>`:

```html
<link rel="preload" as="video" href="…/clip.mp4?v=…" type="video/mp4" fetchpriority="high">
<link rel="preload" as="image" href="…/cache/hero-still.jpg/avif-1280-50.avif?…" fetchpriority="high">
```

`linkpreload` ist **orthogonal zu `preload`** — letzteres ist das HTML-`<video preload>`-Attribut (Fetch-Verhalten nach dem Body-Parse), `linkpreload` injiziert den Preload schon im Head. Beides setzen ist die korrekte Kombination für LCP-Hero-Videos.

**Wichtig zum Poster-Preload:** Es wird nur dann ein `<link as="image">` für den Poster emittiert, wenn der Poster-Wert eine vollständige URL (`://`), ein absoluter Pfad oder eine Data-URI ist. Blosser Mediapool-Filename → kein Poster-Preload (er würde auf die Mediapool-URL zeigen, während der Browser das `<video poster>` relativ zur Page-URL auflöst — die Diskrepanz wäre verschwendete Bandbreite). Mit dem oben gezeigten `REX_PIC[as='url']`-Pattern liefert die Pipeline eine signierte absolute URL — Preload und tatsächlicher Fetch matchen byte-genau.

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
| `preload`     | string | `metadata` | `none`, `metadata` oder `auto` (HTML-Attribut) |
| `linkpreload` | bool   | `false`    | `"true"` injiziert `<link rel="preload" as="video">` (und ein `<link as="image">` für ein URL-/Absolut-/Data-URI-Poster) in den `<head>` |
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

### Hinweise zu `poster`

Der `poster`-Wert wird vor dem Rendern validiert:

- **Volle URLs** (`https://...`, `http://...`, `//cdn...`), **absolute Pfade** (`/assets/...`) und **Data-URIs** (`data:image/...`) werden unverändert durchgereicht — Remote-Existenz wird nicht geprüft.
- **Blosser Mediapool-Filename** (`hero-still.jpg`): wenn die Datei nicht unter `rex_path::media($poster)` lesbar ist und auch keinen `rex_media`-Eintrag hat, wird das `poster`-Attribut komplett weggelassen und der Vorfall via `rex_logger` geloggt. Das verhindert einen Layout-Kollaps in WebKit / Blink (siehe Hintergrund unten).

**Hintergrund:** Browser handhaben einen kaputten `<video poster>`-URL inkonsistent. WebKit / Blink behalten die 0×0-Box des broken-image-Status als Intrinsic-Size des Video-Elements, bis die Video-Metadaten geladen sind — das Element kollabiert auf null Höhe, solange kein `width` / `height` gesetzt ist. Die HTML5-Spec verlangt eigentlich Fallback zu "no poster"-Verhalten, aber Engines divergieren. Sicherer ist: keine `poster`-Attribute emittieren, von denen wir wissen dass sie kaputt sind.

**Empfehlung:** Setze für robustes Layout immer `width` und `height` (oder `aspect-ratio` via CSS), unabhängig vom Poster-Status. Für responsive Poster aus dem Mediapool siehe das `as="url"`-Pattern in der REX_PIC-Doku — `REX_VIDEO[poster="REX_PIC[src='hero.jpg' width='1280' as='url']"]` liefert eine signierte URL aus der Glide-Pipeline.

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

## Metadaten-TTL und Sentinel-TTL

Asset-Metadaten (intrinsische Maße, MIME-Typ, Animations-Flag, Focal-Point) werden pro Quelle in einem `_meta`-Sidecar gecacht, damit nicht bei jedem Render erneut `getimagesize()` und ein Imagick-Ping laufen. Zwei Einstellungen auf dem Tab **Sicherheit & Cache** steuern, wie lange ein solcher Sidecar gilt:

- **Metadata TTL** (`metadata_ttl_seconds`, Default 90 Tage): Maximale Lebensdauer eines **gültigen** Metadaten-Sidecars, bevor er neu berechnet wird. Das ist ein Backstop — Focal-Point- und Datei-Änderungen invalidieren den Sidecar ohnehin sofort über `MEDIA_UPDATED` / `MEDIA_DELETED`. `0` deaktiviert die Alters-Prüfung (Cache gilt bis zur expliziten Invalidierung).
- **Sentinel TTL** (`sentinel_ttl_seconds`, Default 60 s): Kurze TTL für **fehlgeschlagene** Reads. Lässt sich eine Quelle nicht decodieren (kaputtes / unlesbares Asset, `getimagesize()` scheitert ohne erkennbares Format), wird das Ergebnis als `failed`-Sentinel im Sidecar markiert. Innerhalb dieser TTL wird der Fehler wiederverwendet, ohne das Asset erneut zu prüfen — das verhindert, dass ein kaputtes Asset bei jedem Request neu geprüft wird (Hammering). Nach Ablauf wird die Quelle erneut versucht, statt dauerhaft als `0×0` festzuhängen. `0` deaktiviert die Prüfung.

Ein **SVG** liest sich zwar ebenfalls als `0×0` (keine Raster-Maße), löst aber zu Format `svg` auf und gilt damit als gültiger Eintrag — er bekommt die lange Metadata-TTL, nicht die kurze Sentinel-TTL.

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

## `REX_PIC[…]` / `REX_VIDEO[…]` erscheinen als Literal-Text

Wenn nach dem Aktivieren des Addons `REX_PIC[src="…"]` oder `REX_VIDEO[src="…"]` als Plain-Text in der gerenderten Seite stehen statt durch `<picture>` / `<video>` ersetzt zu werden, ist der Article-Cache veraltet — der Slice wurde gecacht **bevor** die `REX_PIC` / `REX_VIDEO`-Vars registriert waren.

Lösung:

**Backend → System → Cache leeren**

Frische Installationen ab dem Fix-Release machen das automatisch (`install.php` ruft `rex_delete_cache()`). Beim Update von einer älteren Version, in der noch kein Auto-Cache-Clear lief, reicht das einmalige manuelle Cache-Leeren.

---

## Fatal-Error nach Install: `rex_logger::log() must be compatible with Psr\Log\AbstractLogger::log()`

Tritt auf, wenn das Addon auf REDAXO < 5.18 installiert wird. Die Mindest-REDAXO-Version ist `^5.18.0` (siehe *Anforderungen* oben) — REDAXO 5.13–5.17 bringt psr/log v1 mit, unsere ab 1.0.4-beta geshippte psr/log v3 ist mit dieser v1-Signatur LSP-inkompatibel, sobald REDAXOs Vendor-Scanner unsere `Psr\Log\AbstractLogger` indiziert (`rex_addon::enlist()` ruft `rex_autoload::addDirectory($addon . 'vendor')`).

Lösung: REDAXO Core auf ≥ 5.18.0 anheben, dann Addon neu installieren.

Wenn der Fehler auf REDAXO ≥ 5.18 trotzdem auftritt, shippt vermutlich eine andere aktive Addon eine konfliktierende psr/log-Version (z. B. v1 in einem Legacy-Addon). Die anderen Addons-`vendor/`-Verzeichnisse prüfen und ggf. updaten.

---

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

Im gerenderten `<picture>` wird dieser Wert mit vorangestelltem `auto` emittiert (`sizes="auto, …"`) — auf lazy-geladenen Bildern berechnet der Browser die tatsächliche Render-Breite selbst und wählt die srcset-Variante danach; der konfigurierte `sizes`-String bleibt der Fallback für `loading="eager"`-Bilder und Browser ohne `sizes=auto`-Support.

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
