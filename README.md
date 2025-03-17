# Trash - Dein Artikel-Retter für REDAXO 🗑️

**Ups, 🚨 Artikel gelöscht? Kein Problem mehr!** 

Hey! Kennt ihr das? Ein falscher Klick und *schwupps*. Jemand vermisst einen Artikel oder eine Kategoerie: "Wo ist eigentlich die Seite XY, können Sie mal eben…" 😱
Ja, der Klassiker. In REDAXO bislang mühsam. Ein komplettes Backup zurückspielen? - Ne besser nicht! Denn die Reakteur:innen haben zwischenzeitlich weitergearbeitet. 
Den Artikel mühsam aus dem Backup extrahieren? 😱😱 - Ne das ist Arbeit die niemand bezahlt. 

😄 Hier ist Deine Rettung!

## Was macht dieser Lebensretter?

Trash fängt deine gelöschten Artikel ab, bevor sie ins digitale Nirvana verschwinden. Wie ein treuer Hund 🐕 bringt er dir die weggeworfenen Stöckchen wieder zurück - wenn du sie brauchst!

## Die coolen Features

- **Automatische Rettung** - Trash schnappt sich jeden gelöschten Artikel blitzschnell
- **Volle Power** - Alle Inhaltsblöcke, Module und sogar die Arbeitsversion bleiben erhalten
- **Original-IDs** - Artikel werden wenn möglich unter ihrer ursprünglichen ID wiederhergestellt
- **Meta-Daten Erhaltung** - Alle Metafelder von AddOns wie MetaInfo oder YRewrite bleiben erhalten
- **Selbstreinigend** - Der integrierte Cronjob räumt alte Artikel automatisch auf (wenn gewünscht)
- **One-Click-Wonder** - Artikel mit einem Klick komplett wiederherstellen
- **Versionierungs-freundlich** - Deine Arbeitsversionen bleiben erhalten (**ja, wirklich!**)
- **Mehrsprachig** - Deine Übersetzungen sind genauso wichtig wie die Hauptsprache
- **Aufgeräumt** - Übersichtliche Liste zum einfachen Finden deiner verlorenen Schätze

## Der Alltag mit Trash

Einfach wie Kuchen essen:
- **"Oh nein, falscher Artikel gelöscht!"** → Ab in den Papierkorb, wiederherstellen, fertig!
- **"Der alte Kram kann jetzt wirklich weg"** → Endgültig löschen
- **"Großreinemachen"** → Papierkorb komplett leeren oder einfach den Cronjob für dich arbeiten lassen

## Insider-Tipps

- Nur für Admins sichtbar (damit nicht jeder in deinem Müll wühlt)
- Wenn die Elternkategorie weg ist, landet der wiederhergestellte Artikel einfach in der Hauptebene
- Falls die Original-ID bereits vergeben ist, bekommt der wiederhergestellte Artikel automatisch eine neue ID
- Meta-Daten werden nur wiederhergestellt, wenn die entsprechenden AddOns noch installiert sind
- Mit dem Cronjob kannst du festlegen, wie lange Artikel im Papierkorb bleiben sollen (1 Tag bis 1 Jahr)

## Technische Details

- Erhält alle REDAXO-Artikel-Daten (Slices, Module, Revisionen)
- Speichert Meta-Daten in JSON-Format für einfache Wiederherstellung
- Unterstützt das Structure/Version Plugin (falls installiert)
- Saubere Deinstallation mit vollständiger Entfernung aller Tabellen
- Optionaler Cronjob zum automatischen Aufräumen alter Einträge

# Technische Details für Entwickler

## Wie der Papierkorb funktioniert

Der Trash-AddOn nutzt REDAXOs Extension Points, um Artikel und ihre Inhalte vor dem permanenten Löschen zu sichern. Hier ein Überblick über die technische Architektur und den Datenfluss:

### Datenmodell

Das AddOn verwendet drei Tabellen:

1. **rex_trash_article**
   - Speichert die Basisinformationen gelöschter Artikel/Kategorien
   - Standard-Eigenschaften wie name, parent_id, status etc. direkt als Spalten
   - JSON-kodierte Attribute (path, priority, etc.) in der `attributes`-Spalte
   - Meta-Attribute (von anderen AddOns) in der `meta_attributes`-Spalte

2. **rex_trash_article_slice**
   - Sichert alle Inhaltsblöcke (Slices) der gelöschten Artikel
   - Alle Standard-Felder wie value1-20, media1-10, etc.
   - Speichert auch Revisionen für Arbeits- und Live-Versionen

3. **rex_trash_slice_meta**
   - Speichert unbekannte/dynamische Felder von Slices
   - Verbunden mit `trash_article_slice` über die `trash_slice_id`
   - JSON-kodierte Meta-Attribute in der `meta_data`-Spalte

### Ablauf beim Löschen

1. **Extension Point abfangen**
   - Der Papierkorb registriert sich am `ART_PRE_DELETED` Extension Point
   - Wenn ein Artikel/Kategorie gelöscht wird, greift der Papierkorb ein

2. **Artikel-Daten sichern**
   - Grunddaten werden in `rex_trash_article` gespeichert
   - Standard-Meta-Attribute wie SEO-Felder werden identifiziert und als JSON gespeichert
   - Datum der Löschung wird festgehalten

3. **Slices sichern**
   - Alle Inhaltsblöcke werden in der Tabelle `rex_trash_article_slice` gesichert
   - Für jede Sprache (clang) und jede Revision (live/Arbeitsversion)
   - Standard-Felder direkt kopieren
   - Meta-Attribute werden identifiziert und in der Meta-Tabelle gespeichert

### Ablauf beim Wiederherstellen

1. **Artikel wiederherstellen**
   - Prüfung, ob Original-ID verfügbar ist
   - Falls möglich: Artikel mit Original-ID wiederherstellen
   - Falls nicht: Neuen Artikel erstellen und ID-Änderung protokollieren
   - Meta-Attribute zurückschreiben (nur wenn entsprechende Spalten existieren)

2. **Slices wiederherstellen**
   - Inhaltsblöcke nach Sprache und Revision für den Artikel wiederherstellen
   - Alle Standard-Felder direkt zurückschreiben
   - Meta-Attribute werden erst nach dem Slice-Insert als separates Update angewendet

3. **Aufräumen**
   - Daten aus allen drei Papierkorb-Tabellen löschen
   - Cache-Invalidierung und Content-Generierung anstoßen

### Automatische Bereinigung

Ein Cronjob kann konfiguriert werden, um den Papierkorb automatisch zu bereinigen:

1. **Zeitgesteuerte Bereinigung**
   - Artikel älter als X Tage werden automatisch entfernt
   - Konfigurierbare Zeitspannen (1 Tag bis 1 Jahr)

2. **Transaktionssicherheit**
   - Alle Löschvorgänge verwenden Transaktionen
   - Entweder werden alle zusammengehörigen Daten gelöscht oder keine
   - Garantiert Datenintegrität

### Integration mit anderen AddOns

- **Structure/Version**
   - Arbeits- und Live-Versionen werden separat gesichert und wiederhergestellt
   - Versionierung bleibt nach Wiederherstellung erhalten

- **Meta-Infos und Custom-Felder**
   - Meta-Informationen von MetaInfo-AddOn werden gesichert
   - SEO-/YRewrite-Daten werden erhalten
   - Dynamisch hinzugefügte Felder werden erkannt und gesichert

### Anpassbarkeit für Entwickler

Um den Papierkorb mit eigenen AddOns zu integrieren:

1. **Eigene Metadaten sichern**
   - Achten Sie darauf, dass Ihre benutzerdefinierten Felder in der Artikel-Tabelle erkannt werden
   - Namen der Felder sollten eindeutig sein
   - Komplexe Datenstrukturen werden automatisch als JSON serialisiert

2. **Erweiterungsmöglichkeiten**
   - Extension Points könnten hinzugefügt werden (in zukünftigen Versionen)
   - Die Tabellen können als Basis für eigene "Undo"-Funktionalitäten dienen
   - Weitere Inhaltstypen (Media, etc.) könnten über die gleiche Architektur gesichert werden

## Wer hat's gemacht?

**Thomas Skerbis** 

*Problem? Idee? Fehler gefunden?* Schreib's auf GitHub oder schick 'ne Flaschenpost!

## Lizenz 

MIT - Nimm's, nutz's, mach was draus!

---

**Merke:** Löschen ist sowas von gestern - mit Trash hast du immer einen Plan B! 🚀
