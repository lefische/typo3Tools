#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Verschiebt Löschkandidaten aus fileadmin/_migrated in ein Quarantäne-
 * Verzeichnis außerhalb des Web-Roots – statt sie sofort zu löschen.
 *
 *  - Standard ist ein Probelauf; erst mit --execute wird verschoben.
 *  - Vor dem Verschieben wird jede Datei erneut gegen die DB geprüft.
 *  - Es entsteht ein Manifest, mit dem --restore alles zurückverschiebt.
 *  - --requested sucht in den Access-Logs nach Aufrufen verschobener Dateien
 *    (= jemand nutzt noch einen Link) und holt diese mit --execute zurück.
 *  - Für die spätere endgültige Bereinigung wird ein SQL-Skript erzeugt
 *    (wird NICHT ausgeführt).
 */

use Typo3Tools\AccessLog;
use Typo3Tools\Db;
use Typo3Tools\Typo3Env;

require __DIR__ . '/../lib/Typo3Env.php';

const USAGE = <<<TXT
Aufruf:
  Probelauf:   php bin/migrated-quarantine.php --root=/var/www/typo3 --list=report/loeschkandidaten.txt --target=/var/typo3-quarantaene
  Ausführen:   ... zusätzlich --execute
  Zurück:      php bin/migrated-quarantine.php --root=/var/www/typo3 --restore=/var/typo3-quarantaene/<lauf>/manifest.csv [--execute]
  Beobachten:  php bin/migrated-quarantine.php --root=/var/www/typo3 --requested=/var/typo3-quarantaene/<lauf>/manifest.csv \
                 --access-log='/var/log/apache2/*access.log*' [--execute]

Optionen:
  --root=PFAD       TYPO3-Projektverzeichnis
  --public=PFAD     Web-Root, falls nicht automatisch erkannt
  --list=DATEI      Liste (Pfade relativ zu fileadmin), z. B. loeschkandidaten.txt
  --target=PFAD     Quarantäne-Basisverzeichnis (muss außerhalb des Web-Roots liegen)
  --execute         Wirklich verschieben (sonst nur Probelauf)
  --no-recheck      Keine erneute Prüfung gegen die Datenbank (nicht empfohlen)
  --max-age=TAGE    Liste darf höchstens so alt sein (Standard 7)
  --restore=DATEI   Dateien laut Manifest zurückverschieben
  --requested=DATEI Nur die Dateien des Manifests zurückholen, die seit der Quarantäne laut
                    Access-Log aufgerufen wurden (benötigt --access-log, Bots zählen nicht)
  --access-log=GLOB Access-Logs (mehrfach angebbar, .gz wird gelesen)
  --include-bots    bei --requested auch Abrufe von Bots/Suchmaschinen berücksichtigen
  --db-host=, --db-port=, --db-name=, --db-user=, --db-socket=, --db-password= (oder TYPO3_DB_PASSWORD)

TXT;

$opts = getopt('h', ['root:', 'public:', 'list:', 'target:', 'execute', 'no-recheck', 'max-age:', 'restore:',
    'requested:', 'access-log:', 'include-bots',
    'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-password:', 'db-socket:', 'help']);
if (isset($opts['h']) || isset($opts['help']) || empty($opts['root'])) {
    fwrite(STDERR, USAGE);
    exit(empty($opts['root']) && !isset($opts['h']) && !isset($opts['help']) ? 1 : 0);
}
$execute = isset($opts['execute']);
$say = static function (string $msg): void {
    fwrite(STDOUT, $msg . "\n");
};

try {
    $env = new Typo3Env((string)$opts['root'], isset($opts['public']) ? (string)$opts['public'] : null);

    // ---------------------------------------------------------------- Restore / Beobachten
    if (isset($opts['restore']) || isset($opts['requested'])) {
        $rows = readManifest((string)($opts['restore'] ?? $opts['requested']));
        if (isset($opts['requested'])) {
            $globs = isset($opts['access-log']) ? (array)$opts['access-log'] : [];
            if ($globs === []) {
                throw new RuntimeException('--requested benötigt --access-log.');
            }
            $byIdentifier = [];
            foreach ($rows as $i => $row) {
                $byIdentifier[substr($row['original'], strlen($env->fileadmin))] = $i;
            }
            $log = new AccessLog($globs);
            $hits = [];
            foreach ($log->entries('/fileadmin/_migrated/') as $e) {
                $identifier = substr($e['path'], strpos($e['path'], '/fileadmin/_migrated/') + strlen('/fileadmin'));
                $i = $byIdentifier[$identifier] ?? null;
                if ($i === null || ($e['bot'] && !isset($opts['include-bots'])) || ($e['ts'] !== null && $e['ts'] < $rows[$i]['since'])) {
                    continue;
                }
                $hits[$i][] = date('Y-m-d H:i', (int)$e['ts']) . ' ' . $e['status'] . ($e['host'] ? ' ' . $e['host'] : '')
                    . ($e['referer'] !== '' ? ' von ' . $e['referer'] : '');
            }
            foreach ($log->warnings as $w) {
                $say("WARNUNG: $w");
            }
            $say(sprintf('Access-Logs: %d Dateien, Zeitraum %s bis %s', $log->files,
                $log->from ? date('Y-m-d', $log->from) : '?', $log->to ? date('Y-m-d', $log->to) : '?'));
            foreach ($hits as $i => $list) {
                $say(sprintf("\nAUFGERUFEN (%dx): %s", count($list), $rows[$i]['original']));
                foreach (array_slice($list, 0, 5) as $line) {
                    $say("  $line");
                }
            }
            $say($hits === [] ? "\nKeine der verschobenen Dateien wurde seit der Quarantäne aufgerufen." : '');
            $rows = array_intersect_key($rows, $hits);
        }
        [$ok, $skip] = restoreRows($rows, $execute, $say);
        $say(sprintf("\n%s: %d Dateien, %d übersprungen.%s", $execute ? 'Wiederhergestellt' : 'Probelauf', $ok, $skip,
            $execute ? '' : ($ok > 0 ? "\nMit --execute zurückholen." : '')));
        exit(0);
    }

    // ---------------------------------------------------------------- Quarantäne
    if (empty($opts['list']) || empty($opts['target'])) {
        throw new RuntimeException('--list und --target sind erforderlich.');
    }
    $listFile = (string)$opts['list'];
    if (!is_file($listFile)) {
        throw new RuntimeException("Liste nicht gefunden: $listFile");
    }
    $maxAge = (int)($opts['max-age'] ?? 7);
    if (filemtime($listFile) < time() - $maxAge * 86400) {
        throw new RuntimeException("Die Liste ist älter als $maxAge Tage – bitte die Analyse neu laufen lassen (oder --max-age setzen).");
    }

    $targetBase = rtrim((string)$opts['target'], '/');
    $targetReal = realpath($targetBase) ?: $targetBase;
    if (str_starts_with($targetReal . '/', $env->publicDir . '/')) {
        throw new RuntimeException('Das Quarantäne-Verzeichnis darf nicht im Web-Root liegen.');
    }
    $runDir = $targetBase . '/' . date('Ymd-His');
    for ($n = 2; file_exists($runDir); $n++) {
        $runDir = $targetBase . '/' . date('Ymd-His') . "-$n";
    }

    $db = null;
    if (!isset($opts['no-recheck'])) {
        $db = $env->connect(array_filter([
            'host' => $opts['db-host'] ?? null,
            'port' => $opts['db-port'] ?? null,
            'name' => $opts['db-name'] ?? null,
            'user' => $opts['db-user'] ?? null,
            'password' => $opts['db-password'] ?? (getenv('TYPO3_DB_PASSWORD') ?: null),
            'socket' => $opts['db-socket'] ?? null,
        ], static fn ($v) => $v !== null));
    }

    $entries = [];
    foreach (file($listFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $rel = ltrim(preg_replace('~^fileadmin/~', '', $line), '/');
        if (!str_starts_with($rel, '_migrated/') || str_contains($rel, '..')) {
            $say("ABGELEHNT (nur Dateien unter _migrated/ erlaubt): $line");
            continue;
        }
        $entries[] = $rel;
    }

    $moved = $skipped = 0;
    $bytes = 0;
    $manifestRows = [];
    $fileUids = [];
    foreach ($entries as $rel) {
        $source = $env->fileadmin . '/' . $rel;
        if (!is_file($source)) {
            $say("ÜBERSPRUNGEN (existiert nicht): $rel");
            $skipped++;
            continue;
        }
        $uid = null;
        if ($db !== null) {
            [$uid, $why] = recheck($db, '/' . $rel);
            if ($why !== null) {
                $say("ÜBERSPRUNGEN ($why): $rel");
                $skipped++;
                continue;
            }
        }
        $target = $runDir . '/' . $rel;
        $size = (int)filesize($source);
        $say(($execute ? 'VERSCHOBEN: ' : 'würde verschieben: ') . $rel);
        if ($execute) {
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true)) {
                throw new RuntimeException('Verzeichnis kann nicht angelegt werden: ' . dirname($target));
            }
            $sha1 = (string)sha1_file($source);
            if (!rename($source, $target)) {
                throw new RuntimeException("Verschieben fehlgeschlagen: $source");
            }
            $manifestRows[] = [$source, $target, $size, $sha1, $uid ?? '', date('c')];
        }
        if ($uid !== null) {
            $fileUids[] = $uid;
        }
        $moved++;
        $bytes += $size;
    }

    if ($execute && $manifestRows !== []) {
        $h = fopen($runDir . '/manifest.csv', 'wb');
        fputcsv($h, ['original', 'quarantaene', 'groesse', 'sha1', 'sys_file_uid', 'zeitpunkt'], ';', '"', '');
        foreach ($manifestRows as $row) {
            fputcsv($h, $row, ';', '"', '');
        }
        fclose($h);
        file_put_contents($runDir . '/cleanup-sys_file.sql', cleanupSql($fileUids));
    }

    $say(sprintf("\n%s: %d Dateien (%.1f MB), %d übersprungen.", $execute ? 'Verschoben' : 'Probelauf', $moved, $bytes / 1048576, $skipped));
    if ($execute && $manifestRows !== []) {
        $say("Manifest:      $runDir/manifest.csv");
        $say("Wiederherstellen: php " . $argv[0] . " --root=" . $env->root . " --restore=$runDir/manifest.csv --execute");
        $say("SQL für die spätere endgültige Bereinigung (nicht ausgeführt): $runDir/cleanup-sys_file.sql");
    } elseif (!$execute) {
        $say('Es wurde nichts verändert. Mit --execute ausführen.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nFEHLER: " . $e->getMessage() . "\n");
    exit(2);
}

/**
 * Prüft unmittelbar vor dem Verschieben, ob die Datei inzwischen (wieder) verwendet wird.
 * @return array{0:?int,1:?string} [sys_file.uid, Grund zum Überspringen oder null]
 */
function recheck(Db $db, string $identifier): array
{
    $storage = (int)($db->one(
        "SELECT uid FROM sys_file_storage WHERE configuration LIKE '%<value index=\"vDEF\">fileadmin/</value>%' ORDER BY uid LIMIT 1"
    )['uid'] ?? 1);
    $file = $db->one('SELECT uid FROM sys_file WHERE storage = ? AND identifier = ?', [$storage, $identifier]);
    if ($file === null) {
        return [null, null];
    }
    $uid = (int)$file['uid'];
    $deleted = $db->has('sys_file_reference', 'deleted') ? 'AND deleted = 0' : '';
    $refs = 0;
    foreach ($db->all("SELECT tablenames, uid_foreign FROM sys_file_reference WHERE uid_local = ? $deleted", [$uid]) as $ref) {
        // Referenzen auf nicht mehr existierende Datensätze (verwaist) zählen nicht
        $table = (string)$ref['tablenames'];
        if ($db->has($table, 'uid') && $db->one('SELECT uid FROM ' . Db::q($table) . ' WHERE uid = ?', [(int)$ref['uid_foreign']]) !== null) {
            $refs++;
        }
    }
    if ($refs > 0) {
        return [$uid, "$refs nicht gelöschte Dateireferenz(en)"];
    }
    if ($db->tableExists('sys_refindex')) {
        $soft = (int)$db->one(
            "SELECT COUNT(*) AS c FROM sys_refindex WHERE ref_table = 'sys_file' AND ref_uid = ?
             AND tablename NOT IN ('sys_file_reference', 'sys_file_metadata', 'sys_file')",
            [$uid]
        )['c'];
        if ($soft > 0) {
            return [$uid, "$soft Link(s) laut Referenzindex"];
        }
    }
    return [$uid, null];
}

function cleanupSql(array $uids): string
{
    $sql = "-- Endgültige Bereinigung der in Quarantäne verschobenen Dateien.\n"
        . "-- ERST AUSFÜHREN, wenn die Quarantäne-Frist ohne Beanstandung abgelaufen ist\n"
        . "-- und ein aktuelles Datenbank-Backup existiert!\n"
        . "-- Danach: Admin Tools > Maintenance > \"Remove Temporary Assets\" (verarbeitete Bilder).\n\n";
    if ($uids === []) {
        return $sql . "-- Keine indizierten Dateien betroffen.\n";
    }
    foreach (array_chunk($uids, 500) as $chunk) {
        $in = implode(',', $chunk);
        $sql .= "DELETE FROM sys_file_metadata WHERE file IN ($in);\n"
            . "DELETE FROM sys_file_processedfile WHERE original IN ($in);\n"
            . "-- Referenzen aus bereits gelöschten Datensätzen (Papierkorb):\n"
            . "DELETE FROM sys_file_reference WHERE uid_local IN ($in) AND deleted = 1;\n"
            . "DELETE FROM sys_file WHERE uid IN ($in);\n\n";
    }
    return $sql . "-- Anschließend: vendor/bin/typo3 referenceindex:update\n";
}

/** @return array<int, array{original:string, quarantined:string, since:int}> */
function readManifest(string $manifest): array
{
    $h = @fopen($manifest, 'rb');
    if ($h === false) {
        throw new RuntimeException("Manifest nicht lesbar: $manifest");
    }
    fgetcsv($h, null, ';', '"', ''); // Kopfzeile
    $rows = [];
    while (($row = fgetcsv($h, null, ';', '"', '')) !== false) {
        if (count($row) >= 2) {
            $rows[] = ['original' => $row[0], 'quarantined' => $row[1], 'since' => (int)strtotime($row[5] ?? '')];
        }
    }
    fclose($h);
    return $rows;
}

/** @return array{0:int,1:int} [zurückgeholt, übersprungen] */
function restoreRows(array $rows, bool $execute, callable $say): array
{
    $ok = $skip = 0;
    foreach ($rows as $row) {
        if (!is_file($row['quarantined'])) {
            $say("ÜBERSPRUNGEN (nicht in Quarantäne): {$row['quarantined']}");
            $skip++;
            continue;
        }
        if (file_exists($row['original'])) {
            $say("ÜBERSPRUNGEN (Ziel existiert bereits): {$row['original']}");
            $skip++;
            continue;
        }
        $say(($execute ? 'ZURÜCK: ' : 'würde zurück: ') . $row['original']);
        if ($execute) {
            if (!is_dir(dirname($row['original']))) {
                mkdir(dirname($row['original']), 0775, true);
            }
            if (!rename($row['quarantined'], $row['original'])) {
                throw new RuntimeException("Zurückverschieben fehlgeschlagen: {$row['quarantined']}");
            }
        }
        $ok++;
    }
    return [$ok, $skip];
}
