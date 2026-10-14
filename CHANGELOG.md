# Changelog

## 1.0.0-beta1 – 2026-10-14

Erste Fassung für REDAXO 5, übertragen aus image_editor für REDAXO 6.

- Bildeditor im Medienpool: Zuschneiden, Drehen, Spiegeln, Ausrichten, Entzerren (zerstörungsfrei).
- Fokuspunkt per Klick ins Bild.
- Zuschnitte je Format mit eigenem Ausschnitt.
- Anpassen und Darstellung im Rahmen.
- Media-Manager-Typen `image_editor*` mit Effekt „Bildeditor“; Breiten als `typ__breite`.
- Zusammenarbeit mit media_negotiator (AVIF/WebP je Browser, Cache je Format), sonst WebP.
- `ImageEditor::img()`, `::url()`, `::srcset()` für Module und Templates.
