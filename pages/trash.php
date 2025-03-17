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

// Meldungen initialisieren
$message = '';

// Aktionen verarbeiten
if ($func === 'restore' && $articleId > 0) {
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
                $message = rex_view::error(rex_i18n::msg('restore_error') . ': ' . $e->getMessage());
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
                $articleSql->setValue('createdate', $attributes['createdate'] ?? date('Y-m-d H:i:s'));
                $articleSql->setValue('createuser', $attributes['createuser'] ?? rex::getUser()->getLogin());
                $articleSql->setValue('updatedate', date('Y-m-d H:i:s'));
                $articleSql->setValue('updateuser', rex::getUser()->getLogin());
                
                // Für jede Sprache einen Eintrag erstellen
                foreach (rex_clang::getAllIds() as $clangId) {
                    $articleSql->setValue('clang_id', $clangId);
                    $articleSql->insert();
                }
                
                $success = true;
            } catch (Exception $e) {
                $message = rex_view::error(rex_i18n::msg('restore_error') . ': ' . $e->getMessage());
                $newArticleId = null;
                $success = false;
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
                        
                        $sliceSql = rex_sql::factory();
                        $sliceSql->setTable(rex::getTable('article_slice'));
                        
                        // Alle wichtigen Slice-Daten kopieren
                        foreach ($slice as $key => $value) {
                            // Diese Felder nicht kopieren
                            if (in_array($key, ['id', 'trash_article_id', 'article_id'])) {
                                continue;
                            }
                            $sliceSql->setValue($key, $value);
                        }
                        
                        // Neuen Artikel-ID setzen
                        $sliceSql->setValue('article_id', $newArticleId);
                        
                        // Revision setzen
                        $sliceSql->setValue('revision', $revision);
                        
                        // Die richtige Priorität setzen
                        $sliceSql->setValue('priority', $currentPriority++);
                        
                        // Weitere Daten ergänzen
                        $sliceSql->addGlobalCreateFields();
                        $sliceSql->addGlobalUpdateFields();
                        
                        $sliceSql->insert();
                    }
                }
            }
            
            // Artikel aus dem Papierkorb entfernen
            $sql->setQuery('DELETE FROM ' . $trashTable . ' WHERE id = :id', ['id' => $articleId]);
            
            // Auch die Slices aus dem Papierkorb entfernen
            $sql->setQuery('DELETE FROM ' . $trashSliceTable . ' WHERE trash_article_id = :trash_id', ['trash_id' => $articleId]);
            
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
} elseif ($func === 'delete' && $articleId > 0) {
    // Artikel endgültig löschen
    
    // Zuerst die Slices entfernen
    $sql = rex_sql::factory();
    
    try {
        // Beginne eine Transaktion, damit entweder alles oder nichts gelöscht wird
        $sql->beginTransaction();
        
        // Zuerst die Slices entfernen
        $sql->setQuery('DELETE FROM ' . $trashSliceTable . ' WHERE trash_article_id = :id', ['id' => $articleId]);
        
        // Dann den Artikel selbst
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
        
        // Zuerst alle Slices entfernen
        $sql->setQuery('DELETE FROM ' . $trashSliceTable);
        
        // Dann alle Artikel
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
