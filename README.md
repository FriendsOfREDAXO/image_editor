# image_editor – Bilder bearbeiten für REDAXO 6

> Für **REDAXO 5** gibt es den Zweig [`5.x`](https://github.com/FriendsOfREDAXO/image_editor/tree/5.x) (gleiche Oberfläche, mit media_negotiator, focuspoint und media_srcset).

Zerstörungsfreie Bildbearbeitung für den Medienpool (aus KLXM Studio, MIT): **Zuschneiden, Drehen, Spiegeln, Ausrichten,
Entzerren**, dazu **Anpassen** (Effekte, Sättigung, Helligkeit, Kontrast, Schärfe), **Fokuspunkt/Einpassen** und
**Ausschnitt je Bildformat**: Für 16:9, 4:3, 1:1, 4:5 … legst du je Format selbst fest, welcher Teil des Bildes zu sehen
ist, wenn der Fokuspunkt nicht reicht.
Das Original im Medienpool bleibt unverändert – gespeichert werden nur die Schritte.

## Voraussetzungen und Installation

- REDAXO 6 (`redaxo/core` 6.x), PHP 8.5 mit GD; Imagick ist optional (Entzerren großer Bilder schneller und genauer).
- Am einfachsten zusammen mit [fluid](https://github.com/FriendsOfREDAXO/fluid): dessen `install-dev.sh` richtet
  REDAXO 6, fluid und image_editor mit einem Befehl ein.
- In ein bestehendes REDAXO-6-Projekt (solange das AddOn noch nicht auf Packagist steht, direkt von GitHub):

```bash
composer config repositories.image_editor '{"type": "vcs", "url": "https://github.com/FriendsOfREDAXO/image_editor", "no-api": true}'
composer require friendsofredaxo/image_editor:*@dev
php bin/console migrate
```

Nach Updates (`composer update`) wieder `php bin/console migrate` – das kopiert die Assets neu.

## Nutzung

- **Medienpool:** In der Seitenleiste eines Bildes (wie die Mediathek in KLXM Studio) gibt es:
  - eine Vorschau, in die du klickst, um den **Fokuspunkt** zu setzen,
  - die Werkzeuge Zuschneiden, Drehen, Spiegeln, Ausrichten und Entzerren,
  - **Zuschnitte je Format** als kleine Vorschauen; ein Klick öffnet den Ausschnitt dieses Formats, ein Punkt
    zeigt einen eigenen Ausschnitt,
  - **Anpassen** und **Darstellung im Rahmen**.
- **fluid:** an jedem Bild auf der Website und in Bildfeldern der Blöcke. Steht ein Bild in einem festen Format,
  erscheint zusätzlich **„Ausschnitt“**: Der Dialog öffnet mit diesem Format, die anderen Formate lassen sich daneben
  wählen.

**Ausschnitt je Format:**
- Ziehen verschiebt, Mausrad oder Zwei-Finger-Geste zoomt, die Pfeiltasten verschieben ebenfalls.
- „Automatisch (Fokuspunkt)“ bzw. ein Doppelklick nimmt den eigenen Ausschnitt wieder weg; dann schneidet das Bild
  wieder um den Fokuspunkt.
- Ein Punkt am Format zeigt, dass es einen eigenen Ausschnitt hat.
- Ändert sich das Bild selbst (Zuschneiden, Drehen …), werden die Ausschnitte zurückgesetzt, weil sie nicht mehr passen.

## In Templates und Modulen

```php
use FriendsOfRedaxo\ImageEditor\ImageEditor;

// Bild im Format 16:9 – eigener Ausschnitt, sonst um den Fokuspunkt; srcset, width/height, Alt-Text aus dem Medienpool
echo ImageEditor::img('bild.jpg', '(min-width: 1200px) 600px, 100vw', '16:9');

// eigene Attribute; 'alt' überschreibt den Alt-Text, 'priority' für das wichtigste Bild der Seite (LCP)
echo ImageEditor::img('bild.jpg', '100vw', '21:9', ['class' => 'hero', 'priority' => true]);

// anderes Format je Bildschirmbreite – jedes Format mit eigenem Ausschnitt
echo ImageEditor::picture('bild.jpg', '16:9', ['(max-width: 639px)' => '4:5'], '100vw');
```

- AVIF/WebP wählt der Media Manager des Cores je nach Browser.
- Der Alt-Text kommt aus dem Alt-Feld des Medienpools (je Sprache). Der Titel ist kein Alt-Text; ohne Alt-Text gibt es
  `alt="" role="presentation"`.
- Ohne PHP geht auch die Adresse direkt, z. B. `index.php?rex_media_type=image_editor_16x9_960&rex_media_file=bild.jpg`.

## Für Entwickler

- Speicher: Spalte `rex_media.image_editor` (JSON: `ops`, `orig`, `fx`, `focus`, `fit`, `crops`, `at`); bearbeitete Fassung
  unter `data/addons/image_editor/master/`.
- Ausgabe über den Media Manager: Typen `image_editor` (volle Größe) und `image_editor_320 … _2560` (AVIF/WebP je nach
  Browser), `image_editor_base` (ohne Anpassung, für die Vorschau).
- Je Format: `image_editor_{a}x{b}_{breite}`, z. B. `image_editor_16x9_960` (Formate in `ImageEditor::RATIOS`: 16:9,
  16:10, 21:9, 3:2, 4:3, 1:1, 4:5, 3:4). Ausgeliefert wird der eigene Ausschnitt (`crops`), sonst das größte Rechteck um
  den Fokuspunkt. Das Bild hat danach genau das Format, im Rahmen schneidet CSS nichts mehr ab.
- `ImageEditor::url($datei, $breite, $format)`, `::srcset($datei, $format)`, `::size($datei, $format)` (Format optional,
  z. B. `'16:9'`) und `::imgStyle($datei)` (object-position/-fit für den
  Fokuspunkt). Eigene Media-Manager-Typen: `ImageEditor::useEdited($context)` am Anfang von `process()`.
- API: `index.php?rex-api-call=image_editor` (`action` = `info`, `save`, `adjust`, `crop` mit `ratio` und `rect` als
  Anteile `{x, y, w, h}` oder `null`) – Rechte des Medienpools.
- JS: `ImageEditor.crop({ file, api, ratio })` öffnet den Dialog „Ausschnitt je Format“.
- Quellen der Oberfläche in `resources/js`, gebaut mit esbuild:
  `esbuild resources/js/image-editor.entry.js --bundle --minify --format=iife --target=es2020 --outfile=assets/image-editor.js`

## Lizenz

MIT – siehe [LICENSE](LICENSE). Bildeditor aus [KLXM Studio](https://github.com/KLXM-Opensource/studio) (MIT).
