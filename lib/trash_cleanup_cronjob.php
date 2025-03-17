<?php

/**
 * Trash AddOn - Cronjob zum Aufräumen alter Einträge im Papierkorb.
 *
 * @package redaxo\trash
 */

class rex_cronjob_trash_cleanup extends rex_cronjob
{
    /**
     * Führt den Cronjob aus.
     *
     * @return bool true bei Erfolg, sonst false
     */
    public function execute()
    {
        $success = true;
        $message = [];
        
        // Maximales Alter in Tagen aus der Konfiguration holen
        $maxAge = (int) $this->getParam('max_age', 30);
        
        // Wenn 0 angegeben wurde, wird der Cronjob nicht ausgeführt
        if ($maxAge <= 0) {
            $message[] = rex_i18n::msg('trash_cronjob_max_age_zero');
            $this->setMessage(implode(', ', $message));
            return true;
        }
        
        // Datum für die Löschung berechnen (alle Einträge älter als dieses Datum werden gelöscht)
        $deleteDate = new DateTime();
        $deleteDate->modify('-' . $maxAge . ' days');
        $deleteDateString = $deleteDate->format('Y-m-d H:i:s');
        
        // Tabellennamen definieren
        $trashTable = rex::getTable('trash_article');
        $trashSliceTable = rex::getTable('trash_article_slice');
        
        // SQL Objekt initialisieren
        $sql = rex_sql::factory();
        
        try {
            // Transaktion starten
            $sql->beginTransaction();
            
            // IDs der zu löschenden Artikel holen
            $articlesToDelete = $sql->getArray(
                'SELECT id FROM ' . $trashTable . ' WHERE deleted_at < :delete_date',
                ['delete_date' => $deleteDateString]
            );
            
            // Anzahl der zu löschenden Artikel
            $deletedCount = count($articlesToDelete);
            
            if ($deletedCount > 0) {
                // Alle gefundenen Artikel löschen
                foreach ($articlesToDelete as $article) {
                    $id = $article['id'];
                    
                    // Zuerst alle Slices des Artikels löschen
                    $sql->setQuery(
                        'DELETE FROM ' . $trashSliceTable . ' WHERE trash_article_id = :id',
                        ['id' => $id]
                    );
                    
                    // Dann den Artikel selbst löschen
                    $sql->setQuery(
                        'DELETE FROM ' . $trashTable . ' WHERE id = :id',
                        ['id' => $id]
                    );
                }
                
                $message[] = rex_i18n::msg('trash_cronjob_deleted_count', $deletedCount);
            } else {
                $message[] = rex_i18n::msg('trash_cronjob_no_articles_found');
            }
            
            // Transaktion bestätigen
            $sql->commit();
        } catch (Exception $e) {
            // Bei Fehler Transaktion zurückrollen
            if ($sql->inTransaction()) {
                $sql->rollBack();
            }
            
            // Fehler loggen
            rex_logger::logException($e);
            
            $message[] = rex_i18n::msg('trash_cronjob_error', $e->getMessage());
            $success = false;
        }
        
        $this->setMessage(implode(', ', $message));
        return $success;
    }
    
    /**
     * Gibt ein Array mit den Beschreibungsfeldern zurück.
     *
     * @return array
     */
    public function getTypeName()
    {
        return rex_i18n::msg('trash_cronjob_name');
    }
    
    /**
     * Gibt die Umgebungen zurück, in denen der Cronjob ausgeführt werden kann.
     *
     * @return array
     */
    public function getEnvironments()
    {
        return ['frontend', 'backend'];
    }
    
    /**
     * Definiert die Parameter des Cronjobs.
     *
     * @return array
     */
    public function getParamFields()
    {
        return [
            [
                'label' => rex_i18n::msg('trash_cronjob_max_age'),
                'name' => 'max_age',
                'type' => 'select',
                'options' => [
                    0 => rex_i18n::msg('trash_cronjob_max_age_never'),
                    1 => '1 ' . rex_i18n::msg('trash_cronjob_max_age_day'),
                    7 => '7 ' . rex_i18n::msg('trash_cronjob_max_age_days'),
                    14 => '14 ' . rex_i18n::msg('trash_cronjob_max_age_days'),
                    30 => '30 ' . rex_i18n::msg('trash_cronjob_max_age_days'),
                    60 => '60 ' . rex_i18n::msg('trash_cronjob_max_age_days'),
                    90 => '90 ' . rex_i18n::msg('trash_cronjob_max_age_days'),
                    180 => '180 ' . rex_i18n::msg('trash_cronjob_max_age_days'),
                    365 => '365 ' . rex_i18n::msg('trash_cronjob_max_age_days'),
                ],
                'default' => 30,
            ],
        ];
    }
}
