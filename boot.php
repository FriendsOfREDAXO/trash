<?php
/**
 * Trash AddOn - Boot file
 *
 * @package redaxo\trash
 */

use FriendsOfREDAXO\trash\QuickUndo;
use FriendsOfREDAXO\trash\TrashService;

// Artikel und Kategorien in den Papierkorb legen, statt sie zu loeschen
rex_extension::register('ART_PRE_DELETED', static function (rex_extension_point $ep): void {
    (new TrashService())->handleArticleDeletion($ep);
});

// Cronjob registrieren, wenn das Cronjob AddOn installiert und aktiviert ist
if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType('rex_cronjob_trash_cleanup');
}

// ---------------------------------------------------------------------
// Sofort-Rueckgaengig (frueher das eigenstaendige AddOn "undo")
//
// Es gibt keinen eigenen Speicher: Artikel und Kategorien liegen im
// Papierkorb, einzelne Bloecke im Snapshot von structure/history. Der
// Hinweis verlinkt jeweils nur die sofortige Ruecknahme.
// ---------------------------------------------------------------------
if (rex::isBackend() && null !== rex::getUser() && QuickUndo::isEnabled()) {
    QuickUndo::addAssets();

    // ART_DELETED feuert ausschliesslich fuer Artikel (startarticle=0),
    // CAT_DELETED fuer Kategorien - der Typ steht also schon durch den
    // Extension Point fest. Beide feuern je Sprache, der Hinweis wird
    // deshalb nur einmal ausgegeben (siehe renderNotice()).
    rex_extension::register('ART_DELETED', static function (rex_extension_point $ep): string {
        return QuickUndo::renderNotice('article', [
            'article_id' => (int) $ep->getParam('id'),
            'page' => 'structure',
            'category_id' => (int) $ep->getParam('parent_id', 0),
        ]);
    });

    // Bei Kategorien verpackt rex_api_category_delete die Meldung in ein
    // rex_api_result und gibt sie escaped aus - der Rueckgabewert des
    // Extension Points erreicht die Seite also nicht als HTML. Der Hinweis
    // wird deshalb gemerkt und spaeter ueber PAGE_TITLE_SHOWN ausgegeben.
    rex_extension::register('CAT_DELETED', static function (rex_extension_point $ep): void {
        QuickUndo::queueNotice('category', [
            'article_id' => (int) $ep->getParam('id'),
            'page' => 'structure',
            'category_id' => (int) $ep->getParam('parent_id', 0),
        ]);
    });

    rex_extension::register('PAGE_TITLE_SHOWN', static function (rex_extension_point $ep): string {
        return (string) $ep->getSubject() . QuickUndo::flushQueuedNotice();
    });

    // Bloecke werden von structure/history gesichert; ohne das Plugin gibt es
    // fuer sie nichts zurueckzuholen und damit auch keinen Hinweis.
    rex_extension::register('SLICE_DELETED', static function (rex_extension_point $ep): string {
        if (!QuickUndo::hasHistory()) {
            return '';
        }

        return QuickUndo::renderNotice('slice', [
            'slice_id' => (int) $ep->getParam('slice_id'),
            'page' => 'content/edit',
            'mode' => 'edit',
            'article_id' => (int) $ep->getParam('article_id'),
            'ctype' => (int) $ep->getParam('ctype', 1),
        ]);
    });

    // Rueckgaengig-Link auswerten, bevor die Seite aufgebaut wird
    if ('' !== rex_request('trash_undo', 'string', '')) {
        rex_extension::register('PACKAGES_INCLUDED', static function (): void {
            $message = QuickUndo::handleRequest();
            if ('' !== $message) {
                rex_extension::register('PAGE_TITLE_SHOWN', static fn (): string => $message);
            }
        }, rex_extension::LATE);
    }
}
