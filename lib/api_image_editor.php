<?php

use FriendsOfRedaxo\ImageEditor\ImageEditor;

/**
 * Laden und Speichern für die Dialoge: `?rex-api-call=image_editor&_csrf_token=…`
 * (POST: file, action=info|crop, ratio, rect). Auch im Frontend aufrufbar (published) – nur mit Backend-Sitzung und
 * Recht auf die Kategorie des Mediums.
 */
class rex_api_image_editor extends rex_api_function
{
    protected $published = true;

    public function execute()
    {
        try {
            $user = rex::getUser() ?? rex_backend_login::createUser();
            if (!$user) {
                throw new RuntimeException('Bitte im Backend anmelden.', 403);
            }
            $file = rex_post('file', 'string', '');
            $media = rex_media::get($file);
            if (!$media) {
                throw new RuntimeException('Bild nicht gefunden.', 404);
            }
            $perm = $user->getComplexPerm('media');
            if (!$perm instanceof rex_media_perm || !$perm->hasCategoryPerm($media->getCategoryId())) {
                throw new RuntimeException('Keine Berechtigung für dieses Bild.', 403);
            }
            $data = match (rex_post('action', 'string', 'info')) {
                'crop' => ImageEditor::saveCrop($file, rex_post('ratio', 'string', ''), json_decode(rex_post('rect', 'string', 'null'), true)),
                default => ImageEditor::info($file),
            };
            self::send(['ok' => true, 'item' => $data]);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            self::send(['ok' => false, 'error' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 400);
        }
    }

    /** @param array<string, mixed> $data */
    private static function send(array $data, int $status = 200): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus(match ($status) {
            403 => rex_response::HTTP_FORBIDDEN, 404 => rex_response::HTTP_NOT_FOUND, 200 => rex_response::HTTP_OK, default => '400 Bad Request',
        });
        rex_response::sendJson($data);
        exit;
    }
}
