# image_editor – Bilder bearbeiten für REDAXO 6

Zerstörungsfreie Bildbearbeitung für den Medienpool (aus KLXM Studio, MIT): **Zuschneiden, Drehen, Spiegeln, Ausrichten,
Entzerren**, dazu **Anpassen** (Effekte, Sättigung, Helligkeit, Kontrast, Schärfe) und **Fokuspunkt/Einpassen**.
Das Original im Medienpool bleibt unverändert – gespeichert werden nur die Schritte.

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

MIT. Bildeditor aus KLXM Studio (MIT).
