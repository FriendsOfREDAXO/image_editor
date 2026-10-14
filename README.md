# image_editor – Zuschnitte je Bildformat für REDAXO 5

> **Zweig `5.x` = REDAXO 5.** Die Version für REDAXO 6 liegt im Zweig
> [`main`](https://github.com/FriendsOfREDAXO/image_editor). Dort ist auch die Bildbearbeitung eingebaut.

In REDAXO 5 erledigen andere AddOns schon vieles:
- [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace) bearbeitet Bilder (Zuschneiden, Drehen, Spiegeln,
  Ausrichten, Perspektive, Tonwerte),
- [focuspoint](https://github.com/FriendsOfREDAXO/focuspoint) verwaltet den Fokuspunkt.

image_editor ergänzt, was dort fehlt:

- **Zuschnitte je Format** (16:9, 16:10, 21:9, 3:2, 4:3, 1:1, 4:5, 3:4): Reicht der Fokuspunkt nicht, legt die Redaktion
  je Format selbst fest, welcher Ausschnitt zu sehen ist. Ohne eigenen Ausschnitt wird um den Fokuspunkt geschnitten.
- **Media-Manager-Typen** dafür, mit [media_negotiator](https://github.com/FriendsOfREDAXO/media_negotiator)
  (AVIF/WebP je Browser).
- **Ausgabe in Modulen**: `img()` und `picture()` mit `srcset`, `width`/`height`, Alt-Text und Priorität.

## Installation

1. Den Inhalt dieses Zweigs als Ordner `redaxo/src/addons/image_editor` ablegen (ZIP:
   [5.x herunterladen](https://github.com/FriendsOfREDAXO/image_editor/archive/refs/heads/5.x.zip)).
2. Im Backend unter AddOns installieren.
3. Empfohlen: focuspoint (Fokuspunkt) und media_negotiator (AVIF/WebP). Ohne media_negotiator liefert image_editor WebP.

Voraussetzungen: REDAXO ab 5.17 mit Media Manager und Medienpool, PHP ab 8.1 mit GD.

## Bedienung

**Mit MediaPlace:**
- image_editor legt in MediaPlace das Feld **„Zuschnitte je Format“** an. Es erscheint in der Detailansicht eines Bildes.
- Dafür muss unter **MediaPlace → Einstellungen** die Option für eigene Metadaten eingeschaltet sein. Ohne sie zeigt
  MediaPlace keine eigenen Felder.

**Ohne MediaPlace:** Im klassischen Medienpool steht die Box „Zuschnitte je Format“ unter der Vorschau.

**In beiden Fällen:**
- Jedes Format hat eine kleine Vorschau. Ein Klick öffnet den Ausschnitt: Ziehen verschiebt, Mausrad oder zwei Finger
  zoomen, die Pfeiltasten verschieben ebenfalls.
- **„Automatisch (Fokuspunkt)“** nimmt den eigenen Ausschnitt wieder weg.
- Ein Punkt am Format zeigt einen eigenen Ausschnitt.
- Ändert sich das Bild selbst (z. B. in MediaPlace zugeschnitten, andere Maße), werden die Ausschnitte zurückgesetzt,
  weil sie nicht mehr passen.

## In Modulen und Templates

```php
use FriendsOfRedaxo\ImageEditor\ImageEditor;

// Bild im Format 16:9 – eigener Ausschnitt, sonst um den Fokuspunkt; srcset mit allen Breiten
echo ImageEditor::img('REX_MEDIA[1]', '(min-width: 1200px) 600px, 100vw', '16:9');

// ohne Format (ganzes Bild, alle Breiten)
echo ImageEditor::img('REX_MEDIA[1]', '100vw');

// eigene Attribute; 'alt' überschreibt den Alt-Text, 'priority' für das wichtigste Bild der Seite (LCP)
echo ImageEditor::img('REX_MEDIA[1]', '100vw', '21:9', ['class' => 'hero', 'alt' => 'Unser Team', 'priority' => true]);

// anderes Format je Bildschirmbreite (Art Direction) – jedes Format mit eigenem Ausschnitt
echo ImageEditor::picture('REX_MEDIA[1]', '16:9', ['(max-width: 639px)' => '4:5'], '100vw');

// nur Adressen
ImageEditor::url('bild.jpg', 960, '4:3');   // eine Breite
ImageEditor::srcset('bild.jpg', '4:3');     // alle Breiten
```

Was `img()` und `picture()` setzen:
- `width` und `height` (der Browser reserviert den Platz),
- `loading="lazy" decoding="async"`, mit `priority` stattdessen `loading="eager" fetchpriority="high"`,
- den **Alt-Text** nach derselben Regel wie media_srcset: übergeben > MediaPlace > `med_alt` > leer. Der Titel ist kein
  Alt-Text; ohne Alt-Text gibt es `alt="" role="presentation"`.
- Das `srcset` reicht bis zur tatsächlichen Breite der Quelle, jeder Descriptor stimmt mit der Datei überein.

**Ohne PHP**, z. B. im Texteditor oder in CSS, geht auch die Adresse direkt:

```
/index.php?rex_media_type=image_editor_16x9__960&rex_media_file=bild.jpg
/media/image_editor_16x9__960/bild.jpg   (mit YRewrite)
```

## Media Manager

| Typ | Inhalt |
|---|---|
| `image_editor` | ganzes Bild |
| `image_editor_16x9`, `_16x10`, `_21x9`, `_3x2`, `_4x3`, `_1x1`, `_4x5`, `_3x4` | im Format |

- Breiten werden als `typ__breite` angefragt, z. B. `image_editor_16x9__640` (320, 640, 960, 1280, 1920, 2560).
  Andere Breiten werden auf die nächste Stufe aufgerundet (`__700` liefert 960). Vergrößert wird nie.
- Jeder Typ hat zwei Effekte:
  - **„Ausschnitt je Format“**,
  - danach **media_negotiator**, ohne media_negotiator „Bildformat: WebP“.
- Wird media_negotiator nachträglich installiert oder entfernt, gleicht das AddOn seine Typen beim nächsten Aufruf des
  Backends an.
- Den Effekt „Ausschnitt je Format“ kannst du auch in eigenen Typen verwenden.

## Zusammenspiel mit anderen AddOns

| AddOn | Zusammenspiel |
|---|---|
| MediaPlace | Bildbearbeitung (ersetzend, Original wiederherstellbar); Feld „Zuschnitte je Format“ in der Detailansicht; Alt-Text |
| focuspoint | Fokuspunkt (`med_focuspoint`) für alle Formate ohne eigenen Ausschnitt |
| media_negotiator | AVIF/WebP je Browser, Cache je Format getrennt |
| media_srcset | Läuft daneben; `image_editor_*__breite` beantwortet image_editor selbst. Für den Alt-Text nutzt image_editor die Regel von media_srcset. |

## Für Entwickler

- Speicher: Spalte `rex_media.image_editor` (JSON: `crops` je Format als Anteile `{x, y, w, h}`, `orig` = Maße beim
  Speichern, `at` = Zeitstempel).
- API: `index.php?rex-api-call=image_editor` (POST `file`, `action` = `info` oder `crop` mit `ratio` und `rect`).
  Es gelten die Rechte des Medienpools.
- Oberfläche: `resources/js` (Dialog aus KLXM Studio), gebaut mit esbuild:
  `esbuild resources/js/image-editor.entry.js --bundle --minify --format=iife --target=es2020 --outfile=assets/image-editor.js`
- Test-Umgebung: `docker compose -f tests/compose.yml up -d`, dann `redaxo/src/addons/image_editor` → `/addon` verlinken
  (Anleitung in der Datei).

## Lizenz

MIT – siehe [LICENSE](LICENSE). Dialog aus [KLXM Studio](https://github.com/KLXM-Opensource/studio) (MIT).
