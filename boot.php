<?php

/**
 * image_editor für REDAXO 5: Zuschnitte je Bildformat (aus KLXM Studio, MIT).
 * Bearbeiten macht MediaPlace, den Fokuspunkt focuspoint – dieses AddOn ergänzt die Ausschnitte je Format.
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\ImageEditor\ImageEditor;

rex_media_manager::addEffect(rex_effect_image_editor::class);

// Breiten „typ__breite“ (z. B. image_editor_16x9__640): Effekte des Grundtyps mit dieser Breite. LATE, weil
// media_srcset jede „typ__breite“-Anfrage auf die Effekte des Grundtyps setzt – für image_editor_* entscheidet dieses AddOn.
rex_extension::register('MEDIA_MANAGER_FILTERSET', static function (rex_extension_point $ep) {
    return ImageEditor::filterset((string) $ep->getParam('rex_media_type')) ?? $ep->getSubject();
}, rex_extension::LATE);

// Original geändert (z. B. in MediaPlace zugeschnitten, andere Maße) oder gelöscht: Ausschnitte passen nicht mehr
rex_extension::register('MEDIA_UPDATED', static fn (rex_extension_point $ep) => ImageEditor::check((string) $ep->getParam('filename')));
rex_extension::register('MEDIA_DELETED', static fn (rex_extension_point $ep) => ImageEditor::forget((string) $ep->getParam('filename')));

// MediaPlace: Feldtyp „Zuschnitte je Format“ für die Detailansicht (unter MediaPlace → Metainfo Felder anlegen)
rex_extension::register('MEDIAPLACE_WIDGET_TYPES', static function (rex_extension_point $ep): array {
    $types = $ep->getSubject();
    $types['image_editor_crops'] = [
        'label' => 'Zuschnitte je Format (image_editor)',
        'class' => FriendsOfRedaxo\ImageEditor\MediaplaceWidget::class,
        'fragment' => 'image_editor/mediaplace_crops.php',
    ];

    return $types;
});

if (rex::isBackend() && rex::getUser()) {
    // media_negotiator nachträglich installiert oder entfernt: Typen angleichen (AVIF/WebP je nach Browser bzw. WebP)
    if ($this->getConfig('negotiator') !== rex_addon::get('media_negotiator')->isAvailable()) {
        ImageEditor::syncTypes();
    }
    // MediaPlace: Feld „Zuschnitte je Format“ einmalig anlegen
    ImageEditor::ensureMediaplaceField();
    // Skript überall im Backend: MediaPlace öffnet seine Detailansicht auf jeder Seite
    rex_view::addCssFile($this->getAssetsUrl('image-editor.css'));
    rex_view::addJsFile($this->getAssetsUrl('image-editor.js'));

    // Klassischer Medienpool: Box unter der Vorschau des Medienpools
    rex_extension::register('MEDIA_DETAIL_SIDEBAR', static function (rex_extension_point $ep): string {
        $box = ImageEditor::box((string) $ep->getParam('filename'));

        return (string) $ep->getSubject() . ('' !== $box ? '<section class="panel panel-default image-editor-panel"><header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-crop"></i> Zuschnitte je Format</div></header><div class="panel-body">' . $box . '</div></section>' : '');
    });
}
