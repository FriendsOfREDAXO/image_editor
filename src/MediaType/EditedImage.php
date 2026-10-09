<?php

namespace FriendsOfRedaxo\ImageEditor\MediaType;

use FriendsOfRedaxo\ImageEditor\Adjust;
use FriendsOfRedaxo\ImageEditor\ImageEditor;
use Override;
use Redaxo\Core\MediaManager\Attribute\AsMediaType;
use Redaxo\Core\MediaManager\MediaContext;
use Redaxo\Core\MediaManager\MediaType;
use Redaxo\Core\MediaManager\NegotiatesFormat;
use Redaxo\Core\MediaManager\NegotiatesImageFormats;

/**
 * Bearbeitete Fassung (sofern vorhanden) in einer Breite, AVIF/WebP nach Browser. image_editor = volle Größe.
 * Nicht bearbeitbare Dateien (SVG, GIF …) und Bilder ohne Bearbeitung kommen unverändert bzw. nur verkleinert.
 */
#[AsMediaType('image_editor', maxWidth: 0)]
#[AsMediaType('image_editor_320', maxWidth: 320)]
#[AsMediaType('image_editor_640', maxWidth: 640)]
#[AsMediaType('image_editor_960', maxWidth: 960)]
#[AsMediaType('image_editor_1280', maxWidth: 1280)]
#[AsMediaType('image_editor_1920', maxWidth: 1920)]
#[AsMediaType('image_editor_2560', maxWidth: 2560)]
#[AsMediaType('image_editor_base', maxWidth: 1600, adjust: false)]   // Vorschau im Dialog „Anpassen“: ohne Anpassung
final readonly class EditedImage implements MediaType, NegotiatesFormat
{
    use NegotiatesImageFormats;

    public function __construct(
        private int $maxWidth,
        private bool $adjust = true,
    ) {}

    #[Override]
    public function process(MediaContext $context): void
    {
        $ext = strtolower(pathinfo($context->filename, PATHINFO_EXTENSION));
        if (\in_array($ext, ['svg', 'gif'], true)) {
            return;   // unverändert ausliefern
        }
        ImageEditor::useEdited($context);
        $fx = $this->adjust ? ImageEditor::fx($context->filename) : '';
        if ($this->maxWidth > 0) {
            $context->image->scaleDown(width: $this->maxWidth);
        } elseif ('' === $fx) {
            return;   // volle Größe ohne Anpassung: Datei unverändert ausliefern lassen
        }
        // Anpassen (Effekte, Sättigung …) nach dem Verkleinern – schnell und für jede Größe gleich
        if ('' !== $fx) {
            Adjust::apply($context->image, $fx);
        }
    }
}
