<?php
try {
    // Tabellen für gelöschte Artikel und deren Slices löschen
    rex_sql_table::get(rex::getTable('trash_article'))->drop();
    rex_sql_table::get(rex::getTable('trash_article_slice'))->drop();
    rex_sql_table::get(rex::getTable('trash_slice_meta'))->drop();
    
    
    // Cache löschen
    rex_delete_cache();
    
    return true;
} catch (rex_sql_exception $e) {
    $addon = rex_addon::get('trash');
    $addon->setProperty('installmsg', $e->getMessage());
    return false;
}
  ?>
