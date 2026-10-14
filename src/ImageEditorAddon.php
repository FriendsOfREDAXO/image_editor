<?php

namespace FriendsOfRedaxo\ImageEditor;

use Override;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Column;
use Redaxo\Core\Database\Table;
use Redaxo\Core\ExtensionPoint\Extension;
use Redaxo\Core\ExtensionPoint\ExtensionPoint;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\MediaPool\Media;
use Redaxo\Core\View\Asset;

use function Redaxo\Core\View\escape;

final class ImageEditorAddon extends Addon
{
    #[Override]
    public function install(): void
    {
        // Schritte der Bearbeitung je Medium (JSON) – das Original bleibt unverändert
        Table::get('rex_media')->ensureColumn(new Column(ImageEditor::COLUMN, 'text', true))->alter();
    }

    #[Override]
    public function boot(): void
    {
        // Original ersetzt (andere Maße) oder gelöscht: Bearbeitung passt nicht mehr
        Extension::register('MEDIA_UPDATED', static function (ExtensionPoint $ep): void {
            $file = (string) $ep->getParam('filename');
            $orig = ImageEditor::stored($file)['orig'] ?? null;
            $media = Media::get($file);
            if ($orig && $media && !\in_array([$media->width, $media->height], [[$orig['w'], $orig['h']], [$orig['h'], $orig['w']]], true)) {
                ImageEditor::forget($file);
            }
        });
        Extension::register('MEDIA_DELETED', static fn (ExtensionPoint $ep) => ImageEditor::forget((string) $ep->getParam('filename')));

        if (Core::isBackend() && str_starts_with(Request::get('page', 'string'), 'mediapool')) {
            self::assets();
            Extension::register('MEDIA_DETAIL_SIDEBAR', static function (ExtensionPoint $ep): string {
                return (string) $ep->subject . self::sidebar((string) $ep->getParam('filename'));
            });
        }
    }

    /** CSS und Skript des Editors einbinden (Backend) */
    public static function assets(): void
    {
        $addon = self::instance();
        Asset::addCssFile($addon->getAssetsUrl('image-editor.css'));
        Asset::addJsFile($addon->getAssetsUrl('image-editor.js'));
    }

    /**
     * Angaben für das Skript (window.ImageEditor): API-Adresse mit CSRF-Token, Stylesheet.
     * Andere Addons (z. B. fluid) geben das per config() an ihr Skript weiter.
     *
     * @return array{api: string, css: string, js: string}
     */
    public static function config(): array
    {
        $addon = self::instance();
        return [
            // im selben Bereich aufrufen, in dem das Token entsteht: Backend (Seite Medienpool) bzw. Website (Api ist „published“)
            'api' => Core::isBackend()
                ? Url::backendController(['page' => 'mediapool/media'] + Api::getUrlParams())
                : Url::frontendController(Api::getUrlParams()),
            'css' => $addon->getAssetsUrl('image-editor.css') . '?v=' . self::version('image-editor.css'),
            'js' => $addon->getAssetsUrl('image-editor.js') . '?v=' . self::version('image-editor.js'),
        ];
    }

    /** Werkzeugleiste in der Detailansicht des Medienpools */
    private static function sidebar(string $file): string
    {
        $media = Media::get($file);
        if (!$media || !str_starts_with((string) $media->type, 'image/')) {
            return '';
        }
        $why = ImageEditor::editable($media);
        $info = $why ? null : ImageEditor::info($file);
        $config = json_encode(['file' => $file] + self::config(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $h = '<section class="panel panel-default image-editor-panel"><header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-crop-simple"></i> Bild bearbeiten</div></header><div class="panel-body">';
        if ($why) {
            return $h . '<p class="help-block">' . escape($why) . '</p></div></section>';
        }
        $h .= '<div class="image-editor-box" data-image-editor="' . escape($config) . '">'
            . '<p class="image-editor-preview"><img src="' . escape($info['url']) . '" alt="" loading="lazy"></p>'
            . '<p class="help-block" data-image-editor-state>Wird geladen …</p>'
            . '</div>';

        return $h . '</div></section>';
    }

    private static function version(string $file): string
    {
        $path = self::instance()->getPath('assets/' . $file);

        return is_file($path) ? substr(md5((string) filemtime($path)), 0, 8) : '0';
    }
}
