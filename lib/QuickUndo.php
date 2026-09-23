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
 * Artikel und Kategorien liegen dafuer bereits im Papierkorb (TrashService),
 * es gibt also keinen zweiten Speicher. Nur einzelne Slices, die der
 * Papierkorb nicht als eigene Einheit kennt, werden hier zwischengespeichert
 * und nach Ablauf der Frist wieder verworfen.
 */
class QuickUndo
{
    /** Voreingestellte Frist in Sekunden, innerhalb derer zurueckgenommen werden kann */
    public const DEFAULT_TIMEOUT = 30;

    private const CSRF_ID = 'trash_quick_undo';

    public static function isEnabled(): bool
    {
        return (bool) rex_config::get('trash', 'quick_undo', true);
    }

    public static function getTimeout(): int
    {
        $timeout = (int) rex_config::get('trash', 'quick_undo_timeout', self::DEFAULT_TIMEOUT);

        return max(5, min(300, $timeout));
    }

    private static function table(): string
    {
        return rex::getTable('trash_slice_undo');
    }

    /**
     * Einzelnen Slice sichern (EP SLICE_DELETE).
     *
     * Der Papierkorb sichert Slices nur als Teil eines geloeschten Artikels.
     * Ein einzeln geloeschter Block hat dort keine Entsprechung, deshalb
     * dieser kurzlebige Zwischenspeicher.
     *
     * @param rex_extension_point<mixed> $ep
     */
    public static function captureSlice(rex_extension_point $ep): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $sliceId = (int) $ep->getParam('slice_id');
        if ($sliceId < 1) {
            return;
        }

        try {
            self::purgeExpired();

            $slice = rex_sql::factory();
            $slice->setQuery('SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE id = :id', ['id' => $sliceId]);
            if (0 === $slice->getRows()) {
                return;
            }

            // Spalten einzeln lesen: getRow() liefert bei "SELECT *" je nach
            // Treiber tabellenqualifizierte Schluessel ("rex_article_slice.id"),
            // die beim Zurueckschreiben keine gueltigen Spaltennamen waeren.
            $payload = [];
            foreach (rex_sql::showColumns(rex::getTable('article_slice')) as $column) {
                $name = $column['name'];
                $payload[$name] = $slice->getValue($name);
            }

            $sql = rex_sql::factory();
            $sql->setTable(self::table());
            $sql->setValue('slice_id', $sliceId);
            $sql->setValue('article_id', (int) $slice->getValue('article_id'));
            $sql->setValue('clang_id', (int) $slice->getValue('clang_id'));
            $sql->setValue('ctype_id', (int) $slice->getValue('ctype_id'));
            $sql->setValue('revision', (int) $slice->getValue('revision'));
            $sql->setValue('payload', (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            $sql->setValue('deleted_at', date('Y-m-d H:i:s'));
            $sql->insert();
        } catch (Throwable $e) {
            // Die Loeschung selbst darf daran nie scheitern
            \rex_logger::logException($e);
        }
    }

    /**
     * Hinweis mit Countdown und Rueckgaengig-Link.
     *
     * @param 'article'|'category'|'slice' $type
     * @param array<string, int|string> $params zusaetzliche Parameter fuer den Rueckgaengig-Link
     */
    public static function renderNotice(string $type, array $params): string
    {
        if (!self::isEnabled()) {
            return '';
        }

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

    /** Einzelnen Slice aus dem Zwischenspeicher zuruecknehmen */
    private static function restoreSlice(int $sliceId): string
    {
        if ($sliceId < 1) {
            return '';
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM ' . self::table() . '
             WHERE slice_id = :id AND deleted_at >= :limit
             ORDER BY id DESC LIMIT 1',
            ['id' => $sliceId, 'limit' => date('Y-m-d H:i:s', time() - self::getTimeout())],
        );
        if (0 === $sql->getRows()) {
            return \rex_view::warning(rex_i18n::msg('trash_undo_slice_expired'));
        }

        /** @var array<string, scalar|null>|null $payload */
        $payload = json_decode((string) $sql->getValue('payload'), true);
        if (!is_array($payload)) {
            return \rex_view::error(rex_i18n::msg('trash_undo_failed'));
        }

        $articleId = (int) $sql->getValue('article_id');
        $clangId = (int) $sql->getValue('clang_id');
        $ctypeId = (int) $sql->getValue('ctype_id');
        $revision = (int) $sql->getValue('revision');

        $existing = rex_sql::factory();
        $existing->setQuery('SELECT id FROM ' . rex::getTable('article_slice') . ' WHERE id = :id', ['id' => $sliceId]);

        if (0 === $existing->getRows()) {
            // Nur tatsaechlich vorhandene Spalten zurueckschreiben. Schuetzt
            // vor Payloads aelterer Versionen und vor Spalten, die es in der
            // Zwischenzeit nicht mehr gibt.
            $columns = [];
            foreach (rex_sql::showColumns(rex::getTable('article_slice')) as $column) {
                $columns[$column['name']] = true;
            }

            $insert = rex_sql::factory();
            $insert->setTable(rex::getTable('article_slice'));
            foreach ($payload as $column => $value) {
                if (isset($columns[$column])) {
                    $insert->setValue($column, $value);
                }
            }
            $insert->insert();
        }

        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE slice_id = :id', ['id' => $sliceId]);

        rex_sql_util::organizePriorities(
            rex::getTable('article_slice'),
            'priority',
            'article_id = ' . $articleId . ' AND clang_id = ' . $clangId . ' AND ctype_id = ' . $ctypeId . ' AND revision = ' . $revision,
            'priority, updatedate DESC',
        );

        rex_article_cache::delete($articleId, $clangId);

        return \rex_view::success(rex_i18n::msg('trash_undo_slice_restored'));
    }

    /**
     * Abgelaufene Slice-Zwischenspeicher verwerfen.
     * Artikel und Kategorien bleiben im Papierkorb - dort ist die Frist
     * ausdruecklich unbegrenzt.
     */
    public static function purgeExpired(): void
    {
        rex_sql::factory()->setQuery(
            'DELETE FROM ' . self::table() . ' WHERE deleted_at < :limit',
            ['limit' => date('Y-m-d H:i:s', time() - self::getTimeout())],
        );
    }

    /** Assets nur laden, wenn die Funktion aktiv ist */
    public static function addAssets(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        \rex_view::addCssFile(rex_url::addonAssets('trash', 'trash.css'));
        \rex_view::addJsFile(rex_url::addonAssets('trash', 'trash.js'));
    }
}
