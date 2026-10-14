<?php

use FriendsOfRedaxo\ImageEditor\Adjust;
use FriendsOfRedaxo\ImageEditor\ImageEditor;

/**
 * Media-Manager-Effekt „Bildeditor“: nimmt die bearbeitete Fassung (Zuschneiden, Drehen …), schneidet auf ein Format
 * (eigener Ausschnitt oder um den Fokuspunkt), verkleinert auf eine Breite und wendet die Anpassung an.
 * In eigenen Typen an erster Stelle einsetzen; danach z. B. „Bildformat“ (WebP).
 */
class rex_effect_image_editor extends rex_effect_abstract
{
    public function execute()
    {
        $file = $this->media->getMediaFilename();
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'gif'], true)) {
            return;   // Vektorgrafiken und Animationen unverändert
        }
        $ratio = (string) ($this->params['ratio'] ?? '');
        $ratio = in_array($ratio, ImageEditor::RATIOS, true) ? $ratio : '';
        $width = max(0, (int) ($this->params['width'] ?? 0));
        $fx = 'no' !== ($this->params['adjust'] ?? 'yes') ? ImageEditor::fx($file) : '';
        $edited = null !== ImageEditor::master($file);
        if (!$edited && '' === $ratio && 0 === $width && '' === $fx) {
            return;   // nichts zu tun: Original unverändert ausliefern
        }

        ImageEditor::useEdited($this->media);
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
            $nh = max(1, (int) round($H * $width / $W));
            $img = $this->copy($img, 0, 0, $W, $H, $width, $nh);
        }
        if ('' !== $fx) {
            Adjust::apply($img, $fx);
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
        return 'Bildeditor (bearbeitete Fassung, Format, Breite)';
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
                'notice' => 'Leer = Seitenverhältnis des Bildes. Sonst eigener Ausschnitt der Redaktion bzw. um den Fokuspunkt.',
            ],
            [
                'label' => 'Breite (px)',
                'name' => 'width',
                'type' => 'int',
                'notice' => 'Höchstbreite, 0 = volle Größe',
            ],
            [
                'label' => 'Anpassung anwenden',
                'name' => 'adjust',
                'type' => 'select',
                'options' => ['yes', 'no'],
                'default' => 'yes',
                'notice' => 'Effekte, Sättigung, Helligkeit, Kontrast, Schärfe aus dem Bildeditor',
            ],
        ];
    }
}
