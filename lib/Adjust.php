<?php

namespace FriendsOfRedaxo\ImageEditor;

use GdImage;
use Imagick;

use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * Bild anpassen – Effekte, Sättigung, Helligkeit, Kontrast, Schärfe (Format aus KLXM Studio, Core\ImageFx, MIT).
 *
 * Speicherformat (kompakt, kanonisch): Effekt zuerst, dann s/b/c in Prozent, zuletzt „sharp{-100…100}“
 * (negativ = weichzeichnen), Standardwerte entfallen – z. B. „sepia s120 c110“, „s80 sharp40“. Leer = keine Anpassung.
 *
 * Anders als in Studio (CSS-Filter je Einbindung) wird die Anpassung in REDAXO beim Ausliefern über den Media Manager
 * berechnet (Typen image_editor_*) – sie gilt damit überall, wo das Bild über image_editor kommt.
 */
final class Adjust
{
    /** Effekte → Bezeichnung */
    public const PRESETS = [
        'gray' => 'S/W', 'sepia' => 'Sepia', 'warm' => 'Warm', 'cool' => 'Kühl',
        'muted' => 'Entsättigt', 'vivid' => 'Kräftig', 'contrast' => 'Kontrast',
    ];
    /** Regler in Prozent: [min, max], Schrittweite STEP */
    public const RANGES = ['s' => [0, 200], 'b' => [50, 150], 'c' => [50, 150]];
    public const STEP = 10;
    public const SHARP = [-100, 100];

    /** @return ?array{p: ?string, s: int, b: int, c: int, k: int} null = ungültig */
    public static function parse(?string $v): ?array
    {
        $out = ['p' => null, 's' => 100, 'b' => 100, 'c' => 100, 'k' => 0];
        $v = trim((string) $v);
        if ('' === $v) {
            return $out;
        }
        if (strlen($v) > 60) {
            return null;
        }
        $seen = [];
        foreach (preg_split('~[\s,;]+~', strtolower($v)) ?: [] as $tok) {
            if ('' === $tok) {
                continue;
            }
            if (isset(self::PRESETS[$tok])) {
                $key = 'p';
            } elseif (preg_match('~^([sbc])(\d{1,3})$~', $tok, $m)) {
                $key = $m[1];
                $n = (int) $m[2];
                [$min, $max] = self::RANGES[$key];
                if ($n < $min || $n > $max || 0 !== $n % self::STEP) {
                    return null;
                }
            } elseif (preg_match('~^sharp([+-]?\d{1,3})$~', $tok, $m)) {
                $key = 'k';
                $n = (int) $m[1];
                if ($n < self::SHARP[0] || $n > self::SHARP[1] || 0 !== $n % self::STEP) {
                    return null;
                }
            } else {
                return null;
            }
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            if ('p' === $key) {
                $out['p'] = $tok;
            } else {
                $out[$key] = $n;
            }
        }

        return $out;
    }

    /** Kanonische Schreibweise (null = ungültig). Nimmt auch {p, s, b, c, k}. */
    public static function normalize(mixed $in): ?string
    {
        if (is_array($in)) {
            $parts = [];
            $p = (string) ($in['p'] ?? '');
            if ('' !== $p) {
                $parts[] = $p;
            }
            foreach (['s', 'b', 'c'] as $k) {
                if (isset($in[$k]) && '' !== (string) $in[$k]) {
                    $parts[] = $k . (int) $in[$k];
                }
            }
            if (isset($in['k']) && '' !== (string) $in['k']) {
                $parts[] = 'sharp' . (int) $in['k'];
            }
            $in = implode(' ', $parts);
        }
        if (null !== $in && !is_string($in)) {
            return null;
        }
        $a = self::parse($in);
        if (null === $a) {
            return null;
        }
        $parts = null !== $a['p'] ? [$a['p']] : [];
        foreach (['s', 'b', 'c'] as $k) {
            if (100 !== $a[$k]) {
                $parts[] = $k . $a[$k];
            }
        }
        if (0 !== $a['k']) {
            $parts[] = 'sharp' . $a['k'];
        }

        return implode(' ', $parts);
    }

    /** Lesbare Kurzbeschreibung, z. B. „Sepia · Sättigung 120 %“ */
    public static function label(?string $v): string
    {
        $a = self::parse($v);
        if (!$a) {
            return '';
        }
        $out = null !== $a['p'] ? [self::PRESETS[$a['p']]] : [];
        foreach (['s' => 'Sättigung', 'b' => 'Helligkeit', 'c' => 'Kontrast'] as $k => $l) {
            if (100 !== $a[$k]) {
                $out[] = $l . ' ' . $a[$k] . ' %';
            }
        }
        if (0 !== $a['k']) {
            $out[] = $a['k'] > 0 ? 'Geschärft' : 'Weichgezeichnet';
        }

        return implode(' · ', $out);
    }

    /** Filterkette wie im Browser (Effekt, dann Sättigung, Helligkeit, Kontrast) – gleiche Werte wie cssFilter() */
    private static function chain(array $a): array
    {
        $p = [
            'gray' => [['grayscale', 1.0]],
            'sepia' => [['sepia', 0.8]],
            'warm' => [['sepia', 0.3], ['saturate', 1.25]],
            'cool' => [['hue', 180.0], ['sepia', 0.35], ['hue', 180.0], ['brightness', 1.03]],
            'muted' => [['saturate', 0.45], ['brightness', 1.03]],
            'vivid' => [['saturate', 1.6], ['contrast', 1.08]],
            'contrast' => [['contrast', 1.35]],
        ][$a['p'] ?? ''] ?? [];
        if (100 !== $a['s']) {
            $p[] = ['saturate', $a['s'] / 100];
        }
        if (100 !== $a['b']) {
            $p[] = ['brightness', $a['b'] / 100];
        }
        if (100 !== $a['c']) {
            $p[] = ['contrast', $a['c'] / 100];
        }

        return $p;
    }

    /**
     * Farbmatrix (3×3 + Versatz, Werte 0–1) einer CSS-Filterfunktion – nach „Filter Effects Module“ des W3C.
     *
     * @return array{array<int, array<int, float>>, array<int, float>}
     */
    private static function matrix(string $fn, float $v): array
    {
        $id = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
        switch ($fn) {
            case 'grayscale':
                $i = 1 - $v;

                return [[[0.2126 + 0.7874 * $i, 0.7152 - 0.7152 * $i, 0.0722 - 0.0722 * $i], [0.2126 - 0.2126 * $i, 0.7152 + 0.2848 * $i, 0.0722 - 0.0722 * $i], [0.2126 - 0.2126 * $i, 0.7152 - 0.7152 * $i, 0.0722 + 0.9278 * $i]], [0, 0, 0]];
            case 'sepia':
                $i = 1 - $v;

                return [[[0.393 + 0.607 * $i, 0.769 - 0.769 * $i, 0.189 - 0.189 * $i], [0.349 - 0.349 * $i, 0.686 + 0.314 * $i, 0.168 - 0.168 * $i], [0.272 - 0.272 * $i, 0.534 - 0.534 * $i, 0.131 + 0.869 * $i]], [0, 0, 0]];
            case 'saturate':
                return [[[0.213 + 0.787 * $v, 0.715 - 0.715 * $v, 0.072 - 0.072 * $v], [0.213 - 0.213 * $v, 0.715 + 0.285 * $v, 0.072 - 0.072 * $v], [0.213 - 0.213 * $v, 0.715 - 0.715 * $v, 0.072 + 0.928 * $v]], [0, 0, 0]];
            case 'hue':
                $c = cos(deg2rad($v));
                $s = sin(deg2rad($v));

                return [[[0.213 + $c * 0.787 - $s * 0.213, 0.715 - $c * 0.715 - $s * 0.715, 0.072 - $c * 0.072 + $s * 0.928],
                    [0.213 - $c * 0.213 + $s * 0.143, 0.715 + $c * 0.285 + $s * 0.140, 0.072 - $c * 0.072 - $s * 0.283],
                    [0.213 - $c * 0.213 - $s * 0.787, 0.715 - $c * 0.715 + $s * 0.715, 0.072 + $c * 0.928 + $s * 0.072]], [0, 0, 0]];
            case 'brightness':
                return [[[$v, 0, 0], [0, $v, 0], [0, 0, $v]], [0, 0, 0]];
            case 'contrast':
                $o = 0.5 - 0.5 * $v;

                return [[[$v, 0, 0], [0, $v, 0], [0, 0, $v]], [$o, $o, $o]];
        }

        return [$id, [0, 0, 0]];
    }

    /** Filterkette zu einer Matrix zusammensetzen (später angewendete Filter links) */
    private static function compose(array $chain): array
    {
        $A = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
        $t = [0, 0, 0];
        foreach ($chain as [$fn, $v]) {
            [$F, $ft] = self::matrix($fn, (float) $v);
            $nA = [];
            $nt = [];
            for ($r = 0; $r < 3; ++$r) {
                for ($c = 0; $c < 3; ++$c) {
                    $nA[$r][$c] = $F[$r][0] * $A[0][$c] + $F[$r][1] * $A[1][$c] + $F[$r][2] * $A[2][$c];
                }
                $nt[$r] = $F[$r][0] * $t[0] + $F[$r][1] * $t[1] + $F[$r][2] * $t[2] + $ft[$r];
            }
            [$A, $t] = [$nA, $nt];
        }

        return [$A, $t];
    }

    /**
     * Anpassung auf ein Bild (Media Manager) anwenden – nach dem Verkleinern. Farben genau wie die CSS-Filter der
     * Vorschau (eine Farbmatrix je Bildpunkt); Schärfen per Faltung, Weichzeichnen per Gauß (GD).
     */
    public static function apply(GdImage $img, string $fx): void
    {
        $a = self::parse($fx);
        if (!$a) {
            return;
        }
        $chain = self::chain($a);
        if ($chain) {
            [$A, $t] = self::compose($chain);
            self::gdMatrix($img, $A, $t);
        }
        if ($a['k'] > 0) {
            // Schärfen wie Intervention Image (GD) sharpen(round(k / 5)) in REDAXO 6 – gleiche Wirkung in beiden Versionen
            $amount = (int) round($a['k'] / 5);
            $min = $amount >= 10 ? $amount * -0.01 : 0;
            $max = $amount * -0.025;
            $abs = ((4 * $min + 4 * $max) * -1) + 1;
            imageconvolution($img, [[$min, $max, $min], [$max, $abs, $max], [$min, $max, $min]], 1, 0);
        } elseif ($a['k'] < 0) {
            for ($i = 0, $n = max(1, (int) round(-$a['k'] / 10)); $i < $n; ++$i) {
                imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }
    }

    /** GD: Farbmatrix je Bildpunkt (Ausgabegrößen sind klein genug) */
    private static function gdMatrix(GdImage $im, array $A, array $t): void
    {
        $w = imagesx($im);
        $h = imagesy($im);
        imagealphablending($im, false);
        $o = [$t[0] * 255, $t[1] * 255, $t[2] * 255];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $c = imagecolorat($im, $x, $y);
                $r = ($c >> 16) & 255;
                $g = ($c >> 8) & 255;
                $b = $c & 255;
                $nr = (int) max(0, min(255, round($A[0][0] * $r + $A[0][1] * $g + $A[0][2] * $b + $o[0])));
                $ng = (int) max(0, min(255, round($A[1][0] * $r + $A[1][1] * $g + $A[1][2] * $b + $o[1])));
                $nb = (int) max(0, min(255, round($A[2][0] * $r + $A[2][1] * $g + $A[2][2] * $b + $o[2])));
                imagesetpixel($im, $x, $y, ($c & 0x7F000000) | ($nr << 16) | ($ng << 8) | $nb);
            }
        }
    }

    /** CSS-Filter für die Vorschau im Browser (gleiche Werte wie Studio image-fx.css) */
    public static function cssFilter(?string $v): string
    {
        $a = self::parse($v);
        if (!$a) {
            return '';
        }
        $p = ['gray' => 'grayscale(1)', 'sepia' => 'sepia(.8)', 'warm' => 'sepia(.3) saturate(1.25)', 'cool' => 'hue-rotate(180deg) sepia(.35) hue-rotate(180deg) brightness(1.03)',
            'muted' => 'saturate(.45) brightness(1.03)', 'vivid' => 'saturate(1.6) contrast(1.08)', 'contrast' => 'contrast(1.35)'][$a['p'] ?? ''] ?? '';
        $f = array_filter([$p, 100 !== $a['s'] ? 'saturate(' . ($a['s'] / 100) . ')' : '', 100 !== $a['b'] ? 'brightness(' . ($a['b'] / 100) . ')' : '',
            100 !== $a['c'] ? 'contrast(' . ($a['c'] / 100) . ')' : '', $a['k'] < 0 ? 'blur(' . (-$a['k'] * 0.04) . 'px)' : '']);

        return implode(' ', $f);
    }

    /** @internal Fokuspunkt prüfen: {x, y} in Prozent (0–100) oder null */
    public static function focus(mixed $in): ?array
    {
        if (!is_array($in) || count($in) < 2) {
            return null;
        }
        $x = (float) ($in['x'] ?? $in[0] ?? 50);
        $y = (float) ($in['y'] ?? $in[1] ?? 50);

        return ['x' => round(max(0, min(100, $x)), 1), 'y' => round(max(0, min(100, $y)), 1)];
    }

    /** @internal Einpassen: cover (füllen, Standard) | contain (ganz zeigen) | original (Rahmen im Seitenverhältnis des Bildes) */
    public static function fit(mixed $in): string
    {
        return in_array($in, ['contain', 'original'], true) ? (string) $in : 'cover';
    }
}
