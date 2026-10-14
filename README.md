# image_editor – Bilder bearbeiten für REDAXO 5

Zerstörungsfreie Bildbearbeitung für den Medienpool (aus KLXM Studio, MIT).

> **Zweig `5.x` = REDAXO 5.** Die Version für REDAXO 6 liegt im Zweig
> [`main`](https://github.com/FriendsOfREDAXO/image_editor). Oberfläche und Funktionen sind gleich.

- **Zuschneiden, Drehen, Spiegeln, Ausrichten, Entzerren.** Das Original bleibt unverändert; gespeichert werden nur die
  Schritte.
- **Fokuspunkt:** ins Bild klicken. Dieser Bereich bleibt in jedem Format sichtbar.
- **Zuschnitte je Format** (16:9, 16:10, 21:9, 3:2, 4:3, 1:1, 4:5, 3:4): Reicht der Fokuspunkt nicht, legst du je Format
  selbst fest, welcher Ausschnitt zu sehen ist.
- **Anpassen:** Effekte, Sättigung, Helligkeit, Kontrast, Schärfe.
- **Darstellung im Rahmen:** füllen, einpassen oder Originalformat.
- **[media_negotiator](https://github.com/FriendsOfREDAXO/media_negotiator):** Ist es installiert, bekommt jeder
  Browser AVIF oder WebP (sonst immer WebP). Der Cache wird je Format getrennt.

## Installation

Den Inhalt dieses Zweigs als Ordner `redaxo/src/addons/image_editor` ablegen (ZIP:
[5.x herunterladen](https://github.com/FriendsOfREDAXO/image_editor/archive/refs/heads/5.x.zip)) und im Backend unter
AddOns installieren. Optional vorher [media_negotiator](https://github.com/FriendsOfREDAXO/media_negotiator) installieren.

## Voraussetzungen

REDAXO ab 5.17 mit Media Manager und Medienpool, PHP ab 8.1 mit GD (WebP; AVIF über media_negotiator).

## Bedienung

Medienpool → Bild öffnen → rechts **„Bild bearbeiten“**:
1. Vorschau anklicken: **Fokuspunkt** setzen.
2. Werkzeuge **Zuschneiden … Entzerren** öffnen den Bildeditor.
3. **Zuschnitte je Format:** Klick auf ein Format öffnet den Ausschnitt.
   - Ziehen verschiebt, Mausrad zoomt.
   - „Automatisch (Fokuspunkt)“ nimmt den eigenen Ausschnitt wieder weg.
   - Ein Punkt am Format zeigt einen eigenen Ausschnitt.
4. **Anpassen …** und **Rahmen …** für Effekte bzw. Darstellung.

Ändert sich das Bild selbst (Zuschneiden, Drehen …), werden die Ausschnitte je Format zurückgesetzt.

## In Modulen und Templates

```php
use FriendsOfRedaxo\ImageEditor\ImageEditor;

// Bild im Format 16:9 – eigener Ausschnitt der Redaktion, sonst um den Fokuspunkt; srcset mit allen Breiten
echo ImageEditor::img('REX_MEDIA[1]', '(min-width: 1200px) 600px, 100vw', '16:9');

// ohne Format (bearbeitete Fassung im eigenen Seitenverhältnis)
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

Formate: `16:9`, `16:10`, `21:9`, `3:2`, `4:3`, `1:1`, `4:5`, `3:4`.

## Media Manager

| Typ | Inhalt |
|---|---|
| `image_editor` | bearbeitete Fassung, volle Größe |
| `image_editor_16x9`, `_16x10`, `_21x9`, `_3x2`, `_4x3`, `_1x1`, `_4x5`, `_3x4` | im Format |
| `image_editor_base` | Vorschau ohne Anpassung (für den Dialog) |
| `image_editor_edit` | nur die bearbeitete Fassung, ohne Formatumwandlung – als Vorverarbeitung für andere Typen |

- Breiten werden als `typ__breite` angefragt, z. B. `image_editor_16x9__640` (320, 640, 960, 1280, 1920, 2560).
  Andere Breiten werden auf die nächste Stufe aufgerundet (`__700` liefert 960). Vergrößert wird nie.
  Diese Schreibweise kennen auch media_negotiator und media_srcset.
- Jeder Typ hat zwei Effekte:
  - **„Bildeditor“**: bearbeitete Fassung, Format, Breite, Anpassung,
  - danach **media_negotiator**, ohne media_negotiator „Bildformat: WebP“.
- Wird media_negotiator nachträglich installiert oder entfernt, gleicht das AddOn seine Typen beim nächsten Aufruf des
  Backends an.
- Den Effekt „Bildeditor“ kannst du auch in eigenen Typen verwenden. Er gehört an die erste Stelle.

## Zusammenspiel mit anderen AddOns

| AddOn | Zusammenspiel |
|---|---|
| [media_negotiator](https://github.com/FriendsOfREDAXO/media_negotiator) | AVIF/WebP je Browser, Cache je Format getrennt (sonst WebP) |
| [focuspoint](https://github.com/FriendsOfREDAXO/focuspoint) | Der Fokuspunkt aus dem Bildeditor wird in `med_focuspoint` mitgeschrieben, dann folgen auch focuspoint-Typen ihm. Ein vorhandener focuspoint-Wert gilt, solange im Bildeditor keiner gesetzt ist. |
| [media_srcset](https://github.com/FriendsOfREDAXO/media_srcset) | Die Sets folgen dem Fokuspunkt (über focuspoint). Mit `'chain' => 'image_editor_edit'` bekommt ein Set auch Zuschnitt, Drehung und Anpassung aus dem Bildeditor; eigene Ausschnitte je Format gelten nur in den Typen `image_editor_*`. Für den Alt-Text nutzt image_editor die Regel von media_srcset. |

## Für Entwickler

- Speicher: Spalte `rex_media.image_editor` (JSON: `ops`, `orig`, `fx`, `focus`, `fit`, `crops`, `at`). Die bearbeitete
  Fassung liegt unter `redaxo/data/addons/image_editor/master/`.
- API: `index.php?rex-api-call=image_editor` (POST `file`, `action` = `info`, `save`, `adjust`, `crop`). Es gelten die
  Rechte des Medienpools.
- Oberfläche: `assets/image-editor.js` und `.css`, identisch mit der Version für REDAXO 6; Quellen dort unter
  `resources/js`.
- Test-Umgebung: `docker compose -f tests/compose.yml up -d`, dann `redaxo/src/addons/image_editor` → `/addon` verlinken
  (Anleitung in der Datei).

## Lizenz

MIT – siehe [LICENSE](LICENSE). Bildeditor aus [KLXM Studio](https://github.com/KLXM-Opensource/studio) (MIT).
