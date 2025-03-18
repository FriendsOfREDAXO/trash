<?php
/**
 * Trash AddOn - Boot file
 * 
 * @package redaxo\trash
 */

// Hilfsfunktion für die Datumskonvertierung
function fixDateFormat($dateStr) {
    // Wenn es ein Timestamp ist (numerisch), konvertieren
    if (is_numeric($dateStr)) {
        return date('Y-m-d H:i:s', (int)$dateStr);
    }
    
    // Wenn es ein leerer String oder NULL ist, aktuelles Datum zurückgeben
    if (empty($dateStr) || $dateStr === '0000-00-00 00:00:00') {
        return date('Y-m-d H:i:s');
    }
    
    // Sonst wie erhalten zurückgeben
    return $dateStr;
}

// Extension Point für das Löschen von Artikeln
rex_extension::register('ART_PRE_DELETED', function(rex_extension_point $ep) {
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
        // Artikel-Referenz für alle verfügbaren Sprachen speichern (nur einmal)
        $article = rex_article::get($articleId, $clangId);
        if ($article) {
            // Artikel in Papierkorb verschieben
            $sql = rex_sql::factory();
            $sql->setTable($trashTable);
            $sql->setValue('article_id', $articleId);
            $sql->setValue('parent_id', $parentId);
            $sql->setValue('name', $article->getValue('name'));
            $sql->setValue('catname', $article->getValue('catname'));
            $sql->setValue('status', $article->getValue('status'));
            $sql->setValue('startarticle', $article->isStartArticle() ? 1 : 0);
            $sql->setValue('deleted_at', date('Y-m-d H:i:s'));
            
            // Korrekte Speicherung der Prioritäten je nach Artikeltyp
            if ($article->isStartArticle()) {
                // Bei Startartikeln (Kategorien) die catpriority speichern
                $sql->setValue('catpriority', $article->getValue('catpriority'));
                // Für Startartikel auch die Kategorie-Priorität als reguläre Priorität speichern
                $sql->setValue('priority', $article->getValue('priority') ?: $article->getValue('catpriority'));
            } else {
                // Bei normalen Artikeln die priority speichern
                $sql->setValue('priority', $article->getValue('priority'));
                // Falls es einen catpriority-Wert gibt, auch diesen speichern (sollte eigentlich nicht vorkommen)
                if ($article->hasValue('catpriority')) {
                    $sql->setValue('catpriority', $article->getValue('catpriority'));
                } else {
                    $sql->setValue('catpriority', 0);
                }
            }
            
            // Direkte Speicherung der Attribute als eigene Spalten mit Datumsfixierung
            $sql->setValue('path', $article->getValue('path'));
            $sql->setValue('template_id', $article->getValue('template_id'));
            
            // Hier die Datumswerte korrekt formatieren
            $sql->setValue('createdate', fixDateFormat($article->getValue('createdate')));
            $sql->setValue('createuser', $article->getValue('createuser'));
            $sql->setValue('updatedate', fixDateFormat($article->getValue('updatedate')));
            $sql->setValue('updateuser', $article->getValue('updateuser'));
            
            // Revision-Wert speichern, wenn vorhanden
            if ($article->hasValue('revision')) {
                $sql->setValue('revision', $article->getValue('revision'));
            } else {
                $sql->setValue('revision', 0);
            }
            
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
                                if (!empty($sliceMetaAttributes)) {
                                    $metaSql = rex_sql::factory();
                                    $metaSql->setTable(rex::getTable('trash_slice_meta'));
                                    $metaSql->setValue('trash_slice_id', $sliceId);
                                    $metaSql->setValue('meta_data', json_encode($sliceMetaAttributes, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG));
                                    $metaSql->insert();
                                }
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
});

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
    $tableColumns = rex_sql::showColumns($table);
    
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
            $metaValue = $object->getValue($columnName);
            if ($metaValue !== null) {
                $allMetaAttributes[$columnName] = $metaValue;
            }
        }
    }

    return $allMetaAttributes;
}

// Cronjob registrieren, wenn das Cronjob AddOn installiert und aktiviert ist
if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType('rex_cronjob_trash_cleanup');
}