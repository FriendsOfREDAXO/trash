<?php
/**
 * Trash AddOn - Service Class
 * 
 * @package redaxo\trash
 */

namespace FriendsOfREDAXO\trash;

use rex;
use rex_sql;
use rex_article;
use rex_clang;
use rex_user;
use rex_plugin;
use rex_logger;
use rex_extension_point;
use Exception;

/**
 * Main service class for Trash functionality
 */
class TrashService
{
    /**
     * Login des handelnden Benutzers. Loeschungen koennen auch ohne
     * angemeldeten Benutzer erfolgen (Cronjob, Konsole, API) - dann steht
     * kein Login zur Verfuegung.
     */
    private function currentUserLogin(): string
    {
        $user = rex::getUser();

        return null !== $user ? $user->getLogin() : '';
    }

    /**
     * Get table names used by trash
     * @return array<string, string>
     */
    public function getTableNames(): array
    {
        return [
            'trash_article' => rex::getTable('trash_article'),
            'trash_slice' => rex::getTable('trash_article_slice'),
            'trash_slice_meta' => rex::getTable('trash_slice_meta')
        ];
    }

    /**
     * Utility function for date format conversion
     * 
     * @param string|int $dateStr Date string or timestamp
     * @return string Formatted date string
     */
    public function fixDateFormat($dateStr): string
    {
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

    /**
     * Helper function to collect meta attributes
     * 
     * @param string $tableName Name of the table (without prefix)
     * @param rex_sql|rex_article $object Object to read values from
     * @param array<string, int> $fieldTypes Optional: Array with field types for slices
     * @return array<string, mixed> Collected meta attributes
     */
    public function collectMetaAttributes(string $tableName, $object, array $fieldTypes = []): array
    {
        if ('' === $tableName) {
            return [];
        }

        $allMetaAttributes = [];
        $table = rex::getTable($tableName);

        // Get all columns of the table
        $columnInfo = rex_sql::showColumns($table);
        
        // Standard columns that should not be considered as meta attributes
        $standardColumns = [
            'id', 'article_id', 'clang_id', 'ctype_id', 'module_id', 'priority', 
            'status', 'revision', 'createdate', 'createuser', 'updatedate', 'updateuser'
        ];
        
        // For slices: add all field types to standard columns
        if (!empty($fieldTypes)) {
            foreach ($fieldTypes as $type => $count) {
                for ($i = 1; $i <= $count; $i++) {
                    $standardColumns[] = $type . $i;
                }
            }
        }
        
        // Additional standard columns for articles
        if ($tableName === 'article') {
            $standardColumns = array_merge($standardColumns, [
                'name', 'catname', 'catpriority', 'startarticle', 'parent_id', 
                'path', 'template_id'
            ]);
        }

        foreach ($columnInfo as $column) {
            $columnName = $column['name'];
            if (!in_array($columnName, $standardColumns)) {
                // Get value from object
                $metaValue = $object->getValue($columnName);
                if ($metaValue !== null) {
                    $allMetaAttributes[$columnName] = $metaValue;
                }
            }
        }

        return $allMetaAttributes;
    }

    /**
     * Check if a priority is already taken in a category
     * 
     * @param int $parentId Parent category ID
     * @param int $priority Priority to check
     * @param bool $isStartarticle Whether it's a category
     * @return bool True if priority is taken, false otherwise
     */
    public function isPriorityTaken(int $parentId, int $priority, bool $isStartarticle = false): bool
    {
        $sql = rex_sql::factory();
        
        if ($isStartarticle) {
            // For categories: check catpriority
            $query = 'SELECT id FROM ' . rex::getTablePrefix() . 'article 
                     WHERE parent_id = :parent_id AND startarticle = 1 AND catpriority = :priority LIMIT 1';
        } else {
            // For articles: check priority
            $query = 'SELECT id FROM ' . rex::getTablePrefix() . 'article 
                     WHERE parent_id = :parent_id AND startarticle = 0 AND priority = :priority LIMIT 1';
        }
        
        $sql->setQuery($query, ['parent_id' => $parentId, 'priority' => $priority]);
        return $sql->getRows() > 0;
    }

    /**
     * Get an available priority, starting from desired priority
     * 
     * @param int $parentId Parent category ID
     * @param int $desiredPriority Desired priority
     * @param bool $isStartarticle Whether it's a category
     * @return int Available priority
     */
    public function getAvailablePriority(int $parentId, int $desiredPriority, bool $isStartarticle = false): int
    {
        $priority = $desiredPriority;
        
        // Search for free priority starting from desired value
        while ($this->isPriorityTaken($parentId, $priority, $isStartarticle)) {
            $priority++;
            
            // Safety check to avoid infinite loops
            if ($priority > 1000) {
                break;
            }
        }
        
        return $priority;
    }

    /**
     * Get the next available priority in a category
     * 
     * @param int $parentId Parent category ID
     * @param bool $isStartarticle Whether it's a category
     * @return int Next available priority
     */
    public function getNextPriority(int $parentId, bool $isStartarticle = false): int
    {
        $sql = rex_sql::factory();
        
        if ($isStartarticle) {
            // For categories: get max catpriority
            $query = 'SELECT COALESCE(MAX(catpriority), 0) + 1 as next_priority 
                     FROM ' . rex::getTablePrefix() . 'article 
                     WHERE parent_id = :parent_id AND startarticle = 1';
        } else {
            // For articles: get max priority
            $query = 'SELECT COALESCE(MAX(priority), 0) + 1 as next_priority 
                     FROM ' . rex::getTablePrefix() . 'article 
                     WHERE parent_id = :parent_id AND startarticle = 0';
        }
        
        $sql->setQuery($query, ['parent_id' => $parentId]);
        return (int) $sql->getValue('next_priority');
    }

    /**
     * Insert article directly into database
     * 
     * @param array $articleData Article data to insert
     * @param bool $debug Debug mode
     * @param array<string, mixed> $articleData
     * @return array{0: bool, 1: int|null, 2: string, 3: bool, 4: int}
     */
    public function insertArticleDirectly(array $articleData, bool $debug = false): array
    {
        try {
            // Use table name directly instead of getTable()
            $tableName = rex::getTablePrefix() . 'article';
            
            $idChanged = false;
            $originalId = $articleData['id'];
            
            // Check if an article with the specified ID already exists
            if (isset($articleData['id']) && $articleData['id'] > 0) {
                $checkSql = rex_sql::factory();
                $checkSql->setQuery("SELECT id FROM " . $tableName . " WHERE id = :id LIMIT 1", 
                    ['id' => $articleData['id']]);
                
                // If an article with this ID was found, determine new ID
                if ($checkSql->getRows() > 0) {
                    // Find next free ID
                    $newIdSql = rex_sql::factory();
                    $newIdSql->setQuery("SELECT COALESCE(MAX(id), 0) + 1 as new_id FROM " . $tableName);
                    $articleData['id'] = (int) $newIdSql->getValue('new_id');
                    $idChanged = true;
                    
                    if ($debug) {
                        echo '<pre>ID bereits vergeben, neue ID gewählt: ' . $articleData['id'] . '</pre>';
                    }
                }
            }
            
            $insertedId = null;
            
            // Insert for all languages
            foreach (rex_clang::getAllIds() as $clangId) {
                // Build query manually for more control
                $query = "INSERT INTO " . $tableName . " SET ";
                $params = [];
                $first = true;
                
                // Go through all data and add to query
                foreach ($articleData as $key => $value) {
                    if ($key !== 'clang_id') { // clang_id is added separately
                        if (!$first) {
                            $query .= ", ";
                        }
                        $query .= "`" . $key . "` = :" . $key;
                        $params[$key] = $value;
                        $first = false;
                    }
                }
                
                // Add clang_id
                if (!$first) {
                    $query .= ", ";
                }
                $query .= "`clang_id` = :clang_id";
                $params['clang_id'] = $clangId;
                
                // Execute SQL
                $articleSql = rex_sql::factory();
                if ($debug) $articleSql->setDebug();
                $articleSql->setQuery($query, $params);
                
                // Remember ID (only on first insert)
                if ($insertedId === null) {
                    $insertedId = $articleData['id'];
                }
            }
            
            // Successful insertion with information whether ID was changed
            return [true, $insertedId, "", $idChanged, $originalId];
        } catch (Exception $e) {
            rex_logger::logException($e);
            return [false, null, $e->getMessage(), false, $originalId];
        }
    }

    /**
     * Handle article deletion - save to trash
     * 
     * @param rex_extension_point<mixed> $ep Extension point
     */
    public function handleArticleDeletion(rex_extension_point $ep): void
    {
        // Get article data from extension point.
        // ART_PRE_DELETED liefert kein 'clang' - der Core liest die Daten
        // selbst aus der Startsprache. Ohne diesen Bezug lieferte
        // rex_article::get() null und Kategorien landeten nie im Papierkorb.
        $articleId = (int) $ep->getParam('id');
        $clangId = (int) $ep->getParam('clang', rex_clang::getStartId());
        $parentId = $ep->getParam('parent_id');
        $name = $ep->getParam('name');
        $status = $ep->getParam('status');
        
        $tables = $this->getTableNames();
        $trashTable = $tables['trash_article'];
        $trashSliceTable = $tables['trash_slice'];
        
        // ART_PRE_DELETED feuert je Sprache - der Eintrag soll aber nur einmal
        // entstehen. Die Pruefung darf sich dabei nicht allein auf article_id
        // stuetzen: REDAXO vergibt geloeschte IDs neu, sonst waere ein spaeter
        // geloeschter Artikel mit derselben ID faelschlich ein "Duplikat" und
        // wuerde gar nicht gesichert. Deshalb zusaetzlich auf den laufenden
        // Loeschvorgang begrenzen.
        $sql = rex_sql::factory();
        $exists = $sql->getArray(
            'SELECT id FROM ' . $trashTable . ' WHERE article_id = :article_id AND deleted_at >= :since',
            ['article_id' => $articleId, 'since' => date('Y-m-d H:i:s', time() - 5)],
        );
        
        if (empty($exists)) {
            // Save article reference for all available languages (only once)
            $article = rex_article::get($articleId, $clangId);
            if ($article) {
                // Move article to trash
                $sql = rex_sql::factory();
                $sql->setTable($trashTable);
                $sql->setValue('article_id', $articleId);
                $sql->setValue('parent_id', $parentId);
                $sql->setValue('name', $article->getValue('name'));
                $sql->setValue('catname', $article->getValue('catname'));
                $sql->setValue('status', $status);
                $sql->setValue('deleted_at', date('Y-m-d H:i:s'));
                $sql->setValue('deleted_by', $this->currentUserLogin());
                
                // Check if it's a category
                if ($article->getValue('startarticle') == 1) {
                    $sql->setValue('startarticle', 1);
                    $sql->setValue('priority', $article->getValue('catpriority'));
                    // If there's a catpriority value, save it too (shouldn't normally occur)
                    if ($article->hasValue('catpriority')) {
                        $sql->setValue('catpriority', $article->getValue('catpriority'));
                    } else {
                        $sql->setValue('catpriority', 0);
                    }
                } else {
                    $sql->setValue('startarticle', 0);
                    $sql->setValue('priority', $article->getValue('priority'));
                    // If there's a catpriority value, save it too (shouldn't normally occur)
                    if ($article->hasValue('catpriority')) {
                        $sql->setValue('catpriority', $article->getValue('catpriority'));
                    } else {
                        $sql->setValue('catpriority', 0);
                    }
                }
                
                // Direct storage of attributes as separate columns with date fixing
                $sql->setValue('path', $article->getValue('path'));
                $sql->setValue('template_id', $article->getValue('template_id'));
                
                // Format date values correctly here
                $sql->setValue('createdate', $this->fixDateFormat((string) $article->getValue('createdate')));
                $sql->setValue('createuser', $article->getValue('createuser'));
                $sql->setValue('updatedate', $this->fixDateFormat((string) $article->getValue('updatedate')));
                $sql->setValue('updateuser', $article->getValue('updateuser'));
                
                // Save revision value if available
                if ($article->hasValue('revision')) {
                    $sql->setValue('revision', $article->getValue('revision'));
                } else {
                    $sql->setValue('revision', 0);
                }
                
                // Collect and save meta attributes
                $metaAttributes = $this->collectMetaAttributes('article', $article);
                if (!empty($metaAttributes)) {
                    $sql->setValue('meta_attributes', json_encode($metaAttributes, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG));
                }
                
                $sql->insert();
                $trashArticleId = $sql->getLastId();
                
                // Save slices for all languages
                foreach (rex_clang::getAllIds() as $langId) {
                    // Different revisions that should be backed up
                    $revisions = [0]; // Default: Live version (0)
                    
                    // If versions plugin is available, also backup working version
                    if (rex_plugin::get('structure', 'version')->isAvailable()) {
                        $revisions[] = 1; // Add working version (1)
                    }
                    
                    foreach ($revisions as $revision) {
                        // Get all slices of the article with this revision
                        $slices = rex_sql::factory();
                        $slices->setQuery('SELECT * FROM ' . rex::getTable('article_slice') . ' 
                                           WHERE article_id = :id AND clang_id = :clang AND revision = :revision', 
                                          ['id' => $articleId, 'clang' => $langId, 'revision' => $revision]);
                        
                        // Copy slices to trash
                        if ($slices->getRows() > 0) {
                            foreach ($slices as $slice) {
                                try {
                                    $sliceSql = rex_sql::factory();
                                    $sliceSql->setTable($trashSliceTable);
                                    
                                    // Set minimal required fields
                                    $sliceSql->setValue('trash_article_id', $trashArticleId);
                                    $sliceSql->setValue('article_id', $slice->getValue('article_id'));
                                    $sliceSql->setValue('clang_id', $slice->getValue('clang_id'));
                                    $sliceSql->setValue('ctype_id', $slice->getValue('ctype_id') ?: 1);
                                    $sliceSql->setValue('module_id', $slice->getValue('module_id') ?: 0);
                                    $sliceSql->setValue('priority', $slice->getValue('priority') ?: 0);
                                    $sliceSql->setValue('revision', $revision);
                                    
                                    // Copy status if available
                                    if ($slice->hasValue('status')) {
                                        $sliceSql->setValue('status', $slice->getValue('status'));
                                    }
                                    
                                    // Process all field types with loops
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
                                    
                                    // Save slice to trash
                                    $sliceSql->insert();
                                    
                                    // Get ID of inserted slice
                                    $sliceId = $sliceSql->getLastId();
                                    
                                    // Save meta attributes in separate table
                                    $sliceMetaAttributes = $this->collectMetaAttributes('article_slice', $slice, $fieldTypes);
                                    
                                    // Save meta attributes if available
                                    if (!empty($sliceMetaAttributes)) {
                                        $metaSql = rex_sql::factory();
                                        $metaSql->setTable(rex::getTable('trash_slice_meta'));
                                        $metaSql->setValue('trash_slice_id', $sliceId);
                                        $metaSql->setValue('meta_data', json_encode($sliceMetaAttributes, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG));
                                        $metaSql->insert();
                                    }
                                } catch (Exception $e) {
                                    // Log error when inserting slice
                                    rex_logger::logException($e);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Restore article from trash
     * 
     * @param int $articleId Trash article ID
     * @param bool $debug Debug mode
     * @return array{0: bool, 1: string, 2: bool, 3: int, 4: int, 5: bool, 6: string}
     */
    public function restoreArticle(int $articleId, bool $debug = false): array
    {
        $tables = $this->getTableNames();
        $trashTable = $tables['trash_article'];
        $trashSliceTable = $tables['trash_slice'];
        $trashSliceMetaTable = $tables['trash_slice_meta'];

        // Get article data from trash
        $sql = rex_sql::factory();
        if ($debug) $sql->setDebug();
        $sql->setQuery('SELECT * FROM ' . $trashTable . ' WHERE id = :id', ['id' => $articleId]);
        
        if ($sql->getRows() !== 1) {
            return [false, 'Article not found in trash', false, 0, 0, false, ''];
        }

        $original_id = (int) $sql->getValue('article_id');
        $parent_id = (int) $sql->getValue('parent_id');
        $name = (string) $sql->getValue('name');
        $catname = (string) $sql->getValue('catname');
        $catpriority = (int) $sql->getValue('catpriority');
        $status = (int) $sql->getValue('status');
        $path = (string) $sql->getValue('path');
        $priority = (int) $sql->getValue('priority');
        $startarticle = (int) $sql->getValue('startarticle');
        $template_id = (int) $sql->getValue('template_id');
        $createdate = (string) $sql->getValue('createdate');
        $createuser = (string) $sql->getValue('createuser');
        $metaAttributes = json_decode((string) $sql->getValue('meta_attributes'), true);

        // Check if parent category exists
        $parentExists = true;
        if ($parent_id > 0) {
            $parentSql = rex_sql::factory();
            $parentSql->setQuery('SELECT id FROM ' . rex::getTablePrefix() . 'article WHERE id = :id AND startarticle = 1', ['id' => $parent_id]);
            $parentExists = $parentSql->getRows() > 0;
        }

        // Priority handling
        $priorityChangedInfo = '';
        $isStartarticle = ($startarticle == 1);
        
        if ($isStartarticle && $catpriority > 0) {
            // For categories: check catpriority
            if ($this->isPriorityTaken($parent_id, $catpriority, true)) {
                $newPriority = $this->getAvailablePriority($parent_id, $catpriority, true);
                $catpriority = $newPriority;
                $priorityChangedInfo = '<div class="alert alert-info">' . 
                    'Die ursprüngliche Kategorie-Priorität war bereits vergeben. Neue Priorität: ' . $newPriority . 
                    '</div>';
            }
        } elseif (!$isStartarticle && $priority > 0) {
            // For articles: check priority
            if ($this->isPriorityTaken($parent_id, $priority, false)) {
                $newPriority = $this->getAvailablePriority($parent_id, $priority, false);
                $priority = $newPriority;
                $priorityChangedInfo = '<div class="alert alert-info">' . 
                    'Die ursprüngliche Artikel-Priorität war bereits vergeben. Neue Priorität: ' . $newPriority . 
                    '</div>';
            }
        }

        // Prepare article data for restoration
        $articleData = [];
        $articleData['id'] = $original_id;
        $articleData['parent_id'] = $parent_id;
        $articleData['name'] = $name;
        $articleData['catname'] = $catname;
        $articleData['catpriority'] = $catpriority;
        $articleData['startarticle'] = $startarticle;
        $articleData['status'] = $status;
        $articleData['path'] = $path;
        $articleData['priority'] = $priority;
        $articleData['template_id'] = $template_id;
        $articleData['createdate'] = $createdate;
        $articleData['createuser'] = '' !== (string) $createuser ? (string) $createuser : $this->currentUserLogin();
        $articleData['updatedate'] = date('Y-m-d H:i:s');
        $articleData['updateuser'] = $this->currentUserLogin();
        $articleData['revision'] = 0; // Set to live version

        if ($debug) {
            echo '<pre>Versuche Artikel wiederherzustellen mit ID ' . $original_id . ': ' . print_r($articleData, true) . '</pre>';
        }

        // Direct insertion into database
        list($success, $newArticleId, $errorMessage, $idChanged, $originalRequestedId) = $this->insertArticleDirectly($articleData, $debug);

        if (!$success) {
            return [false, 'Restore error: ' . $errorMessage, false, $originalRequestedId, 0, $parentExists, ''];
        }

        // Restore meta attributes if available
        if ($metaAttributes) {
            try {
                // Get column information of article table
                $columnInfo = rex_sql::showColumns(rex::getTable('article'));
                $existingColumns = [];
                $primaryKeys = [];
                
                // Convert existing columns to array for faster access and identify primary keys
                foreach ($columnInfo as $column) {
                    $existingColumns[$column['name']] = true;
                    if ($column['key'] === 'PRI' || $column['extra'] === 'auto_increment') {
                        $primaryKeys[$column['name']] = true;
                    }
                }
                
                // List of columns that should always be ignored
                $ignoreColumns = ['pid', 'id', 'article_id'];
                
                foreach (rex_clang::getAllIds() as $clangId) {
                    // Set meta attributes for each language
                    
                    // Build query manually for more control
                    $query = "UPDATE " . rex::getTable('article') . " SET ";
                    $params = [];
                    $hasValues = false;
                    $first = true;
                    
                    foreach ($metaAttributes as $key => $value) {
                        // Only process if column exists and is not a primary key or ignored
                        if (isset($existingColumns[$key]) && !isset($primaryKeys[$key]) && !in_array($key, $ignoreColumns)) {
                            if (!$first) {
                                $query .= ", ";
                            }
                            $query .= "`" . $key . "` = :" . $key;
                            $params[$key] = $value;
                            $first = false;
                            $hasValues = true;
                        }
                    }
                    
                    // Only execute if there are values to set
                    if ($hasValues) {
                        // Add clang_id condition
                        $query .= " WHERE `id` = :id AND `clang_id` = :clang_id";
                        $params['id'] = $newArticleId;
                        $params['clang_id'] = $clangId;
                        
                        // Execute SQL
                        $metaSql = rex_sql::factory();
                        if ($debug) $metaSql->setDebug();
                        $metaSql->setQuery($query, $params);
                    }
                }
            } catch (Exception $e) {
                // Log error when restoring meta attributes, but continue process
                rex_logger::logException($e);
                if ($debug) {
                    echo '<pre>FEHLER beim Setzen der Meta-Attribute: ' . $e->getMessage() . '</pre>';
                }
            }
        }

        // Now restore slices, grouped by language and revision
        $slicesSql = rex_sql::factory();
        if ($debug) $slicesSql->setDebug();
        $slicesSql->setQuery('SELECT * FROM ' . $trashSliceTable . ' WHERE trash_article_id = :trash_id ORDER BY clang_id, revision, priority', ['trash_id' => $articleId]);
        $slices = $slicesSql->getArray();
        
        // List of all languages in which slices exist
        $clangIds = [];
        foreach ($slices as $slice) {
            $clangIds[(int) $slice['clang_id']] = true;
        }

        // Restore slices for each language
        foreach (array_keys($clangIds) as $clangId) {
            $languageSlices = array_filter($slices, function($slice) use ($clangId) {
                return $slice['clang_id'] == $clangId;
            });

            if (!empty($languageSlices)) {
                foreach ($languageSlices as $slice) {
                    try {
                        $restoreSliceSql = rex_sql::factory();
                        $restoreSliceSql->setTable(rex::getTable('article_slice'));
                        
                        // Set basic fields
                        $restoreSliceSql->setValue('article_id', $newArticleId);
                        $restoreSliceSql->setValue('clang_id', $slice['clang_id']);
                        $restoreSliceSql->setValue('ctype_id', $slice['ctype_id']);
                        $restoreSliceSql->setValue('module_id', $slice['module_id']);
                        $restoreSliceSql->setValue('priority', $slice['priority']);
                        $restoreSliceSql->setValue('revision', $slice['revision']);
                        
                        if (!empty($slice['status'])) {
                            $restoreSliceSql->setValue('status', $slice['status']);
                        }

                        // Restore all field types
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
                                if (!empty($slice[$field])) {
                                    $restoreSliceSql->setValue($field, $slice[$field]);
                                }
                            }
                        }
                        
                        $restoreSliceSql->insert();
                        $restoredSliceId = $restoreSliceSql->getLastId();

                        // Restore slice meta attributes
                        $sliceMetaSql = rex_sql::factory();
                        $sliceMetaSql->setQuery('SELECT * FROM ' . $trashSliceMetaTable . ' WHERE trash_slice_id = :slice_id', ['slice_id' => $slice['id']]);
                        
                        if ($sliceMetaSql->getRows() > 0) {
                            $metaData = json_decode((string) $sliceMetaSql->getValue('meta_data'), true);
                            if ($metaData) {
                                // Get slice table columns for meta attribute restoration
                                $sliceColumnInfo = rex_sql::showColumns(rex::getTable('article_slice'));
                                $sliceExistingColumns = [];
                                foreach ($sliceColumnInfo as $column) {
                                    $sliceExistingColumns[$column['name']] = true;
                                }
                                
                                $updateQuery = "UPDATE " . rex::getTable('article_slice') . " SET ";
                                $updateParams = [];
                                $hasSliceValues = false;
                                $firstSlice = true;
                                
                                foreach ($metaData as $key => $value) {
                                    if (isset($sliceExistingColumns[$key])) {
                                        if (!$firstSlice) {
                                            $updateQuery .= ", ";
                                        }
                                        $updateQuery .= "`" . $key . "` = :" . $key;
                                        $updateParams[$key] = $value;
                                        $firstSlice = false;
                                        $hasSliceValues = true;
                                    }
                                }
                                
                                if ($hasSliceValues) {
                                    $updateQuery .= " WHERE `id` = :id";
                                    $updateParams['id'] = $restoredSliceId;
                                    
                                    $updateSliceSql = rex_sql::factory();
                                    if ($debug) $updateSliceSql->setDebug();
                                    $updateSliceSql->setQuery($updateQuery, $updateParams);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        rex_logger::logException($e);
                        if ($debug) {
                            echo '<pre>FEHLER beim Wiederherstellen eines Slices: ' . $e->getMessage() . '</pre>';
                        }
                    }
                }
            }
        }

        return [true, 'success', $idChanged, (int) $originalRequestedId, (int) $newArticleId, $parentExists, $priorityChangedInfo];
    }

    /**
     * Delete article permanently from trash
     * 
     * @param int $articleId Trash article ID
     * @return array{0: bool, 1: string}
     */
    public function deleteArticlePermanently(int $articleId): array
    {
        $tables = $this->getTableNames();
        $trashTable = $tables['trash_article'];
        $trashSliceTable = $tables['trash_slice'];
        $trashSliceMetaTable = $tables['trash_slice_meta'];

        try {
            // Alles oder nichts: Slices, deren Meta-Daten und der Eintrag selbst
            rex_sql::factory()->transactional(static function () use ($trashTable, $trashSliceTable, $trashSliceMetaTable, $articleId): void {
                $sql = rex_sql::factory();

                $sql->setQuery(
                    'DELETE m FROM ' . $trashSliceMetaTable . ' m
                     JOIN ' . $trashSliceTable . ' s ON s.id = m.trash_slice_id
                     WHERE s.trash_article_id = :id',
                    ['id' => $articleId],
                );
                $sql->setQuery('DELETE FROM ' . $trashSliceTable . ' WHERE trash_article_id = :id', ['id' => $articleId]);
                $sql->setQuery('DELETE FROM ' . $trashTable . ' WHERE id = :id', ['id' => $articleId]);
            });

            return [true, 'Article deleted permanently'];
        } catch (Exception $e) {
            rex_logger::logException($e);

            return [false, 'Delete error: ' . $e->getMessage()];
        }
    }

    /**
     * Empty trash completely
     * 
     * @return array{0: bool, 1: string}
     */
    public function emptyTrash(): array
    {
        $tables = $this->getTableNames();
        $trashTable = $tables['trash_article'];
        $trashSliceTable = $tables['trash_slice'];
        $trashSliceMetaTable = $tables['trash_slice_meta'];

        try {
            rex_sql::factory()->transactional(static function () use ($trashTable, $trashSliceTable, $trashSliceMetaTable): void {
                $sql = rex_sql::factory();
                $sql->setQuery('DELETE FROM ' . $trashSliceMetaTable);
                $sql->setQuery('DELETE FROM ' . $trashSliceTable);
                $sql->setQuery('DELETE FROM ' . $trashTable);
            });

            return [true, 'Trash emptied'];
        } catch (Exception $e) {
            rex_logger::logException($e);

            return [false, 'Empty trash error: ' . $e->getMessage()];
        }
    }
}