<?php
/**
 * Trash AddOn - Main backend page
 * 
 * @package redaxo\trash
 */

// Rechteprüfung
if (!rex::getUser()->isAdmin()) {
    // Nur Admins dürfen auf den Papierkorb zugreifen
    echo rex_view::error(rex_i18n::msg('no_permission'));
    return;
}

// Durchführung von Aktionen (Wiederherstellen oder Endgültig löschen)
$func = rex_request('func', 'string');
$articleId = rex_request('id', 'int');

// Tabellennamen definieren für gelöschte Artikel
$trashTable = rex::getTable('trash_article');
$trashSliceTable = rex::getTable('trash_article_slice');
$trashSliceMetaTable = rex::getTable('trash_slice_meta');

// Meldungen initialisieren
$message = '';

// Debug-Modus zum Anzeigen detaillierter Fehlermeldungen
$debug = false; // Auf true setzen für Entwicklungszwecke


/**
 * Prüft, ob eine Priorität in einer Kategorie bereits vergeben ist
 * 
 * @param int $parentId ID der Elternkategorie
 * @param int $priority Die zu prüfende Priorität
 * @param bool $isStartarticle Ob es sich um eine Kategorie handelt
 * @return bool True wenn die Priorität bereits vergeben ist, sonst false
 */
function isPriorityTaken($parentId, $priority, $isStartarticle = false) {
    $sql = rex_sql::factory();
    
    if ($isStartarticle) {
        // Für Kategorien: catpriority prüfen
        $query = 'SELECT id FROM ' . rex::getTablePrefix() . 'article 
                 WHERE parent_id = :parent_id AND startarticle = 1 AND catpriority = :priority LIMIT 1';
    } else {
        // Für Artikel: priority prüfen
        $query = 'SELECT id FROM ' . rex::getTablePrefix() . 'article 
                 WHERE parent_id = :parent_id AND startarticle = 0 AND priority = :priority LIMIT 1';
    }
    
    $sql->setQuery($query, [
        'parent_id' => $parentId,
        'priority' => $priority
    ]);
    
    return $sql->getRows() > 0;
}

/**
 * Ermittelt eine verfügbare Priorität für den Artikel
 * Versucht zuerst die gewünschte Priorität zu verwenden, 
 * falls diese bereits belegt ist, wird die nächste freie Priorität verwendet
 * 
 * @param int $parentId ID der Elternkategorie
 * @param int $desiredPriority Die gewünschte Priorität
 * @param bool $isStartarticle Ob es sich um eine Kategorie handelt
 * @return int Eine verfügbare Priorität
 */
function getAvailablePriority($parentId, $desiredPriority, $isStartarticle = false) {
    // Wenn die gewünschte Priorität nicht belegt ist, kann sie verwendet werden
    if (!isPriorityTaken($parentId, $desiredPriority, $isStartarticle)) {
        return $desiredPriority;
    }
    
    // Sonst die nächsthöchste freie Priorität finden
    $priority = $desiredPriority;
    while (isPriorityTaken($parentId, $priority, $isStartarticle)) {
        $priority++;
    }
    
    return $priority;
}

/**
 * Ermittelt die nächste verfügbare Priorität für einen Artikel in seiner Kategorie
 * 
 * @param int $parentId Die ID der Eltern-Kategorie
 * @param bool $isStartarticle Ob es sich um einen Startartikel handelt
 * @return int Die nächste verfügbare Priorität
 */
function getNextPriority($parentId, $isStartarticle = false) {
    $sql = rex_sql::factory();
    
    if ($isStartarticle) {
        // Bei Startartikeln (Kategorien) maximale catpriority + 1 in der Elternkategorie verwenden
        $sql->setQuery('SELECT MAX(catpriority) as max_prio FROM ' . rex::getTablePrefix() . 'article 
                      WHERE parent_id = :parent_id AND startarticle = 1', 
                      ['parent_id' => $parentId]);
        
        if ($sql->getRows() > 0 && $sql->getValue('max_prio') !== null) {
            return (int)$sql->getValue('max_prio') + 1;
        }
        
        return 1; // Fallback: erste Position
    } else {
        // Bei normalen Artikeln maximale priority + 1 in der Kategorie verwenden
        $sql->setQuery('SELECT MAX(priority) as max_prio FROM ' . rex::getTablePrefix() . 'article 
                      WHERE parent_id = :parent_id AND startarticle = 0', 
                      ['parent_id' => $parentId]);
        
        if ($sql->getRows() > 0 && $sql->getValue('max_prio') !== null) {
            return (int)$sql->getValue('max_prio') + 1;
        }
        
        return 1; // Fallback: erste Position
    }
}

/**
 * Direkte Artikel-Einfügung mit verbesserter ID- und Prioritätsbehandlung für REDAXO
 * 
 * @param array $articleData Die Daten für den Artikel
 * @return array [success, articleId, errorMessage, idChanged, originalRequestedId]
 */
function insertArticleDirectly($articleData) {
    global $debug;
    
    try {
        // Tabellennamen direkt verwenden statt getTable() aufzurufen
        $tableName = rex::getTablePrefix() . 'article';
        if (empty($tableName)) {
            return [false, null, "Tabellenname ist leer.", false, 0];
        }
        
        $idChanged = false;
        $originalId = $articleData['id'];
        
        // Prüfen, ob mit der angegebenen ID bereits ein Artikel existiert
        if (isset($articleData['id']) && $articleData['id'] > 0) {
            $checkSql = rex_sql::factory();
            $checkSql->setQuery("SELECT id FROM " . $tableName . " WHERE id = :id LIMIT 1", 
                ['id' => $articleData['id']]);
            
            // Wenn ein Artikel mit dieser ID gefunden wurde, neue ID ermitteln
            if ($checkSql->getRows() > 0) {
                if ($debug) {
                    echo '<pre>HINWEIS: Artikel mit ID ' . $articleData['id'] . ' existiert bereits. Ermittle neue ID.</pre>';
                }
                
                // Die nächsthöhere verfügbare ID finden
                $maxSql = rex_sql::factory();
                $maxSql->setQuery("SELECT MAX(id) as max_id FROM " . $tableName);
                $maxId = (int)$maxSql->getValue('max_id');
                
                // Sicherstellen, dass die ID-Zuweisung eindeutig ist
                $newId = $maxId + 1;
                
                // Überprüfen, ob die neue ID bereits existiert (zur Sicherheit)
                $existsSql = rex_sql::factory();
                while (true) {
                    $existsSql->setQuery("SELECT id FROM " . $tableName . " WHERE id = :id LIMIT 1", 
                        ['id' => $newId]);
                    
                    if ($existsSql->getRows() === 0) {
                        // ID ist frei, wir können sie verwenden
                        break;
                    }
                    
                    // Nächste ID versuchen
                    $newId++;
                }
                
                // Neue ID festlegen
                $articleData['id'] = $newId;
                $idChanged = true;
                
                if ($debug) {
                    echo '<pre>HINWEIS: Neue generierte ID: ' . $newId . '</pre>';
                }
            }
        }
        
        // Für jede Sprache einen Eintrag erstellen
        $insertedId = null;
        foreach (rex_clang::getAllIds() as $clangId) {
            // Zur Sicherheit den Query manuell aufbauen und prüfen
            $query = "INSERT INTO " . $tableName . " SET ";
            $params = [];
            $first = true;
            
            // Alle Felder durchgehen
            foreach ($articleData as $key => $value) {
                if ($key != 'clang_id') { // clang_id nicht doppelt setzen
                    if (!$first) {
                        $query .= ", ";
                    }
                    $query .= "`" . $key . "` = :" . $key;
                    $params[$key] = $value;
                    $first = false;
                }
            }
            
            // clang_id hinzufügen
            if (!$first) {
                $query .= ", ";
            }
            $query .= "`clang_id` = :clang_id";
            $params['clang_id'] = $clangId;
            
            // SQL ausführen
            $articleSql = rex_sql::factory();
            if ($debug) $articleSql->setDebug();
            $articleSql->setQuery($query, $params);
            
            // ID merken (nur beim ersten Einfügen)
            if ($insertedId === null) {
                $insertedId = $articleData['id'];
            }
        }
        
        // Erfolgreiche Eingabe mit Information, ob ID geändert wurde
        return [true, $insertedId, "", $idChanged, $originalId];
    } catch (Exception $e) {
        rex_logger::logException($e);
        return [false, null, $e->getMessage(), false, $originalId];
    }
}

// Aktionen verarbeiten
if ($func === 'restore' && $articleId > 0) {
    // Artikel-Daten aus dem Papierkorb holen
    $sql = rex_sql::factory();
    if ($debug) $sql->setDebug();
    $sql->setQuery('SELECT * FROM ' . $trashTable . ' WHERE id = :id', ['id' => $articleId]);
    
    if ($sql->getRows() === 1) {
        $original_id = $sql->getValue('article_id');
        $parent_id = $sql->getValue('parent_id');
        $name = $sql->getValue('name');
        $catname = $sql->getValue('catname');
        $catpriority = $sql->getValue('catpriority');
        $status = $sql->getValue('status');
        // Direkte Verwendung der Spalten anstelle von attributes JSON
        $path = $sql->getValue('path');
        $priority = $sql->getValue('priority');
        $template_id = $sql->getValue('template_id');
        $createdate = fixDateFormat($sql->getValue('createdate'));
        $createuser = $sql->getValue('createuser');
        $updatedate = fixDateFormat($sql->getValue('updatedate'));
        $updateuser = $sql->getValue('updateuser');
        $revision = $sql->getValue('revision');
        
        $startarticle = $sql->getValue('startarticle');
        
        // Meta-Attribute laden (falls vorhanden)
        $metaAttributes = null;
        if ($sql->hasValue('meta_attributes') && $sql->getValue('meta_attributes')) {
            $metaAttributes = json_decode($sql->getValue('meta_attributes'), true);
        }
        
        // Prüfen, ob die Elternkategorie noch existiert
        $parentExists = true;
        if ($parent_id > 0) {
            $parentCategory = rex_category::get($parent_id);
            if (!$parentCategory) {
                $parentExists = false;
                // Falls die Elternkategorie nicht mehr existiert, verwenden wir die Root-Kategorie
                $parent_id = 0;
            }
        }
        
        // Artikel-Daten vorbereiten
        $articleData = [
            'id' => $original_id,
            'parent_id' => $parent_id,
            'name' => $name,
            'catname' => $catname,
            'startarticle' => $startarticle
        ];

        // Priorität ermitteln mit Überprüfung auf bereits vergebene Prioritäten
        if ($startarticle) {
            // Für Startartikel (Kategorien) die catpriority prüfen und ggf. anpassen
            $savedPriority = ($catpriority > 0) ? $catpriority : 1;
            $articleData['catpriority'] = getAvailablePriority($parent_id, $savedPriority, true);
            
            // Falls die Priorität angepasst wurde, eine Infomeldung vorbereiten
            if ($articleData['catpriority'] != $savedPriority) {
                $priorityChangedInfo = rex_view::info(
                    'Die ursprüngliche Kategorie-Priorität ' . $savedPriority . 
                    ' ist bereits vergeben. Die neue Priorität ist ' . $articleData['catpriority'] . '.'
                );
            }
        } else {
            // Für normale Artikel die priority prüfen und ggf. anpassen
            $savedPriority = ($priority > 0) ? $priority : 1;
            $articleData['priority'] = getAvailablePriority($parent_id, $savedPriority, false);
            
            // Falls die Priorität angepasst wurde, eine Infomeldung vorbereiten
            if ($articleData['priority'] != $savedPriority) {
                $priorityChangedInfo = rex_view::info(
                    'Die ursprüngliche Artikel-Priorität ' . $savedPriority . 
                    ' ist bereits vergeben. Die neue Priorität ist ' . $articleData['priority'] . '.'
                );
            }
        }

        // Die restlichen Felder hinzufügen
        $articleData['path'] = $path;
        $articleData['status'] = $status;
        $articleData['template_id'] = $template_id;
        $articleData['createdate'] = $createdate;
        $articleData['createuser'] = !empty($createuser) ? $createuser : rex::getUser()->getLogin();
        $articleData['updatedate'] = date('Y-m-d H:i:s');
        $articleData['updateuser'] = rex::getUser()->getLogin();
        $articleData['revision'] = 0; // Auf Live-Version setzen
        
        if ($debug) {
            echo '<pre>Versuche Artikel wiederherzustellen mit ID ' . $original_id . ': ' . print_r($articleData, true) . '</pre>';
        }
        
        // Direkte Einfügung in die Datenbank
        list($success, $newArticleId, $errorMessage, $idChanged, $originalRequestedId) = insertArticleDirectly($articleData);
        
        if (!$success) {
            $message = rex_view::error(rex_i18n::msg('trash_restore_error') . ': ' . $errorMessage);
            
            if ($debug) {
                echo '<pre>FEHLER beim Wiederherstellen des Artikels: ' . $errorMessage . '</pre>';
            }
        } else {
            // Prüfen, ob die ID geändert wurde
            if ($idChanged) {
                $message = rex_view::success(rex_i18n::msg('trash_article_restored'));
                $message .= rex_view::info(rex_i18n::msg('trash_article_restored_with_new_id', $originalRequestedId, $newArticleId));
            } else {
                $message = rex_view::success(rex_i18n::msg('trash_article_restored'));
            }
            
            // Falls vorhanden, die Info über geänderte Priorität anzeigen
            if (isset($priorityChangedInfo)) {
                $message .= $priorityChangedInfo;
            }
            
            if (!$parentExists) {
                $message .= rex_view::warning(rex_i18n::msg('trash_parent_category_missing'));
            }
            
// Meta-Attribute wiederherstellen, falls vorhanden
if ($metaAttributes) {
    try {
        // Spalteninformationen der Artikeltabelle abrufen
        $columnInfo = rex_sql::showColumns(rex::getTable('article'));
        $existingColumns = [];
        $primaryKeys = [];
        
        // Vorhandene Spalten in ein Array überführen für schnelleren Zugriff
        // und Primary Keys identifizieren
        foreach ($columnInfo as $column) {
            $existingColumns[$column['name']] = true;
            if ($column['key'] === 'PRI' || $column['extra'] === 'auto_increment') {
                $primaryKeys[$column['name']] = true;
            }
        }
        
        // Liste von Spalten, die immer ignoriert werden sollten
        $ignoreColumns = ['pid', 'id', 'article_id'];
        
        foreach (rex_clang::getAllIds() as $clangId) {
            // Für jede Sprache die Meta-Attribute setzen
            
            // Query manuell aufbauen für mehr Kontrolle
            $query = "UPDATE " . rex::getTable('article') . " SET ";
            $params = [];
            $hasValues = false;
            $first = true;
            
            foreach ($metaAttributes as $key => $value) {
                // Prüfen ob die Spalte existiert und kein Primary Key oder Auto-Increment-Feld ist
                if (isset($existingColumns[$key]) && !isset($primaryKeys[$key]) && !in_array($key, $ignoreColumns)) {
                    if (!$first) {
                        $query .= ", ";
                    }
                    $query .= "`" . $key . "` = :" . $key;
                    $params[$key] = $value;
                    $hasValues = true;
                    $first = false;
                }
            }
            
            if ($hasValues) {
                $query .= " WHERE id = :id AND clang_id = :clang_id";
                $params['id'] = $newArticleId;
                $params['clang_id'] = $clangId;
                
                if ($debug) {
                    echo '<pre>Meta-Update Query: ' . $query . '</pre>';
                    echo '<pre>Meta-Update Params: ' . print_r($params, true) . '</pre>';
                }
                
                $metaSql = rex_sql::factory();
                if ($debug) $metaSql->setDebug();
                $metaSql->setQuery($query, $params);
            }
        }
    } catch (Exception $e) {
        // Fehler beim Wiederherstellen der Meta-Attribute loggen, aber den Prozess fortsetzen
        rex_logger::logException($e);
        if ($debug) {
            echo '<pre>FEHLER beim Setzen der Meta-Attribute: ' . $e->getMessage() . '</pre>';
        }
    }
}

            
            // Jetzt die Slices wiederherstellen, gruppiert nach Sprache und Revision
            $slicesSql = rex_sql::factory();
            if ($debug) $slicesSql->setDebug();
            $slicesSql->setQuery('SELECT * FROM ' . $trashSliceTable . ' WHERE trash_article_id = :trash_id ORDER BY clang_id, revision, priority', ['trash_id' => $articleId]);
            $slices = $slicesSql->getArray();
            
            // Eine Liste aller Sprachen, in denen Slices existieren
            $clangIds = [];
            $revisions = [0]; // Standardmäßig Live-Version (0)
            
            // Prüfen, ob rex_plugin::get('structure', 'version')->isAvailable()
            if (rex_plugin::get('structure', 'version')->isAvailable()) {
                $revisions[] = 1; // Arbeitsversion (1) hinzufügen, wenn das Versions-Plugin verfügbar ist
            }
            
            foreach ($slices as $slice) {
                $clangIds[$slice['clang_id']] = true;
                if (isset($slice['revision']) && $slice['revision'] > 0 && !in_array($slice['revision'], $revisions)) {
                    $revisions[] = $slice['revision'];
                }
            }
            
            if ($debug) {
                echo '<pre>Wiederherstellen von Slices für Artikel #' . $newArticleId . ' mit Sprachen: ' . implode(', ', array_keys($clangIds)) . ' und Revisionen: ' . implode(', ', $revisions) . '</pre>';
            }
            
            // Zähler für wiederhergestellte Slices
            $restoredSliceCount = 0;
            
            foreach (array_keys($clangIds) as $clangId) {
                foreach ($revisions as $revision) {
                    // Für jede Sprache und Revision die Slices wiederherstellen
                    $currentPriority = 1;
                    
                    foreach ($slices as $slice) {
                        // Nur die Slices der aktuellen Sprache bearbeiten
                        if ($slice['clang_id'] != $clangId) {
                            continue;
                        }
                        
                        // Wenn das Slice eine Revision hat und wir nach Revision filtern wollen
                        if (isset($slice['revision']) && $slice['revision'] != $revision) {
                            continue;
                        }
                        
                        try {
                            // Spalteninformationen der Slice-Tabelle abrufen (nur einmal)
                            static $sliceColumns = null;
                            if ($sliceColumns === null) {
                                $columnInfo = rex_sql::showColumns(rex::getTable('article_slice'));
                                $sliceColumns = [];
                                
                                // Vorhandene Spalten in ein Array überführen für schnelleren Zugriff
                                foreach ($columnInfo as $column) {
                                    $sliceColumns[$column['name']] = true;
                                }
                            }
                            
                            // Wichtig: Hier die neue Artikel-ID verwenden!
                            $params = [
                                'article_id' => $newArticleId,  // Die neue ID des wiederhergestellten Artikels
                                'clang_id' => $slice['clang_id'],
                                'ctype_id' => $slice['ctype_id'] ?: 1,
                                'module_id' => $slice['module_id'] ?: 0,
                                'revision' => $revision,
                                'priority' => $currentPriority++,
                                'createdate' => date('Y-m-d H:i:s'),
                                'createuser' => rex::getUser()->getLogin(),
                                'updatedate' => date('Y-m-d H:i:s'),
                                'updateuser' => rex::getUser()->getLogin()
                            ];
                            
                            // Status kopieren, falls vorhanden
                            if (isset($slice['status'])) {
                                $params['status'] = (int)$slice['status'];
                            }
                            
                            // Feldwerte aus dem Slice kopieren
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
                                    if (isset($slice[$field])) {
                                        $params[$field] = $slice[$field];
                                    }
                                }
                            }
                            
                            // SQL-Query aufbauen
                            $query = "INSERT INTO " . rex::getTable('article_slice') . " SET ";
                            $first = true;
                            
                            foreach ($params as $key => $value) {
                                if (isset($sliceColumns[$key])) {
                                    if (!$first) {
                                        $query .= ", ";
                                    }
                                    $query .= "`" . $key . "` = :" . $key;
                                    $first = false;
                                }
                            }
                            
                            if ($debug) {
                                echo '<pre>Slice-Insert Query: ' . $query . '</pre>';
                            }
                            
                            // Slice einfügen
                            $sliceSql = rex_sql::factory();
                            if ($debug) $sliceSql->setDebug();
                            $sliceSql->setQuery($query, $params);
                            $restoredSliceCount++;
                            
                            // Neue Slice-ID holen
                            $newSliceId = $sliceSql->getLastId();
                            
                            // Meta-Attribute aus der separaten Tabelle holen und anwenden
                            $metaSql = rex_sql::factory();
                            if ($debug) $metaSql->setDebug();
                            $metaSql->setQuery(
                                'SELECT meta_data FROM ' . $trashSliceMetaTable . ' WHERE trash_slice_id = :id',
                                ['id' => $slice['id']]
                            );
                            
                            if ($metaSql->getRows() === 1) {
                                $metaData = json_decode($metaSql->getValue('meta_data'), true);
                                
                                if (is_array($metaData) && !empty($metaData)) {
                                    // Update-Query für Meta-Attribute
                                    $updateQuery = "UPDATE " . rex::getTable('article_slice') . " SET ";
                                    $updateParams = [];
                                    $hasUpdates = false;
                                    $updateFirst = true;
                                    
                                    foreach ($metaData as $key => $value) {
                                        if (isset($sliceColumns[$key])) {
                                            if (!$updateFirst) {
                                                $updateQuery .= ", ";
                                            }
                                            $updateQuery .= "`" . $key . "` = :" . $key;
                                            $updateParams[$key] = $value;
                                            $hasUpdates = true;
                                            $updateFirst = false;
                                        }
                                    }
                                    
                                    if ($hasUpdates) {
                                        $updateQuery .= " WHERE id = :id";
                                        $updateParams['id'] = $newSliceId;
                                        
                                        if ($debug) {
                                            echo '<pre>Slice-Meta-Update Query: ' . $updateQuery . '</pre>';
                                        }
                                        
                                        $updateSql = rex_sql::factory();
                                        if ($debug) $updateSql->setDebug();
                                        $updateSql->setQuery($updateQuery, $updateParams);
                                    }
                                }
                            }
                        } catch (rex_sql_exception $e) {
                            rex_logger::logException($e);
                            if ($debug) {
                                echo '<pre>FEHLER beim Einfügen eines Slices: ' . $e->getMessage() . '</pre>';
                            }
                            // Weiter mit dem nächsten Slice, auch wenn der aktuelle fehlgeschlagen ist
                        }
                    }
                }
            }
            
            // Artikel aus dem Papierkorb entfernen
            $sql->setQuery('DELETE FROM ' . $trashTable . ' WHERE id = :id', ['id' => $articleId]);
            
            // Auch die Slices aus dem Papierkorb entfernen
            $sql->setQuery('DELETE FROM ' . $trashSliceTable . ' WHERE trash_article_id = :trash_id', ['trash_id' => $articleId]);
            
            // Auch die Slice-Meta-Daten aus dem Papierkorb entfernen
            // Wir müssen alle Slices holen, um deren IDs für die Meta-Daten zu finden
            $slice_ids = $sql->getArray('SELECT id FROM ' . $trashSliceTable . ' WHERE trash_article_id = :trash_id', ['trash_id' => $articleId]);
            if (!empty($slice_ids)) {
                foreach ($slice_ids as $slice) {
                    $sql->setQuery('DELETE FROM ' . $trashSliceMetaTable . ' WHERE trash_slice_id = :slice_id', ['slice_id' => $slice['id']]);
                }
            }
            
            // Cache löschen und neu generieren
            rex_article_cache::delete($newArticleId);
            
            // Wenn das Versions-Plugin vorhanden ist, Content generieren für beide Revisionen
            if (rex_plugin::get('structure', 'version')->isAvailable()) {
                foreach (array_keys($clangIds) as $clangId) {
                    rex_content_service::generateArticleContent($newArticleId, $clangId);
                }
            } else {
                // Sonst nur für die Live-Version
                foreach (array_keys($clangIds) as $clangId) {
                    rex_content_service::generateArticleContent($newArticleId, $clangId);
                }
            }
            
            // Bei Debug-Modus zusätzliche Info über wiederhergestellte Slices anzeigen
            if ($debug) {
                $message .= rex_view::info('Wiederhergestellte Slices: ' . $restoredSliceCount);
            }
        }
    } else {
        $message = rex_view::error(rex_i18n::msg('trash_article_not_found'));
    }
} elseif ($func === 'delete' && $articleId > 0) {
    // Artikel endgültig löschen
    
    $sql = rex_sql::factory();
    
    try {
        // Beginne eine Transaktion, damit entweder alles oder nichts gelöscht wird
        $sql->beginTransaction();
        
        // Alle Slice-IDs holen, um die Meta-Daten zu löschen
        $slice_ids = $sql->getArray('SELECT id FROM ' . $trashSliceTable . ' WHERE trash_article_id = :id', ['id' => $articleId]);
        
        // Für jede Slice-ID die Meta-Daten löschen
        foreach ($slice_ids as $slice) {
            $sql->setQuery('DELETE FROM ' . $trashSliceMetaTable . ' WHERE trash_slice_id = :slice_id', ['slice_id' => $slice['id']]);
        }
        
        // Dann die Slices entfernen
        $sql->setQuery('DELETE FROM ' . $trashSliceTable . ' WHERE trash_article_id = :id', ['id' => $articleId]);
        
        // Zuletzt den Artikel selbst
        $sql->setQuery('DELETE FROM ' . $trashTable . ' WHERE id = :id', ['id' => $articleId]);
        
        // Transaktion abschließen
        $sql->commit();
        
        $message = rex_view::success(rex_i18n::msg('trash_article_deleted'));
    } catch (Exception $e) {
        // Im Fehlerfall Transaktion zurückrollen
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        $message = rex_view::error(rex_i18n::msg('trash_delete_error') . ': ' . $e->getMessage());
        rex_logger::logException($e);
    }
} elseif ($func === 'empty') {
    // Papierkorb leeren
    $sql = rex_sql::factory();
    
    try {
        // Beginne eine Transaktion, damit entweder alles oder nichts gelöscht wird
        $sql->beginTransaction();
        
        // Zuerst alle Slice-Meta-Daten löschen
        $sql->setQuery('DELETE FROM ' . $trashSliceMetaTable);
        
        // Dann alle Slices entfernen
        $sql->setQuery('DELETE FROM ' . $trashSliceTable);
        
        // Zuletzt alle Artikel
        $sql->setQuery('DELETE FROM ' . $trashTable);
        
        // Transaktion abschließen
        $sql->commit();
        
        $message = rex_view::success(rex_i18n::msg('trash_emptied'));
    } catch (Exception $e) {
        // Im Fehlerfall Transaktion zurückrollen
        if ($sql->inTransaction()) {
            $sql->rollBack();
        }
        $message = rex_view::error(rex_i18n::msg('trash_empty_error') . ': ' . $e->getMessage());
        rex_logger::logException($e);
    }
}


// Ausgabe der Liste der Artikel im Papierkorb
echo $message;

// SQL-Query, der Sprachen zusammengefasst darstellt
$sql = 'SELECT a.*, 
        COUNT(s.id) as slice_count,
        GROUP_CONCAT(DISTINCT s.clang_id) as languages
        FROM ' . $trashTable . ' a 
        LEFT JOIN ' . $trashSliceTable . ' s 
        ON a.id = s.trash_article_id 
        GROUP BY a.id 
        ORDER BY a.deleted_at DESC';

$list = rex_list::factory($sql);
$list->addTableAttribute('class', 'table-striped');

// Spalten definieren - nur die wichtigsten behalten
$list->removeColumn('id');
$list->removeColumn('meta_attributes');
$list->removeColumn('path');
$list->removeColumn('createdate');
$list->removeColumn('updatedate');
$list->removeColumn('createuser');
$list->removeColumn('updateuser');
$list->removeColumn('revision');
$list->removeColumn('template_id');

// Die Originalspalten behalten, aber ausblenden
// - diese werden später referenziert
$list->setColumnLabel('article_id', rex_i18n::msg('trash_original_id'));
$list->setColumnLabel('name', rex_i18n::msg('trash_article_name'));
$list->setColumnLabel('catname', rex_i18n::msg('trash_category_name'));
$list->setColumnLabel('parent_id', rex_i18n::msg('trash_parent_id'));
$list->setColumnLabel('languages', rex_i18n::msg('trash_languages'));
$list->setColumnLabel('deleted_at', rex_i18n::msg('trash_deleted_at'));

// Eine einfachere Herangehensweise:
// 1. Typ als normaler Text mit Symbolen
$list->addColumn('typ', 'Artikeltyp', -1);
$list->setColumnFormat('typ', 'custom', function ($params) {
    $startArticle = $params['list']->getValue('startarticle');
    $status = $params['list']->getValue('status');
    
    $type = $startArticle == 1 ? 'Kategorie' : 'Artikel';
    $statusText = $status == 1 ? 'Online' : 'Offline';
    
    $icon = $startArticle == 1 ? 
        '<i class="rex-icon rex-icon-category"></i>' : 
        '<i class="rex-icon rex-icon-article"></i>';
    
    $statusIcon = $status == 1 ? 
        '<span class="text-success"><i class="rex-icon rex-icon-online"></i></span>' : 
        '<span class="text-danger"><i class="rex-icon rex-icon-offline"></i></span>';
    
    return $icon . ' ' . $statusIcon . '<br>' . $type . ' (' . $statusText . ')';
});

// 2. Infospalte mit Details
$list->addColumn('details', 'Details', -1);
$list->setColumnFormat('details', 'custom', function ($params) {
    $startArticle = $params['list']->getValue('startarticle');
    $output = [];
    
    // Priorität hinzufügen je nach Artikeltyp
    if ($startArticle == 1) {
        $output[] = 'Kat-Prio: ' . $params['list']->getValue('catpriority');
    } else {
        $output[] = 'Prio: ' . $params['list']->getValue('priority');
    }
    
    // Template-ID hinzufügen
    $output[] = 'Template: ' . $params['list']->getValue('template_id');
    
    // Weitere Infos
    $output[] = 'Original-ID: ' . $params['list']->getValue('article_id');
    $output[] = 'Slices: ' . $params['list']->getValue('slice_count');
    
    return implode('<br>', $output);
});

// Formatierungen für verbleibende Spalten
$list->setColumnFormat('deleted_at', 'date', 'd.m.Y H:i');
$list->setColumnFormat('languages', 'custom', function($params) {
    if (!$params['value']) {
        return rex_i18n::msg('trash_no_languages');
    }
    $langIds = explode(',', $params['value']);
    $names = [];
    foreach ($langIds as $id) {
        $clang = rex_clang::get((int)$id);
        $names[] = $clang ? $clang->getName() : 'Unbekannt';
    }
    return implode(', ', $names);
});

// Verstecke nicht benötigte Spalten
$list->removeColumn('status');
$list->removeColumn('startarticle');
$list->removeColumn('slice_count');
$list->removeColumn('article_id');
$list->removeColumn('catpriority');
$list->removeColumn('priority');

// Aktionsspalten hinzufügen
$list->addColumn('restore', '<i class="rex-icon rex-icon-refresh"></i> ' . rex_i18n::msg('trash_restore'));
$list->setColumnParams('restore', ['func' => 'restore', 'id' => '###id###']);

$list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('trash_delete'));
$list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###']);
$list->addLinkAttribute('delete', 'data-confirm', rex_i18n::msg('trash_confirm_delete'));

// Keine Einträge Meldung
$list->setNoRowsMessage(rex_i18n::msg('trash_is_empty'));

// Ausgabe der Liste
$content = $list->get();

// Buttons für Aktionen über der Liste
$buttons = '
<div class="row">
    <div class="col-sm-12">
        <div class="pull-right">
            <a href="' . rex_url::currentBackendPage(['func' => 'empty']) . '" class="btn btn-danger" data-confirm="' . rex_i18n::msg('trash_confirm_empty_trash') . '">
                <i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('trash_empty_trash') . '
            </a>
        </div>
    </div>
</div>';

// Ausgabe des Inhalts
$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('trash'), false);
$fragment->setVar('content', $content, false);
$fragment->setVar('options', $buttons, false);
echo $fragment->parse('core/page/section.php');
