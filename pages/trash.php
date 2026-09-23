<?php
/**
 * Trash AddOn - Main backend page
 * 
 * @package redaxo\trash
 */

use FriendsOfREDAXO\trash\TrashService;

// Rechteprüfung
$user = rex::getUser();
if (null === $user || !$user->isAdmin()) {
    // Nur Admins dürfen auf den Papierkorb zugreifen
    echo rex_view::error(rex_i18n::msg('no_permission'));
    return;
}

// TrashService initialisieren
$trashService = new TrashService();

// Durchführung von Aktionen (Wiederherstellen oder Endgültig löschen)
$func = rex_request('func', 'string');
$articleId = rex_request('id', 'int');

// Tabellennamen für Anzeige
$tables = $trashService->getTableNames();
$trashTable = $tables['trash_article'];
$trashSliceTable = $tables['trash_slice'];
$trashSliceMetaTable = $tables['trash_slice_meta'];

// Meldungen initialisieren
$message = '';

// Debug-Modus zum Anzeigen detaillierter Fehlermeldungen

// Aktionen verarbeiten
if ($func === 'restore' && $articleId > 0) {
    // Use TrashService to restore article
    list($success, $resultMessage, $idChanged, $originalRequestedId, $newArticleId, $parentExists, $priorityChangedInfo) = $trashService->restoreArticle($articleId);
    
    if ($success) {
        // Check if ID was changed
        if ($idChanged) {
            $message = rex_view::success(rex_i18n::msg('trash_article_restored'));
            $message .= rex_view::info(rex_i18n::msg('trash_article_restored_with_new_id', $originalRequestedId, $newArticleId));
        } else {
            $message = rex_view::success(rex_i18n::msg('trash_article_restored'));
        }
        
        // Show priority change info if available
        if (!empty($priorityChangedInfo)) {
            $message .= $priorityChangedInfo;
        }
        
        // Show parent category warning if necessary
        if (!$parentExists) {
            $message .= rex_view::warning(rex_i18n::msg('trash_parent_category_missing'));
        }
        
        // Delete from trash after successful restoration
        list($deleteSuccess, $deleteMessage) = $trashService->deleteArticlePermanently($articleId);
        if (!$deleteSuccess) {
            $message .= rex_view::warning('Artikel wiederhergestellt, konnte aber nicht aus Papierkorb entfernt werden: ' . $deleteMessage);
        }
    } else {
        $message = rex_view::error(rex_i18n::msg('trash_restore_error') . ': ' . rex_escape($resultMessage));
    }
} elseif ($func === 'delete' && $articleId > 0) {
    // Use TrashService to permanently delete article
    list($success, $resultMessage) = $trashService->deleteArticlePermanently($articleId);
    
    if ($success) {
        $message = rex_view::success(rex_i18n::msg('trash_article_deleted'));
    } else {
        $message = rex_view::error(rex_i18n::msg('trash_delete_error') . ': ' . $resultMessage);
    }
} elseif ($func === 'empty') {
    // Use TrashService to empty trash
    list($success, $resultMessage) = $trashService->emptyTrash();
    
    if ($success) {
        $message = rex_view::success(rex_i18n::msg('trash_emptied'));
    } else {
        $message = rex_view::error(rex_i18n::msg('trash_empty_error') . ': ' . $resultMessage);
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
$list->setColumnLabel('deleted_by', rex_i18n::msg('trash_deleted_by'));

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
