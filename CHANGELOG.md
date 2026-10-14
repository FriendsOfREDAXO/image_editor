# Changelog

## 1.0.0-beta3 – 2026-10-14

Schlank: Was MediaPlace und focuspoint schon können, ist raus.
- Entfernt:
  - Bildbearbeitung (Zuschneiden, Drehen, Spiegeln, Ausrichten, Entzerren) und „Anpassen“ → MediaPlace,
  - der eigene Fokuspunkt → focuspoint (`med_focuspoint`),
  - „Rahmen“,
  - die Typen `image_editor_base` und `image_editor_edit` (werden beim Update gelöscht).
- Neu: Feldtyp „Zuschnitte je Format“ für die Detailansicht von MediaPlace (wird bei installiertem MediaPlace einmalig
  angelegt).
- Ändert sich das Original (andere Maße), werden die Ausschnitte zurückgesetzt.
- Skript 8 KB statt 42 KB.

## 1.0.0-beta2 – 2026-10-14

Ideen aus media_srcset übernommen:
- Breiten ohne eigene Stufe werden aufgerundet, statt die volle Größe zu liefern. Das `srcset` reicht bis zur Breite der Quelle.
- `img()`: Alt-Text-Regel wie media_srcset (kein Titel; `role="presentation"`), Option `priority` für das wichtigste Bild der Seite.
  Achtung: Den Alt-Text übergibt man jetzt über `['alt' => …]` statt als vierten Parameter.
- Neu `picture()` für ein anderes Format je Bildschirmbreite.
- focuspoint: Fokus wird in `med_focuspoint` mitgeschrieben bzw. von dort übernommen.
- Neuer Typ `image_editor_edit` als Vorverarbeitung, z. B. in Sets von media_srcset (`chain`).
- Läuft neben media_srcset: Anfragen `image_editor_*__breite` entscheidet image_editor selbst.

## 1.0.0-beta1 – 2026-10-14

Erste Fassung für REDAXO 5, übertragen aus image_editor für REDAXO 6.

- Bildeditor im Medienpool: Zuschneiden, Drehen, Spiegeln, Ausrichten, Entzerren (zerstörungsfrei).
- Fokuspunkt per Klick ins Bild.
- Zuschnitte je Format mit eigenem Ausschnitt.
- Anpassen und Darstellung im Rahmen.
- Media-Manager-Typen `image_editor*` mit Effekt „Bildeditor“; Breiten als `typ__breite`.
- Zusammenarbeit mit media_negotiator (AVIF/WebP je Browser, Cache je Format), sonst WebP.
- `ImageEditor::img()`, `::url()`, `::srcset()` für Module und Templates.
