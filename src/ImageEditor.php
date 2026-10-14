<?php

namespace FriendsOfRedaxo\ImageEditor;

use GdImage;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\Filesystem\File;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\MediaManager\MediaContext;
use Redaxo\Core\MediaManager\MediaManager;
use Redaxo\Core\MediaPool\Media;
use RuntimeException;

use function in_array;
use function is_array;
use function is_file;

/**
 * Bilder zerstörungsfrei bearbeiten: Zuschneiden, Drehen, Spiegeln, Ausrichten, Entzerren (Rechnung: ImageEdit, aus KLXM Studio).
 *
 *  - Das Original im Medienpool bleibt unverändert. Gespeichert werden nur die Schritte (Spalte rex_media.image_editor,
 *    JSON {"ops": {…}, "orig": {"w","h"}, "at": Zeitstempel}). Daraus entsteht eine bearbeitete Fassung im Datenordner des
 *    Addons (data/addons/image_editor/master/{datei}.jpg|png) – nicht öffentlich.
 *  - Ausgabe über den Media Manager: Typen image_editor (volle Größe) und image_editor_{breite} (320 … 2560, AVIF/WebP
 *    nach Browser) nutzen die bearbeitete Fassung. Eigene Typen im Projekt: ImageEditor::useEdited($context) am Anfang von
 *    process() – dann gilt die Bearbeitung auch dort.
 *  - Andere Addons: ImageEditor::url($datei, $breite) und ::srcset($datei) liefern die Adressen (mit Zeitstempel der
 *    Bearbeitung, damit Browser nach dem Speichern die neue Fassung laden).
 */
final class ImageEditor
{
    public const string COLUMN = 'image_editor';
    /** Breiten der Media-Manager-Typen image_editor_{w} */
    public const array WIDTHS = [320, 640, 960, 1280, 1920, 2560];
    /** Bildformate mit eigenem Ausschnitt (Media-Manager-Typen image_editor_{a}x{b}_{w}, MediaType\RatioImage) */
    public const array RATIOS = ['16:9', '16:10', '21:9', '3:2', '4:3', '1:1', '4:5', '3:4'];

    /** @return array<string, mixed> gespeicherte Angaben ({ops, orig, at}) */
    public static function stored(string $file): array
    {
        $row = Sql::factory()->getArray('SELECT ' . self::COLUMN . ' AS e FROM rex_media WHERE filename = ?', [$file]);
        $e = json_decode((string) ($row[0]['e'] ?? ''), true);

        return is_array($e) ? $e : [];
    }

    /** @return array<string, mixed> Schritte der Bearbeitung ([] = keine) */
    public static function ops(string $file): array
    {
        return ImageEdit::normalize(self::stored($file)['ops'] ?? []) ?? [];
    }

    public static function isEdited(string $file): bool
    {
        return [] !== self::ops($file) && null !== self::master($file);
    }

    /** Pfad der bearbeiteten Fassung oder null */
    public static function master(string $file): ?string
    {
        foreach (['jpg', 'png'] as $ext) {
            $path = Path::addonData('image_editor', 'master/' . $file . '.' . $ext);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Hinweis, warum ein Medium nicht bearbeitet werden kann – oder null */
    public static function editable(Media $media): ?string
    {
        return ImageEdit::editable(['mime' => (string) $media->type, 'original_name' => $media->fileName]);
    }

    /** Media-Manager-Typ: Quelle auf die bearbeitete Fassung umstellen (für eigene Typen im Projekt) */
    public static function useEdited(MediaContext $context): void
    {
        if ($master = self::master($context->filename)) {
            $context->sourcePath = $master;
        }
    }

    /**
     * Adresse in einer Breite (Media-Manager-Typ image_editor_{w}; 0 = volle Größe). Mit $ratio (z. B. '16:9'): Bild in
     * diesem Format – eigener Ausschnitt, sonst um den Fokuspunkt (image_editor_{a}x{b}_{w}).
     */
    public static function url(string $file, int $width = 1280, ?string $ratio = null): string
    {
        $type = 'image_editor';
        if (null !== $ratio && in_array($ratio, self::RATIOS, true)) {
            $type .= '_' . str_replace(':', 'x', $ratio);
            $width = max(1, $width);
        }
        if ($width > 0) {
            $w = self::WIDTHS[0];
            foreach (self::WIDTHS as $candidate) {
                $w = $candidate;
                if ($candidate >= $width) {
                    break;
                }
            }
            $type .= '_' . $w;
        }
        $media = Media::get($file);
        $at = (int) (self::stored($file)['at'] ?? 0);

        return MediaManager::getUrl($type, $file, max($at, $media?->updateDate ?? 0));
    }

    /** srcset über alle Breiten (bis zur Breite der bearbeiteten Fassung bzw. des Ausschnitts) */
    public static function srcset(string $file, ?string $ratio = null): string
    {
        [$w] = self::size($file, $ratio);
        $out = [];
        // Stufen bis zur Quellbreite; die nächstgrößere Stufe liefert die Quelle selbst (nie vergrößert) und steht mit der
        // tatsächlichen Breite im srcset – so ist auch die volle Auflösung erreichbar und jeder Descriptor stimmt
        foreach (self::WIDTHS as $width) {
            $out[] = self::url($file, $width, $ratio) . ' ' . min($width, $w ?: $width) . 'w';
            if ($w > 0 && $width >= $w) {
                break;
            }
        }

        return implode(', ', $out);
    }

    /** @return array{int, int} Breite und Höhe der Ausgabe (bearbeitet oder Original; mit $ratio: des Ausschnitts) */
    public static function size(string $file, ?string $ratio = null): array
    {
        $media = Media::get($file);
        if (!$media) {
            return [0, 0];
        }
        $ops = self::ops($file);
        $size = [] === $ops || !$media->width || !$media->height
            ? [(int) $media->width, (int) $media->height]
            : ImageEdit::outputSize($media->width, $media->height, $ops);
        if (null !== $ratio && in_array($ratio, self::RATIOS, true) && $size[0] > 0 && $size[1] > 0) {
            [, , $w, $h] = self::cropBox($file, $ratio, $size[0], $size[1]);

            return [$w, $h];
        }

        return $size;
    }

    /** @return array<string, array{x: float, y: float, w: float, h: float}> eigene Ausschnitte je Format (Anteile 0–1) */
    public static function crops(string $file): array
    {
        $c = self::stored($file)['crops'] ?? [];

        return is_array($c) ? array_filter($c, static fn ($r, $k) => in_array($k, self::RATIOS, true) && is_array($r), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * Ausschnitt in Pixeln für ein Format auf einem Bild der Größe $W × $H: eigener Ausschnitt (genau im Seitenverhältnis),
     * sonst so groß wie möglich um den Fokuspunkt.
     *
     * @return array{int, int, int, int} x, y, Breite, Höhe
     */
    public static function cropBox(string $file, string $ratio, int $W, int $H): array
    {
        [$a, $b] = array_map('intval', explode(':', $ratio)) + [1, 1];
        $ar = $a > 0 && $b > 0 ? $a / $b : 1;
        $c = self::crops($file)[$ratio] ?? null;
        if (null !== $c) {
            $w = max(1, (int) round($c['w'] * $W));
            $h = (int) round($w / $ar);
            if ($h > $H) {
                $h = $H;
                $w = (int) round($h * $ar);
            }
            $x = (int) round(min(max(0, $c['x']) * $W, $W - $w));
            $y = (int) round(min(max(0, $c['y']) * $H, $H - $h));

            return [max(0, $x), max(0, $y), min($w, $W), min($h, $H)];
        }
        // automatisch: größtes Rechteck im Format, Mitte möglichst auf dem Fokuspunkt
        $w = $W;
        $h = (int) round($W / $ar);
        if ($h > $H) {
            $h = $H;
            $w = (int) round($H * $ar);
        }
        $f = Adjust::focus(self::stored($file)['focus'] ?? null) ?? ['x' => 50, 'y' => 50];
        $x = (int) round(min(max(0, $f['x'] / 100 * $W - $w / 2), $W - $w));
        $y = (int) round(min(max(0, $f['y'] / 100 * $H - $h / 2), $H - $h));

        return [$x, $y, max(1, $w), max(1, $h)];
    }

    /**
     * Ausschnitt für ein Format festlegen ($rect = Anteile x, y, w, h zwischen 0 und 1 auf der bearbeiteten Fassung) oder
     * mit null entfernen (wieder automatisch um den Fokuspunkt).
     *
     * @return array<string, mixed> Angaben für den Editor (info())
     */
    public static function saveCrop(string $file, string $ratio, mixed $rect): array
    {
        $media = Media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
        if (null !== ($why = self::editable($media))) {
            throw new RuntimeException($why);
        }
        if (!in_array($ratio, self::RATIOS, true)) {
            throw new RuntimeException('Unbekanntes Bildformat.');
        }
        $data = self::stored($file);
        $crops = self::crops($file);
        unset($crops[$ratio]);
        if (is_array($rect)) {
            $f = static fn (string $k): float => round(max(0.0, min(1.0, (float) ($rect[$k] ?? 0))), 5);
            if ($f('w') <= 0 || $f('h') <= 0) {
                throw new RuntimeException('Ungültiger Ausschnitt.');
            }
            $crops[$ratio] = ['x' => $f('x'), 'y' => $f('y'), 'w' => $f('w'), 'h' => $f('h')];
        }
        unset($data['crops']);
        if ([] !== $crops) {
            $data['crops'] = $crops;
        }
        self::store($file, $data);

        return self::info($file);
    }

    /**
     * Bearbeitung speichern (leer = zurück zum Original) und die bearbeitete Fassung erzeugen.
     *
     * @return array<string, mixed> Angaben für den Editor (info())
     */
    public static function save(string $file, mixed $ops): array
    {
        $media = Media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
        if (null !== ($why = self::editable($media))) {
            throw new RuntimeException($why);
        }
        $clean = ImageEdit::normalize($ops);
        if (null === $clean) {
            throw new RuntimeException('Ungültige Bearbeitung.');
        }

        foreach (['jpg', 'png'] as $ext) {
            @unlink(Path::addonData('image_editor', 'master/' . $file . '.' . $ext));
        }
        // Ausschnitte je Format gelten für die bisherige Fassung – nur behalten, wenn sich an ihr nichts ändert
        $keep = array_intersect_key(self::stored($file), array_flip($clean == self::ops($file) ? ['fx', 'focus', 'fit', 'crops'] : ['fx', 'focus', 'fit']));
        $stored = null;
        if ([] !== $clean) {
            $src = Path::media($file);
            $img = @imagecreatefromstring((string) file_get_contents($src));
            if (!$img instanceof GdImage) {
                throw new RuntimeException('Das Bild lässt sich nicht öffnen.');
            }
            // EXIF-Ausrichtung (Fotos vom Telefon) vor den Schritten anwenden – wie beim Hochladen in Studio
            if ('image/jpeg' === $media->type && \function_exists('exif_read_data')) {
                $exif = @exif_read_data($src);
                $img = ImageEdit::orient($img, (int) ($exif['Orientation'] ?? 1));
            }
            $ow = imagesx($img);
            $oh = imagesy($img);
            $out = ImageEdit::apply($img, $clean);
            $png = in_array($media->type, ['image/png', 'image/webp'], true);
            $target = Path::addonData('image_editor', 'master/' . $file . '.' . ($png ? 'png' : 'jpg'));
            File::put($target, '');
            $ok = $png ? (imagesavealpha($out, true) && imagepng($out, $target, 6)) : imagejpeg($out, $target, 90);
            if (!$ok) {
                throw new RuntimeException('Die bearbeitete Fassung konnte nicht gespeichert werden.');
            }
            $stored = ['ops' => $clean, 'orig' => ['w' => $ow, 'h' => $oh]];
        }
        self::store($file, null !== $stored ? $stored + $keep : $keep);

        return self::info($file);
    }

    /**
     * Anpassen (Effekte, Sättigung, Helligkeit, Kontrast, Schärfe), Fokuspunkt und Einpassen speichern.
     *
     * @return array<string, mixed> Angaben für den Editor (info())
     */
    public static function saveAdjust(string $file, mixed $fx, mixed $focus, mixed $fit): array
    {
        $media = Media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
        if (null !== ($why = self::editable($media))) {
            throw new RuntimeException($why);
        }
        $clean = Adjust::normalize($fx);
        if (null === $clean) {
            throw new RuntimeException('Ungültige Anpassung.');
        }
        $data = self::stored($file);
        unset($data['fx'], $data['focus'], $data['fit']);
        if ('' !== $clean) {
            $data['fx'] = $clean;
        }
        if ($f = Adjust::focus($focus)) {
            if (50.0 !== $f['x'] || 50.0 !== $f['y']) {
                $data['focus'] = $f;
            }
        }
        if ('cover' !== ($m = Adjust::fit($fit))) {
            $data['fit'] = $m;
        }
        self::store($file, $data);

        return self::info($file);
    }

    /** Angaben speichern (leer = Spalte leeren), Cache des Media Managers leeren */
    private static function store(string $file, array $data): void
    {
        $data = array_filter($data, static fn ($v) => null !== $v && [] !== $v && '' !== $v);
        if ($data) {
            $data['at'] = time();
        }
        Sql::factory()->setQuery('UPDATE rex_media SET ' . self::COLUMN . ' = ? WHERE filename = ?', [$data ? json_encode($data, JSON_UNESCAPED_SLASHES) : null, $file]);
        MediaManager::deleteCache($file);
        Media::clearInstances($file);
    }

    /** Anpassung (kanonisch, '' = keine) */
    public static function fx(string $file): string
    {
        return Adjust::normalize(self::stored($file)['fx'] ?? '') ?? '';
    }

    /**
     * Fokuspunkt und Einpassen für die Ausgabe: style-Angaben für <img> (object-position/-fit) – leer = Standard des Kits.
     */
    public static function imgStyle(string $file): string
    {
        $d = self::stored($file);
        $css = [];
        if ($f = Adjust::focus($d['focus'] ?? null)) {
            $css[] = 'object-position:' . $f['x'] . '% ' . $f['y'] . '%';
        }
        if ('contain' === Adjust::fit($d['fit'] ?? '')) {
            $css[] = 'object-fit:contain';
        }

        return implode(';', $css);
    }

    /**
     * Angaben für den Editor-Dialog (Format wie in KLXM Studio: url/original, Maße, edit …).
     *
     * @return array<string, mixed>
     */
    public static function info(string $file): array
    {
        $media = Media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
        $ops = self::ops($file);
        [$w, $h] = self::size($file);

        return [
            'file' => $file,
            'display' => '' !== $media->title ? $media->title : $file,
            'url' => self::url($file, 1280),
            'thumb' => self::url($file, 320),
            'base' => MediaManager::getUrl('image_editor_base', $file, max((int) (self::stored($file)['at'] ?? 0), $media->updateDate)),
            'original' => $media->getUrl(),
            'width' => $w,
            'height' => $h,
            'original_size' => ['w' => (int) $media->width, 'h' => (int) $media->height],
            'edit' => [] !== $ops ? $ops : null,
            'fx' => self::fx($file),
            'fx_label' => Adjust::label(self::fx($file)),
            'focus' => Adjust::focus(self::stored($file)['focus'] ?? null) ?? ['x' => 50, 'y' => 50],
            'fit' => Adjust::fit(self::stored($file)['fit'] ?? ''),
            'crops' => self::crops($file),
            'ratios' => self::RATIOS,
            // Vorschau je Format (Medienpool: „Zuschnitte je Format“)
            'thumbs' => array_combine(self::RATIOS, array_map(static fn (string $r) => self::url($file, 320, $r), self::RATIOS)),
            'presets' => Adjust::PRESETS,
            'editable' => self::editable($media),
            'pool' => false,
        ];
    }

    /** Bearbeitung entfernen, wenn das Original im Medienpool ersetzt oder gelöscht wird */
    public static function forget(string $file): void
    {
        foreach (['jpg', 'png'] as $ext) {
            @unlink(Path::addonData('image_editor', 'master/' . $file . '.' . $ext));
        }
        Sql::factory()->setQuery('UPDATE rex_media SET ' . self::COLUMN . ' = NULL WHERE filename = ?', [$file]);
    }
}
