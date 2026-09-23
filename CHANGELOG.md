# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [2.0.0] - 2026-09-23

Trash bekommt das Sofort-Rückgängig: direkt nach dem Löschen ein Hinweis mit Countdown und einem Klick zurück – ohne Umweg über die Papierkorb-Seite. Damit deckt ein AddOn beide Fälle ab, den schnellen Griff daneben und das späte „wo ist eigentlich …".

### 🚀 New Features (Neue Funktionen)

#### Added (Hinzugefügt)

- **Sofort-Rückgängig** für Artikel, Kategorien und einzelne Blöcke. Der Hinweis erscheint direkt nach dem Löschen, zählt die verbleibende Zeit herunter und verschwindet danach von selbst. Artikel und Kategorien liegen dabei im Papierkorb – es gibt **keinen zweiten Speicher**, der Link nimmt die Löschung nur sofort zurück. Läuft die Frist ab, ist nichts verloren.
- **Einzelne Blöcke (Slices)** werden über den Extension Point `SLICE_DELETE` erfasst. Der Papierkorb kennt Slices nur als Teil eines Artikels; ein einzeln gelöschter Block wird deshalb für die Dauer der Frist zwischengespeichert (`rex_trash_slice_undo`) und danach verworfen.
- **Einstellungsseite** unter *System › Papierkorb › Einstellungen*: Sofort-Rückgängig an/aus und Frist in Sekunden (5–300, Standard 30). Ist die Funktion aus, werden auch ihre CSS- und JS-Dateien nicht mehr geladen.
- **Englische Sprachdatei** vervollständigt; alle neuen Texte in Deutsch und Englisch.

#### Changed (Geändert)

- **Konflikt zum AddOn `undo`**: Beide gleichzeitig würden jede Löschung doppelt sichern und zwei konkurrierende Wiederherstellungswege anbieten. `package.yml` enthält deshalb einen `conflicts`-Block; REDAXO verhindert die Installation mit einer klaren Meldung. Wer nur das Sofort-Rückgängig braucht, installiert weiterhin `undo` – es bleibt eigenständig.
- **Übernahme vorhandener undo-Daten**: Beim Update werden Einträge aus `rex_article_undo` samt zugehöriger Slices in den Papierkorb überführt, sofern die Tabellen noch existieren.
- `update.php` pflegt die Tabellenstruktur nicht mehr als zweite Kopie, sondern bindet `install.php` ein (alle Definitionen sind idempotent). Das beseitigt die bisherige Doppelpflege.
- Mindestanforderung auf **REDAXO ^5.18** angehoben.

### 🔒 Security

- **Rechteprüfung beim Sofort-Rückgängig ergänzt.** Die Papierkorb-Seite ist Admins vorbehalten, der Rückgängig-Link prüfte bislang nur das CSRF-Token. Ein Backend-Benutzer ohne Admin-Rechte hätte damit fremde Löschungen zurücknehmen können. Es gilt jetzt dieselbe Hürde wie auf der Papierkorb-Seite.
- **Frist wird serverseitig durchgesetzt.** Der Countdown war reine Anzeige; der Link funktionierte unbegrenzt weiter. Bei Artikeln konnte er dadurch sogar einen später gelöschten Eintrag mit derselben ID zurückholen. Beide Wiederherstellungswege prüfen jetzt `deleted_at` gegen die eingestellte Frist.

### 🐛 Bug Fixes (Fehlerbehebungen)

- **Fatal Error ohne angemeldeten Benutzer behoben**: `rex::getUser()->getLogin()` wurde an drei Stellen ungeprüft aufgerufen. Wird ein Artikel ohne Backend-Session gelöscht (Cronjob, Konsole, API), lieferte `getUser()` `null` und der Aufruf brach ab. Der Login wird jetzt über einen Helfer ermittelt, der in diesem Fall einen leeren String liefert. Dasselbe galt für die Rechteprüfung auf der Papierkorb-Seite.
- Ein toter `$debug`-Zweig auf der Papierkorb-Seite (fest auf `false`) wurde entfernt; die Fehlermeldung steht ohnehin schon in der regulären Ausgabe und wird jetzt escaped.
- **Irreführende Countdown-Meldung korrigiert.** „Möglich noch 30 Sekunden" las sich, als sei danach alles verloren. Tatsächlich läuft nur die Sofort-Frist ab – der Papierkorb bleibt. Der Text sagt das jetzt („Noch 30 Sekunden – danach über den Papierkorb"); nur bei einzelnen Blöcken, die wirklich verworfen werden, steht „danach endgültig".
- **Zwischenspeicher einzelner Blöcke wird zuverlässig aufgeräumt.** Bisher geschah das nur beim nächsten Löschvorgang – wurde längere Zeit nichts gelöscht, blieben Einträge liegen. Jetzt räumen zusätzlich der Cronjob und der Aufruf der Papierkorb-Seite auf.

### 🧹 Code Quality

- Statische Analyse (PHPStan/rexstan, Level 8) von 37 auf **0 Findings** gebracht: präzise Array-Shapes für die Rückgabewerte von `restoreArticle()`, `deleteArticlePermanently()`, `emptyTrash()` und `insertArticleDirectly()`, saubere Typumwandlung der `rex_sql::getValue()`-Ergebnisse, ergänzte Docblock-Typen und ein korrigierter Rückgabetyp bei `getTypeName()`.
- Manuelle Transaktionen (`beginTransaction()`/`commit()`/`rollBack()`) durch `rex_sql::transactional()` ersetzt – in `deleteArticlePermanently()`, `emptyTrash()` und im Aufräum-Cronjob. Das Löschen der Slice-Meta-Daten läuft dabei als ein JOIN-Statement statt als Schleife pro Slice.

## [1.1.0] - 2026-03-14

- Spalte `deleted_by` ergänzt und auf der Papierkorb-Seite angezeigt

## Ältere Versionen

Für dieses Projekt wurde erst ab 2.0.0 ein Changelog geführt. Details zu früheren Versionen:
[Commit-Historie](https://github.com/FriendsOfREDAXO/trash/commits/main) und [Tags](https://github.com/FriendsOfREDAXO/trash/tags).
