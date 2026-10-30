#!/usr/bin/env bash
# Selbsttest gegen eine lokale MariaDB/MySQL (Root-Zugang per Socket).
# Legt eine Wegwerf-Datenbank und eine Mini-TYPO3-Verzeichnisstruktur an.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="${WORK:-$(mktemp -d)}"
DB="typo3tools_test"
MYSQL="${MYSQL:-mysql -uroot} --default-character-set=utf8mb4"

$MYSQL -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;"
$MYSQL "$DB" < "$HERE/fixture.sql"
$MYSQL -e "DROP USER IF EXISTS 'typo3tools'@'localhost'; CREATE USER 'typo3tools'@'localhost' IDENTIFIED BY 'test';
           GRANT SELECT ON $DB.* TO 'typo3tools'@'localhost';"

P="$WORK/project"
rm -rf "$P"
mkdir -p "$P/config/system" "$P/config/sites/site-a" "$P/config/sites/site-b" "$P/packages/sitepackage/Configuration" \
         "$P/public/fileadmin/_migrated/pics" "$P/public/fileadmin/_migrated/media" \
         "$P/public/fileadmin/_migrated/content_uploads" "$P/public/fileadmin/_migrated/RTE" "$P/public/fileadmin/user_upload"
cat > "$P/config/system/settings.php" <<PHP
<?php
return ['DB' => ['Connections' => ['Default' => [
    'driver' => 'mysqli', 'host' => 'localhost', 'dbname' => '$DB', 'user' => 'typo3tools', 'password' => 'test',
]]]];
PHP
printf "rootPageId: 1\nbase: 'https://www.site-a.example/'\n" > "$P/config/sites/site-a/config.yaml"
cat > "$P/config/sites/site-b/config.yaml" <<'YAML'
rootPageId: 10
base: 'https://www.site-b.example/'
languages:
  -
    title: Deutsch
    languageId: 0
    base: /
  -
    title: English
    languageId: 1
    base: 'https://www.site-b.example.com/'
YAML
echo 'lib.alt = IMAGE
lib.alt.file = fileadmin/_migrated/media/aus_typoscript.png' > "$P/packages/sitepackage/Configuration/setup.typoscript"

F="$P/public/fileadmin/_migrated"
for f in pics/aktiv.jpg pics/versteckt.jpg pics/geloeschte_seite.jpg content_uploads/geloeschtes_element.pdf \
         media/rte_link.pdf pics/ordner.jpg pics/ohne_site.jpg pics/hartkodiert.jpg "pics/logo alt.png" \
         media/aus_typoscript.png media/logclick.pdf content_uploads/nicht_indiziert.doc media/englisch.jpg \
         media/verwaist.pdf media/nurbot.pdf media/intern.pdf; do
  echo "inhalt $f" > "$F/$f"
done
printf 'unbenutzt-inhalt\n' > "$F/content_uploads/unbenutzt.pdf"

LOG="$WORK/access.log"
cat > "$LOG" <<'LOG'
1.2.3.4 - - [28/Oct/2026:10:00:00 +0100] "GET /fileadmin/_migrated/media/logclick.pdf HTTP/1.1" 200 123 "https://forum.example.org/thread/1" "Mozilla/5.0"
5.6.7.8 - - [29/Oct/2026:11:00:00 +0100] "GET /fileadmin/_migrated/media/logclick.pdf HTTP/1.1" 200 123 "-" "Googlebot/2.1"
5.6.7.8 - - [29/Oct/2026:11:00:01 +0100] "GET /fileadmin/_migrated/pics/weg.jpg HTTP/1.1" 404 0 "-" "Mozilla/5.0"
www.site-b.example:443 1.2.3.4 - - [29/Oct/2026:12:00:00 +0100] "GET /fileadmin/_migrated/media/logclick.pdf HTTP/1.1" 200 123 "-" "Mozilla/5.0"
5.6.7.8 - - [29/Oct/2026:13:00:00 +0100] "GET /fileadmin/_migrated/media/nurbot.pdf HTTP/1.1" 200 123 "-" "Mozilla/5.0 (compatible; bingbot/2.0)"
9.9.9.9 - - [29/Oct/2026:14:00:00 +0100] "GET /fileadmin/_migrated/media/intern.pdf HTTP/1.1" 200 123 "https://www.site-a.example/aktuelles/" "Mozilla/5.0"
LOG

OUT="$WORK/report"
php "$HERE/../bin/migrated-analyze.php" --root="$P" --out="$OUT" --access-log="$LOG" 2>"$WORK/analyze.log" || { cat "$WORK/analyze.log"; exit 1; }

expect() { # pfad status
  local line
  line=$(grep -F "\"fileadmin/_migrated/$1\";" "$OUT/dateien.csv" || grep -F "fileadmin/_migrated/$1;" "$OUT/dateien.csv" || true)
  if [[ "$line" == *";$2;"* ]]; then echo "OK    $1 → $2"; else echo "FEHLER $1: erwartet $2, ist: $line"; FAIL=1; fi
}
FAIL=0
expect pics/aktiv.jpg IN_VERWENDUNG
expect pics/versteckt.jpg VERSTECKT_VERWENDET
expect pics/geloeschte_seite.jpg IM_PAPIERKORB
expect content_uploads/geloeschtes_element.pdf IM_PAPIERKORB
expect content_uploads/unbenutzt.pdf IM_PAPIERKORB
expect media/rte_link.pdf IN_VERWENDUNG
expect pics/fehlt.jpg IN_VERWENDUNG
expect pics/ordner.jpg VERSTECKT_VERWENDET
expect pics/ohne_site.jpg VERSTECKT_VERWENDET
expect pics/hartkodiert.jpg IN_VERWENDUNG
expect "pics/logo alt.png" IN_VERWENDUNG
expect media/aus_typoscript.png IN_VERWENDUNG
expect media/logclick.pdf ABGERUFEN_OHNE_REFERENZ
expect media/intern.pdf ABGERUFEN_OHNE_REFERENZ
expect media/nurbot.pdf PRUEFEN
expect media/englisch.jpg IN_VERWENDUNG
expect media/verwaist.pdf UNBENUTZT
expect content_uploads/nicht_indiziert.doc UNBENUTZT

check() { # beschreibung datei muster
  grep -qF -- "$3" "$OUT/$2" && echo "OK    $1" || { echo "FEHLER $1 ($3 nicht in $2)"; FAIL=1; }
}
check "Domain auch für Papierkorb" dateien.csv 'www.site-a.example (Papierkorb)'
check "Domain über Sprache" domain_dateien.csv 'www.site-b.example.com;fileadmin/_migrated/media/englisch.jpg;aktiv'
check "URL der Übersetzung" verwendungen.csv 'https://www.site-b.example.com/products'
check "Domain aus vhost-Log" dateien.csv 'www.site-b.example (1)'
check "interner Referer → TYPO3-Seite" zusammenfassung.md 'www.site-a.example/aktuelles/ → Seite 2 "Aktuelles"'
check "externer Referer" dateien.csv 'forum.example.org (1)'
check "Bearbeiter erkannt" verwendungen.csv 'redakteur'
grep -q 'gibtsnicht.pdf' "$OUT/zusammenfassung.md" && echo "OK    defekter Verweis erkannt" || { echo "FEHLER defekter Verweis"; FAIL=1; }
grep -q '_migrated/pics/logo` ' "$OUT/zusammenfassung.md" && { echo "FEHLER Teiltreffer als defekt gemeldet"; FAIL=1; } || echo "OK    keine Teiltreffer"
grep -q 'user_upload/kopie.pdf' "$OUT/dateien.csv" && echo "OK    Duplikat erkannt" || { echo "FEHLER Duplikat"; FAIL=1; }

# Quarantäne: Probelauf, Ausführen, Wiederherstellen
Q="$WORK/quarantaene"
rm -rf "$Q"
php "$HERE/../bin/migrated-quarantine.php" --root="$P" --list="$OUT/loeschkandidaten.txt" --target="$Q" >/dev/null
[[ -f "$F/content_uploads/nicht_indiziert.doc" ]] && echo "OK    Probelauf verändert nichts" || { echo "FEHLER Probelauf"; FAIL=1; }
php "$HERE/../bin/migrated-quarantine.php" --root="$P" --list="$OUT/loeschkandidaten.txt" --target="$Q" --execute >/dev/null
[[ ! -f "$F/content_uploads/nicht_indiziert.doc" ]] && echo "OK    Datei in Quarantäne" || { echo "FEHLER Quarantäne"; FAIL=1; }
php "$HERE/../bin/migrated-quarantine.php" --root="$P" --restore="$(ls "$Q"/*/manifest.csv)" --execute >/dev/null
[[ -f "$F/content_uploads/nicht_indiziert.doc" ]] && echo "OK    Wiederherstellung" || { echo "FEHLER Wiederherstellung"; FAIL=1; }
# Beobachtung: Datei in Quarantäne wird aufgerufen → wird zurückgeholt
php "$HERE/../bin/migrated-quarantine.php" --root="$P" --list="$OUT/loeschkandidaten.txt" --target="$Q" --execute >/dev/null
echo '1.2.3.4 - - [31/Dec/2026:10:00:00 +0100] "GET /fileadmin/_migrated/content_uploads/nicht_indiziert.doc HTTP/1.1" 404 0 "https://partner.example/" "Mozilla/5.0"' > "$WORK/access-neu.log"
php "$HERE/../bin/migrated-quarantine.php" --root="$P" --requested="$(ls -t "$Q"/*/manifest.csv | head -1)" --access-log="$WORK/access-neu.log" --execute >/dev/null
[[ -f "$F/content_uploads/nicht_indiziert.doc" && ! -f "$F/media/verwaist.pdf" ]] && echo "OK    aufgerufene Datei zurückgeholt, andere bleibt in Quarantäne" || { echo "FEHLER --requested"; FAIL=1; }
php "$HERE/../bin/migrated-quarantine.php" --root="$P" --restore="$(ls -t "$Q"/*/manifest.csv | head -1)" --execute >/dev/null
# Recheck: eine verwendete Datei in der Liste muss übersprungen werden
echo "_migrated/pics/aktiv.jpg" >> "$OUT/loeschkandidaten.txt"
php "$HERE/../bin/migrated-quarantine.php" --root="$P" --list="$OUT/loeschkandidaten.txt" --target="$Q" | grep -q 'ÜBERSPRUNGEN.*aktiv.jpg' \
  && echo "OK    Recheck schützt verwendete Datei" || { echo "FEHLER Recheck"; FAIL=1; }

echo "Bericht: $OUT"
exit $FAIL
