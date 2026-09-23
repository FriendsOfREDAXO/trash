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

// Ein Artikel wird immer in allen Sprachen geloescht. Die Spalte "Inhalte in"
// zeigt deshalb nicht "in welcher Sprache geloescht", sondern in welchen
// Sprachen ueberhaupt Inhalte gesichert wurden - Kategorien haben keine.
$sql = 'SELECT a.*,
        COUNT(s.id) as slice_count,
        GROUP_CONCAT(DISTINCT s.clang_id) as languages
        FROM ' . $trashTable . ' a
        LEFT JOIN ' . $trashSliceTable . ' s
        ON a.id = s.trash_article_id
        GROUP BY a.id
        ORDER BY a.deleted_at DESC';

$list = rex_list::factory($sql);
$list->addTableAttribute('class', 'table-hover');

// Technische Spalten braucht niemand auf einen Blick; das Wesentliche steht
// als Zusatzzeile unter dem Namen.
foreach ([
    'id', 'meta_attributes', 'path', 'createdate', 'updatedate', 'createuser',
    'updateuser', 'revision', 'template_id', 'parent_id', 'catpriority', 'priority',
] as $column) {
    $list->removeColumn($column);
}

// Symbol: Artikel oder Kategorie, online oder offline
$list->addColumn('icon', '', 0);
$list->setColumnLabel('icon', '');
$list->setColumnFormat('icon', 'custom', static function ($params) {
    $isCategory = 1 == $params['list']->getValue('startarticle');
    $isOnline = 1 == $params['list']->getValue('status');

    $title = rex_i18n::msg($isCategory ? 'trash_type_category' : 'trash_type_article')
        . ' – ' . rex_i18n::msg($isOnline ? 'trash_state_online' : 'trash_state_offline');

    return '<i class="rex-icon ' . ($isCategory ? 'rex-icon-category' : 'rex-icon-article')
        . ' ' . ($isOnline ? 'text-success' : 'text-muted') . '" title="' . rex_escape($title) . '"></i>';
});

// Name mit Zusatzinfos darunter - die Zeile bleibt schmal, die Details
// sind trotzdem da, wenn man sie braucht.
$list->setColumnLabel('name', rex_i18n::msg('trash_article_name'));
$list->setColumnFormat('name', 'custom', static function ($params) {
    $isCategory = 1 == $params['list']->getValue('startarticle');
    $name = (string) ($isCategory ? $params['list']->getValue('catname') : $params['list']->getValue('name'));

    $meta = [rex_i18n::msg($isCategory ? 'trash_type_category' : 'trash_type_article')];
    if (!$isCategory) {
        $meta[] = rex_i18n::msg('trash_slice_count_info', (string) (int) $params['list']->getValue('slice_count'));
    }
    $meta[] = rex_i18n::msg('trash_original_id') . ' ' . (int) $params['list']->getValue('article_id');

    return '<strong>' . rex_escape($name) . '</strong>'
        . '<br><small class="text-muted">' . rex_escape(implode(' · ', $meta)) . '</small>';
});

$list->setColumnLabel('languages', rex_i18n::msg('trash_languages'));
$list->setColumnFormat('languages', 'custom', static function ($params) {
    if (!$params['value']) {
        return '<span class="text-muted">' . rex_i18n::msg('trash_no_content') . '</span>';
    }

    $names = [];
    foreach (explode(',', (string) $params['value']) as $id) {
        $clang = rex_clang::get((int) $id);
        $names[] = $clang ? $clang->getName() : (string) $id;
    }

    return rex_escape(implode(', ', $names));
});

$list->setColumnLabel('deleted_at', rex_i18n::msg('trash_deleted_at'));
$list->setColumnFormat('deleted_at', 'custom', static function ($params) {
    $time = strtotime((string) $params['value']);

    return false === $time
        ? rex_escape((string) $params['value'])
        : rex_escape(rex_formatter::intlDateTime($time, IntlDateFormatter::SHORT));
});

$list->setColumnLabel('deleted_by', rex_i18n::msg('trash_deleted_by'));
$list->setColumnFormat('deleted_by', 'custom', static function ($params) {
    $value = trim((string) $params['value']);

    return '' === $value
        ? '<span class="text-muted">' . rex_i18n::msg('trash_deleted_by_system') . '</span>'
        : rex_escape($value);
});

// Nur noch intern benoetigt, nicht als eigene Spalte
$list->removeColumn('status');
$list->removeColumn('startarticle');
$list->removeColumn('slice_count');
$list->removeColumn('article_id');
$list->removeColumn('catname');

// Aktionen als Text mit Symbol - wie in der Strukturverwaltung
$list->addColumn('restore', '<i class="rex-icon rex-icon-refresh"></i> ' . rex_i18n::msg('trash_restore'));
$list->setColumnParams('restore', ['func' => 'restore', 'id' => '###id###']);
$list->setColumnLayout('restore', ['<th class="rex-table-action" colspan="2">' . rex_i18n::msg('trash_functions') . '</th>', '<td class="rex-table-action">###VALUE###</td>']);

$list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('trash_delete'));
$list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###']);
$list->setColumnLayout('delete', ['', '<td class="rex-table-action">###VALUE###</td>']);
$list->addLinkAttribute('delete', 'data-confirm', rex_i18n::msg('trash_confirm_delete'));
$list->addLinkAttribute('delete', 'class', 'rex-link-expanded');

$list->setNoRowsMessage(rex_i18n::msg('trash_is_empty'));

// "Papierkorb leeren" nur anbieten, wenn etwas drin ist
$rows = rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . $trashTable);
$options = '';
if ((int) $rows[0]['c'] > 0) {
    $options = '<a class="btn btn-delete btn-xs" href="' . rex_url::currentBackendPage(['func' => 'empty']) . '"'
        . ' data-confirm="' . rex_escape(rex_i18n::msg('trash_confirm_empty_trash')) . '">'
        . '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('trash_empty_trash') . '</a>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('trash'), false);
$fragment->setVar('content', '<p>' . rex_i18n::msg('trash_intro') . '</p>' . $list->get(), false);
$fragment->setVar('options', $options, false);
echo $fragment->parse('core/page/section.php');
