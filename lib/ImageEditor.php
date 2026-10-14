<?php

namespace FriendsOfRedaxo\ImageEditor;

use GdImage;
use rex;
use rex_file;
use rex_managed_media;
use rex_media;
use rex_media_manager;
use rex_path;
use rex_sql;
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
 *  - Ausgabe über den Media Manager: Typen image_editor (volle Größe), image_editor_{breite} (320 … 2560, WebP) und
 *    image_editor_{a}x{b}_{breite} (Format) – sie stehen nicht in der Typen-Liste, das AddOn liefert die Effekte
 *    (MEDIA_MANAGER_FILTERSET). In eigenen Typen: Effekt „Bildeditor (bearbeitete Fassung)“ an erster Stelle.
 *  - Andere Addons: ImageEditor::url($datei, $breite) und ::srcset($datei) liefern die Adressen (mit Zeitstempel der
 *    Bearbeitung, damit Browser nach dem Speichern die neue Fassung laden).
 */
final class ImageEditor
{
    public const COLUMN = 'image_editor';
    /** Breiten der Media-Manager-Typen image_editor_{w} */
    public const WIDTHS = [320, 640, 960, 1280, 1920, 2560];
    /** Bildformate mit eigenem Ausschnitt (Media-Manager-Typen image_editor_{a}x{b}_{w}, MediaType\RatioImage) */
    public const RATIOS = ['16:9', '16:10', '21:9', '3:2', '4:3', '1:1', '4:5', '3:4'];

    /** @return array<string, mixed> gespeicherte Angaben ({ops, orig, at}) */
    public static function stored(string $file): array
    {
        $row = rex_sql::factory()->getArray('SELECT ' . self::COLUMN . ' AS e FROM ' . rex::getTable('media') . ' WHERE filename = ?', [$file]);
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
            $path = rex_path::addonData('image_editor', 'master/' . $file . '.' . $ext);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Hinweis, warum ein Medium nicht bearbeitet werden kann – oder null */
    public static function editable(rex_media $media): ?string
    {
        return ImageEdit::editable(['mime' => (string) $media->getType(), 'original_name' => $media->getFileName()]);
    }

    /** Media-Manager-Typ: Quelle auf die bearbeitete Fassung umstellen (für eigene Typen im Projekt) */
    public static function useEdited(rex_managed_media $media): void
    {
        if ($master = self::master($media->getMediaFilename())) {
            $media->setSourcePath($master);
        }
    }

    /**
     * Adresse eines Media-Manager-Typs mit Zeitstempel der Bearbeitung. Die Typen image_editor_* gibt es nicht in der
     * Datenbank (MEDIA_MANAGER_FILTERSET), deshalb hängt der Media Manager keinen „buster“ an – das macht diese Methode.
     */
    private static function mmUrl(string $type, string $file, int $at): string
    {
        $url = rex_media_manager::getUrl($type, $file, $at, false);

        return str_contains($url, 'buster=') ? $url : $url . (str_contains($url, '?') ? '&' : '?') . 'buster=' . $at;
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
        // Breite als „typ__breite“ (wie media_srcset): Effekte des Grundtyps, Breite aus dem Namen (filterset())
        if ($width > 0) {
            $w = self::WIDTHS[0];
            foreach (self::WIDTHS as $candidate) {
                $w = $candidate;
                if ($candidate >= $width) {
                    break;
                }
            }
            $type .= '__' . $w;
        }
        $media = rex_media::get($file);
        $at = (int) (self::stored($file)['at'] ?? 0);

        return self::mmUrl($type, $file, max($at, (int) $media?->getUpdateDate()));
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
        $media = rex_media::get($file);
        if (!$media) {
            return [0, 0];
        }
        $ops = self::ops($file);
        $size = [] === $ops || !$media->getWidth() || !$media->getHeight()
            ? [(int) $media->getWidth(), (int) $media->getHeight()]
            : ImageEdit::outputSize($media->getWidth(), $media->getHeight(), $ops);
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
        $f = self::focus($file);
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
        $media = rex_media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
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
        $media = rex_media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
        if (null !== ($why = self::editable($media))) {
            throw new RuntimeException($why);
        }
        $clean = ImageEdit::normalize($ops);
        if (null === $clean) {
            throw new RuntimeException('Ungültige Bearbeitung.');
        }

        foreach (['jpg', 'png'] as $ext) {
            @unlink(rex_path::addonData('image_editor', 'master/' . $file . '.' . $ext));
        }
        // Ausschnitte je Format gelten für die bisherige Fassung – nur behalten, wenn sich an ihr nichts ändert
        $keep = array_intersect_key(self::stored($file), array_flip($clean == self::ops($file) ? ['fx', 'focus', 'fit', 'crops'] : ['fx', 'focus', 'fit']));
        $stored = null;
        if ([] !== $clean) {
            $src = rex_path::media($file);
            $img = @imagecreatefromstring((string) file_get_contents($src));
            if (!$img instanceof GdImage) {
                throw new RuntimeException('Das Bild lässt sich nicht öffnen.');
            }
            // EXIF-Ausrichtung (Fotos vom Telefon) vor den Schritten anwenden – wie beim Hochladen in Studio
            if ('image/jpeg' === $media->getType() && \function_exists('exif_read_data')) {
                $exif = @exif_read_data($src);
                $img = ImageEdit::orient($img, (int) ($exif['Orientation'] ?? 1));
            }
            $ow = imagesx($img);
            $oh = imagesy($img);
            $out = ImageEdit::apply($img, $clean);
            $png = in_array($media->getType(), ['image/png', 'image/webp'], true);
            $target = rex_path::addonData('image_editor', 'master/' . $file . '.' . ($png ? 'png' : 'jpg'));
            rex_file::put($target, '');
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
        $media = rex_media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
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
        // AddOn focuspoint (med_focuspoint): denselben Fokus mitschreiben – dann folgen auch focuspoint-Typen und
        // media_srcset-Sets dem Fokus aus dem Bildeditor
        if (self::hasMediaColumn('med_focuspoint')) {
            $f = $data['focus'] ?? ['x' => 50, 'y' => 50];
            rex_sql::factory()->setQuery('UPDATE ' . rex::getTable('media') . ' SET med_focuspoint = ? WHERE filename = ?', [sprintf('%.1F,%.1F', $f['x'], $f['y']), $file]);
            rex_media::clearInstance($file);
        }

        return self::info($file);
    }

    /** Angaben speichern (leer = Spalte leeren), Cache des Media Managers leeren */
    private static function store(string $file, array $data): void
    {
        $data = array_filter($data, static fn ($v) => null !== $v && [] !== $v && '' !== $v);
        if ($data) {
            $data['at'] = time();
        }
        rex_sql::factory()->setQuery('UPDATE ' . rex::getTable('media') . ' SET ' . self::COLUMN . ' = ? WHERE filename = ?', [$data ? json_encode($data, JSON_UNESCAPED_SLASHES) : null, $file]);
        rex_media_manager::deleteCache($file);
        rex_media::clearInstance($file);
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
        $media = rex_media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
        $ops = self::ops($file);
        [$w, $h] = self::size($file);

        return [
            'file' => $file,
            'display' => '' !== $media->getTitle() ? $media->getTitle() : $file,
            'url' => self::url($file, 1280),
            'thumb' => self::url($file, 320),
            'base' => self::mmUrl('image_editor_base', $file, max((int) (self::stored($file)['at'] ?? 0), (int) $media->getUpdateDate())),
            'original' => $media->getUrl(),
            'width' => $w,
            'height' => $h,
            'original_size' => ['w' => (int) $media->getWidth(), 'h' => (int) $media->getHeight()],
            'edit' => [] !== $ops ? $ops : null,
            'fx' => self::fx($file),
            'fx_label' => Adjust::label(self::fx($file)),
            'focus' => self::focus($file),
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
            @unlink(rex_path::addonData('image_editor', 'master/' . $file . '.' . $ext));
        }
        rex_sql::factory()->setQuery('UPDATE ' . rex::getTable('media') . ' SET ' . self::COLUMN . ' = NULL WHERE filename = ?', [$file]);
    }

    // ------------------------------------------------------------------ REDAXO 5: Media Manager, Medienpool, Module

    /** Grundtypen in der Typen-Liste des Media Managers: Name => [Format, Breite, Anpassung, Beschreibung] */
    public static function types(): array
    {
        $types = [
            'image_editor' => ['', 0, 'yes', 'Bildeditor: bearbeitete Fassung, volle Größe'],
            'image_editor_base' => ['', 1600, 'no', 'Bildeditor: Vorschau ohne Anpassung (Dialog)'],
            // nur die bearbeitete Fassung, ohne Formatumwandlung – als Vorverarbeitung für andere Typen (media_srcset: chain)
            'image_editor_edit' => ['', 0, 'yes', 'Bildeditor: nur bearbeitete Fassung (Vorverarbeitung, z. B. media_srcset „chain“)'],
        ];
        foreach (self::RATIOS as $r) {
            $types['image_editor_' . str_replace(':', 'x', $r)] = [$r, 0, 'yes', 'Bildeditor: Format ' . $r . ' (eigener Ausschnitt oder Fokuspunkt)'];
        }

        return $types;
    }

    /**
     * Typen anlegen bzw. angleichen (Installation, Update, media_negotiator nachträglich installiert): Effekt
     * „Bildeditor“, danach media_negotiator (AVIF/WebP je nach Browser) – ohne media_negotiator WebP.
     */
    public static function syncTypes(): void
    {
        $negotiator = \rex_addon::get('media_negotiator')->isAvailable();
        $sql = rex_sql::factory();
        foreach (self::types() as $name => [$ratio, $width, $adjust, $desc]) {
            $id = (int) ($sql->getArray('SELECT id FROM ' . rex::getTable('media_manager_type') . ' WHERE name = ?', [$name])[0]['id'] ?? 0);
            if (0 === $id) {
                $sql->setTable(rex::getTable('media_manager_type'))->setValues(['name' => $name, 'description' => $desc, 'status' => 1])
                    ->addGlobalCreateFields()->addGlobalUpdateFields()->insert();
                $id = (int) $sql->getLastId();
            } else {
                $sql->setTable(rex::getTable('media_manager_type'))->setWhere(['id' => $id])->setValues(['description' => $desc, 'status' => 1])->addGlobalUpdateFields()->update();
            }
            rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = ?', [$id]);
            $effects = [['image_editor', ['ratio' => $ratio, 'width' => (string) $width, 'adjust' => $adjust]]];
            if ('image_editor_edit' !== $name) {
                $effects[] = $negotiator ? ['negotiator', []] : ['image_format', ['convert_to' => 'webp']];
            }
            foreach ($effects as $prio => [$effect, $params]) {
                $p = [];
                foreach ($params as $k => $v) {
                    $p['rex_effect_' . $effect . '_' . $k] = $v;
                }
                rex_sql::factory()->setTable(rex::getTable('media_manager_type_effect'))->setValues([
                    'type_id' => $id, 'effect' => $effect, 'priority' => $prio + 1,
                    'parameters' => json_encode(['rex_effect_' . $effect => (object) $p]),
                ])->addGlobalCreateFields()->addGlobalUpdateFields()->insert();
            }
        }
        \rex_addon::get('image_editor')->setConfig('negotiator', $negotiator);
        rex_media_manager::deleteCache();
        \rex_file::delete(rex_path::addonCache('media_manager', 'types.cache'));
    }

    /**
     * Breiten „typ__breite“ (z. B. image_editor_16x9__640): Effekte des Grundtyps, Breite aus dem Namen.
     * media_negotiator findet über denselben Grundtyp seinen Effekt und trennt den Cache nach Format.
     *
     * @return list<array{effect: string, params: array<string, mixed>}>|null
     */
    public static function filterset(string $type, string $file): ?array
    {
        if (1 !== preg_match('/^(image_editor(?:_\d+x\d+|_base)?)__(\d+)$/', $type, $m)) {
            return null;
        }
        // Breiten ohne eigene Stufe auf die nächstgrößere runden (wie media_srcset) – begrenzt die Zahl der Cache-Dateien
        $width = self::WIDTHS[count(self::WIDTHS) - 1];
        foreach (self::WIDTHS as $w) {
            if ($w >= (int) $m[2]) {
                $width = $w;
                break;
            }
        }
        $rows = rex_sql::factory()->getArray(
            'SELECT e.effect, e.parameters FROM ' . rex::getTable('media_manager_type') . ' t JOIN ' . rex::getTable('media_manager_type_effect') . ' e ON e.type_id = t.id WHERE t.name = ? ORDER BY e.priority',
            [$m[1]],
        );
        if ([] === $rows) {
            return null;
        }
        $set = [];
        foreach ($rows as $row) {
            $effect = (string) $row['effect'];
            $all = json_decode((string) $row['parameters'], true);
            $params = [];
            foreach ((array) ($all['rex_effect_' . $effect] ?? []) as $k => $v) {
                $params[str_replace('rex_effect_' . $effect . '_', '', (string) $k)] = $v;
            }
            if ('image_editor' === $effect) {
                $params['width'] = $width;
            }
            $set[] = ['effect' => $effect, 'params' => $params];
        }

        return $set;
    }

    /**
     * Angaben für das Skript (window.ImageEditor): API-Adresse mit CSRF-Token, Stylesheet und Skript.
     *
     * @return array{api: string, css: string, js: string}
     */
    public static function config(): array
    {
        $addon = \rex_addon::get('image_editor');
        $v = static fn (string $f): string => (string) @filemtime($addon->getAssetsPath($f));

        return [
            'api' => \rex::isBackend()
                ? \rex_url::backendController(['page' => 'mediapool/media'] + \rex_api_image_editor::getUrlParams(), false)
                : \rex_url::frontendController(\rex_api_image_editor::getUrlParams(), false),
            'css' => $addon->getAssetsUrl('image-editor.css') . '?v=' . $v('image-editor.css'),
            'js' => $addon->getAssetsUrl('image-editor.js') . '?v=' . $v('image-editor.js'),
        ];
    }

    /** Werkzeuge in der Seitenleiste des Medienpools (wie die Mediathek in KLXM Studio) */
    public static function sidebar(string $file): string
    {
        $media = rex_media::get($file);
        if (!$media || !str_starts_with((string) $media->getType(), 'image/')) {
            return '';
        }
        $why = self::editable($media);
        $h = '<section class="panel panel-default image-editor-panel"><header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-crop"></i> Bild bearbeiten</div></header><div class="panel-body">';
        if (null !== $why) {
            return $h . '<p class="help-block">' . \rex_escape($why) . '</p></div></section>';
        }
        $config = json_encode(['file' => $file] + self::config(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return $h . '<div class="image-editor-box" data-image-editor="' . \rex_escape((string) $config) . '">'
            . '<p class="image-editor-preview"><img src="' . \rex_escape(self::url($file, 640)) . '" alt="" loading="lazy"></p>'
            . '<p class="help-block" data-image-editor-state>Wird geladen …</p></div></div></section>';
    }

    /** Fokuspunkt in Prozent: aus dem Bildeditor, sonst aus dem AddOn focuspoint (med_focuspoint), sonst Mitte */
    public static function focus(string $file): array
    {
        if ($f = Adjust::focus(self::stored($file)['focus'] ?? null)) {
            return $f;
        }
        if (self::hasMediaColumn('med_focuspoint') && ($media = rex_media::get($file))) {
            $v = array_map('floatval', explode(',', (string) $media->getValue('med_focuspoint')));
            if (2 === count($v) && ($f = Adjust::focus(['x' => $v[0], 'y' => $v[1]]))) {
                return $f;
            }
        }

        return ['x' => 50.0, 'y' => 50.0];
    }

    /** @var array<string, bool> */
    private static array $columns = [];

    private static function hasMediaColumn(string $name): bool
    {
        return self::$columns[$name] ??= \rex_sql_table::get(rex::getTable('media'))->hasColumn($name);
    }

    /**
     * Alt-Text wie media_srcset (dessen Regel, falls installiert): MediaPlace > med_alt > leer. Der Titel ist kein Alt-Text.
     *
     * @return array{alt: string, decorative: bool}
     */
    public static function alt(rex_media $media): array
    {
        if (class_exists('FriendsOfRedaxo\\MediaSrcset\\Media\\AltText')) {
            $r = \FriendsOfRedaxo\MediaSrcset\Media\AltText::resolve($media, \rex_clang::getCurrentId());

            return ['alt' => (string) ($r['alt'] ?? ''), 'decorative' => (bool) ($r['decorative'] ?? false)];
        }
        if (self::hasMediaColumn('med_alt_decorative') && (bool) $media->getValue('med_alt_decorative')) {
            return ['alt' => '', 'decorative' => true];
        }

        return ['alt' => self::hasMediaColumn('med_alt') ? trim((string) $media->getValue('med_alt')) : '', 'decorative' => false];
    }

    /**
     * <img> für Module und Templates: bearbeitete Fassung, srcset, optional in einem Format (eigener Ausschnitt bzw.
     * Fokuspunkt). Beispiel: echo ImageEditor::img('REX_MEDIA[1]', '(min-width: 1200px) 600px, 100vw', '16:9');
     *
     * $attrs: weitere Attribute; 'alt' überschreibt den Alt-Text, 'priority' => true für das wichtigste Bild der Seite
     * (loading="eager" fetchpriority="high" statt lazy).
     *
     * @param array<string, string|bool> $attrs
     */
    public static function img(string $file, string $sizes = '100vw', ?string $ratio = null, array $attrs = []): string
    {
        $media = rex_media::get($file);
        if (!$media) {
            return '';
        }
        $attrs = self::baseAttrs($media, $attrs);
        if (null !== self::editable($media)) {
            return '<img src="' . \rex_escape($media->getUrl()) . '"' . \rex_string::buildAttributes($attrs) . '>';
        }
        $ratio = null !== $ratio && in_array($ratio, self::RATIOS, true) ? $ratio : null;
        [$w, $h] = self::size($file, $ratio);
        if (null === $ratio && '' !== ($style = self::imgStyle($file))) {
            $attrs['style'] = $style;
        }

        return '<img src="' . \rex_escape(self::url($file, 1280, $ratio)) . '" srcset="' . \rex_escape(self::srcset($file, $ratio)) . '" sizes="' . \rex_escape($sizes) . '"'
            . ($w && $h ? ' width="' . $w . '" height="' . $h . '"' : '') . \rex_string::buildAttributes($attrs) . '>';
    }

    /**
     * <picture> mit anderem Format je Bildschirmbreite (Art Direction). Jedes Format hat seinen eigenen Ausschnitt.
     * Beispiel: echo ImageEditor::picture('REX_MEDIA[1]', '16:9', ['(max-width: 639px)' => '4:5'], '100vw');
     *
     * @param array<string, string> $sources Media Query => Format (Reihenfolge = Reihenfolge im Markup)
     * @param array<string, string|bool> $attrs wie img()
     */
    public static function picture(string $file, string $ratio, array $sources, string $sizes = '100vw', array $attrs = []): string
    {
        $media = rex_media::get($file);
        if (!$media || null !== self::editable($media)) {
            return self::img($file, $sizes, $ratio, $attrs);
        }
        $out = '<picture>';
        foreach ($sources as $query => $r) {
            if (in_array($r, self::RATIOS, true)) {
                [$w, $h] = self::size($file, $r);
                $out .= '<source media="' . \rex_escape($query) . '" srcset="' . \rex_escape(self::srcset($file, $r)) . '" sizes="' . \rex_escape($sizes) . '"'
                    . ($w && $h ? ' width="' . $w . '" height="' . $h . '"' : '') . '>';
            }
        }

        return $out . self::img($file, $sizes, $ratio, $attrs) . '</picture>';
    }

    /**
     * @param array<string, string|bool> $attrs
     * @return array<string, string>
     */
    private static function baseAttrs(rex_media $media, array $attrs): array
    {
        $priority = !empty($attrs['priority']);
        unset($attrs['priority']);
        if (!array_key_exists('alt', $attrs)) {
            $a = self::alt($media);
            $attrs['alt'] = $a['alt'];
            if ('' === $a['alt']) {
                $attrs['role'] = 'presentation';
            }
        }
        $attrs += $priority ? ['loading' => 'eager', 'fetchpriority' => 'high'] : ['loading' => 'lazy'];
        $attrs += ['decoding' => 'async'];

        return array_map('strval', $attrs);
    }
}
