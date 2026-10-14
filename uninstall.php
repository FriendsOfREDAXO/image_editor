<?php

/** @var rex_addon $this */

// Bearbeitungen und bearbeitete Fassungen entfernen – die Originale im Medienpool bleiben unverändert
rex_sql_table::get(rex::getTable('media'))->removeColumn('image_editor')->alter();
rex_dir::delete($this->getDataPath());
// eigene Media-Manager-Typen entfernen
foreach (array_keys(FriendsOfRedaxo\ImageEditor\ImageEditor::types()) as $name) {
    $id = (int) (rex_sql::factory()->getArray('SELECT id FROM ' . rex::getTable('media_manager_type') . ' WHERE name = ?', [$name])[0]['id'] ?? 0);
    if ($id > 0) {
        rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media_manager_type_effect') . ' WHERE type_id = ?', [$id]);
        rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media_manager_type') . ' WHERE id = ?', [$id]);
    }
}
rex_media_manager::deleteCache();
rex_file::delete(rex_path::addonCache('media_manager', 'types.cache'));
