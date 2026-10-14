# image_editor – Bilder bearbeiten für REDAXO 6

Zerstörungsfreie Bildbearbeitung für den Medienpool (aus KLXM Studio, MIT): **Zuschneiden, Drehen, Spiegeln, Ausrichten,
Entzerren**, dazu **Anpassen** (Effekte, Sättigung, Helligkeit, Kontrast, Schärfe) und **Fokuspunkt/Einpassen**.
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

- **Medienpool:** in der Seitenleiste eines Bildes „Bearbeiten“ bzw. „Anpassen“.
- **fluid:** an jedem Bild auf der Website und in Bildfeldern der Blöcke.

## Für Entwickler

- Speicher: Spalte `rex_media.image_editor` (JSON: `ops`, `orig`, `fx`, `focus`, `fit`, `at`); bearbeitete Fassung
  unter `data/addons/image_editor/master/`.
- Ausgabe über den Media Manager: Typen `image_editor` (volle Größe) und `image_editor_320 … _2560` (AVIF/WebP je nach
  Browser), `image_editor_base` (ohne Anpassung, für die Vorschau).
- `ImageEditor::url($datei, $breite)`, `::srcset($datei)` und `::imgStyle($datei)` (object-position/-fit für den
  Fokuspunkt). Eigene Media-Manager-Typen: `ImageEditor::useEdited($context)` am Anfang von `process()`.
- API: `index.php?rex-api-call=image_editor` (`action` = `info`, `save`, `adjust`) – Rechte des Medienpools.

## Lizenz

MIT – siehe [LICENSE](LICENSE). Bildeditor aus [KLXM Studio](https://github.com/KLXM-Opensource/studio) (MIT).
