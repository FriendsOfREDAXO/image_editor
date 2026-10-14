<?php

/** @var rex_addon $this */

// Ausschnitte und eigene Media-Manager-Typen entfernen – die Bilder im Medienpool bleiben unverändert
rex_sql_table::get(rex::getTable('media'))->removeColumn('image_editor')->alter();
foreach (array_keys(FriendsOfRedaxo\ImageEditor\ImageEditor::types()) as $name) {
    FriendsOfRedaxo\ImageEditor\ImageEditor::deleteType($name);
}
// MediaPlace-Feld entfernen
if (rex_addon::get('mediaplace')->isAvailable() && class_exists(FriendsOfRedaxo\Mediaplace\MetainfoFieldGroup::class)) {
    FriendsOfRedaxo\Mediaplace\MetainfoFieldGroup::deleteField('image_editor_crops');
}
rex_media_manager::deleteCache();
rex_file::delete(rex_path::addonCache('media_manager', 'types.cache'));
