# Anforderungen: Bereinigung von `fileadmin/_migrated`

Stand: Entwurf v1 – Abschnitt 6 enthält die offenen Fragen, die vor dem Löschen geklärt werden sollten.

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
| Z4 | Verantwortliche ermitteln („von wem“) | Ersteller/Bearbeiter des einbindenden Inhalts |
| Z5 | Entscheidungsgrundlage: behalten / prüfen / löschen | Status + Begründung je Datei |
| Z6 | Sicheres, umkehrbares Entfernen | Quarantäne mit Manifest und Wiederherstellung |

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
- F3.3 Site: Zuordnung über `config/sites/*/config.yaml` (rootPageId → base/Domain), URL der Seite.
- F3.4 Personen: Ersteller und letzter Bearbeiter des Datensatzes sowie wer die Datei angehängt hat
  (`sys_history`, `sys_log`, ggf. `cruser_id`).

### F4 Klassifizierung
| Status | Regel |
|---|---|
| `IN_VERWENDUNG` | ≥ 1 aktive Fundstelle (sichtbarer Datensatz auf sichtbarer Seite in einer Site, Datensatz auf Root-Ebene, oder Code/TypoScript) |
| `PRUEFEN` | sonst ≥ 1 Fundstelle, die nicht öffentlich ist (versteckt, zeitgesteuert, Workspace, Inhalt in Ordner, Seite ohne Site); oder `UNBENUTZT`/`NUR_GELOESCHTE…` mit Log-Abrufen bzw. referenziertem Ordner |
| `NUR_GELOESCHTE_REFERENZEN` | nur Fundstellen in gelöschten Datensätzen/Seiten oder verwaiste Referenzen |
| `UNBENUTZT` | keine Fundstelle |

### F5 Ausgabe
- Zusammenfassung (Markdown) mit Mengen und Speicherplatz je Ordner und Status, Dateien je Site, Warnungen.
- CSV je Datei und je Fundstelle (Excel-tauglich).
- Kandidatenlisten als Eingabe für die Quarantäne.

### F6 Quarantäne und Bereinigung
- F6.1 Probelauf als Standard, Ausführung nur mit `--execute`.
- F6.2 Erneute DB-Prüfung unmittelbar vor dem Verschieben; Liste darf nicht älter als 7 Tage sein.
- F6.3 Ziel außerhalb des Web-Roots, Verzeichnisstruktur bleibt erhalten, Manifest mit SHA1.
- F6.4 Wiederherstellung anhand des Manifests.
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
6. Optional zweite Runde für `NUR_GELOESCHTE_REFERENZEN`, nachdem der Papierkorb geleert wurde
   (z. B. `vendor/bin/typo3 cleanup:deletedrecords`).

## 6. Offene Fragen

Bitte beantworten – davon hängen Regeln und Voreinstellungen ab:

1. **„Von wem“** – was ist gemeint?
   a) welche **Website/Domain** die Datei nutzt (ist abgedeckt),
   b) welche **Redakteurin/welcher Redakteur** den Inhalt gepflegt hat (abgedeckt, soweit `sys_history`/`sys_log` noch Daten haben),
   c) die **ursprüngliche Quelle** vor der Migration (alte TYPO3-Version, alte Domain)? Für c) bräuchte man ein altes DB-Backup.
2. **Aufbewahrung der Historie**: Läuft ein Scheduler-Task, der `sys_history`/`sys_log` kürzt? Wenn ja, wie lange reicht sie zurück?
3. **Papierkorb**: Dürfen gelöschte Inhalte endgültig verworfen werden? Dann können `NUR_GELOESCHTE_REFERENZEN` mit weg.
4. **Versteckte Inhalte/Seiten**: Gelten sie als „in Verwendung“ (könnten wieder aktiviert werden) oder als löschbar?
5. **Workspaces**: Werden Workspaces genutzt?
6. **Externe Verlinkung**: Ist es wichtig, dass alte direkte Links (z. B. PDFs in E-Mails, auf Partnerseiten, bei Google) weiter funktionieren?
   Dann Logs über einen längeren Zeitraum auswerten, ggf. Weiterleitungen auf neue Pfade.
7. **Logs**: Apache oder nginx? Pfad, Rotationsdauer, eigene Logs pro Domain (vhost)?
8. **Weitere Systeme**: Greifen andere Anwendungen (Newsletter-Tool, App, Intranet) direkt auf Dateien in `fileadmin` zu?
9. **Extensions**: Welche Extensions mit eigenen Datei-Feldern sind installiert (z. B. `news`, `powermail`, eigene)?
   Werden Dateien dort per Pfad statt per FAL gespeichert? (Die Volltextsuche findet Pfade; reine Dateinamen ohne Pfad nicht.)
10. **Verantwortlichkeit**: Wer entscheidet je Site über `PRUEFEN`-Fälle? Soll pro Site eine eigene Liste erzeugt werden?
11. **Aufräumen statt Löschen**: Sollen verwendete Dateien in eine sinnvolle Struktur (z. B. `fileadmin/<site>/…`) umgezogen werden?
    Das könnte ein nächstes Skript vorbereiten (Vorschlagsliste je Site, Umzug über die TYPO3-API, damit Referenzen erhalten bleiben).
12. **Backup**: Gibt es ein aktuelles Backup von `fileadmin` und Datenbank, und wie lange soll die Quarantäne bestehen?

## 7. Mögliche Erweiterungen

- Bericht je Site (eigene CSV/Mail an Verantwortliche).
- HTML-Bericht mit Vorschaubildern.
- Umzug verwendeter Dateien in eine neue Ordnerstruktur über einen TYPO3-Konsolenbefehl (Referenzen bleiben erhalten).
- Automatische 301-Weiterleitungen für extern aufgerufene Dateien (`sys_redirect`).
- Erkennung „wann zuletzt auf der Website ausgeliefert“ über längere Log-Historie (z. B. GoAccess/Matomo).
