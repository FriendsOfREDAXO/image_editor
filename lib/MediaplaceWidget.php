<?php

namespace FriendsOfRedaxo\ImageEditor;

/**
 * Feldtyp für MediaPlace (MEDIAPLACE_WIDGET_TYPES). Speichert nichts in den MediaPlace-Metadaten – die Ausschnitte
 * verwaltet image_editor selbst (Spalte rex_media.image_editor).
 */
final class MediaplaceWidget implements \FriendsOfRedaxo\Mediaplace\MetainfoWidgetInterface
{
    public function __construct(private readonly mixed $field = null) {}

    public function normalizeValue(mixed $value): mixed
    {
        return null;
    }
}
