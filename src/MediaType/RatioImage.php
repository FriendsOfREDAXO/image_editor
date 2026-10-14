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
 * Bild in einem festen Format (16:9, 4:3 …) und einer Breite: Ausschnitt, den die Redaktion für dieses Format gewählt hat
 * (ImageEditor::saveCrop) – sonst automatisch um den Fokuspunkt. Grundlage ist die bearbeitete Fassung; danach Anpassung.
 * Name: image_editor_{a}x{b}_{breite}, z. B. image_editor_16x9_960.
 */
#[AsMediaType('image_editor_16x9_320', ratio: '16:9', maxWidth: 320)]
#[AsMediaType('image_editor_16x9_640', ratio: '16:9', maxWidth: 640)]
#[AsMediaType('image_editor_16x9_960', ratio: '16:9', maxWidth: 960)]
#[AsMediaType('image_editor_16x9_1280', ratio: '16:9', maxWidth: 1280)]
#[AsMediaType('image_editor_16x9_1920', ratio: '16:9', maxWidth: 1920)]
#[AsMediaType('image_editor_16x9_2560', ratio: '16:9', maxWidth: 2560)]
#[AsMediaType('image_editor_16x10_320', ratio: '16:10', maxWidth: 320)]
#[AsMediaType('image_editor_16x10_640', ratio: '16:10', maxWidth: 640)]
#[AsMediaType('image_editor_16x10_960', ratio: '16:10', maxWidth: 960)]
#[AsMediaType('image_editor_16x10_1280', ratio: '16:10', maxWidth: 1280)]
#[AsMediaType('image_editor_16x10_1920', ratio: '16:10', maxWidth: 1920)]
#[AsMediaType('image_editor_16x10_2560', ratio: '16:10', maxWidth: 2560)]
#[AsMediaType('image_editor_21x9_320', ratio: '21:9', maxWidth: 320)]
#[AsMediaType('image_editor_21x9_640', ratio: '21:9', maxWidth: 640)]
#[AsMediaType('image_editor_21x9_960', ratio: '21:9', maxWidth: 960)]
#[AsMediaType('image_editor_21x9_1280', ratio: '21:9', maxWidth: 1280)]
#[AsMediaType('image_editor_21x9_1920', ratio: '21:9', maxWidth: 1920)]
#[AsMediaType('image_editor_21x9_2560', ratio: '21:9', maxWidth: 2560)]
#[AsMediaType('image_editor_3x2_320', ratio: '3:2', maxWidth: 320)]
#[AsMediaType('image_editor_3x2_640', ratio: '3:2', maxWidth: 640)]
#[AsMediaType('image_editor_3x2_960', ratio: '3:2', maxWidth: 960)]
#[AsMediaType('image_editor_3x2_1280', ratio: '3:2', maxWidth: 1280)]
#[AsMediaType('image_editor_3x2_1920', ratio: '3:2', maxWidth: 1920)]
#[AsMediaType('image_editor_3x2_2560', ratio: '3:2', maxWidth: 2560)]
#[AsMediaType('image_editor_4x3_320', ratio: '4:3', maxWidth: 320)]
#[AsMediaType('image_editor_4x3_640', ratio: '4:3', maxWidth: 640)]
#[AsMediaType('image_editor_4x3_960', ratio: '4:3', maxWidth: 960)]
#[AsMediaType('image_editor_4x3_1280', ratio: '4:3', maxWidth: 1280)]
#[AsMediaType('image_editor_4x3_1920', ratio: '4:3', maxWidth: 1920)]
#[AsMediaType('image_editor_4x3_2560', ratio: '4:3', maxWidth: 2560)]
#[AsMediaType('image_editor_1x1_320', ratio: '1:1', maxWidth: 320)]
#[AsMediaType('image_editor_1x1_640', ratio: '1:1', maxWidth: 640)]
#[AsMediaType('image_editor_1x1_960', ratio: '1:1', maxWidth: 960)]
#[AsMediaType('image_editor_1x1_1280', ratio: '1:1', maxWidth: 1280)]
#[AsMediaType('image_editor_1x1_1920', ratio: '1:1', maxWidth: 1920)]
#[AsMediaType('image_editor_1x1_2560', ratio: '1:1', maxWidth: 2560)]
#[AsMediaType('image_editor_4x5_320', ratio: '4:5', maxWidth: 320)]
#[AsMediaType('image_editor_4x5_640', ratio: '4:5', maxWidth: 640)]
#[AsMediaType('image_editor_4x5_960', ratio: '4:5', maxWidth: 960)]
#[AsMediaType('image_editor_4x5_1280', ratio: '4:5', maxWidth: 1280)]
#[AsMediaType('image_editor_4x5_1920', ratio: '4:5', maxWidth: 1920)]
#[AsMediaType('image_editor_4x5_2560', ratio: '4:5', maxWidth: 2560)]
#[AsMediaType('image_editor_3x4_320', ratio: '3:4', maxWidth: 320)]
#[AsMediaType('image_editor_3x4_640', ratio: '3:4', maxWidth: 640)]
#[AsMediaType('image_editor_3x4_960', ratio: '3:4', maxWidth: 960)]
#[AsMediaType('image_editor_3x4_1280', ratio: '3:4', maxWidth: 1280)]
#[AsMediaType('image_editor_3x4_1920', ratio: '3:4', maxWidth: 1920)]
#[AsMediaType('image_editor_3x4_2560', ratio: '3:4', maxWidth: 2560)]
final readonly class RatioImage implements MediaType, NegotiatesFormat
{
    use NegotiatesImageFormats;

    public function __construct(
        private string $ratio,
        private int $maxWidth,
    ) {}

    #[Override]
    public function process(MediaContext $context): void
    {
        $ext = strtolower(pathinfo($context->filename, PATHINFO_EXTENSION));
        if (\in_array($ext, ['svg', 'gif'], true)) {
            return;   // Vektorgrafiken und Animationen unverändert (der Rahmen schneidet per CSS)
        }
        ImageEditor::useEdited($context);
        $img = $context->image;
        [$x, $y, $w, $h] = ImageEditor::cropBox($context->filename, $this->ratio, $img->width(), $img->height());
        $img->crop($w, $h, $x, $y);
        $img->scaleDown(width: $this->maxWidth);
        if ('' !== $fx = ImageEditor::fx($context->filename)) {
            Adjust::apply($img, $fx);
        }
    }
}
