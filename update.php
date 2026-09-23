<?php

/** @var rex_addon $this */

// Tabellenstruktur wird ausschliesslich in install.php gepflegt; alle
// Definitionen sind idempotent (ensureColumn/ensureIndex/ensure).
$this->includeFile('install.php');

// ---------------------------------------------------------------------
// Uebernahme aus dem AddOn "undo"
//
// Seit 2.0.0 enthaelt trash die Sofort-Rueckgaengig-Funktion. War undo
// zuvor installiert, liegen dort moeglicherweise noch Eintraege aus einer
// gerade erfolgten Loeschung. Sie werden in den Papierkorb uebernommen,
// damit beim Umstieg nichts verloren geht.
// ---------------------------------------------------------------------
$articleUndo = rex::getTable('article_undo');

if (rex_sql_table::get($articleUndo)->exists()) {
    try {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT u.* FROM ' . $articleUndo . ' u
             WHERE NOT EXISTS (
                SELECT 1 FROM ' . rex::getTable('trash_article') . ' t WHERE t.article_id = u.id
             )
             GROUP BY u.id',
        );

        foreach ($rows as $row) {
            $insert = rex_sql::factory();
            $insert->setTable(rex::getTable('trash_article'));
            $insert->setValue('article_id', (int) $row['id']);
            $insert->setValue('parent_id', (int) ($row['parent_id'] ?? 0));
            $insert->setValue('name', (string) ($row['name'] ?? ''));
            $insert->setValue('catname', (string) ($row['catname'] ?? ''));
            $insert->setValue('catpriority', (int) ($row['catpriority'] ?? 0));
            $insert->setValue('priority', (int) ($row['priority'] ?? 0));
            $insert->setValue('path', (string) ($row['path'] ?? ''));
            $insert->setValue('template_id', (int) ($row['template_id'] ?? 0));
            $insert->setValue('status', (int) ($row['status'] ?? 0));
            $insert->setValue('startarticle', (int) ($row['startarticle'] ?? 0));
            $insert->setValue('revision', (int) ($row['revision'] ?? 0));
            $insert->setValue('createdate', (string) ($row['createdate'] ?? date('Y-m-d H:i:s')));
            $insert->setValue('createuser', (string) ($row['createuser'] ?? ''));
            $insert->setValue('updatedate', (string) ($row['updatedate'] ?? date('Y-m-d H:i:s')));
            $insert->setValue('updateuser', (string) ($row['updateuser'] ?? ''));
            $insert->setValue('deleted_at', date('Y-m-d H:i:s'));
            $insert->setValue('deleted_by', (string) ($row['updateuser'] ?? ''));
            $insert->insert();

            $trashArticleId = (int) $insert->getLastId();

            // Zugehoerige Slices uebernehmen, soweit vorhanden
            $sliceUndo = rex::getTable('article_slice_undo');
            if (!rex_sql_table::get($sliceUndo)->exists()) {
                continue;
            }

            $slices = rex_sql::factory();
            $slices->setQuery('SELECT * FROM ' . $sliceUndo . ' WHERE article_id = :id', ['id' => (int) $row['id']]);

            $targetColumns = [];
            foreach (rex_sql::showColumns(rex::getTable('trash_article_slice')) as $column) {
                $targetColumns[$column['name']] = true;
            }

            foreach ($slices as $slice) {
                $sliceInsert = rex_sql::factory();
                $sliceInsert->setTable(rex::getTable('trash_article_slice'));
                $sliceInsert->setValue('trash_article_id', $trashArticleId);

                foreach ($slice->getRow() as $column => $value) {
                    // "id" wird neu vergeben, unbekannte Spalten uebergehen
                    if ('id' === $column || !isset($targetColumns[$column])) {
                        continue;
                    }
                    $sliceInsert->setValue($column, $value);
                }

                $sliceInsert->insert();
            }
        }
    } catch (Throwable $e) {
        // Ein misslungener Uebertrag darf das Update nicht blockieren
        rex_logger::logException($e);
    }
}
