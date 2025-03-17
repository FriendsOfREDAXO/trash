<?php
/**
 * Trash AddOn - Boot file
 * 
 * @package redaxo\trash
 */

// Extension Point für das Löschen von Artikeln
rex_extension::register('ART_PRE_DELETED', function(rex_extension_point $ep) {
    try {
        // Artikel-Daten aus dem Extension Point holen
        $articleId = $ep->getParam('id');
        $clangId = $ep->getParam('clang');
        $parentId = $ep->getParam('parent_id');
        $name = $ep->getParam('name');
        $status = $ep->getParam('status');
        
        // Tabellennamen definieren für gelöschte Artikel
        $trashTable = rex::getTable('trash_article');
        $trashSliceTable = rex::getTable('trash_article_slice');
        
        // Prüfen, ob der Artikel bereits im Papierkorb existiert (vermeidet Duplikate bei mehreren Sprachen)
        $sql = rex_sql::factory();
        $exists = $sql->getArray('SELECT id FROM ' . $trashTable . ' WHERE article_id = :article_id', ['article_id' => $articleId]);
        
        if (empty($exists)) {
            // Prüfen, ob der Artikel existiert, bevor wir versuchen, ihn zu sichern
            $articleCheck = rex_sql::factory();
            // Wir verwenden eine allgemeinere Abfrage ohne die Einschränkung startarticle=0
            $articleCheck->setQuery('SELECT * FROM ' . rex::getTable('article') . ' WHERE id = ? AND clang_id = ?', [$articleId, $clangId]);
            
            if ($articleCheck->getRows() > 0) {
                // Artikel existiert, wir können ihn in den Papierkorb verschieben
                $isStartArticle = (int)$articleCheck->getValue('startarticle') === 1;
                
                // Artikel in Papierkorb verschieben
                $sql = rex_sql::factory();
                $sql->setTable($trashTable);
                $sql->setValue('article_id', $articleId);
                $sql->setValue('parent_id', $parentId);
                $sql->setValue('name', $articleCheck->getValue('name'));
                $sql->setValue('catname', $articleCheck->getValue('catname'));
                $sql->setValue('catpriority', $articleCheck->getValue('catpriority'));
                $sql->setValue('status', $articleCheck->getValue('status'));
                $sql->setValue('startarticle', $isStartArticle ? 1 : 0);
                $sql->setValue('deleted_at', date('Y-m-d H:i:s'));
                
                // Weitere wichtige Daten speichern
                $attributes = json_encode([
                    'path' => $articleCheck->getValue('path'),
                    'priority' => $articleCheck->getValue('priority'),
                    'template_id' => $articleCheck->getValue('template_id'),
                    'createdate' => $articleCheck->getValue('createdate'),
                    'createuser' => $articleCheck->getValue('createuser'),
                    'updatedate' => $articleCheck->getValue('updatedate'),
                    'updateuser' => $articleCheck->getValue('updateuser')
                ]);
                $sql->setValue('attributes', $attributes);
                $sql->insert();
                
                // Neue ID des Eintrags im Papierkorb
                $trashArticleId = $sql->getLastId();
                
                // Alle Sprachversionen und ihre Slices in den Papierkorb kopieren
                foreach(rex_clang::getAllIds() as $langId) {
                    // Alle Revisions prüfen
                    $revisions = [0]; // Standardmäßig Live-Version (0)
                    
                    // Wenn das Versions-Plugin verfügbar ist, auch die Arbeitsversion sichern
                    if (rex_plugin::get('structure', 'version')->isAvailable()) {
                        $revisions[] = 1; // Arbeitsversion (1) hinzufügen
                    }
                    
                    foreach ($revisions as $revision) {
                        // Alle Slices des Artikels mit dieser Revision holen
                        $slices = rex_sql::factory();
                        $slices->setQuery('SELECT * FROM ' . rex::getTable('article_slice') . ' 
                                          WHERE article_id = :id AND clang_id = :clang AND revision = :revision', 
                                          ['id' => $articleId, 'clang' => $langId, 'revision' => $revision]);
                        
                        // Slices in den Papierkorb kopieren
                        if ($slices->getRows() > 0) {
                            foreach ($slices as $slice) {
                                try {
                                    $sliceSql = rex_sql::factory();
                                    $sliceSql->setTable($trashSliceTable);
                                    
                                    // Minimal erforderliche Felder setzen
                                    $sliceSql->setValue('trash_article_id', $trashArticleId);
                                    $sliceSql->setValue('article_id', $slice->getValue('article_id'));
                                    $sliceSql->setValue('clang_id', $slice->getValue('clang_id'));
                                    $sliceSql->setValue('ctype_id', $slice->getValue('ctype_id') ?: 1);
                                    $sliceSql->setValue('module_id', $slice->getValue('module_id') ?: 0);
                                    $sliceSql->setValue('priority', $slice->getValue('priority') ?: 0);
                                    $sliceSql->setValue('revision', $revision);
                                    
                                    // Alle Feldtypen mit Schleifen verarbeiten
                                    $fieldTypes = [
                                        'value' => 20,
                                        'media' => 10,
                                        'medialist' => 10,
                                        'link' => 10,
                                        'linklist' => 10
                                    ];
                                    
                                    foreach ($fieldTypes as $type => $count) {
                                        for ($i = 1; $i <= $count; $i++) {
                                            $field = $type . $i;
                                            if ($slice->hasValue($field)) {
                                                $sliceSql->setValue($field, $slice->getValue($field));
                                            }
                                        }
                                    }
                                    
                                    $sliceSql->insert();
                                } catch (Exception $e) {
                                    // Fehler beim Einfügen des Slices loggen
                                    rex_logger::logException($e);
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Fehler beim Kopieren in den Papierkorb loggen, aber den Löschvorgang nicht unterbrechen
        rex_logger::logException($e);
    }
    
    // Immer true zurückgeben, damit der Löschvorgang nicht unterbrochen wird
    return true;
});

// Seiteneinträge hinzufügen
if (rex::isBackend() && rex::getUser()) {
    if (rex::getUser()->isAdmin()) {
        // Hauptmenüpunkt für das Trash-AddOn hinzufügen
        rex_extension::register('PAGES_PREPARED', function (rex_extension_point $ep) {
            $pages = $ep->getSubject();
            
            // Füge eine neue Seite nach dem "Struktur" Menüpunkt hinzu
            $structureIndex = $pages->getIndex('structure');
            if ($structureIndex !== false) {
                $trashPage = new rex_be_page('trash', rex_i18n::msg('trash'));
                $trashPage->setHref(rex_url::backendPage('trash'));
                $trashPage->setIcon('fa fa-trash');
                $trashPage->setRequiredPermissions(['isAdmin']);
                
                $pages->insertAt($structureIndex + 1, $trashPage);
            }
        });
    }
}
