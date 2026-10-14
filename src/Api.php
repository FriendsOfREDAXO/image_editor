<?php

namespace FriendsOfRedaxo\ImageEditor;

use Override;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\ApiFunction\Result;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\MediaPool\Media;
use Redaxo\Core\MediaPool\MediaPoolPermission;
use Redaxo\Core\Security\BackendLogin;
use Throwable;

/**
 * Laden und Speichern für den Editor-Dialog: `?rex-api-call=image_editor&_csrf_token=…` (POST: file, action=info|save|adjust|crop, ops | fx,focus,fit | ratio,rect).
 * Auch auf der Website aufrufbar (published) – nur mit Backend-Sitzung und Recht auf den Medienpool bzw. die Kategorie.
 */
#[AsApiFunction('image_editor')]
final class Api extends ApiFunction
{
    protected bool $published = true;

    #[Override]
    public function execute(): Result
    {
        try {
            $user = BackendLogin::createUser() ?? self::fail('Bitte im Backend anmelden.', 403);
            $file = Request::post('file', 'string', '');
            $media = Media::get($file) ?? self::fail('Bild nicht gefunden.', 404);
            $perm = $user->getComplexPerm('media');
            if (!$perm instanceof MediaPoolPermission || !$perm->hasCategoryPerm($media->categoryId)) {
                self::fail('Keine Berechtigung für dieses Bild.', 403);
            }
            $data = match (Request::post('action', 'string', 'info')) {
                'save' => ImageEditor::save($file, json_decode(Request::post('ops', 'string', ''), true) ?? []),
                'crop' => ImageEditor::saveCrop($file, Request::post('ratio', 'string', ''), json_decode(Request::post('rect', 'string', 'null'), true)),
                'adjust' => ImageEditor::saveAdjust($file, Request::post('fx', 'string', ''), json_decode(Request::post('focus', 'string', ''), true), Request::post('fit', 'string', 'cover')),
                default => ImageEditor::info($file),
            };
            self::send(['ok' => true, 'item' => $data]);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            self::send(['ok' => false, 'error' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 400);
        }
    }

    private static function fail(string $message, int $code): never
    {
        throw new \RuntimeException($message, $code);
    }

    private static function send(array $data, int $status = 200): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($status);
        Response::sendJson($data);
        exit;
    }
}
