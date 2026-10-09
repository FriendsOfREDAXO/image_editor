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

    /** Adresse in einer Breite (Media-Manager-Typ image_editor_{w}; 0 = volle Größe) */
    public static function url(string $file, int $width = 1280): string
    {
        $type = 'image_editor';
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

    /** srcset über alle Breiten (bis zur Breite der bearbeiteten Fassung bzw. des Originals) */
    public static function srcset(string $file): string
    {
        [$w] = self::size($file);
        $out = [];
        foreach (self::WIDTHS as $width) {
            if ($w > 0 && $width > $w * 1.15 && $out) {
                break;
            }
            $out[] = self::url($file, $width) . ' ' . min($width, $w ?: $width) . 'w';
        }

        return implode(', ', $out);
    }

    /** @return array{int, int} Breite und Höhe der Ausgabe (bearbeitet oder Original) */
    public static function size(string $file): array
    {
        $media = Media::get($file);
        if (!$media) {
            return [0, 0];
        }
        $ops = self::ops($file);
        if ([] === $ops || !$media->width || !$media->height) {
            return [(int) $media->width, (int) $media->height];
        }

        return ImageEdit::outputSize($media->width, $media->height, $ops);
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
            $stored = json_encode(['ops' => $clean, 'orig' => ['w' => $ow, 'h' => $oh], 'at' => time()], JSON_UNESCAPED_SLASHES);
        }

        Sql::factory()->setQuery('UPDATE rex_media SET ' . self::COLUMN . ' = ? WHERE filename = ?', [$stored, $file]);
        MediaManager::deleteCache($file);
        Media::clearInstances($file);

        return self::info($file);
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
            'original' => $media->getUrl(),
            'width' => $w,
            'height' => $h,
            'original_size' => ['w' => (int) $media->width, 'h' => (int) $media->height],
            'edit' => [] !== $ops ? $ops : null,
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
