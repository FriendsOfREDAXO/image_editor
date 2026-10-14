<?php

namespace FriendsOfRedaxo\ImageEditor;

use rex;
use rex_addon;
use rex_clang;
use rex_file;
use rex_media;
use rex_media_manager;
use rex_path;
use rex_sql;
use rex_sql_table;
use rex_string;
use rex_url;
use RuntimeException;

use function in_array;
use function is_array;

/**
 * Zuschnitte je Bildformat für REDAXO 5 (aus KLXM Studio, MIT).
 *
 * Bearbeiten (Zuschneiden, Drehen, Tonwerte …) macht MediaPlace, den Fokuspunkt das AddOn focuspoint (med_focuspoint).
 * Dieses AddOn ergänzt, was beide nicht können: je Format (16:9, 4:3, 1:1 …) einen eigenen Ausschnitt, die passenden
 * Media-Manager-Typen (image_editor_16x9__640 …, mit media_negotiator) und die Ausgabe in Modulen (img(), picture()).
 *
 * Speicher: Spalte rex_media.image_editor (JSON {"crops": {"16:9": {x, y, w, h}}, "orig": {w, h}, "at"}), Anteile 0–1
 * des Originals. Ändert sich das Original (andere Maße, z. B. nach dem Zuschneiden in MediaPlace), fallen die Ausschnitte weg.
 */
final class ImageEditor
{
    public const COLUMN = 'image_editor';
    /** Breiten der Typen image_editor_*__{breite} */
    public const WIDTHS = [320, 640, 960, 1280, 1920, 2560];
    /** Formate mit eigenem Ausschnitt */
    public const RATIOS = ['16:9', '16:10', '21:9', '3:2', '4:3', '1:1', '4:5', '3:4'];

    /** @return array<string, mixed> */
    public static function stored(string $file): array
    {
        $row = rex_sql::factory()->getArray('SELECT ' . self::COLUMN . ' AS e FROM ' . rex::getTable('media') . ' WHERE filename = ?', [$file]);
        $e = json_decode((string) ($row[0]['e'] ?? ''), true);

        return is_array($e) ? $e : [];
    }

    /** Hinweis, warum ein Medium keine Zuschnitte haben kann – oder null */
    public static function editable(rex_media $media): ?string
    {
        if (!str_starts_with((string) $media->getType(), 'image/')) {
            return 'Nur Bilder haben Zuschnitte je Format.';
        }
        if (in_array(strtolower(rex_file::extension($media->getFileName())), ['svg', 'gif'], true)) {
            return 'SVG-Grafiken und GIF-Animationen werden immer vollständig gezeigt.';
        }
        if (!$media->getWidth() || !$media->getHeight()) {
            return 'Die Maße des Bildes sind unbekannt.';
        }

        return null;
    }

    // ------------------------------------------------------------------ Adressen

    /**
     * Adresse in einer Breite, optional in einem Format: image_editor[_{a}x{b}][__{breite}].
     * Der Zeitstempel („buster“) ändert sich mit jedem gespeicherten Ausschnitt.
     */
    public static function url(string $file, int $width = 1280, ?string $ratio = null): string
    {
        $type = 'image_editor';
        if (null !== $ratio && in_array($ratio, self::RATIOS, true)) {
            $type .= '_' . str_replace(':', 'x', $ratio);
        }
        if ($width > 0) {
            $type .= '__' . self::step($width);
        }
        $media = rex_media::get($file);
        $at = max((int) (self::stored($file)['at'] ?? 0), (int) $media?->getUpdateDate());
        $url = rex_media_manager::getUrl($type, $file, $at, false);

        // Typen „…__breite“ stehen nicht in der Datenbank – dann hängt der Media Manager keinen buster an
        return str_contains($url, 'buster=') ? $url : $url . (str_contains($url, '?') ? '&' : '?') . 'buster=' . $at;
    }

    /** srcset bis zur Breite der Quelle bzw. des Ausschnitts; jede Angabe entspricht der tatsächlichen Dateibreite */
    public static function srcset(string $file, ?string $ratio = null): string
    {
        [$w] = self::size($file, $ratio);
        $out = [];
        foreach (self::WIDTHS as $width) {
            $out[] = self::url($file, $width, $ratio) . ' ' . min($width, $w ?: $width) . 'w';
            if ($w > 0 && $width >= $w) {
                break;
            }
        }

        return implode(', ', $out);
    }

    /** @return array{int, int} Breite und Höhe des Originals bzw. des Ausschnitts */
    public static function size(string $file, ?string $ratio = null): array
    {
        $media = rex_media::get($file);
        $W = (int) $media?->getWidth();
        $H = (int) $media?->getHeight();
        if (null !== $ratio && in_array($ratio, self::RATIOS, true) && $W > 0 && $H > 0) {
            [, , $w, $h] = self::cropBox($file, $ratio, $W, $H);

            return [$w, $h];
        }

        return [$W, $H];
    }

    private static function step(int $width): int
    {
        foreach (self::WIDTHS as $w) {
            if ($w >= $width) {
                return $w;
            }
        }

        return self::WIDTHS[count(self::WIDTHS) - 1];
    }

    // ------------------------------------------------------------------ Ausschnitte

    /** @return array<string, array{x: float, y: float, w: float, h: float}> */
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
        if (null !== ($c = self::crops($file)[$ratio] ?? null)) {
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
     * Ausschnitt für ein Format festlegen ($rect = Anteile x, y, w, h zwischen 0 und 1) oder mit null entfernen.
     *
     * @return array<string, mixed> Angaben für den Dialog (info())
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
        $crops = self::crops($file);
        unset($crops[$ratio]);
        if (is_array($rect)) {
            $f = static fn (string $k): float => round(max(0.0, min(1.0, (float) ($rect[$k] ?? 0))), 5);
            if ($f('w') <= 0 || $f('h') <= 0) {
                throw new RuntimeException('Ungültiger Ausschnitt.');
            }
            $crops[$ratio] = ['x' => $f('x'), 'y' => $f('y'), 'w' => $f('w'), 'h' => $f('h')];
        }
        self::store($file, [] !== $crops ? ['crops' => $crops, 'orig' => ['w' => (int) $media->getWidth(), 'h' => (int) $media->getHeight()]] : []);

        return self::info($file);
    }

    /** Ausschnitte verwerfen (Original gelöscht bzw. mit anderen Maßen ersetzt) */
    public static function forget(string $file): void
    {
        rex_sql::factory()->setQuery('UPDATE ' . rex::getTable('media') . ' SET ' . self::COLUMN . ' = NULL WHERE filename = ?', [$file]);
        rex_media_manager::deleteCache($file);
    }

    /** Nach einer Änderung am Original: andere Maße → die Ausschnitte passen nicht mehr */
    public static function check(string $file): void
    {
        $orig = self::stored($file)['orig'] ?? null;
        $media = rex_media::get($file);
        if (is_array($orig) && $media && [(int) $media->getWidth(), (int) $media->getHeight()] !== [(int) $orig['w'], (int) $orig['h']]) {
            self::forget($file);
        }
    }

    /** @param array<string, mixed> $data */
    private static function store(string $file, array $data): void
    {
        if ([] !== $data) {
            $data['at'] = time();
        }
        rex_sql::factory()->setQuery('UPDATE ' . rex::getTable('media') . ' SET ' . self::COLUMN . ' = ? WHERE filename = ?', [[] !== $data ? json_encode($data, JSON_UNESCAPED_SLASHES) : null, $file]);
        rex_media_manager::deleteCache($file);
        rex_media::clearInstance($file);
    }

    /**
     * Fokuspunkt in Prozent aus dem AddOn focuspoint (med_focuspoint, z. B. „35.0,60.0“), sonst Mitte.
     *
     * @return array{x: float, y: float}
     */
    public static function focus(string $file): array
    {
        if (self::hasMediaColumn('med_focuspoint') && ($media = rex_media::get($file))) {
            $v = array_map('floatval', explode(',', (string) $media->getValue('med_focuspoint')));
            if (2 === count($v)) {
                return ['x' => round(max(0, min(100, $v[0])), 1), 'y' => round(max(0, min(100, $v[1])), 1)];
            }
        }

        return ['x' => 50.0, 'y' => 50.0];
    }

    /**
     * Angaben für den Dialog „Ausschnitt je Format“ und die Vorschauen.
     *
     * @return array<string, mixed>
     */
    public static function info(string $file): array
    {
        $media = rex_media::get($file) ?? throw new RuntimeException('Bild nicht gefunden.');
        $at = max((int) (self::stored($file)['at'] ?? 0), (int) $media->getUpdateDate());

        return [
            'file' => $file,
            'display' => '' !== (string) $media->getTitle() ? (string) $media->getTitle() : $file,
            // Grundlage für den Dialog: das Original, 1280 px breit (Anteile beziehen sich auf das ganze Bild)
            'url' => self::url($file, 1280),
            'width' => (int) $media->getWidth(),
            'height' => (int) $media->getHeight(),
            'focus' => self::focus($file),
            'focuspoint' => self::hasMediaColumn('med_focuspoint'),
            'crops' => self::crops($file),
            'ratios' => self::RATIOS,
            'thumbs' => array_combine(self::RATIOS, array_map(static fn (string $r) => self::url($file, 320, $r), self::RATIOS)),
            'editable' => self::editable($media),
            'at' => $at,
        ];
    }

    // ------------------------------------------------------------------ Media Manager

    /** @return array<string, array{string, string}> Grundtypen: Name => [Format, Beschreibung] */
    public static function types(): array
    {
        $types = ['image_editor' => ['', 'Zuschnitte je Format: Original (Breite über „__breite“)']];
        foreach (self::RATIOS as $r) {
            $types['image_editor_' . str_replace(':', 'x', $r)] = [$r, 'Zuschnitte je Format: ' . $r . ' (eigener Ausschnitt oder Fokuspunkt)'];
        }

        return $types;
    }

    /**
     * Typen anlegen bzw. angleichen (Installation, Update, media_negotiator nachträglich installiert): Effekt
     * „Ausschnitt je Format“, danach media_negotiator (AVIF/WebP je nach Browser) – ohne media_negotiator WebP.
     * Typen früherer Versionen (image_editor_base, image_editor_edit) werden entfernt.
     */
    public static function syncTypes(): void
    {
        $negotiator = rex_addon::get('media_negotiator')->isAvailable();
        $sql = rex_sql::factory();
        foreach (['image_editor_base', 'image_editor_edit'] as $old) {
            self::deleteType($old);
        }
        foreach (self::types() as $name => [$ratio, $desc]) {
            $id = (int) ($sql->getArray('SELECT id FROM ' . rex::getTable('media_manager_type') . ' WHERE name = ?', [$name])[0]['id'] ?? 0);
            if (0 === $id) {
                $sql->setTable(rex::getTable('media_manager_type'))->setValues(['name' => $name, 'description' => $desc, 'status' => 1])
                    ->addGlobalCreateFields()->addGlobalUpdateFields()->insert();
                $id = (int) $sql->getLastId();
            } else {
                $sql->setTable(rex::getTable('media_manager_type'))->setWhere(['id' => $id])->setValues(['description' => $desc, 'status' => 1])->addGlobalUpdateFields()->update();
            }
            rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = ?', [$id]);
            $effects = [['image_editor', ['ratio' => $ratio, 'width' => '0']]];
            $effects[] = $negotiator ? ['negotiator', []] : ['image_format', ['convert_to' => 'webp']];
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
        rex_addon::get('image_editor')->setConfig('negotiator', $negotiator);
        rex_media_manager::deleteCache();
        rex_file::delete(rex_path::addonCache('media_manager', 'types.cache'));
    }

    public static function deleteType(string $name): void
    {
        $id = (int) (rex_sql::factory()->getArray('SELECT id FROM ' . rex::getTable('media_manager_type') . ' WHERE name = ?', [$name])[0]['id'] ?? 0);
        if ($id > 0) {
            rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = ?', [$id]);
            rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media_manager_type') . ' WHERE id = ?', [$id]);
        }
    }

    /**
     * Breiten „typ__breite“ (z. B. image_editor_16x9__640): Effekte des Grundtyps, Breite aus dem Namen (auf die nächste
     * Stufe aufgerundet). media_negotiator findet über denselben Grundtyp seinen Effekt und trennt den Cache nach Format.
     *
     * @return list<array{effect: string, params: array<string, mixed>}>|null
     */
    public static function filterset(string $type): ?array
    {
        if (1 !== preg_match('/^(image_editor(?:_\d+x\d+)?)__(\d+)$/', $type, $m)) {
            return null;
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
                $params['width'] = self::step((int) $m[2]);
            }
            $set[] = ['effect' => $effect, 'params' => $params];
        }

        return $set;
    }

    // ------------------------------------------------------------------ Backend

    /**
     * MediaPlace: Feld „Zuschnitte je Format“ einmalig anlegen (über MediaPlace selbst). Löscht ein Admin es unter
     * MediaPlace → Metainfo Felder, bleibt es gelöscht.
     */
    public static function ensureMediaplaceField(): void
    {
        $addon = rex_addon::get('image_editor');
        if ($addon->getConfig('mediaplace_field') || !rex_addon::get('mediaplace')->isAvailable() || !class_exists(\FriendsOfRedaxo\Mediaplace\MetainfoFieldGroup::class)) {
            return;
        }
        \FriendsOfRedaxo\Mediaplace\MetainfoFieldGroup::saveField('image_editor_crops', 'Zuschnitte je Format', 'image_editor_crops', [], false, true, 10);
        $addon->setConfig('mediaplace_field', true);
    }

    /** @return array{api: string} */
    public static function config(): array
    {
        return ['api' => rex_url::backendController(['page' => 'mediapool/media'] + \rex_api_image_editor::getUrlParams(), false)];
    }

    /** Box „Zuschnitte je Format“ (Medienpool-Seitenleiste und MediaPlace-Detailansicht); Inhalt baut das Skript */
    public static function box(string $file): string
    {
        $media = rex_media::get($file);
        if (!$media || null !== self::editable($media)) {
            return '';
        }
        $config = json_encode(['file' => $file] + self::config(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<div class="image-editor-box" data-image-editor="' . rex_escape((string) $config) . '"><p class="help-block" data-image-editor-state>Zuschnitte je Format werden geladen …</p></div>';
    }

    // ------------------------------------------------------------------ Ausgabe in Modulen und Templates

    /** @var array<string, bool> */
    private static array $columns = [];

    private static function hasMediaColumn(string $name): bool
    {
        return self::$columns[$name] ??= rex_sql_table::get(rex::getTable('media'))->hasColumn($name);
    }

    /**
     * Alt-Text wie media_srcset (dessen Regel, falls installiert): MediaPlace > med_alt > leer. Der Titel ist kein Alt-Text.
     *
     * @return array{alt: string, decorative: bool}
     */
    public static function alt(rex_media $media): array
    {
        if (class_exists('FriendsOfRedaxo\\MediaSrcset\\Media\\AltText')) {
            $r = \FriendsOfRedaxo\MediaSrcset\Media\AltText::resolve($media, rex_clang::getCurrentId());

            return ['alt' => (string) ($r['alt'] ?? ''), 'decorative' => (bool) ($r['decorative'] ?? false)];
        }
        if (self::hasMediaColumn('med_alt_decorative') && (bool) $media->getValue('med_alt_decorative')) {
            return ['alt' => '', 'decorative' => true];
        }

        return ['alt' => self::hasMediaColumn('med_alt') ? trim((string) $media->getValue('med_alt')) : '', 'decorative' => false];
    }

    /**
     * <img> mit srcset, optional in einem Format (eigener Ausschnitt bzw. Fokuspunkt).
     *
     *     echo ImageEditor::img('REX_MEDIA[1]', '(min-width: 1200px) 600px, 100vw', '16:9');
     *
     * $attrs: weitere Attribute; 'alt' überschreibt den Alt-Text, 'priority' => true für das wichtigste Bild der Seite.
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
            return '<img src="' . rex_escape($media->getUrl()) . '"' . rex_string::buildAttributes($attrs) . '>';
        }
        $ratio = null !== $ratio && in_array($ratio, self::RATIOS, true) ? $ratio : null;
        [$w, $h] = self::size($file, $ratio);

        return '<img src="' . rex_escape(self::url($file, 1280, $ratio)) . '" srcset="' . rex_escape(self::srcset($file, $ratio)) . '" sizes="' . rex_escape($sizes) . '"'
            . ($w && $h ? ' width="' . $w . '" height="' . $h . '"' : '') . rex_string::buildAttributes($attrs) . '>';
    }

    /**
     * <picture> mit anderem Format je Bildschirmbreite (Art Direction); jedes Format hat seinen eigenen Ausschnitt.
     *
     *     echo ImageEditor::picture('REX_MEDIA[1]', '16:9', ['(max-width: 639px)' => '4:5'], '100vw');
     *
     * @param array<string, string> $sources Media Query => Format
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
                $out .= '<source media="' . rex_escape($query) . '" srcset="' . rex_escape(self::srcset($file, $r)) . '" sizes="' . rex_escape($sizes) . '"'
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
