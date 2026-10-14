<?php

use FriendsOfRedaxo\ImageEditor\ImageEditor;

/**
 * Media-Manager-Effekt „Ausschnitt je Format“: schneidet auf ein Format (eigener Ausschnitt der Redaktion, sonst um den
 * Fokuspunkt aus focuspoint) und verkleinert auf eine Breite. Danach z. B. media_negotiator oder „Bildformat“.
 */
class rex_effect_image_editor extends rex_effect_abstract
{
    public function execute()
    {
        $file = $this->media->getMediaFilename();
        if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['svg', 'gif'], true)) {
            return;   // Vektorgrafiken und Animationen unverändert
        }
        $ratio = (string) ($this->params['ratio'] ?? '');
        $ratio = in_array($ratio, ImageEditor::RATIOS, true) ? $ratio : '';
        $width = max(0, (int) ($this->params['width'] ?? 0));
        if ('' === $ratio && 0 === $width) {
            return;
        }
        $this->media->asImage();
        $img = $this->media->getImage();
        $W = imagesx($img);
        $H = imagesy($img);
        if ('' !== $ratio) {
            [$x, $y, $w, $h] = ImageEditor::cropBox($file, $ratio, $W, $H);
            $img = $this->copy($img, $x, $y, $w, $h, $w, $h);
            [$W, $H] = [$w, $h];
        }
        if ($width > 0 && $W > $width) {
            $img = $this->copy($img, 0, 0, $W, $H, $width, max(1, (int) round($H * $width / $W)));
        }
        $this->media->setImage($img);
        $this->media->refreshImageDimensions();
    }

    /** Ausschnitt kopieren und skalieren (Transparenz bleibt erhalten) */
    private function copy(GdImage $src, int $x, int $y, int $w, int $h, int $tw, int $th): GdImage
    {
        $dst = imagecreatetruecolor($tw, $th);
        $this->keepTransparent($dst);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $tw, $th, $w, $h);

        return $dst;
    }

    public function getName()
    {
        return 'Ausschnitt je Format (image_editor)';
    }

    public function getParams()
    {
        return [
            [
                'label' => 'Format',
                'name' => 'ratio',
                'type' => 'select',
                'options' => ['', ...ImageEditor::RATIOS],
                'default' => '',
                'notice' => 'Eigener Ausschnitt der Redaktion, sonst um den Fokuspunkt. Leer = Seitenverhältnis des Bildes.',
            ],
            [
                'label' => 'Breite (px)',
                'name' => 'width',
                'type' => 'int',
                'notice' => 'Höchstbreite, 0 = volle Größe (über „typ__breite“ wird sie je Anfrage gesetzt)',
            ],
        ];
    }
}
