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
        /** @var list<string> $message */
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
        $trashSliceMetaTable = rex::getTable('trash_slice_meta');
        
        try {
            $sql = rex_sql::factory();

            // IDs der zu löschenden Einträge holen
            $articlesToDelete = $sql->getArray(
                'SELECT id FROM ' . $trashTable . ' WHERE deleted_at < :delete_date',
                ['delete_date' => $deleteDateString]
            );

            $deletedCount = count($articlesToDelete);

            if ($deletedCount > 0) {
                // Alles oder nichts: Meta-Daten, Slices und Einträge gehören zusammen
                rex_sql::factory()->transactional(static function () use ($articlesToDelete, $trashTable, $trashSliceTable, $trashSliceMetaTable): void {
                    $sql = rex_sql::factory();

                    foreach ($articlesToDelete as $article) {
                        $id = (int) $article['id'];

                        $sql->setQuery(
                            'DELETE m FROM ' . $trashSliceMetaTable . ' m
                             JOIN ' . $trashSliceTable . ' s ON s.id = m.trash_slice_id
                             WHERE s.trash_article_id = :id',
                            ['id' => $id]
                        );
                        $sql->setQuery('DELETE FROM ' . $trashSliceTable . ' WHERE trash_article_id = :id', ['id' => $id]);
                        $sql->setQuery('DELETE FROM ' . $trashTable . ' WHERE id = :id', ['id' => $id]);
                    }
                });

                $message[] = rex_i18n::msg('trash_cronjob_deleted_count', $deletedCount);
            } else {
                $message[] = rex_i18n::msg('trash_cronjob_no_articles_found');
            }

            // Abgelaufene Sofort-Rueckgaengig-Eintraege einzelner Bloecke.
            // Sie werden sonst nur beim naechsten Loeschvorgang verworfen und
            // blieben liegen, wenn laengere Zeit nichts geloescht wird.
            \FriendsOfREDAXO\trash\QuickUndo::purgeExpired();
        } catch (Exception $e) {
            rex_logger::logException($e);

            $message[] = rex_i18n::msg('trash_cronjob_error', $e->getMessage());
            $success = false;
        }
        
        $this->setMessage(implode(', ', $message));
        return $success;
    }
    
    /**
     * Name des Cronjob-Typs.
     *
     * @return string
     */
    public function getTypeName()
    {
        return rex_i18n::msg('trash_cronjob_name');
    }
    
    /**
     * Gibt die Umgebungen zurück, in denen der Cronjob ausgeführt werden kann.
     *
     * @return list<string>
     */
    public function getEnvironments()
    {
        return ['frontend', 'backend'];
    }
    
    /**
     * Definiert die Parameter des Cronjobs.
     *
     * @return list<array<string, mixed>>
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
