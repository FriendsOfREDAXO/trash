<?php

namespace FriendsOfREDAXO\trash;

use rex;
use rex_article_cache;
use rex_clang;
use rex_config;
use rex_csrf_token;
use rex_extension_point;
use rex_i18n;
use rex_sql;
use rex_sql_util;
use rex_url;
use Throwable;

/**
 * Sofort-Rueckgaengig direkt nach dem Loeschen.
 *
 * Zeigt nach dem Loeschen eines Artikels, einer Kategorie oder eines Slices
 * einen Hinweis mit Countdown und einem Link, der die Loeschung unmittelbar
 * zuruecknimmt - ohne Umweg ueber die Papierkorb-Seite.
 *
 * Es gibt dabei keinen eigenen Speicher:
 * - Artikel und Kategorien liegen im Papierkorb (TrashService)
 * - Einzelne Bloecke kommen aus dem Plugin structure/history, das bei
 *   SLICE_DELETE ohnehin einen Snapshot anlegt - dort liegen sie je nach
 *   Einstellung Tage statt Sekunden
 *
 * Ohne structure/history gibt es fuer einzelne Bloecke keinen Hinweis, weil
 * die Daten dann nirgends aufbewahrt werden.
 */
class QuickUndo
{
    /** Voreingestellte Frist in Sekunden, innerhalb derer zurueckgenommen werden kann */
    public const DEFAULT_TIMEOUT = 30;

    private const CSRF_ID = 'trash_quick_undo';

    /** ART_DELETED und CAT_DELETED feuern je Sprache - der Hinweis genuegt einmal */
    private static bool $noticeRendered = false;

    /** @var array{type: 'article'|'category'|'slice', params: array<string, int|string>}|null */
    private static ?array $queuedNotice = null;

    public static function isEnabled(): bool
    {
        return (bool) rex_config::get('trash', 'quick_undo', true);
    }

    public static function getTimeout(): int
    {
        $timeout = (int) rex_config::get('trash', 'quick_undo_timeout', self::DEFAULT_TIMEOUT);

        return max(5, min(300, $timeout));
    }

    /** Ist das Plugin verfuegbar, das Bloecke versioniert? */
    public static function hasHistory(): bool
    {
        return \rex_plugin::get('structure', 'history')->isAvailable()
            && class_exists('rex_article_slice_history');
    }

    /**
     * Hinweis mit Countdown und Rueckgaengig-Link.
     *
     * @param 'article'|'category'|'slice' $type
     * @param array<string, int|string> $params zusaetzliche Parameter fuer den Rueckgaengig-Link
     */
    public static function renderNotice(string $type, array $params): string
    {
        if (!self::isEnabled() || self::$noticeRendered) {
            return '';
        }
        self::$noticeRendered = true;

        $timeout = self::getTimeout();
        $label = match ($type) {
            'category' => rex_i18n::msg('trash_undo_category_deleted'),
            'slice' => rex_i18n::msg('trash_undo_slice_deleted'),
            default => rex_i18n::msg('trash_undo_article_deleted'),
        };

        $csrf = rex_csrf_token::factory(self::CSRF_ID);
        $linkParams = array_merge(
            ['trash_undo' => $type, 'clang' => rex_clang::getCurrentId()],
            $params,
            $csrf->getUrlParams(),
        );

        $url = 'index.php?' . http_build_query($linkParams, '', '&');

        // Als Toast am unteren Bildschirmrand, damit der Hinweis nicht
        // zwischen den uebrigen Meldungen der Seite untergeht.
        // role="alert" + aria-live: Screenreader lesen ihn sofort vor.
        return '<div class="trash-undo-message trash-undo-toast" role="alert" aria-live="assertive">'
            . '<span class="trash-undo-text">' . rex_escape($label) . '</span>'
            . '<a class="trash-undo-link" data-pjax="false" href="' . rex_escape($url) . '">'
            . rex_escape(rex_i18n::msg('trash_undo_action')) . '</a>'
            // Bei Artikeln und Kategorien laeuft nur die Sofort-Frist ab, der
            // Papierkorb bleibt. Nur einzelne Slices sind danach wirklich weg.
            . '<span class="trash-undo-countdown" data-timeout="' . $timeout . '">'
            . rex_i18n::rawMsg(
                'slice' === $type ? 'trash_undo_countdown_slice' : 'trash_undo_countdown',
                '<span class="trash-undo-seconds">' . $timeout . '</span>',
            )
            . '</span>'
            . '<button type="button" class="trash-undo-close" aria-label="' . rex_escape(rex_i18n::msg('trash_undo_close')) . '">&times;</button>'
            . '</div>';
    }

    /**
     * Hinweis vormerken, wenn er an der Fundstelle nicht als HTML ausgegeben
     * werden kann (siehe CAT_DELETED in der boot.php).
     *
     * @param 'article'|'category'|'slice' $type
     * @param array<string, int|string> $params
     */
    public static function queueNotice(string $type, array $params): void
    {
        self::$queuedNotice ??= ['type' => $type, 'params' => $params];
    }

    /** Vorgemerkten Hinweis ausgeben, falls vorhanden */
    public static function flushQueuedNotice(): string
    {
        if (null === self::$queuedNotice) {
            return '';
        }

        $notice = self::$queuedNotice;
        self::$queuedNotice = null;

        return self::renderNotice($notice['type'], $notice['params']);
    }

    /**
     * Rueckgaengig-Link auswerten. Liefert eine Meldung fuer die Ausgabe
     * bzw. einen leeren String, wenn nichts zu tun war.
     */
    public static function handleRequest(): string
    {
        $type = (string) rex_request('trash_undo', 'string', '');
        if ('' === $type) {
            return '';
        }

        if (!rex_csrf_token::factory(self::CSRF_ID)->isValid()) {
            return \rex_view::error(rex_i18n::msg('trash_undo_csrf'));
        }

        // Dieselbe Huerde wie auf der Papierkorb-Seite: nur Admins duerfen
        // Geloeschtes zurueckholen. Ohne diese Pruefung koennte jeder
        // Backend-Benutzer mit einem gueltigen Token fremde Loeschungen
        // rueckgaengig machen.
        $user = \rex::getUser();
        if (null === $user || !$user->isAdmin()) {
            return \rex_view::error(rex_i18n::msg('trash_undo_no_permission'));
        }

        try {
            return match ($type) {
                'slice' => self::restoreSlice((int) rex_request('slice_id', 'int', 0)),
                'article', 'category' => self::restoreArticle((int) rex_request('article_id', 'int', 0), $type),
                default => '',
            };
        } catch (Throwable $e) {
            \rex_logger::logException($e);

            return \rex_view::error(rex_i18n::msg('trash_undo_failed'));
        }
    }

    /**
     * Artikel oder Kategorie aus dem Papierkorb zuruecknehmen.
     * Nutzt denselben Weg wie die Papierkorb-Seite - es gibt nur einen Speicher.
     */
    private static function restoreArticle(int $articleId, string $type): string
    {
        if ($articleId < 1) {
            return '';
        }

        // Nur der Eintrag der gerade erfolgten Loeschung, nicht irgendein
        // aelterer mit derselben Artikel-ID: Der Link ist die Sofort-Aktion,
        // fuer alles andere ist die Papierkorb-Seite zustaendig.
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT id FROM ' . rex::getTable('trash_article') . '
             WHERE article_id = :id AND deleted_at >= :limit
             ORDER BY id DESC LIMIT 1',
            ['id' => $articleId, 'limit' => date('Y-m-d H:i:s', time() - self::getTimeout())],
        );

        if (0 === $sql->getRows()) {
            return \rex_view::warning(rex_i18n::msg('trash_undo_expired'));
        }

        $service = new TrashService();
        [$success, $message] = $service->restoreArticle((int) $sql->getValue('id'));

        if (!$success) {
            return \rex_view::error(rex_i18n::msg('trash_undo_failed') . ' ' . rex_escape($message));
        }

        $service->deleteArticlePermanently((int) $sql->getValue('id'));

        return \rex_view::success(rex_i18n::msg(
            'category' === $type ? 'trash_undo_category_restored' : 'trash_undo_article_restored',
        ));
    }

    /**
     * Einzelnen Block aus dem Snapshot von structure/history zuruecknehmen.
     *
     * Das Plugin legt bei SLICE_DELETE - also vor dem eigentlichen Loeschen -
     * einen Snapshot aller Bloecke des Artikels an, jeden Block als eigene
     * Zeile mit seiner slice_id. Daraus laesst sich genau der eine Block
     * zurueckholen, ohne den ganzen Artikel zurueckzurollen.
     */
    private static function restoreSlice(int $sliceId): string
    {
        if ($sliceId < 1) {
            return '';
        }

        if (!self::hasHistory()) {
            return \rex_view::warning(rex_i18n::msg('trash_undo_slice_no_history'));
        }

        // Existiert der Block noch, wurde bereits zurueckgeholt (Doppelklick)
        $existing = rex_sql::factory();
        $existing->setQuery('SELECT id FROM ' . rex::getTable('article_slice') . ' WHERE id = :id', ['id' => $sliceId]);
        if ($existing->getRows() > 0) {
            return \rex_view::success(rex_i18n::msg('trash_undo_slice_restored'));
        }

        $historyTable = \rex_article_slice_history::getTable();

        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . $historyTable . '
             WHERE slice_id = :id AND history_type = :type
             ORDER BY history_date DESC, id DESC LIMIT 1',
            ['id' => $sliceId, 'type' => 'slice_delete'],
        );

        if (0 === $sql->getRows()) {
            return \rex_view::warning(rex_i18n::msg('trash_undo_slice_expired'));
        }

        // Nur Spalten uebernehmen, die es in article_slice auch gibt: die
        // History-Tabelle fuehrt zusaetzlich history_* und slice_id.
        $columns = [];
        foreach (rex_sql::showColumns(rex::getTable('article_slice')) as $column) {
            $columns[$column['name']] = true;
        }

        $insert = rex_sql::factory();
        $insert->setTable(rex::getTable('article_slice'));
        $insert->setValue('id', $sliceId);

        foreach ($sql->getRow() as $column => $value) {
            $name = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
            if ('id' === $name || !isset($columns[$name])) {
                continue;
            }
            $insert->setValue($name, $value);
        }

        $insert->insert();

        $articleId = (int) $sql->getValue('article_id');
        $clangId = (int) $sql->getValue('clang_id');
        $ctypeId = (int) $sql->getValue('ctype_id');

        rex_sql_util::organizePriorities(
            rex::getTable('article_slice'),
            'priority',
            'article_id = ' . $articleId . ' AND clang_id = ' . $clangId . ' AND ctype_id = ' . $ctypeId . ' AND revision = 0',
            'priority, updatedate DESC',
        );

        rex_article_cache::delete($articleId, $clangId);

        return \rex_view::success(rex_i18n::msg('trash_undo_slice_restored'));
    }

    /**
     * Script fuer den Toast. Das Stylesheet laedt die boot.php ohnehin
     * immer - es faerbt auch das Menuesymbol ein.
     */
    public static function addAssets(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        \rex_view::addJsFile(rex_url::addonAssets('trash', 'trash.js'));
    }
}
