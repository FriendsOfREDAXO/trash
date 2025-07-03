<?php
/**
 * Trash AddOn - Boot file
 * 
 * @package redaxo\trash
 */

use FriendsOfREDAXO\trash\TrashService;

// Extension Point für das Löschen von Artikeln
rex_extension::register('ART_PRE_DELETED', function(rex_extension_point $ep) {
    $trashService = new TrashService();
    $trashService->handleArticleDeletion($ep);
});

// Cronjob registrieren, wenn das Cronjob AddOn installiert und aktiviert ist
if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType('rex_cronjob_trash_cleanup');
}