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

// Aktionen verarbeiten
if ($func === 'restore' && $articleId > 0) {
    try {
        // Loggen des Wiederherstellungsversuchs
        rex_logger::factory()->info('Trash: Versuch, Artikel mit ID ' . $articleId . ' wiederherzustellen');
        
        // Artikel-Daten aus dem Papierkorb holen
        $sql = rex_sql::factory();
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
            
            // Debug-Ausgabe der wichtigsten Artikel-Eigenschaften
            rex_logger::factory()->info('Trash: Eigenschaften von Artikel #' . $articleId . ': ' .
                'original_id=' . $original_id . ', ' .
                'parent_id=' . $parent_id . ', ' .
                'name=' . $name . ', ' .
                'startarticle=' . $startarticle
            );
            
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
            
            // Prüfen, ob an der Stelle der Original-ID bereits ein anderer Artikel existiert
            $existingArticle = rex_article::get($original_id);
            if ($existingArticle) {
                // Wir können den Artikel nicht unter seiner ursprünglichen ID wiederherstellen
                // Stattdessen müssen wir einen neuen Artikel erstellen
                
                // Artikel neu erstellen
                $articleData = [
                    'name' => $name,
                    'catname' => $catname,
                    'catpriority' => $catpriority,
                    'priority' => $attributes['priority'] ?? 1,
                    'template_id' => $attributes['template_id'] ?? 1,
                    'status' => $status,
                    'category_id' => $parent_id
                ];
                
                // Artikel erstellen
                $newArticleId = null;
                
                try {
                    // Je nachdem, ob es ein Startartikel war oder ein normaler Artikel
                    if ($startarticle == 1) {
                        // Es war ein Startartikel / eine Kategorie
                        $success = rex_category_service::addCategory($parent_id, $articleData);
                        if ($success) {
                            // ID des neuen Artikels ermitteln
                            $latestSql = rex_sql::factory();
                            $latestSql->setQuery('SELECT id FROM ' . rex::getTable('article') . ' 
                                                WHERE name = :name AND parent_id = :parent_id AND startarticle = 1
                                                ORDER BY id DESC LIMIT 1', 
                                                ['name' => $name, 'parent_id' => $parent_id]);
                            if ($latestSql->getRows() === 1) {
                                $newArticleId = $latestSql->getValue('id');
                            }
                        }
                    } else {
                        // Es war ein normaler Artikel
                        $success = rex_article_service::addArticle($articleData);
                        if ($success) {
                            // ID des neuen Artikels ermitteln
                            $latestSql = rex_sql::factory();
                            $latestSql->setQuery('SELECT id FROM ' . rex::getTable('article') . ' 
                                                WHERE name = :name AND parent_id = :parent_id AND startarticle = 0
                                                ORDER BY id DESC LIMIT 1', 
                                                ['name' => $name, 'parent_id' => $parent_id]);
                            if ($latestSql->getRows() === 1) {
                                $newArticleId = $latestSql->getValue('id');
                            }
                        }
                    }
                } catch (Exception $e) {
                    $message = rex_view::error(rex_i18n::msg('trash_restore_error') . ': ' . $e->getMessage());
                    $newArticleId = null;
                }
            } else {
                // Der Original-Artikel existiert nicht mehr, wir können ihn mit der ursprünglichen ID wiederherstellen
                $newArticleId = $original_id;
                
                try {
                    // Artikel direkt mit Original-ID in die Datenbank einfügen
                    $articleSql = rex_sql::factory();
                    $articleSql->setTable(rex::getTable('article'));
                    $articleSql->setValue('id', $original_id);
                    $articleSql->setValue('parent_id', $parent_id);
                    $articleSql->setValue('name', $name);
                    $articleSql->setValue('catname', $catname);
                    $articleSql->setValue('catpriority', $catpriority);
                    $articleSql->setValue('priority', $attributes['priority'] ?? 1);
                    $articleSql->setValue('path', $attributes['path'] ?? '|');
                    $articleSql->setValue('status', $status);
                    $articleSql->setValue('template_id', $attributes['template_id'] ?? 1);
                    $articleSql->setValue('startarticle', $startarticle);
                    
                    // Korrekte Formatierung der Datumsfelder sicherstellen
                    $articleSql->setValue('createdate', formatDateIfNeeded($attributes['createdate'] ?? date('Y-m-d H:i:s')));
                    $articleSql->setValue('createuser', $attributes['createuser'] ?? rex::getUser()->getLogin());
                    $articleSql->setValue('updatedate', formatDateIfNeeded(date('Y-m-d H:i:s')));
                    $articleSql->setValue('updateuser', rex::getUser()->getLogin());
                    
                    // Für jede Sprache einen Eintrag erstellen
                    foreach (rex_clang::getAllIds() as $clangId) {
                        $articleSql->setValue('clang_id', $clangId);
                        $articleSql->insert();
                    }
                    
                    $success = true;
                } catch (Exception $e) {
                    $message = rex_view::error(rex_i18n::msg('trash_restore_error') . ': ' . $e->getMessage());
                    $newArticleId = null;
                    $success = false;
                }
            }
            
            if ($newArticleId) {
                // Sicherstellen, dass jede Tabelle existiert und zugänglich ist
                try {
                    $check = rex_sql::factory();
                    $check->setQuery('SHOW TABLES LIKE :table', ['table' => rex::getTable('article')]);
                    if ($check->getRows() === 0) {
                        throw new Exception('Die Artikel-Tabelle existiert nicht oder ist nicht zugänglich.');
                    }
                    
                    $check->setQuery('SHOW TABLES LIKE :table', ['table' => rex::getTable('article_slice')]);
                    if ($check->getRows() === 0) {
                        throw new Exception('Die Slice-Tabelle existiert nicht oder ist nicht zugänglich.');
                    }
                } catch (Exception $e) {
                    rex_logger::logException($e);
                    $message = rex_view::error('Fehler beim Überprüfen der Tabellen: ' . $e->getMessage());
                }
                
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
                        $metaSql->setTable(rex::getTable('article'));
                        
                        foreach (rex_clang::getAllIds() as $clangId) {
                            // Für jede Sprache die Meta-Attribute setzen
                            $metaSql->setWhere('id = :id AND clang_id = :clang_id', ['id' => $newArticleId, 'clang_id' => $clangId]);
                            
                            $hasValues = false;
                            foreach ($metaAttributes as $key => $value) {
                                // Prüfen ob die Spalte existiert (AddOn könnte deinstalliert worden sein)
                                if (isset($existingColumns[$key])) {
                                    $metaSql->setValue($key, $value);
                                    $hasValues = true;
                                }
                            }
                            
                            if ($hasValues) {
                                $metaSql->update();
                            }
                        }
                    } catch (Exception $e) {
                        // Fehler beim Wiederherstellen der Meta-Attribute loggen, aber den Prozess fortsetzen
                        rex_logger::logException($e);
                    }
                }
                
                // Jetzt die Slices wiederherstellen, gruppiert nach Sprache und Revision
                $slicesSql = rex_sql::factory();
                $slicesSql->setQuery('SELECT * FROM ' . $trashSliceTable . ' WHERE trash_article_id = :trash_id ORDER BY clang_id, revision, priority', ['trash_id' => $articleId]);
                $slices = $slicesSql->getArray();
                
                // Eine Liste aller Sprachen, in denen Slices existieren
                $clangIds = [];
                $revisions = [];
                
                // Alle verfügbaren Revisions und Sprachen erfassen
                foreach ($slices as $slice) {
                    $clangIds[$slice['clang_id']] = true;
                    $revisions[$slice['revision']] = true;
                }
                
                // Sicherstellen, dass Live-Version (0) immer vorhanden ist
                $revisions[0] = true;
                
                // Wenn das Versions-Plugin verfügbar ist, auch die Arbeitsversion (1) hinzufügen
                if (rex_plugin::get('structure', 'version')->isAvailable()) {
                    $revisions[1] = true;
                }
                
                // Slices nach Sprache und Revision wiederherstellen
                foreach (array_keys($clangIds) as $clangId) {
                    foreach (array_keys($revisions) as $revision) {
                        // Priorität für jede Kombination aus Sprache und Revision neu beginnen
                        $currentPriority = 1;
                        
                        // Alle relevanten Slices für diese Sprache und Revision sammeln
                        $revisionSlices = [];
                        foreach ($slices as $slice) {
                            // Nur Slices für aktuelle Sprache und Revision berücksichtigen
                            if ((int)$slice['clang_id'] === (int)$clangId && 
                                (int)$slice['revision'] === (int)$revision) {
                                $revisionSlices[] = $slice;
                            }
                        }
                        
                        // Slices nach Priorität sortieren
                        usort($revisionSlices, function($a, $b) {
                            return (int)$a['priority'] - (int)$b['priority'];
                        });
                        
                        // Slices für aktuelle Sprache und Revision wiederherstellen
                        foreach ($revisionSlices as $slice) {
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
                                
                                // Erstelle ein Set von SQL-Wertepaaren für die INSERT-Query
                                $insertData = [
                                    'article_id' => $newArticleId,
                                    'clang_id' => $slice['clang_id'],
                                    'ctype_id' => $slice['ctype_id'] ?: 1,
                                    'module_id' => $slice['module_id'] ?: 0,
                                    'revision' => $revision,  // Explizit die aktuelle Revision verwenden
                                    'priority' => $currentPriority++,
                                    'createdate' => formatDateIfNeeded(date('Y-m-d H:i:s')),
                                    'createuser' => rex::getUser()->getLogin(),
                                    'updatedate' => formatDateIfNeeded(date('Y-m-d H:i:s')),
                                    'updateuser' => rex::getUser()->getLogin()
                                ];
                                
                                // Status kopieren, falls vorhanden
                                if (isset($slice['status'])) {
                                    $insertData['status'] = (int)$slice['status'];
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
                                            $insertData[$field] = $slice[$field];
                                        }
                                    }
                                }
                                
                                // Neues SQL-Objekt erstellen und nur mit gültigen Spalten befüllen
                                $sliceSql = rex_sql::factory();
                                $sliceSql->setTable(rex::getTable('article_slice'));
                                
                                // Nur die Spalten setzen, die in der Zieltabelle existieren
                                foreach ($insertData as $key => $value) {
                                    if (isset($sliceColumns[$key])) {
                                        $sliceSql->setValue($key, $value);
                                    }
                                }
                                
                                // Slice einfügen
                                $sliceSql->insert();
                                
                                // Neue Slice-ID holen
                                $newSliceId = $sliceSql->getLastId();
                                
                                // Meta-Attribute aus der separaten Tabelle holen und anwenden
                                $metaSql = rex_sql::factory();
                                $metaSql->setQuery(
                                    'SELECT meta_data FROM ' . $trashSliceMetaTable . ' WHERE trash_slice_id = :id',
                                    ['id' => $slice['id']]
                                );
                                
                                if ($metaSql->getRows() === 1) {
                                    $metaData = json_decode($metaSql->getValue('meta_data'), true);
                                    
                                    if (is_array($metaData) && !empty($metaData)) {
                                        $updateSql = rex_sql::factory();
                                        $updateSql->setTable(rex::getTable('article_slice'));
                                        $updateSql->setWhere('id = :id', ['id' => $newSliceId]);
                                        
                                        $hasUpdates = false;
                                        foreach ($metaData as $key => $value) {
                                            if (isset($sliceColumns[$key])) {
                                                $updateSql->setValue($key, $value);
                                                $hasUpdates = true;
                                            }
                                        }
                                        
                                        if ($hasUpdates) {
                                            $updateSql->update();
                                        }
                                    }
                                }
                            } catch (rex_sql_exception $e) {
                                rex_logger::logException($e);
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
                
                try {
                    // Cache löschen und neu generieren
                    rex_article_cache::delete($newArticleId);
                    
                    // Content generieren für alle relevanten Revisionen und Sprachen
                    if (rex_plugin::get('structure', 'version')->isAvailable()) {
                        foreach (array_keys($clangIds) as $clangId) {
                            // Explizit den Content für die Live-Version generieren
                            rex_content_service::generateArticleContent($newArticleId, $clangId, 0);
                            // Und explizit den Content für die Arbeitsversion generieren
                            rex_content_service::generateArticleContent($newArticleId, $clangId, 1);
                        }
                    } else {
                        // Sonst nur für die Live-Version
                        foreach (array_keys($clangIds) as $clangId) {
                            rex_content_service::generateArticleContent($newArticleId, $clangId);
                        }
                    }
                } catch (Exception $e) {
                    rex_logger::logException($e);
                    $message .= rex_view::warning('Der Artikel wurde wiederhergestellt, aber es gab ein Problem bei der Content-Generierung: ' . $e->getMessage());
                }
                
                // Erfolgsmeldung anzeigen
                $message = rex_view::success(rex_i18n::msg('trash_article_restored'));
                if (!$parentExists) {
                    $message .= rex_view::warning(rex_i18n::msg('trash_parent_category_missing'));
                }
                if ($newArticleId != $original_id) {
                    $message .= rex_view::info(rex_i18n::msg('trash_article_restored_with_new_id', $original_id, $newArticleId));
                }
            } else {
                $message = rex_view::error(rex_i18n::msg('trash_restore_error'));
            }
        } else {
            $message = rex_view::error(rex_i18n::msg('trash_article_not_found'));
        }
    } catch (Exception $e) {
        // Detaillierte Fehlerprotokollierung
        rex_logger::logException($e);
        $message = rex_view::error(rex_i18n::msg('trash_restore_error') . ': ' . $e->getMessage());
        
        // Zusätzliche Fehlermeldung für den Benutzer
        $message .= rex_view::error('Bitte kontaktieren Sie den Administrator und teilen Sie mit, dass ein Fehler beim Wiederherstellen eines Artikels aufgetreten ist. Die Fehlerdetails wurden im System-Log gespeichert.');
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
