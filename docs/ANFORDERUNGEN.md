# Anforderungen: Bereinigung von `fileadmin/_migrated`

Stand: v2 – Entscheidungen aus der Abstimmung in Abschnitt 6 eingearbeitet, verbleibende Fragen in Abschnitt 7.

## 1. Ausgangslage

- TYPO3 13.4 auf Ubuntu, eine Installation mit mehreren Seitenbäumen (Multi-Root) und mehreren Domains.
- In `fileadmin/_migrated/` liegen die Ordner `content_uploads`, `media` und `pics`. Sie stammen aus der
  FAL-Migration (Upgrade von TYPO3 ≤ 6.x) und enthalten Dateien, die damals in `uploads/` lagen.
- Unklar ist, welche Dateien noch genutzt werden, zu welcher Website sie gehören und wer dafür verantwortlich ist.

## 2. Ziele

| # | Ziel | Messbar durch |
|---|---|---|
| Z1 | Vollständige Übersicht über alle Dateien in den drei Ordnern | Anzahl/Größe je Ordner, abgeglichen mit `sys_file` |
| Z2 | Für jede Datei: alle Stellen, an denen sie eingebunden oder verlinkt ist | `verwendungen.csv` |
| Z3 | Zuordnung zu Seite, Site und Domain | Seite, Seitenpfad, Site, URL je Fundstelle |
| Z4 | „Von wem“ = **Domain** – auch für versteckte und gelöschte Verwendungen | Domain je Datei/Fundstelle, `domain_dateien.csv` |
| Z5 | Entscheidungsgrundlage: behalten / prüfen / löschen | Status + Begründung je Datei |
| Z6 | Links von außen und von TYPO3-Seiten funktionieren weiter | Access-Log-Auswertung, Referer → TYPO3-Seite, Beobachtung in der Quarantäne |
| Z7 | Sicheres, umkehrbares Entfernen | Quarantäne mit Manifest, Wiederherstellung, automatisches Zurückholen bei Abruf |

Nicht-Ziel (vorerst): Dateien in eine neue Ordnerstruktur umziehen. Das kann über die TYPO3-Dateiliste erfolgen,
weil FAL-Referenzen beim Verschieben erhalten bleiben – Grundlage ist die Liste der Dateien mit `IN_VERWENDUNG` je Site.

## 3. Funktionale Anforderungen

### F1 Inventar
- F1.1 Alle Dateien rekursiv aus den Ordnern lesen (Pfad, Größe, Änderungsdatum).
- F1.2 Abgleich mit `sys_file` (fileadmin-Storage): indiziert ja/nein, Datei fehlt auf der Platte ja/nein.
- F1.3 Duplikate per SHA1 erkennen – innerhalb von `_migrated` und gegenüber dem restlichen fileadmin.
- F1.4 Weitere Unterordner von `_migrated` (z. B. `RTE`) melden und optional einbeziehen.

### F2 Verwendungen finden
- F2.1 FAL-Referenzen (`sys_file_reference`) inkl. gelöschter, versteckter und Workspace-Referenzen.
- F2.2 Links aus RTE/Link-Feldern über den Referenzindex (`sys_refindex`).
- F2.3 Fest eingetragene Pfade (`fileadmin/_migrated/...`, URL-kodiert) und Datei-Links (`t3://file?uid=`,
  `<link file:…>`) in allen Text-Spalten der Datenbank – unabhängig davon, ob der Referenzindex aktuell ist.
- F2.4 Pfade in TypoScript-, Fluid-, CSS-, JS- und Konfigurationsdateien (Sitepackage, `config/`, `fileadmin`).
- F2.5 Verweise auf ganze Ordner (Dateisammlungen, Dateifreigaben, TypoScript) erkennen.
- F2.6 Direkte Abrufe aus den Webserver-Logs (externe Links, Suchmaschinen), Bots getrennt.
- F2.7 Defekte Verweise melden: Pfade, die auf nicht existierende `_migrated`-Dateien zeigen; verwendete Dateien,
  die auf der Platte fehlen; im Log angefragte, aber fehlende Dateien.

### F3 Kontext je Fundstelle
- F3.1 Datensatz: Tabelle, Feld, UID, Titel, Inhaltstyp, Sprache, Status (gelöscht/versteckt/Zeitsteuerung/Workspace).
- F3.2 Seite: Titel, Seitentyp, Rootline, Sichtbarkeit unter Berücksichtigung der Elternseiten.
- F3.3 Site und Domain: Zuordnung über `config/sites/*/config.yaml` (rootPageId → base, Sprach-Basen, baseVariants);
  die Domain richtet sich nach der Sprache des Datensatzes. URL der Seite (Slug der Übersetzung).
- F3.4 Die Domain wird auch für gelöschte Datensätze/Seiten (Papierkorb) und versteckte Seiten ermittelt.
- F3.5 Access-Log: Domain des Abrufs (vhost/Logdateiname), Referer intern (→ TYPO3-Seite über Slug/`?id=`) oder extern.
  Interne Referer ohne aktive Referenz in der DB werden hervorgehoben.
- F3.6 Ersteller/Bearbeiter (`sys_history`, `sys_log`) nur informativ – die Historie ist gekürzt.

### F4 Klassifizierung
Versteckte und gelöschte (Papierkorb-)Verwendungen gelten als Verwendung. Es gilt der Status mit der höchsten Priorität:

| Status | Regel |
|---|---|
| `IN_VERWENDUNG` | ≥ 1 aktive Fundstelle (sichtbarer Datensatz auf sichtbarer Seite in einer Site, Datensatz auf Root-Ebene, oder Code/TypoScript) |
| `VERSTECKT_VERWENDET` | ≥ 1 Fundstelle versteckt, zeitgesteuert, Workspace, Inhalt in Ordner, Seite ohne Site |
| `IM_PAPIERKORB` | nur Fundstellen in gelöschten Datensätzen/Seiten (wiederherstellbar) |
| `ABGERUFEN_OHNE_REFERENZ` | keine Fundstelle, aber Abrufe (keine Bots) im Access-Log |
| `PRUEFEN` | Fundstelle ohne Datensatz-UID; oder sonst unbenutzt, aber Bot-Abrufe bzw. der ganze Ordner ist referenziert |
| `UNBENUTZT` | keine Fundstelle (verwaiste Referenzen auf nicht existierende Datensätze zählen nicht) und keine Abrufe – **einziger Löschkandidat** |

### F5 Ausgabe
- Zusammenfassung (Markdown) mit Mengen und Speicherplatz je Ordner und Status, Dateien je Site, Warnungen.
- CSV je Datei und je Fundstelle (Excel-tauglich).
- Kandidatenlisten als Eingabe für die Quarantäne.

### F6 Quarantäne und Bereinigung
- F6.1 Probelauf als Standard, Ausführung nur mit `--execute`.
- F6.2 Erneute DB-Prüfung unmittelbar vor dem Verschieben; Liste darf nicht älter als 7 Tage sein.
- F6.3 Ziel außerhalb des Web-Roots, Verzeichnisstruktur bleibt erhalten, Manifest mit SHA1.
- F6.4 Wiederherstellung anhand des Manifests.
- F6.4a Beobachtung: Abrufe verschobener Dateien im Access-Log seit dem Quarantäne-Zeitpunkt finden und genau diese
  zurückholen (für einen täglichen Cronjob).
- F6.5 SQL für die endgültige Bereinigung der `sys_file`-Einträge wird erzeugt, aber nicht ausgeführt.

## 4. Nicht-funktionale Anforderungen

- N1 **Nur lesend** während der Analyse; keine Installation in TYPO3, kein TYPO3-Bootstrap.
- N2 Läuft mit der PHP-CLI, die auf dem Server ohnehin vorhanden ist; keine weiteren Abhängigkeiten.
- N3 Robust gegenüber unterschiedlichen Schema-Ständen (optionale Spalten, Extension-Tabellen).
- N4 Laufzeit: auch bei großen Datenbanken im Minutenbereich; Volltextsuche abschaltbar.
- N5 Nachvollziehbarkeit: jede Entscheidung hat eine Begründung und eine Fundstelle.

## 5. Vorgehen (Ablauf)

1. Referenzindex aktualisieren (`referenceindex:update`).
2. Analyse laufen lassen, Zusammenfassung lesen.
3. `PRUEFEN`-Dateien mit den Site-Verantwortlichen klären (Filter in `dateien.csv` nach Site).
4. `UNBENUTZT` in Quarantäne verschieben, Frist abwarten (Vorschlag: 4–8 Wochen), Fehlermeldungen/404 im Log beobachten.
5. Nach der Frist: SQL-Bereinigung, Quarantäne löschen.

## 6. Entscheidungen

| # | Frage | Entscheidung | Umsetzung |
|---|---|---|---|
| 1 | Was bedeutet „von wem“? | die **Domain** | Domain je Fundstelle (Site + Sprache), je Datei und `domain_dateien.csv` |
| 2 | Ist die Historie vollständig? | nein, gekürzt (Installation > 15 Jahre) | Redakteursangaben nur noch informativ in `verwendungen.csv` |
| 3 | Gelten versteckte Inhalte/Papierkorb als unbenutzt? | **nein** – Domain trotzdem angeben | eigene Status `VERSTECKT_VERWENDET`, `IM_PAPIERKORB`; keine Löschkandidaten |
| 4 | Sollen alte Links weiter funktionieren? | **ja**; prüfen, ob die verlinkenden Seiten in TYPO3 existieren | Log-Abrufe schützen vor Löschung; Referer → TYPO3-Seite; Beobachtung in der Quarantäne |
| 5 | news/powermail? | nicht installiert | keine Sonderbehandlung nötig |

## 7. Verbleibende offene Fragen

Die Fragen 1–5 der ersten Fassung sind in Abschnitt 6 beantwortet. Offen sind noch:

1. **Logs**: Apache oder nginx? Pfad und Rotationsdauer? Gibt es eigene Logs je Domain (vHost) bzw. das Format
   `vhost_combined`? Ohne Domain im Log ist bei Abrufen ohne Referer nicht erkennbar, über welche Domain sie kamen.
2. **Log-Aufbewahrung**: Können die Logs künftig länger aufbewahrt werden (Vorschlag: ≥ 6 Monate), damit auch selten
   genutzte Links (PDF in alten E-Mails, Partnerseiten) sicher erkannt werden?
3. **Quarantäne-Frist**: Wie lange sollen Löschkandidaten in Quarantäne bleiben (Vorschlag: 3–6 Monate mit täglicher
   Beobachtung per `--requested`)?
4. **Bot-Abrufe**: Sollen Dateien, die nur noch von Suchmaschinen abgerufen werden, gelöscht werden dürfen
   (derzeit Status `PRUEFEN`)?
5. **Workspaces**: Werden Workspaces genutzt? (Workspace-Versionen gelten derzeit als „versteckt verwendet“.)
6. **Weitere Systeme**: Greifen andere Anwendungen (Newsletter-Tool, App, Intranet) direkt auf Dateien in `fileadmin` zu?
7. **Eigene Extensions**: Speichern sie Dateipfade ohne `fileadmin/_migrated/`-Präfix (z. B. nur Dateinamen)? Das findet die Suche nicht.
8. **Aufräumen statt Löschen**: Sollen verwendete Dateien je Domain in eine sinnvolle Struktur (z. B. `fileadmin/<domain>/…`)
   umgezogen werden? Dann bräuchte es für extern aufgerufene Dateien 301-Weiterleitungen, damit alte Links funktionieren.
9. **Backup**: Gibt es ein aktuelles Backup von `fileadmin` und Datenbank?

## 8. Mögliche Erweiterungen

- Bericht je Site (eigene CSV/Mail an Verantwortliche).
- HTML-Bericht mit Vorschaubildern.
- Umzug verwendeter Dateien in eine neue Ordnerstruktur über einen TYPO3-Konsolenbefehl (Referenzen bleiben erhalten).
- Automatische 301-Weiterleitungen für extern aufgerufene Dateien (`sys_redirect`).
- Erkennung „wann zuletzt auf der Website ausgeliefert“ über längere Log-Historie (z. B. GoAccess/Matomo).
