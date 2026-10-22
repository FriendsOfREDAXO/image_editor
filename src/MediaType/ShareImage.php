<?php

namespace FriendsOfRedaxo\ImageEditor\MediaType;

use FriendsOfRedaxo\ImageEditor\Adjust;
use FriendsOfRedaxo\ImageEditor\ImageEditor;
use Intervention\Image\Format;
use Override;
use Redaxo\Core\MediaManager\AsMediaType;
use Redaxo\Core\MediaManager\MediaContext;
use Redaxo\Core\MediaManager\MediaType;

/**
 * Vorschaubild für soziale Netzwerke (og:image): 1200 × 630 (1,91:1), eigener Ausschnitt im Format „40:21“ oder um den
 * Fokuspunkt, immer JPEG – Facebook & Co. beschneiden andere Seitenverhältnisse selbst, LinkedIn zeigt mit WebP keine
 * Vorschau. Deshalb ohne Formatverhandlung (NegotiatesFormat).
 */
#[AsMediaType('image_editor_share')]
final readonly class ShareImage implements MediaType
{
    public const string RATIO = '40:21';
    public const int WIDTH = 1200;
    public const int HEIGHT = 630;

    #[Override]
    public function process(MediaContext $context): void
    {
        $ext = strtolower(pathinfo($context->filename, PATHINFO_EXTENSION));
        if (!\in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            return;
        }
        ImageEditor::useEdited($context);
        $img = $context->image;
        [$x, $y, $w, $h] = ImageEditor::cropBox($context->filename, self::RATIO, $img->width(), $img->height());
        $img->crop($w, $h, $x, $y);
        $img->resize(self::WIDTH, self::HEIGHT);   // genau 1200 × 630 (wie im Seitenkopf angegeben), kleine Bilder werden größer
        if ('' !== $fx = ImageEditor::fx($context->filename)) {
            Adjust::apply($img, $fx);
        }
        $img->fillTransparentAreas('ffffff');   // Transparenz auf Weiß (JPEG)
        $context->response->setFormat(Format::JPEG)->setQuality(85);
    }
}
