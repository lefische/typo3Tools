# typo3Tools – Analyse von `fileadmin/_migrated`

Werkzeuge, um in einer TYPO3-13.4-Installation (Multi-Root, mehrere Domains) herauszufinden,

- zu **welcher Domain** die Dateien in `fileadmin/_migrated/{content_uploads,media,pics}` gehören –
  auch wenn sie nur in versteckten oder gelöschten Inhalten (Papierkorb) stecken,
- **wo** sie eingebunden sind (Datensatz → Seite → Site → Domain/URL, je Sprache),
- ob sie **von außen oder von TYPO3-Seiten aufgerufen** werden (Access-Log inkl. Referer),
- und **ob sie gelöscht werden können**, ohne dass ein Link kaputtgeht.

Die fachliche Ausarbeitung, Annahmen und offenen Fragen stehen in [docs/ANFORDERUNGEN.md](docs/ANFORDERUNGEN.md).

## Hintergrund: Was ist `_migrated`?

Der Ordner entsteht beim Upgrade von TYPO3 4.x/6.x auf das File Abstraction Layer (FAL, ab 6.0/6.2).
Die Upgrade-Wizards haben Dateien, die vorher direkt in `uploads/` lagen, nach `fileadmin/_migrated/` kopiert:

| Ordner | Herkunft (typisch) |
|---|---|
| `pics` | `uploads/pics` – Bilder aus Inhaltselementen *Bild* / *Text mit Bild* (`tt_content.image`) |
| `media` | `uploads/media` – Dateien aus dem Seitenfeld *Medien* (`pages.media`) und Multimedia-Elementen |
| `content_uploads` | Dateien aus dem Inhaltselement *Dateilinks/Uploads* (`tt_content.media`) |
| `RTE` (falls vorhanden) | Bilder, die im RTE eingefügt wurden (`uploads/RTEmagicC_*`) |

Die ursprünglichen „Besitzer“ sind also die Inhaltselemente/Seiten, die sie damals verwendet haben. Seit der Migration
werden die Dateien wie alle anderen FAL-Dateien über `sys_file` / `sys_file_reference` verwaltet.

## Voraussetzungen

- PHP ≥ 8.1 CLI mit `pdo_mysql` und `zlib` (auf einem TYPO3-13-Server vorhanden)
- Lesezugriff auf das TYPO3-Verzeichnis und die Datenbank (MySQL/MariaDB)
- Optional: Lesezugriff auf die Webserver-Logs (`/var/log/apache2` bzw. `/var/log/nginx`, meist Gruppe `adm`)

Es wird **nichts** in TYPO3 installiert. Die Analyse arbeitet nur lesend (die DB-Session wird zusätzlich auf
`READ ONLY` gesetzt).

## Installation

```bash
cd /opt && git clone <dieses-repository> typo3Tools
```

## Schritt 1: Referenzindex aktualisieren (empfohlen)

Links im RTE (`t3://file?uid=…`) erkennt TYPO3 über den Referenzindex. Er sollte aktuell sein:

```bash
cd /var/www/typo3 && vendor/bin/typo3 referenceindex:update
```

(Ändert nur die Tabelle `sys_refindex`, keine Inhalte. Das Tool sucht zusätzlich selbst in den Textfeldern.)

## Schritt 2: Analyse

```bash
php /opt/typo3Tools/bin/migrated-analyze.php --root=/var/www/typo3 --out=/root/report-migrated --access-log='/var/log/apache2/*access.log*'
```

Der Pfad in `--root` ist das Projektverzeichnis (mit `config/` und `public/`). Zeigt er auf `public/`, wird das erkannt.
Die DB-Zugangsdaten werden aus `config/system/settings.php` und `additional.php` gelesen. Kommen sie aus `.env`
oder Umgebungsvariablen, gibt man sie mit an:

```bash
TYPO3_DB_PASSWORD='geheim' php /opt/typo3Tools/bin/migrated-analyze.php --root=/var/www/typo3 --db-host=localhost --db-name=typo3 --db-user=typo3
```

Wichtige Optionen (vollständig mit `--help`):

| Option | Bedeutung |
|---|---|
| `--folders=all` | alle Unterordner von `_migrated` analysieren (z. B. auch `RTE`) |
| `--access-log=GLOB` | Access-Logs auswerten (mehrfach angebbar, `.gz` wird gelesen) |
| `--scan-dir=PFAD` | weiteres Verzeichnis nach Pfadangaben durchsuchen |
| `--no-text-scan` | Volltextsuche in der DB abschalten (schneller bei sehr großen Datenbanken) |

### Was geprüft wird

1. **Dateisystem** – alle Dateien in den gewählten `_migrated`-Ordnern (Größe, Änderungsdatum)
2. **`sys_file`** – ist die Datei im FAL indiziert? Gibt es eine identische Kopie (SHA1) an anderer Stelle?
3. **`sys_file_reference`** – Einbindungen in Bild-/Medien-/Dateifeldern (`tt_content`, `pages`, News, …)
4. **`sys_refindex`** – Links aus RTE und Link-Feldern (`t3://file?uid=…`)
5. **Volltextsuche** in allen Text-Spalten der DB nach `_migrated/…`, `t3://file?uid=`, `<link file:…>` –
   findet fest eingetragene Pfade (RTE, TypoScript in `sys_template`, FlexForms, Extension-Tabellen)
6. **Dateisystem-Code** – `config/`, `packages/`, `typo3conf/ext/`, Textdateien in `fileadmin`, `.htaccess`
7. **`sys_log`** – Datei-Aktionen im Backend (z. B. Umbenennen, Löschversuche) mit Benutzer
8. **Access-Logs** – wird die Datei noch direkt aufgerufen? Ausgewertet werden:
   - die **Domain**, unter der sie abgerufen wurde (aus `vhost_combined`-Logs oder dem Logdateinamen,
     z. B. `www.example.de-access.log`),
   - der **Referer**: kommt er von einer eigenen Domain, wird die URL über Slug und Sprache einer **TYPO3-Seite**
     zugeordnet; sonst wird die externe Domain gezählt,
   - Bots/Suchmaschinen getrennt.

Für jede Fundstelle wird der Datensatz aufgelöst: gelöscht/versteckt/Zeitsteuerung/Workspace, Seite und deren
Rootline (versteckte oder gelöschte Elternseiten), Site-Konfiguration und Sprache → **Domain** und URL.
Die Domain wird auch für Inhalte im Papierkorb und auf versteckten Seiten ermittelt.

### Ergebnis (im Ausgabeverzeichnis)

| Datei | Inhalt |
|---|---|
| `zusammenfassung.md` | Überblick je Ordner und Status inkl. Speicherplatz, **Dateien je Domain**, Warnungen, defekte Verweise, von TYPO3-Seiten verlinkte Dateien ohne Referenz |
| `dateien.csv` | eine Zeile je Datei: Status, Empfehlung, Begründung, **Domains** (mit aktiv/versteckt/Papierkorb), Seiten, Duplikate, Log-Abrufe, Log-Domains, Referer (TYPO3-Seiten / extern) |
| `domain_dateien.csv` | eine Zeile je Datei **und Domain** – zum Filtern nach Domain |
| `verwendungen.csv` | eine Zeile je Fundstelle: Tabelle/Feld/UID, Domain, Site, URL, Seite, Seitenpfad |
| `loeschkandidaten.txt` | nur Dateien mit Status `UNBENUTZT` – Eingabe für Schritt 3 |

Die CSV-Dateien sind UTF-8 mit BOM und Semikolon getrennt – sie lassen sich direkt in Excel/LibreOffice öffnen.

### Status

Versteckte Inhalte und der Papierkorb gelten als **verwendet**. Nur `UNBENUTZT` ist ein Löschkandidat.

| Status | Bedeutung | Empfehlung |
|---|---|---|
| `IN_VERWENDUNG` | Einbindung in einem sichtbaren Datensatz auf einer sichtbaren Seite einer Site, oder Pfad in Code/TypoScript | behalten |
| `VERSTECKT_VERWENDET` | nur in versteckten/zeitgesteuerten Datensätzen, versteckten Seiten, Workspaces, Ordnern („Datensatz einfügen“) oder Seiten ohne Site | behalten |
| `IM_PAPIERKORB` | nur noch in gelöschten Datensätzen/Seiten (wiederherstellbar) | behalten |
| `ABGERUFEN_OHNE_REFERENZ` | keine Referenz in TYPO3, aber Abrufe im Access-Log (externe Links, alte Seiten) | behalten – Links sollen funktionieren |
| `PRUEFEN` | nur Bot-Abrufe (Suchmaschinen-Index), Fundstelle ohne Datensatz oder der ganze Ordner ist referenziert | manuell entscheiden |
| `UNBENUTZT` | keine Fundstelle (oder nur verwaiste Referenzen auf nicht mehr existierende Datensätze) und keine Abrufe | Löschkandidat |

Zusätzlich wird markiert, wenn eine **verwendete Datei auf der Platte fehlt** (kaputtes Bild auf der Website)
oder eine Datei **nicht indiziert** ist.

## Schritt 3: Quarantäne statt Löschen

Kandidaten werden zuerst **außerhalb des Web-Roots** geparkt. Vor dem Verschieben wird jede Datei erneut gegen die
Datenbank geprüft; was inzwischen verwendet wird, wird übersprungen.

```bash
php /opt/typo3Tools/bin/migrated-quarantine.php --root=/var/www/typo3 --list=/root/report-migrated/loeschkandidaten.txt --target=/var/typo3-quarantaene
```

Das ist ein Probelauf. Wenn die Ausgabe passt, mit `--execute` wirklich verschieben (als Benutzer, dem die Dateien gehören, meist `www-data`):

```bash
sudo -u www-data php /opt/typo3Tools/bin/migrated-quarantine.php --root=/var/www/typo3 --list=/root/report-migrated/loeschkandidaten.txt --target=/var/typo3-quarantaene --execute
```

Zurückholen (z. B. wenn sich jemand über ein fehlendes Dokument meldet):

```bash
sudo -u www-data php /opt/typo3Tools/bin/migrated-quarantine.php --root=/var/www/typo3 --restore=/var/typo3-quarantaene/<lauf>/manifest.csv --execute
```

Einzelne Zeilen in der Liste können vorher gelöscht werden, um Dateien auszunehmen.

### Während der Quarantäne: Log beobachten

Damit kein Link dauerhaft kaputtgeht, regelmäßig (z. B. täglich per Cron) prüfen, ob eine verschobene Datei
aufgerufen wurde. Mit `--execute` werden genau diese Dateien automatisch zurückgeholt:

```bash
sudo -u www-data php /opt/typo3Tools/bin/migrated-quarantine.php --root=/var/www/typo3 --requested=/var/typo3-quarantaene/<lauf>/manifest.csv --access-log='/var/log/apache2/*access.log*' --execute
```

Bot-Abrufe zählen dabei nicht (mit `--include-bots` schon).

## Schritt 4: Endgültig bereinigen (nach der Frist, z. B. 4–8 Wochen)

1. Datenbank-Backup erstellen.
2. Das erzeugte `cleanup-sys_file.sql` im Quarantäne-Lauf prüfen und ausführen (entfernt `sys_file`,
   `sys_file_metadata`, `sys_file_processedfile` und Papierkorb-Referenzen der verschobenen Dateien).
3. `vendor/bin/typo3 referenceindex:update`
4. Admin Tools › Maintenance › *Remove Temporary Assets* (verarbeitete Bildvarianten in `_processed_`).
5. Quarantäne-Verzeichnis löschen.

Ohne Schritt 2 bleibt der `sys_file`-Eintrag bestehen und wird vom Scheduler-Task *Dateiindex aktualisieren* als
„fehlend“ markiert – das ist unschädlich.

## Selbsttest

`tests/run.sh` legt eine Wegwerf-Datenbank und eine Mini-Installation an und prüft alle Status-Fälle sowie die
Quarantäne (benötigt lokale MariaDB/MySQL mit Root-Zugang über Socket):

```bash
bash tests/run.sh
```

## Grenzen

- Die Domain wird über die Site-Konfiguration bestimmt. Seiten außerhalb eines Site-Baums erscheinen als „(keine Site)“.
- Redakteure: `verwendungen.csv` enthält Ersteller/Bearbeiter aus `sys_history`/`sys_log`, bei gekürzter Historie bleiben die Felder leer.
- Dateien, die nur über externe Links aufgerufen werden, erkennt nur die Access-Log-Auswertung – und nur für den
  Zeitraum, den die Logs abdecken (Logrotation, oft 14 Tage). Deshalb: Logs länger aufbewahren
  (`/etc/logrotate.d/apache2`, z. B. `rotate 180`) und während der Quarantäne mit `--requested` beobachten.
- Die Domain eines Log-Abrufs ist nur bekannt, wenn das Log sie enthält (`vhost_combined` bzw. eigene Logdatei je
  vHost) oder der Referer von einer eigenen Domain kommt.
- Pfade, die zur Laufzeit zusammengesetzt werden (z. B. in PHP-Code oder per TypoScript-`stdWrap`), werden nicht erkannt.
- Nur MySQL/MariaDB.
