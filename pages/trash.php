<?php
/**
 * Trash AddOn - Main backend page
 * Korrigierte Version, die mit dem Version-Plugin und anderen Artikeln kompatibel ist
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
 * Direkte Artikel-Einfügung ohne die Service-Klassen zu nutzen
 * Diese Version vermeidet Probleme mit dem Debug-Modus
 * 
 * @param array $articleData Die Daten für den Artikel
 * @return array [success, articleId, errorMessage]
 */
function insertArticleDirectly($articleData) {
    global $debug;
    
    try {
        // Tabellennamen direkt verwenden statt getTable() aufzurufen
        $tableName = rex::getTablePrefix() . 'article';
        if (empty($tableName)) {
            return [false, null, "Tabellenname ist leer."];
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
                if ($insertedId == 0) {
                    $insertedId = $articleSql->getLastId();
                }
            }
        }
        
        return [true, $insertedId];
    } catch (Exception $e) {
        rex_logger::logException($e);
        return [false, null, $e->getMessage()];
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
        $attributes = json_decode($sql->getValue('attributes'), true);
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
        
        $success = false;
        $newArticleId = null;
        
        // Prüfen, ob an der Stelle der Original-ID bereits ein anderer Artikel existiert
        $existingArticle = rex_article::get($original_id);
        
        if ($existingArticle) {
            // Wir können den Artikel nicht unter seiner ursprünglichen ID wiederherstellen
            // Also erstellen wir einen neuen mit Auto-Increment
            
            // Artikel-Daten vorbereiten
            $articleData = [
                'id' => 0, // Auto-Increment verwenden
                'parent_id' => $parent_id,
                'name' => $name,
                'catname' => $catname,
                'catpriority' => $catpriority,
                'priority' => $attributes['priority'] ?? 1,
                'path' => $attributes['path'] ?? '|',
                'status' => $status,
                'template_id' => $attributes['template_id'] ?? 1,
                'startarticle' => $startarticle,
                'createdate' => $attributes['createdate'] ?? date('Y-m-d H:i:s'),
                'createuser' => $attributes['createuser'] ?? rex::getUser()->getLogin(),
                'updatedate' => date('Y-m-d H:i:s'),
                'updateuser' => rex::getUser()->getLogin()
            ];
            
            // Revision auf 0 setzen, wenn das Version-Plugin verwendet wird
            if (rex_plugin::get('structure', 'version')->isAvailable()) {
                $articleData['revision'] = 0;
            }
            
            if ($debug) {
                echo '<pre>Versuche neuen Artikel zu erstellen: ' . print_r($articleData, true) . '</pre>';
            }
            
            // Direkte Einfügung in die Datenbank
            list($success, $newArticleId, $errorMessage) = insertArticleDirectly($articleData);
            
            if (!$success) {
                $message = rex_view::error(rex_i18n::msg('trash_restore_error') . ': ' . $errorMessage);
                if ($debug) {
                    echo '<pre>FEHLER beim Erstellen des Artikels: ' . $errorMessage . '</pre>';
                }
            }
        } else {
            // Der Original-Artikel existiert nicht mehr, wir können versuchen ihn mit der ursprünglichen ID wiederherzustellen
            
            // Artikel-Daten vorbereiten
            $articleData = [
                'id' => $original_id,
                'parent_id' => $parent_id,
                'name' => $name,
                'catname' => $catname,
                'catpriority' => $catpriority,
                'priority' => $attributes['priority'] ?? 1,
                'path' => $attributes['path'] ?? '|',
                'status' => $status,
                'template_id' => $attributes['template_id'] ?? 1,
                'startarticle' => $startarticle,
                'createdate' => $attributes['createdate'] ?? date('Y-m-d H:i:s'),
                'createuser' => $attributes['createuser'] ?? rex::getUser()->getLogin(),
                'updatedate' => date('Y-m-d H:i:s'),
                'updateuser' => rex::getUser()->getLogin()
            ];
            
            // Wichtig: Revision auf 0 setzen, wenn das Version-Plugin verwendet wird
            if (rex_plugin::get('structure', 'version')->isAvailable()) {
                $articleData['revision'] = 0;
            }
            
            if ($debug) {
                echo '<pre>Versuche Original-Artikel wiederherzustellen mit ID ' . $original_id . ': ' . print_r($articleData, true) . '</pre>';
            }
            
            // Direkte Einfügung in die Datenbank
            list($success, $newArticleId, $errorMessage) = insertArticleDirectly($articleData);
            
            if (!$success) {
                $message = rex_view::error(rex_i18n::msg('trash_restore_error') . ': ' . $errorMessage);
                
                if ($debug) {
                    echo '<pre>FEHLER beim Wiederherstellen mit Original-ID: ' . $errorMessage . '</pre>';
                }
                
                // Fallback: Versuchen, einen neuen Artikel mit Auto-Increment zu erstellen
                $articleData['id'] = 0; // Auto-Increment verwenden
                list($success, $newArticleId, $errorMessage) = insertArticleDirectly($articleData);
                
                if (!$success) {
                    if ($debug) {
                        echo '<pre>FEHLER beim Fallback: ' . $errorMessage . '</pre>';
                    }
                    $newArticleId = null;
                    $message .= rex_view::error('Fallback-Versuch fehlgeschlagen: ' . $errorMessage);
                }
            }
        }
        
        if ($newArticleId) {
            // Meta-Attribute wiederherstellen
            if ($metaAttributes) {
                try {
                    // Spalteninformationen der Artikeltabelle abrufen
                    $columnInfo = rex_sql::showColumns(rex::getTable('article'));
                    $existingColumns = [];
                    
                    // Vorhandene Spalten in ein Array überführen für schnelleren Zugriff
                    foreach ($columnInfo as $column) {
                        $existingColumns[$column['name']] = true;
                    }
                    
                    $metaSql = rex_sql::factory();
                    if ($debug) $metaSql->setDebug();
                    
                    foreach (rex_clang::getAllIds() as $clangId) {
                        // Für jede Sprache die Meta-Attribute setzen
                        
                        // Query manuell aufbauen für mehr Kontrolle
                        $query = "UPDATE " . rex::getTable('article') . " SET ";
                        $params = [];
                        $hasValues = false;
                        
                        foreach ($metaAttributes as $key => $value) {
                            // Prüfen ob die Spalte existiert (AddOn könnte deinstalliert worden sein)
                            if (isset($existingColumns[$key])) {
                                if ($hasValues) {
                                    $query .= ", ";
                                }
                                $query .= "`" . $key . "` = :" . $key;
                                $params[$key] = $value;
                                $hasValues = true;
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
                            
                            // Erstelle einen SQL-Query für INSERT
                            $query = "INSERT INTO " . rex::getTable('article_slice') . " SET ";
                            $params = [
                                'article_id' => $newArticleId,
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
                            $firstParam = true;
                            foreach ($params as $key => $value) {
                                if (isset($sliceColumns[$key])) {
                                    if (!$firstParam) {
                                        $query .= ", ";
                                    }
                                    $query .= "`" . $key . "` = :" . $key;
                                    $firstParam = false;
                                }
                            }
                            
                            if ($debug) {
                                echo '<pre>Slice-Insert Query: ' . $query . '</pre>';
                            }
                            
                            // Slice einfügen
                            $sliceSql = rex_sql::factory();
                            if ($debug) $sliceSql->setDebug();
                            $sliceSql->setQuery($query, $params);
                            
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
                                    $firstUpdate = true;
                                    
                                    foreach ($metaData as $key => $value) {
                                        if (isset($sliceColumns[$key])) {
                                            if (!$firstUpdate) {
                                                $updateQuery .= ", ";
                                            }
                                            $updateQuery .= "`" . $key . "` = :" . $key;
                                            $updateParams[$key] = $value;
                                            $hasUpdates = true;
                                            $firstUpdate = false;
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
            
            // Erfolgsmeldung anzeigen
            if (empty($message)) {
                $message = rex_view::success(rex_i18n::msg('trash_article_restored'));
            }
            
            if (!$parentExists) {
                $message .= rex_view::warning(rex_i18n::msg('trash_parent_category_missing'));
            }
            if ($newArticleId != $original_id) {
                $message .= rex_view::info(rex_i18n::msg('trash_article_restored_with_new_id', $original_id, $newArticleId));
            }
        } else {
            if (empty($message)) {
                $message = rex_view::error(rex_i18n::msg('trash_restore_error'));
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

// Spalten definieren
$list->removeColumn('id');
$list->removeColumn('attributes');
$list->removeColumn('meta_attributes');
$list->setColumnLabel('article_id', rex_i18n::msg('trash_original_id'));
$list->setColumnLabel('name', rex_i18n::msg('trash_article_name'));
$list->setColumnLabel('catname', rex_i18n::msg('trash_category_name'));
$list->setColumnLabel('parent_id', rex_i18n::msg('trash_parent_id'));
$list->setColumnLabel('languages', rex_i18n::msg('trash_languages'));
$list->setColumnLabel('deleted_at', rex_i18n::msg('trash_deleted_at'));
$list->setColumnLabel('startarticle', rex_i18n::msg('trash_is_startarticle'));
$list->setColumnLabel('slice_count', rex_i18n::msg('trash_slice_count'));

// Formatierungen
$list->setColumnFormat('deleted_at', 'date', 'd.m.Y H:i');
$list->setColumnFormat('startarticle', 'custom', function($params) {
    return $params['value'] == 1 ? 'Ja' : 'Nein';
});
$list->setColumnFormat('status', 'custom', function($params) {
    return $params['value'] == 1 ? 'Online' : 'Offline';
});
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

// Aktionen definieren
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
