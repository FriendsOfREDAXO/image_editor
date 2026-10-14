<?php

/**
 * image_editor für REDAXO 5 – Bilder zerstörungsfrei bearbeiten (aus KLXM Studio, MIT).
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\ImageEditor\ImageEditor;

// Effekt für eigene Media-Manager-Typen (an erster Stelle: nutzt die bearbeitete Fassung, Format, Breite, Anpassung)
rex_media_manager::addEffect(rex_effect_image_editor::class);

// Breiten „typ__breite“ (z. B. image_editor_16x9__640): Effekte des Grundtyps mit dieser Breite
rex_extension::register('MEDIA_MANAGER_FILTERSET', static function (rex_extension_point $ep) {
    $set = $ep->getSubject();
    if (is_array($set) && [] !== $set) {
        return $set;
    }

    return ImageEditor::filterset((string) $ep->getParam('rex_media_type'), rex_media_manager::getMediaFile()) ?? $set;
});

// media_negotiator nachträglich installiert oder entfernt: Typen angleichen (AVIF/WebP je nach Browser bzw. WebP)
if (rex::isBackend() && rex::getUser() && $this->getConfig('negotiator') !== rex_addon::get('media_negotiator')->isAvailable()) {
    ImageEditor::syncTypes();
}

// Original ersetzt (andere Maße) oder gelöscht: Bearbeitung passt nicht mehr
rex_extension::register('MEDIA_UPDATED', static function (rex_extension_point $ep): void {
    $file = (string) $ep->getParam('filename');
    $orig = ImageEditor::stored($file)['orig'] ?? null;
    $media = rex_media::get($file);
    if ($orig && $media && !in_array([(int) $media->getWidth(), (int) $media->getHeight()], [[$orig['w'], $orig['h']], [$orig['h'], $orig['w']]], true)) {
        ImageEditor::forget($file);
    }
});
rex_extension::register('MEDIA_DELETED', static fn (rex_extension_point $ep) => ImageEditor::forget((string) $ep->getParam('filename')));

// Medienpool: Werkzeuge in der Seitenleiste eines Bildes
if (rex::isBackend() && rex::getUser() && 'mediapool' === rex_be_controller::getCurrentPagePart(1)) {
    rex_view::addCssFile($this->getAssetsUrl('image-editor.css'));
    rex_view::addJsFile($this->getAssetsUrl('image-editor.js'));
    rex_extension::register('MEDIA_DETAIL_SIDEBAR', static function (rex_extension_point $ep): string {
        return (string) $ep->getSubject() . ImageEditor::sidebar((string) $ep->getParam('filename'));
    });
}
