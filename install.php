<?php

/** @var rex_addon $this */

// Schritte der Bearbeitung je Medium (JSON) – das Original bleibt unverändert
rex_sql_table::get(rex::getTable('media'))
    ->ensureColumn(new rex_sql_column('image_editor', 'text', true))
    ->alter();

// Media-Manager-Typen image_editor, image_editor_base, image_editor_16x9 … (Effekt „Bildeditor“ + media_negotiator bzw. WebP)
FriendsOfRedaxo\ImageEditor\ImageEditor::syncTypes();
