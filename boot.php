<?php
/**
 * Trash AddOn - Boot file
 * 
 * @package redaxo\trash
 */

// Extension Point für das Löschen von Artikeln
rex_extension::register('ART_PRE_DELETED', function(rex_extension_point $ep) {
    // Artikel-Daten aus dem Extension Point holen
    $articleId = $ep->getParam('id');
    $clangId = $ep->getParam('clang');
    $parentId = $ep->getParam('parent_id');
    $name = $ep->getParam('name');
    $status = $ep->getParam('status');
    
    // Wenn keine Sprachvariable übergeben wurde, versuchen wir die aktuelle Sprache zu verwenden
    if (!$clangId) {
        $clangId = rex_clang::getCurrentId();
        rex_logger::factory()->info('Trash: Fehlende Sprachvariable für Artikel ' . $articleId . ', verwende aktuelle Sprache: ' . $clangId);
    }
    
    // Prüfen, ob wir genügend Daten haben, um fortzufahren
    if (!$articleId) {
        rex_logger::logError(
            E_WARNING,
            'Trash: Unzureichende Daten für Artikel-Backup, ID fehlt',
            __FILE__,
            __LINE__
        );
        return;
    }
    
    // Tabellennamen definieren für gelöschte Artikel
    $trashTable = rex::getTable('trash_article');
    $trashSliceTable = rex::getTable('trash_article_slice');
    $trashSliceMetaTable = rex::getTable('trash_slice_meta');
    
    // Überprüfen, ob Tabellennamen korrekt definiert sind
    if (empty($trashTable) || empty($trashSliceTable) || empty($trashSliceMetaTable)) {
        rex_logger::logError(
            E_WARNING,
            'Trash: Tabellennamen nicht korrekt definiert: trash_article=' . $trashTable . 
            ', trash_article_slice=' . $trashSliceTable . 
            ', trash_slice_meta=' . $trashSliceMetaTable,
            __FILE__,
            __LINE__
        );
        return;
    }
    
    // Versuchen, die Existenz der Tabellen zu überprüfen
    try {
        $checkSql = rex_sql::factory();
        $checkSql->setQuery('SHOW TABLES LIKE :table', ['table' => $trashTable]);
        if ($checkSql->getRows() === 0) {
            rex_logger::logError(
                E_WARNING,
                'Trash: Tabelle ' . $trashTable . ' existiert nicht',
                __FILE__,
                __LINE__
            );
            return;
        }
    } catch (Exception $e) {
        rex_logger::logException($e);
        return;
    }
    
    // Aktuelle Zeit in korrekt formatiertem Format
    $currentTimestamp = date('Y-m-d H:i:s');
    
    // Prüfen, ob der Artikel bereits im Papierkorb existiert (vermeidet Duplikate bei mehreren Sprachen)
    $sql = rex_sql::factory();
    $exists = $sql->getArray('SELECT id FROM ' . $trashTable . ' WHERE article_id = :article_id', ['article_id' => $articleId]);
    
    if (empty($exists)) {
        // Artikel-Referenz für alle verfügbaren Sprachen holen
        $article = rex_article::get($articleId, $clangId);
        
        // Überprüfen, ob der Artikel existiert
        if (!$article) {
            rex_logger::logError(
                E_WARNING,
                'Trash: Artikel mit ID ' . $articleId . ' und Sprache ' . $clangId . ' konnte nicht gefunden werden',
                __FILE__,
                __LINE__
            );
            
            // Trotzdem versuchen, minimale Daten zu speichern
            $sql = rex_sql::factory();
            $sql->setTable($trashTable);
            $sql->setValue('article_id', $articleId);
            $sql->setValue('parent_id', $parentId);
            $sql->setValue('name', $name ?: 'Unbekannter Artikel #' . $articleId);
            $sql->setValue('catname', '');
            $sql->setValue('catpriority', 0);
            $sql->setValue('status', $status ?: 0);
            $sql->setValue('startarticle', 0);
            $sql->setValue('deleted_at', $currentTimestamp);
            $sql->setValue('attributes', '{}');
            
            // Speichern und fortfahren
            try {
                $sql->insert();
                $trashArticleId = $sql->getLastId();
                
                // Versuchen, Slices direkt aus der Datenbank zu holen
                $slices = rex_sql::factory();
                $slices->setQuery(
                    'SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE article_id = :id',
                    ['id' => $articleId]
                );
                
                if ($slices->getRows() > 0) {
                    foreach ($slices as $slice) {
                        backupSlice($slice, $trashArticleId, $trashSliceTable, $trashSliceMetaTable);
                    }
                }
                
            } catch (Exception $e) {
                rex_logger::logException($e);
            }
            
            return;
        }
        
        // Artikel in Papierkorb verschieben
        $sql = rex_sql::factory();
        
        // Debug-Log für den Tabellennamen
        rex_logger::factory()->info('Trash: Setze Tabellennamen für Artikel-Insert: ' . $trashTable);
        
        $sql->setTable($trashTable);
        $sql->setValue('article_id', $articleId);
        $sql->setValue('parent_id', $parentId);
        $sql->setValue('name', $article->getValue('name'));
        $sql->setValue('catname', $article->getValue('catname'));
        $sql->setValue('catpriority', $article->getValue('catpriority'));
        $sql->setValue('status', $article->getValue('status'));
        $sql->setValue('startarticle', $article->isStartArticle() ? 1 : 0);
        $sql->setValue('deleted_at', $currentTimestamp);
        
        // Beim Speichern der Attribute sicherstellen, dass das Datum korrekt formatiert ist
        $attributes = [
            'path' => $article->getValue('path'),
            'priority' => $article->getValue('priority'),
            'template_id' => $article->getValue('template_id'),
            'createdate' => formatDateIfNeeded($article->getValue('createdate')),
            'createuser' => $article->getValue('createuser'),
            'updatedate' => formatDateIfNeeded($article->getValue('updatedate')),
            'updateuser' => $article->getValue('updateuser')
        ];
        $sql->setValue('attributes', json_encode($attributes));
        
        // Meta-Attribute als JSON speichern
        $metaAttributes = collectMetaAttributes('article', $article);
        if (!empty($metaAttributes)) {
            $sql->setValue('meta_attributes', json_encode($metaAttributes, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG));
        }
        
        $sql->insert();
        
        // Neue ID des Eintrags im Papierkorb
        $trashArticleId = $sql->getLastId();
        
        // Alle Sprachversionen und ihre Slices in den Papierkorb kopieren
        foreach(rex_clang::getAllIds() as $langId) {
            $articleVersion = rex_article::get($articleId, $langId);
            if (!$articleVersion) {
                continue; // Artikel existiert nicht in dieser Sprache
            }
            
            // Alle Revisions prüfen
            $revisions = [0]; // Standardmäßig Live-Version (0)
            
            // Wenn das Versions-Plugin verfügbar ist, auch die Arbeitsversion sichern
            if (rex_plugin::get('structure', 'version')->isAvailable()) {
                $revisions[] = 1; // Arbeitsversion (1) hinzufügen
            }
            
            foreach ($revisions as $revision) {
                // Alle Slices des Artikels mit dieser Revision holen
                try {
                    $slices = rex_sql::factory();
                    $slices->setQuery('SELECT * FROM ' . rex::getTable('article_slice') . ' 
                                    WHERE article_id = :id AND clang_id = :clang AND revision = :revision', 
                                    ['id' => $articleId, 'clang' => $langId, 'revision' => $revision]);
                    
                    // Slices in den Papierkorb kopieren
                    if ($slices->getRows() > 0) {
                        foreach ($slices as $slice) {
                            backupSlice($slice, $trashArticleId, $trashSliceTable, $trashSliceMetaTable);
                        }
                    }
                } catch (Exception $e) {
                    rex_logger::logException($e);
                }
            }
        }
    }
});

/**
 * Hilfsfunktion, um sicherzustellen, dass ein Datum im Format Y-m-d H:i:s vorliegt
 * 
 * @param string $date Das zu formatierende Datum
 * @return string Korrekt formatiertes Datum
 */
function formatDateIfNeeded($date) {
    if (!$date) {
        return date('Y-m-d H:i:s');
    }
    
    // Prüfen, ob das Datum bereits das richtige Format hat
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date)) {
        return $date;
    }
    
    // Versuchen, das Datum zu parsen und neu zu formatieren
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return date('Y-m-d H:i:s');
    }
    
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Slice in den Papierkorb sichern
 * 
 * @param rex_sql $slice Das zu sichernde Slice
 * @param int $trashArticleId ID des Artikels im Papierkorb
 * @param string $trashSliceTable Name der Slice-Tabelle im Papierkorb
 * @param string $trashSliceMetaTable Name der Slice-Meta-Tabelle im Papierkorb
 */
function backupSlice($slice, $trashArticleId, $trashSliceTable, $trashSliceMetaTable) {
    try {
        // Überprüfen, ob Tabellennamen korrekt definiert sind
        if (empty($trashSliceTable) || empty($trashSliceMetaTable)) {
            throw new Exception('Trash: Tabellennamen nicht korrekt definiert');
        }
        
        $sliceSql = rex_sql::factory();
        
        // Überprüfen, ob die Tabelle existiert, bevor wir versuchen, sie zu verwenden
        $sliceSql->setQuery('SHOW TABLES LIKE :table', ['table' => $trashSliceTable]);
        if ($sliceSql->getRows() === 0) {
            throw new Exception('Trash: Tabelle ' . $trashSliceTable . ' existiert nicht');
        }
        
        // Explizit den Tabellennamen setzen und überprüfen
        $sliceSql = rex_sql::factory();
        $tableName = $trashSliceTable;
        rex_logger::factory()->info('Trash: Setze Tabellennamen: ' . $tableName);
        $sliceSql->setTable($tableName);
        
        // Minimal erforderliche Felder setzen
        $sliceSql->setValue('trash_article_id', $trashArticleId);
        $sliceSql->setValue('article_id', $slice->getValue('article_id'));
        $sliceSql->setValue('clang_id', $slice->getValue('clang_id'));
        $sliceSql->setValue('ctype_id', $slice->getValue('ctype_id') ?: 1);
        $sliceSql->setValue('module_id', $slice->getValue('module_id') ?: 0);
        $sliceSql->setValue('priority', $slice->getValue('priority') ?: 0);
        $sliceSql->setValue('revision', $slice->hasValue('revision') ? (int)$slice->getValue('revision') : 0);
        
        // Status kopieren, falls vorhanden
        if ($slice->hasValue('status')) {
            $sliceSql->setValue('status', $slice->getValue('status'));
        }
        
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
        
        // Slice in Papierkorb speichern
        $sliceSql->insert();
        
        // ID des eingefügten Slices holen
        $sliceId = $sliceSql->getLastId();
        
        // Meta-Attribute in separater Tabelle speichern
        $sliceMetaAttributes = collectMetaAttributes('article_slice', $slice, $fieldTypes);
        
        // Meta-Attribute speichern, wenn vorhanden
        if (!empty($sliceMetaAttributes) && !empty($trashSliceMetaTable)) {
            $metaSql = rex_sql::factory();
            
            // Überprüfen, ob die Meta-Tabelle existiert
            $metaSql->setQuery('SHOW TABLES LIKE :table', ['table' => $trashSliceMetaTable]);
            if ($metaSql->getRows() === 0) {
                throw new Exception('Trash: Tabelle ' . $trashSliceMetaTable . ' existiert nicht');
            }
            
            $metaSql = rex_sql::factory();
            $metaSql->setTable($trashSliceMetaTable);
            $metaSql->setValue('trash_slice_id', $sliceId);
            $metaSql->setValue('meta_data', json_encode($sliceMetaAttributes, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG));
            $metaSql->insert();
        }
    } catch (Exception $e) {
        // Fehler beim Einfügen des Slices loggen
        rex_logger::logException($e);
    }
}

/**
 * Hilfsfunktion zum Sammeln von Meta-Attributen
 * 
 * @param string $tableName Name der Tabelle, aus der Meta-Attribute gesammelt werden (ohne Präfix)
 * @param rex_sql|rex_article $object Das Objekt, aus dem die Werte gelesen werden
 * @param array $fieldTypes Optional: Array mit Feldtypen für Slices
 * @return array Die gesammelten Meta-Attribute
 */
function collectMetaAttributes($tableName, $object, $fieldTypes = []) {
    $allMetaAttributes = [];
    $table = rex::getTable($tableName);

    // Alle Spalten der Tabelle auslesen
    try {
        $tableColumns = rex_sql::showColumns($table);
    } catch (Exception $e) {
        rex_logger::logException($e);
        return [];
    }
    
    // Standard-Spalten definieren, die wir nicht als Meta-Attribute speichern
    $standardColumns = [
        'id', 'parent_id', 'name', 'catname', 'catpriority', 'startarticle', 
        'priority', 'path', 'status', 'createdate', 'updatedate', 
        'template_id', 'clang_id', 'createuser', 'updateuser',
        'article_id', 'ctype_id', 'module_id', 'revision'
    ];
    
    // Für Slices: Feldtypen zu den Standard-Spalten hinzufügen
    if ($tableName === 'article_slice' && !empty($fieldTypes)) {
        foreach ($fieldTypes as $type => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $standardColumns[] = $type . $i;
            }
        }
    }

    // Alle Spalten durchgehen und nicht-standard Spalten als Meta-Attribute sammeln
    foreach ($tableColumns as $column) {
        $columnName = $column['name'];
        if (!in_array($columnName, $standardColumns)) {
            // Wert aus dem Objekt holen
            if ($object->hasValue($columnName)) {
                $metaValue = $object->getValue($columnName);
                if ($metaValue !== null) {
                    $allMetaAttributes[$columnName] = $metaValue;
                }
            }
        }
    }

    return $allMetaAttributes;
}

// Cronjob registrieren, wenn das Cronjob AddOn installiert und aktiviert ist
if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType('rex_cronjob_trash_cleanup');
}
