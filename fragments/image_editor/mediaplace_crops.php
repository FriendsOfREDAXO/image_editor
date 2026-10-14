<?php

/**
 * MediaPlace-Detailansicht: Feldkörper „Zuschnitte je Format“. Der Inhalt (Vorschauen, Dialog) entsteht im Skript
 * (assets/image-editor.js); gespeichert wird über die API von image_editor, nicht als Metainfo-Wert.
 *
 * @var rex_fragment $this
 */

$info = (array) $this->getVar('info', []);
echo FriendsOfRedaxo\ImageEditor\ImageEditor::box((string) ($info['filename'] ?? ''));
