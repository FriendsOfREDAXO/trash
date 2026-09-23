# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [2.0.0] - 2026-09-24

Trash bekommt das Sofort-Rückgängig: direkt nach dem Löschen ein Hinweis mit Countdown und einem Klick zurück – ohne Umweg über die Papierkorb-Seite. Damit deckt ein AddOn beide Fälle ab, den schnellen Griff daneben und das späte „wo ist eigentlich …".

### 🚀 New Features (Neue Funktionen)

#### Added (Hinzugefügt)

- **Sofort-Rückgängig** für Artikel, Kategorien und einzelne Blöcke. Der Hinweis erscheint direkt nach dem Löschen, zählt die verbleibende Zeit herunter und verschwindet danach von selbst. Artikel und Kategorien liegen dabei im Papierkorb – es gibt **keinen zweiten Speicher**, der Link nimmt die Löschung nur sofort zurück. Läuft die Frist ab, ist nichts verloren.
- **Einzelne Blöcke (Slices)**: Der Toast holt sie aus dem Snapshot von `structure/history`, das bei `SLICE_DELETE` ohnehin einen anlegt – dort liegen sie je nach Einstellung 7 bis 30 Tage statt weniger Sekunden. Trash speichert dafür **nichts** zusätzlich. Ohne das Plugin erscheint beim Löschen eines Blocks kein Hinweis, weil es dann nichts zurückzuholen gäbe.
- **Einstellungsseite** unter *System › Papierkorb › Einstellungen*: Sofort-Rückgängig an/aus und Frist in Sekunden (5–300, Standard 30). Ist die Funktion aus, werden auch ihre CSS- und JS-Dateien nicht mehr geladen.
- **Menüsymbol zeigt den Zustand**: Ist der Papierkorb leer, steht im Menü ein neutraler Umriss (`fa-regular fa-trash-can`). Liegt etwas drin, wechselt er auf die gefüllte, orange eingefärbte Variante – im dunklen Menü wäre der Unterschied zwischen Umriss und Füllung allein kaum zu erkennen. Gezählt wird über `TrashService::countVisibleFor()`, das die Sichtbarkeit kapselt: Bekommt das AddOn später eine eingeschränkte Sicht (etwa „nur eigene Löschungen"), bleiben Symbol und Liste dadurch zwangsläufig konsistent.
- **Englische Sprachdatei** vervollständigt; alle neuen Texte in Deutsch und Englisch.

#### Changed (Geändert)

- **Nicht mehr nur für Admins.** Bisher war der Papierkorb komplett Admins vorbehalten – jede versehentliche Löschung eines Redakteurs brauchte einen Admin. Jetzt gibt es zwei Rechte: `trash[]` zum Ansehen und Wiederherstellen, `trash[delete]` zusätzlich fürs endgültige Löschen und Leeren. **Admins haben beides automatisch und sehen weiterhin den gesamten Papierkorb.** Alle anderen sehen ausschließlich ihre eigenen Löschungen (gefiltert über `deleted_by`) – fremde Einträge erscheinen weder in der Liste noch lassen sie sich über eine geratene ID wiederherstellen oder löschen. „Papierkorb leeren" entfernt entsprechend nur die sichtbaren Einträge. Die Sicht ist in `TrashService::visibilityCondition()` gebündelt, sodass Liste, Zählung, Einzelaktionen und Menüsymbol zwangsläufig übereinstimmen.
- **Übersichtlichere Papierkorb-Liste.** Statt technischer Spalten (Eltern-ID, Template-ID, Priorität, Original-ID als eigene Spalten) zeigt die Liste jetzt ein Symbol für Artikel/Kategorie samt Online-Status, den Namen mit einer Zusatzzeile („Artikel · 12 Blöcke · Originale ID 42") sowie Löschzeitpunkt, Urheber und Sprachen. Ein einleitender Satz erklärt, was der Papierkorb tut; „Papierkorb leeren" erscheint nur, wenn etwas drin ist.
- **Spalte „Sprachen" umbenannt in „Inhalte in".** Sie zeigte nie, in welcher Sprache gelöscht wurde – gelöscht wird immer in allen –, sondern in welchen Sprachen Inhalte gesichert wurden. Bei Kategorien steht dort jetzt „ohne Inhalte" statt eines irreführenden „Keine".
- **Konflikt zum AddOn `undo`**: Beide gleichzeitig würden jede Löschung doppelt sichern und zwei konkurrierende Wiederherstellungswege anbieten. `package.yml` enthält deshalb einen `conflicts`-Block; REDAXO verhindert die Installation mit einer klaren Meldung. Wer nur das Sofort-Rückgängig braucht, installiert weiterhin `undo` – es bleibt eigenständig.
- **Übernahme vorhandener undo-Daten**: Beim Update werden Einträge aus `rex_article_undo` samt zugehöriger Slices in den Papierkorb überführt, sofern die Tabellen noch existieren.
- `update.php` pflegt die Tabellenstruktur nicht mehr als zweite Kopie, sondern bindet `install.php` ein (alle Definitionen sind idempotent). Das beseitigt die bisherige Doppelpflege.
- Mindestanforderung auf **REDAXO ^5.18** angehoben.

### 🔒 Security

- **Rechteprüfung beim Sofort-Rückgängig ergänzt.** Die Papierkorb-Seite ist Admins vorbehalten, der Rückgängig-Link prüfte bislang nur das CSRF-Token. Ein Backend-Benutzer ohne Admin-Rechte hätte damit fremde Löschungen zurücknehmen können. Es gilt jetzt dieselbe Hürde wie auf der Papierkorb-Seite.
- **Frist wird serverseitig durchgesetzt.** Der Countdown war reine Anzeige; der Link funktionierte unbegrenzt weiter. Bei Artikeln konnte er dadurch sogar einen später gelöschten Eintrag mit derselben ID zurückholen. Beide Wiederherstellungswege prüfen jetzt `deleted_at` gegen die eingestellte Frist.

### 🐛 Bug Fixes (Fehlerbehebungen)

- **Ein zweiter Eintrag mit derselben ID landete nicht im Papierkorb.** Die Prüfung gegen Mehrfacheinträge (der Extension Point feuert je Sprache) verglich nur die Artikel-ID. `rex_sql::setNewId()` vergibt neue IDs jedoch als `MAX(id) + 1`: Löscht man den zuletzt angelegten Artikel, sinkt `MAX(id)` wieder, und der nächste neu angelegte Artikel bekommt exakt dieselbe ID. Der ältere Papierkorb-Eintrag galt dann als „schon vorhanden", die zweite Löschung wurde stillschweigend übergangen – der Inhalt war weg. Kategorien sind davon genauso betroffen wie Artikel, da sie in derselben Tabelle liegen und sich den ID-Raum teilen. Die Prüfung ist jetzt zusätzlich auf den laufenden Löschvorgang begrenzt.
- **Kategorie-Hinweis erschien nicht.** `rex_api_category_delete` verpackt die Meldung in ein `rex_api_result` und gibt sie escaped aus – der Rückgabewert von `CAT_DELETED` erreichte die Seite nie als HTML. Der Hinweis wird jetzt vorgemerkt und über `PAGE_TITLE_SHOWN` ausgegeben.
- **Doppelte Hinweise vermieden.** `ART_DELETED` und `CAT_DELETED` feuern je Sprache; der Toast erschien dadurch mehrfach. Er wird jetzt nur einmal je Seitenaufruf ausgegeben.
- Die Typ-Erkennung im Hinweis wertete `status` (den Online-Status) als Kennzeichen für „ist Kategorie" aus. Da `ART_DELETED` ausschließlich für Artikel und `CAT_DELETED` für Kategorien feuert, steht der Typ längst durch den Extension Point fest.
- **Fatal Error ohne angemeldeten Benutzer behoben**: `rex::getUser()->getLogin()` wurde an drei Stellen ungeprüft aufgerufen. Wird ein Artikel ohne Backend-Session gelöscht (Cronjob, Konsole, API), lieferte `getUser()` `null` und der Aufruf brach ab. Der Login wird jetzt über einen Helfer ermittelt, der in diesem Fall einen leeren String liefert. Dasselbe galt für die Rechteprüfung auf der Papierkorb-Seite.
- Ein toter `$debug`-Zweig auf der Papierkorb-Seite (fest auf `false`) wurde entfernt; die Fehlermeldung steht ohnehin schon in der regulären Ausgabe und wird jetzt escaped.
- **Irreführende Countdown-Meldung korrigiert.** „Möglich noch 30 Sekunden" las sich, als sei danach alles verloren. Tatsächlich läuft nur die Sofort-Frist ab – der Papierkorb bleibt. Der Text sagt das jetzt („Noch 30 Sekunden – danach über den Papierkorb"); nur bei einzelnen Blöcken, die wirklich verworfen werden, steht „danach endgültig".
- **Kein eigener Zwischenspeicher für Blöcke mehr.** Eine frühere Fassung legte gelöschte Blöcke 30 Sekunden lang in einer eigenen Tabelle ab – eine schlechtere Kopie dessen, was `structure/history` ohnehin tut, mit deutlich kürzerer Frist. Die Tabelle entfällt ersatzlos.

### 🧹 Code Quality

- Statische Analyse (PHPStan/rexstan, Level 8) von 37 auf **0 Findings** gebracht: präzise Array-Shapes für die Rückgabewerte von `restoreArticle()`, `deleteArticlePermanently()`, `emptyTrash()` und `insertArticleDirectly()`, saubere Typumwandlung der `rex_sql::getValue()`-Ergebnisse, ergänzte Docblock-Typen und ein korrigierter Rückgabetyp bei `getTypeName()`.
- Manuelle Transaktionen (`beginTransaction()`/`commit()`/`rollBack()`) durch `rex_sql::transactional()` ersetzt – in `deleteArticlePermanently()`, `emptyTrash()` und im Aufräum-Cronjob. Das Löschen der Slice-Meta-Daten läuft dabei als ein JOIN-Statement statt als Schleife pro Slice.

## [1.1.0] - 2026-03-14

- Spalte `deleted_by` ergänzt und auf der Papierkorb-Seite angezeigt

## Ältere Versionen

Für dieses Projekt wurde erst ab 2.0.0 ein Changelog geführt. Details zu früheren Versionen:
[Commit-Historie](https://github.com/FriendsOfREDAXO/trash/commits/main) und [Tags](https://github.com/FriendsOfREDAXO/trash/tags).
