#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Analysiert fileadmin/_migrated/* einer TYPO3-Installation (v12/v13):
 *  - Welche Dateien gibt es (Dateisystem + sys_file)?
 *  - Wo sind sie eingebunden (sys_file_reference, sys_refindex, Textfelder, Code)?
 *  - Auf welchen Seiten / Sites / Domains?
 *  - Wer hat die einbindenden Datensätze angelegt/geändert (sys_history, sys_log)?
 *  - Werden sie noch direkt abgerufen (Webserver-Access-Logs)?
 *  - Können sie gelöscht werden?
 *
 * Das Skript arbeitet ausschließlich lesend.
 */

use Typo3Tools\Db;
use Typo3Tools\Typo3Env;

require __DIR__ . '/../lib/Typo3Env.php';

const USAGE = <<<TXT
Aufruf:
  php bin/migrated-analyze.php --root=/var/www/typo3 [Optionen]

Optionen:
  --root=PFAD          TYPO3-Projektverzeichnis (enthält config/ und public/) – Pflicht
  --public=PFAD        Web-Root, falls nicht automatisch erkannt (public/, web/ oder Projektroot)
  --out=PFAD           Ausgabeverzeichnis (Standard: ./report-migrated-<Datum>)
  --folders=LISTE      Unterordner von _migrated, kommasepariert
                       (Standard: content_uploads,media,pics; "all" = alle Unterordner)
  --storage=UID        UID des fileadmin-Storage (Standard: automatisch, sonst 1)
  --access-log=GLOB    Webserver-Access-Logs auswerten, mehrfach angebbar, .gz wird gelesen
                       z. B. --access-log='/var/log/apache2/*access.log*'
  --scan-dir=PFAD      Zusätzliches Verzeichnis nach Pfadangaben durchsuchen (mehrfach angebbar)
  --no-text-scan       Keine Volltextsuche in Datenbank-Textfeldern
  --no-fs-scan         Keine Suche in TypoScript/Templates/Konfiguration im Dateisystem
  --db-host=, --db-port=, --db-name=, --db-user=, --db-socket=
                       DB-Zugang überschreiben (Standard: aus settings.php/additional.php)
                       Passwort über Umgebungsvariable TYPO3_DB_PASSWORD oder --db-password=
  -h, --help           Diese Hilfe

TXT;

$opts = getopt('h', [
    'root:', 'public:', 'out:', 'folders:', 'storage:', 'access-log:', 'scan-dir:',
    'no-text-scan', 'no-fs-scan',
    'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-password:', 'db-socket:', 'help',
]);
if (isset($opts['h']) || isset($opts['help']) || empty($opts['root'])) {
    fwrite(STDERR, USAGE);
    exit(isset($opts['root']) || isset($opts['h']) || isset($opts['help']) ? 0 : 1);
}

$toList = static fn ($v): array => $v === null ? [] : (is_array($v) ? $v : [$v]);

try {
    $env = new Typo3Env((string)$opts['root'], isset($opts['public']) ? (string)$opts['public'] : null);
    $dbOverrides = array_filter([
        'host' => $opts['db-host'] ?? null,
        'port' => $opts['db-port'] ?? null,
        'name' => $opts['db-name'] ?? null,
        'user' => $opts['db-user'] ?? null,
        'password' => $opts['db-password'] ?? (getenv('TYPO3_DB_PASSWORD') ?: null),
        'socket' => $opts['db-socket'] ?? null,
    ], static fn ($v) => $v !== null);
    $db = $env->connect($dbOverrides);

    $analyzer = new MigratedAnalyzer(
        $env,
        $db,
        (string)($opts['folders'] ?? 'content_uploads,media,pics'),
        isset($opts['storage']) ? (int)$opts['storage'] : null,
        $toList($opts['access-log'] ?? null),
        $toList($opts['scan-dir'] ?? null),
        !isset($opts['no-text-scan']),
        !isset($opts['no-fs-scan']),
    );
    $out = (string)($opts['out'] ?? (getcwd() . '/report-migrated-' . date('Ymd-His')));
    $analyzer->run($out);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nFEHLER: " . $e->getMessage() . "\n");
    exit(2);
}

final class MigratedAnalyzer
{
    private const STATUS_IN_USE = 'IN_VERWENDUNG';
    private const STATUS_REVIEW = 'PRUEFEN';
    private const STATUS_DELETED_ONLY = 'NUR_GELOESCHTE_REFERENZEN';
    private const STATUS_UNUSED = 'UNBENUTZT';

    private const RECOMMENDATION = [
        self::STATUS_IN_USE => 'Behalten. Bei Bedarf über die TYPO3-Dateiliste verschieben/umbenennen (Referenzen bleiben erhalten).',
        self::STATUS_REVIEW => 'Manuell prüfen (siehe Begründung und verwendungen.csv).',
        self::STATUS_DELETED_ONLY => 'Löschbar, sofern die gelöschten Datensätze nicht mehr aus dem Papierkorb wiederhergestellt werden sollen.',
        self::STATUS_UNUSED => 'Löschkandidat – zuerst in Quarantäne verschieben (bin/migrated-quarantine.php).',
    ];

    /** Tabellen, die bei der Volltextsuche übersprungen werden (Präfix-Vergleich) */
    private const TEXT_SCAN_EXCLUDE = [
        'cache_', 'cf_', 'index_', 'tx_extensionmanager_', 'sys_log', 'sys_history', 'sys_refindex',
        'sys_file_processedfile', 'sys_lockedrecords', 'be_sessions', 'fe_sessions', 'sys_registry',
        'sys_messenger_messages', 'sys_http_report', 'sys_csp_resolution', 'sys_file_storage',
    ];

    /** Tabellen, deren Ordner-Treffer nur informativ sind (z. B. Dateifreigaben für Redakteure) */
    private const FOLDER_INFO_TABLES = ['sys_filemounts', 'be_groups', 'be_users'];

    private const FS_SCAN_EXTENSIONS = [
        'typoscript', 'ts', 'tsconfig', 'txt', 'html', 'htm', 'css', 'scss', 'sass', 'less', 'js', 'mjs',
        'json', 'xml', 'yaml', 'yml', 'php', 'xlf', 'tmpl', 'tpl', 'htaccess', 'conf',
    ];
    private const FS_SCAN_SKIP_DIRS = ['vendor', 'node_modules', '.git', '.svn', '_processed_', '_migrated', '_temp_', 'typo3temp'];

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python|java\/|monitor|uptime|scan/i';

    private int $now;
    private int $storage = 1;
    /** @var string[] */
    private array $folders = [];
    /** @var string[] */
    private array $otherMigratedFolders = [];

    /** @var array<string, array> Schlüssel = sys_file.identifier, z. B. /_migrated/pics/foo.jpg */
    private array $files = [];
    /** @var array<int, string> sys_file.uid => identifier */
    private array $uidToKey = [];
    /** @var array<int, array> */
    private array $pages = [];
    /** @var array<int, array> */
    private array $sites = [];
    /** @var array<int, string> */
    private array $users = [];
    private array $recordCache = [];
    private array $pageCache = [];
    private array $whoCache = [];
    /** @var list<array> Treffer auf ganze Ordner */
    private array $folderHits = [];
    /** @var array<string, array> Verweise auf nicht vorhandene _migrated-Dateien */
    private array $brokenRefs = [];
    /** @var array<string, int> Log-Anfragen auf nicht vorhandene _migrated-Dateien */
    private array $logMissing = [];
    /** @var string[] */
    private array $warnings = [];
    private array $logStats = ['files' => 0, 'lines' => 0, 'from' => null, 'to' => null];

    public function __construct(
        private readonly Typo3Env $env,
        private readonly Db $db,
        string $folders,
        ?int $storage,
        private readonly array $accessLogs,
        private readonly array $extraScanDirs,
        private readonly bool $textScan,
        private readonly bool $fsScan,
    ) {
        $this->now = time();
        $migratedDir = $env->fileadmin . '/_migrated';
        $existing = is_dir($migratedDir)
            ? array_values(array_filter(scandir($migratedDir) ?: [], static fn ($d) => $d[0] !== '.' && is_dir("$migratedDir/$d")))
            : [];
        $this->folders = trim($folders) === 'all'
            ? $existing
            : array_values(array_filter(array_map('trim', explode(',', $folders))));
        $this->otherMigratedFolders = array_values(array_diff($existing, $this->folders));
        $this->storage = $storage ?? $this->detectStorage();
    }

    public function run(string $outDir): void
    {
        $this->step('Lade Seitenbaum, Sites und Backend-Benutzer');
        $this->sites = $this->env->sites();
        $this->loadPages();
        $this->loadUsers();
        $this->checkRefindex();

        $this->step('Inventarisiere Dateien in fileadmin/_migrated/{' . implode(',', $this->folders) . '}');
        $this->scanFilesystem();
        $this->loadSysFile();
        $this->step(sprintf('%d Dateien gefunden (%d indiziert)', count($this->files), count($this->uidToKey)));

        $this->step('Suche Duplikate (SHA1)');
        $this->findDuplicates();

        $this->step('Werte sys_file_reference aus');
        $this->collectFileReferences();

        $this->step('Werte Referenzindex (sys_refindex) aus');
        $this->collectRefindex();

        if ($this->textScan) {
            $this->step('Durchsuche Datenbank-Textfelder nach Pfaden/Links');
            $this->collectTextReferences();
        }
        $this->step('Werte Datei-Aktionen im sys_log aus');
        $this->collectFileLog();

        if ($this->fsScan) {
            $this->step('Durchsuche TypoScript/Templates/Konfiguration im Dateisystem');
            $this->collectFilesystemReferences();
        }
        if ($this->accessLogs !== []) {
            $this->step('Werte Access-Logs aus');
            $this->collectAccessLogs();
        }

        $this->step('Ordne Seiten, Sites und Bearbeiter zu, klassifiziere');
        $this->classify();

        $this->step("Schreibe Bericht nach $outDir");
        $this->writeReport($outDir);
        $this->step('Fertig.');
        fwrite(STDERR, "\n" . file_get_contents($outDir . '/zusammenfassung.md') . "\n");
    }

    // ------------------------------------------------------------------
    // Grunddaten
    // ------------------------------------------------------------------

    private function detectStorage(): int
    {
        if (!$this->db->tableExists('sys_file_storage')) {
            return 1;
        }
        $where = $this->db->has('sys_file_storage', 'deleted') ? 'WHERE deleted = 0' : '';
        foreach ($this->db->all("SELECT uid, configuration FROM sys_file_storage $where ORDER BY uid") as $row) {
            if (preg_match('~<field index="basePath">\s*<value index="vDEF">\s*/?fileadmin/?\s*</value>~', (string)$row['configuration'])) {
                return (int)$row['uid'];
            }
        }
        return 1;
    }

    private function loadPages(): void
    {
        $wanted = ['uid', 'pid', 'title', 'slug', 'doktype', 'hidden', 'deleted', 'is_siteroot',
            'extendToSubpages', 'sys_language_uid', 'l10n_parent', 't3ver_wsid', 'starttime', 'endtime'];
        $cols = array_values(array_filter($wanted, fn ($c) => $this->db->has('pages', $c)));
        foreach ($this->db->all('SELECT ' . implode(',', array_map([Db::class, 'q'], $cols)) . ' FROM pages') as $row) {
            $this->pages[(int)$row['uid']] = $row;
        }
    }

    private function loadUsers(): void
    {
        if (!$this->db->tableExists('be_users')) {
            return;
        }
        $realName = $this->db->has('be_users', 'realName') ? ', realName' : '';
        foreach ($this->db->all("SELECT uid, username$realName FROM be_users") as $row) {
            $label = (string)$row['username'];
            if (!empty($row['realName'])) {
                $label .= ' (' . $row['realName'] . ')';
            }
            $this->users[(int)$row['uid']] = $label;
        }
    }

    private function userLabel(?int $uid): string
    {
        if ($uid === null) {
            return '';
        }
        if ($uid === 0) {
            return 'System/CLI';
        }
        return $this->users[$uid] ?? "be_user #$uid (gelöscht)";
    }

    private function checkRefindex(): void
    {
        if (!$this->db->tableExists('sys_refindex')) {
            $this->warnings[] = 'Tabelle sys_refindex fehlt.';
            return;
        }
        $count = (int)($this->db->one('SELECT COUNT(*) AS c FROM sys_refindex')['c'] ?? 0);
        if ($count === 0) {
            $this->warnings[] = 'Der Referenzindex ist leer. Vorher ausführen: vendor/bin/typo3 referenceindex:update';
        }
    }

    private function scanFilesystem(): void
    {
        foreach ($this->folders as $folder) {
            $dir = $this->env->fileadmin . '/_migrated/' . $folder;
            if (!is_dir($dir)) {
                $this->warnings[] = "Ordner fehlt im Dateisystem: fileadmin/_migrated/$folder";
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($this->env->fileadmin));
                $entry = $this->newEntry($rel, $folder);
                $entry['on_disk'] = true;
                $entry['size'] = $file->getSize();
                $entry['mtime'] = $file->getMTime();
                $this->files[$rel] = $entry;
            }
        }
    }

    private function newEntry(string $identifier, string $folder): array
    {
        return [
            'identifier' => $identifier,
            'path' => 'fileadmin' . $identifier,
            'folder' => $folder,
            'on_disk' => false,
            'size' => 0,
            'mtime' => null,
            'uid' => null,
            'sha1' => '',
            'missing_flag' => false,
            'duplicates' => [],
            'usages' => [],
            'file_log' => [],
            'log' => ['hits' => 0, 'bots' => 0, 'last' => null, 'referers' => []],
            'status' => '',
            'reason' => '',
        ];
    }

    private function loadSysFile(): void
    {
        $missing = $this->db->has('sys_file', 'missing') ? ', missing' : ', 0 AS missing';
        $rows = $this->db->all(
            "SELECT uid, identifier, size, sha1$missing FROM sys_file WHERE storage = ? AND identifier LIKE ?",
            [$this->storage, '/\_migrated/%']
        );
        foreach ($rows as $row) {
            $identifier = (string)$row['identifier'];
            $folder = explode('/', $identifier)[2] ?? '';
            if (!in_array($folder, $this->folders, true)) {
                continue;
            }
            if (!isset($this->files[$identifier])) {
                $this->files[$identifier] = $this->newEntry($identifier, $folder);
                $this->files[$identifier]['size'] = (int)$row['size'];
            }
            $this->files[$identifier]['uid'] = (int)$row['uid'];
            $this->files[$identifier]['sha1'] = (string)$row['sha1'];
            $this->files[$identifier]['missing_flag'] = (bool)$row['missing'];
            $this->uidToKey[(int)$row['uid']] = $identifier;
        }
        ksort($this->files, SORT_STRING);
    }

    private function findDuplicates(): void
    {
        $bySha = [];
        foreach ($this->files as $key => &$entry) {
            if ($entry['sha1'] === '' && $entry['on_disk'] && $entry['size'] < 200 * 1024 * 1024) {
                $entry['sha1'] = (string)sha1_file($this->env->fileadmin . $key);
            }
            if ($entry['sha1'] !== '') {
                $bySha[$entry['sha1']][] = $key;
            }
        }
        unset($entry);
        // Duplikate innerhalb von _migrated
        foreach ($bySha as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    foreach ($keys as $other) {
                        if ($other !== $key) {
                            $this->files[$key]['duplicates'][] = 'fileadmin' . $other;
                        }
                    }
                }
            }
        }
        // Duplikate außerhalb von _migrated (alle Storages)
        $missingCond = $this->db->has('sys_file', 'missing') ? 'AND missing = 0' : '';
        foreach (array_chunk(array_keys($bySha), 500) as $chunk) {
            $rows = $this->db->all(
                'SELECT storage, identifier, sha1 FROM sys_file WHERE sha1 IN (' . Db::placeholders($chunk) . ")
                 AND NOT (storage = ? AND identifier LIKE ?) $missingCond",
                [...$chunk, $this->storage, '/\_migrated/%']
            );
            foreach ($rows as $row) {
                $label = ((int)$row['storage'] === $this->storage ? 'fileadmin' : $row['storage'] . ':') . $row['identifier'];
                foreach ($bySha[$row['sha1']] as $key) {
                    $this->files[$key]['duplicates'][] = $label;
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Verwendungen sammeln
    // ------------------------------------------------------------------

    private function addUsage(string $key, string $source, ?string $table, ?int $uid, ?string $field, array $extra = []): void
    {
        $usageKey = $table !== null ? "$table|$uid|$field" : "$source|" . ($extra['location'] ?? '');
        $usages = &$this->files[$key]['usages'];
        if (!isset($usages[$usageKey])) {
            $usages[$usageKey] = [
                'sources' => [],
                'table' => $table,
                'uid' => $uid,
                'field' => $field,
                'ref_uid' => null,
                'ref_deleted' => false,
                'ref_hidden' => false,
                'ref_ws' => 0,
                'location' => '',
            ];
        }
        $usage = &$usages[$usageKey];
        $usage['sources'][$source] = true;
        if (isset($extra['ref_uid'])) {
            // bei mehreren Referenzen auf dieselbe Datei im selben Feld die "lebendigste" behalten
            $better = $usage['ref_uid'] === null
                || ($usage['ref_deleted'] && !$extra['ref_deleted'])
                || ($usage['ref_hidden'] && !$extra['ref_hidden'] && !$extra['ref_deleted']);
            if ($better) {
                foreach (['ref_uid', 'ref_deleted', 'ref_hidden', 'ref_ws'] as $k) {
                    $usage[$k] = $extra[$k];
                }
            }
        }
        if (!empty($extra['location']) && $usage['location'] === '') {
            $usage['location'] = $extra['location'];
        }
    }

    private function collectFileReferences(): void
    {
        $t = 'sys_file_reference';
        $sel = ['uid', 'uid_local', 'uid_foreign', 'tablenames', 'fieldname'];
        foreach (['deleted', 'hidden', 't3ver_wsid'] as $c) {
            $sel[] = $this->db->has($t, $c) ? $c : "0 AS $c";
        }
        foreach (array_chunk(array_keys($this->uidToKey), 500) as $chunk) {
            $rows = $this->db->all('SELECT ' . implode(',', $sel) . " FROM $t WHERE uid_local IN (" . Db::placeholders($chunk) . ')', $chunk);
            foreach ($rows as $row) {
                $this->addUsage($this->uidToKey[(int)$row['uid_local']], 'sys_file_reference', (string)$row['tablenames'],
                    (int)$row['uid_foreign'], (string)$row['fieldname'], [
                        'ref_uid' => (int)$row['uid'],
                        'ref_deleted' => (bool)$row['deleted'],
                        'ref_hidden' => (bool)$row['hidden'],
                        'ref_ws' => (int)$row['t3ver_wsid'],
                    ]);
            }
        }
    }

    private function collectRefindex(): void
    {
        if (!$this->db->tableExists('sys_refindex')) {
            return;
        }
        foreach (array_chunk(array_keys($this->uidToKey), 500) as $chunk) {
            $rows = $this->db->all(
                "SELECT tablename, recuid, field, softref_key, ref_uid FROM sys_refindex
                 WHERE ref_table = 'sys_file' AND ref_uid IN (" . Db::placeholders($chunk) . ")
                 AND tablename NOT IN ('sys_file_reference', 'sys_file_metadata', 'sys_file')",
                $chunk
            );
            foreach ($rows as $row) {
                $this->addUsage($this->uidToKey[(int)$row['ref_uid']], 'sys_refindex', (string)$row['tablename'],
                    (int)$row['recuid'], (string)$row['field'],
                    ['location' => $row['softref_key'] ? 'Softreference: ' . $row['softref_key'] : '']);
            }
        }
    }

    /**
     * Findet Pfade/Links in einem Text und ordnet sie Dateien zu.
     * @return array{files: array<string,string>, folders: string[], broken: string[]} files: key => Kontext
     */
    private function extractReferences(string $text): array
    {
        $result = ['files' => [], 'folders' => [], 'broken' => []];
        $variants = [$text];
        if (str_contains($text, '%')) {
            $variants[] = rawurldecode($text);
        }
        foreach ($variants as $variant) {
            if (preg_match_all('~_migrated/([A-Za-z0-9_\-]+)(/[^"\'\s<>|,;()\[\]?#\\\\]*)?~u', $variant, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $folder = $m[1][0];
                    $rest = isset($m[2]) ? rtrim($m[2][0], '.') : '';
                    $context = $this->snippet($variant, $m[0][1]);
                    if ($rest === '' || str_ends_with($rest, '/')) {
                        $result['folders'][] = "_migrated/$folder$rest";
                        continue;
                    }
                    $identifier = "/_migrated/$folder$rest";
                    if (isset($this->files[$identifier])) {
                        $result['files'][$identifier] = $context;
                    } elseif (isset($this->files[rawurldecode($identifier)])) {
                        $result['files'][rawurldecode($identifier)] = $context;
                    } elseif (in_array($folder, $this->folders, true)) {
                        $result['broken'][] = "fileadmin$identifier";
                    }
                }
            }
        }
        if (preg_match_all('~(?:t3://file\?uid=|<link\s+file:|["\']file:)(\d+)~', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $uid = (int)$m[1][0];
                if (isset($this->uidToKey[$uid])) {
                    $result['files'][$this->uidToKey[$uid]] = $this->snippet($text, $m[0][1]);
                }
            }
        }
        $result['folders'] = array_values(array_unique($result['folders']));
        // Teiltreffer der dekodierten Variante (z. B. "logo" aus "logo alt.png") nicht als defekt melden
        $result['broken'] = array_values(array_filter(array_unique($result['broken']), static function (string $b) use ($result): bool {
            foreach (array_keys($result['files']) as $key) {
                if (str_starts_with('fileadmin' . $key, $b)) {
                    return false;
                }
            }
            return true;
        }));
        return $result;
    }

    private function snippet(string $text, int $offset): string
    {
        $s = substr($text, max(0, $offset - 60), 160);
        $s = preg_replace('/\s+/', ' ', strip_tags($s)) ?? '';
        return mb_substr(trim(mb_convert_encoding($s, 'UTF-8', 'UTF-8')), 0, 140);
    }

    private function collectTextReferences(): void
    {
        $rows = $this->db->all(
            "SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE() AND tb.TABLE_TYPE = 'BASE TABLE'
             AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext')
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
        );
        $tables = [];
        foreach ($rows as $row) {
            $tables[$row['t']][] = $row['c'];
        }
        $patterns = ['%\_migrated%', '%t3://file?uid=%', '%<link file:%', '%"file:%', "%'file:%"];
        foreach ($tables as $table => $columns) {
            foreach (self::TEXT_SCAN_EXCLUDE as $prefix) {
                if (str_starts_with($table, $prefix)) {
                    continue 2;
                }
            }
            if ($table === 'sys_file') {
                continue;
            }
            $where = [];
            $params = [];
            foreach ($columns as $col) {
                foreach ($patterns as $p) {
                    $where[] = Db::q($col) . ' LIKE ?';
                    $params[] = $p;
                }
            }
            $hasUid = $this->db->has($table, 'uid');
            $sql = 'SELECT ' . ($hasUid ? 'uid, ' : '') . implode(',', array_map([Db::class, 'q'], $columns))
                . ' FROM ' . Db::q($table) . ' WHERE ' . implode(' OR ', $where);
            try {
                $stmt = $this->db->pdo->prepare($sql);
                $stmt->execute($params);
            } catch (\PDOException $e) {
                $this->warnings[] = "Textsuche in $table fehlgeschlagen: " . $e->getMessage();
                continue;
            }
            while ($row = $stmt->fetch()) {
                $uid = $hasUid ? (int)$row['uid'] : null;
                foreach ($columns as $col) {
                    $value = (string)($row[$col] ?? '');
                    if ($value === '' || (!str_contains($value, '_migrated') && !str_contains($value, 'file'))) {
                        continue;
                    }
                    $found = $this->extractReferences($value);
                    $location = $uid !== null ? "$table:$uid.$col" : "$table.$col";
                    foreach ($found['files'] as $key => $context) {
                        if ($table === 'sys_file_metadata') {
                            continue; // Metadaten beschreiben die Datei selbst
                        }
                        $this->addUsage($key, 'textfeld', $table, $uid, $col, ['location' => $context]);
                    }
                    foreach ($found['folders'] as $folder) {
                        $this->folderHits[] = ['folder' => $folder, 'source' => 'Datenbank', 'location' => $location,
                            'table' => $table, 'uid' => $uid];
                    }
                    foreach ($found['broken'] as $path) {
                        $this->brokenRefs[$path . '|' . $location] = ['path' => $path, 'location' => $location];
                    }
                }
            }
        }
    }

    private function collectFileLog(): void
    {
        if (!$this->db->has('sys_log', 'log_data')) {
            return;
        }
        $rows = $this->db->all(
            "SELECT tstamp, userid, details, log_data FROM sys_log
             WHERE type = 2 AND (log_data LIKE ? OR details LIKE ?) ORDER BY tstamp",
            ['%\_migrated%', '%\_migrated%']
        );
        foreach ($rows as $row) {
            $args = json_decode((string)$row['log_data'], true);
            if (!is_array($args)) {
                $args = @unserialize((string)$row['log_data'], ['allowed_classes' => false]);
            }
            $text = (string)$row['details'];
            if (is_array($args)) {
                $args = array_map(static fn ($a) => is_scalar($a) ? (string)$a : json_encode($a), array_values($args));
                try {
                    $text = vsprintf($text, $args);
                } catch (\Throwable) {
                    $text .= ' ' . implode(' ', $args);
                }
            }
            $found = $this->extractReferences($text . ' ' . $row['log_data']);
            $line = date('Y-m-d', (int)$row['tstamp']) . ' ' . $this->userLabel((int)$row['userid']) . ': ' . mb_substr($text, 0, 200);
            foreach (array_keys($found['files']) as $key) {
                $this->files[$key]['file_log'][] = $line;
            }
        }
    }

    private function collectFilesystemReferences(): void
    {
        $dirs = [
            $this->env->root . '/config',
            $this->env->root . '/packages',
            $this->env->publicDir . '/typo3conf/ext',
            $this->env->fileadmin,
            ...$this->extraScanDirs,
        ];
        $files = [];
        foreach ([$this->env->publicDir . '/.htaccess', $this->env->publicDir . '/robots.txt'] as $f) {
            if (is_file($f)) {
                $files[] = $f;
            }
        }
        foreach (array_unique($dirs) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $filter = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $f): bool {
                    if ($f->isDir()) {
                        return !in_array($f->getFilename(), self::FS_SCAN_SKIP_DIRS, true);
                    }
                    $ext = strtolower($f->getExtension() ?: ltrim($f->getFilename(), '.'));
                    return in_array($ext, self::FS_SCAN_EXTENSIONS, true) && $f->getSize() < 5 * 1024 * 1024;
                }
            );
            foreach (new \RecursiveIteratorIterator($filter) as $f) {
                $files[] = $f->getPathname();
            }
        }
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false || (!str_contains($content, '_migrated') && !str_contains($content, 't3://file'))) {
                continue;
            }
            $relFile = str_starts_with($file, $this->env->root . '/') ? substr($file, strlen($this->env->root) + 1) : $file;
            foreach (preg_split('/\R/', $content) as $no => $line) {
                if (!str_contains($line, '_migrated') && !str_contains($line, 't3://file')) {
                    continue;
                }
                $location = $relFile . ':' . ($no + 1);
                $found = $this->extractReferences($line);
                foreach ($found['files'] as $key => $context) {
                    $this->addUsage($key, 'dateisystem', null, null, null, ['location' => $location]);
                }
                foreach ($found['folders'] as $folder) {
                    $this->folderHits[] = ['folder' => $folder, 'source' => 'Dateisystem', 'location' => $location,
                        'table' => null, 'uid' => null];
                }
                foreach ($found['broken'] as $path) {
                    $this->brokenRefs[$path . '|' . $location] = ['path' => $path, 'location' => $location];
                }
            }
        }
    }

    private function collectAccessLogs(): void
    {
        $logFiles = [];
        foreach ($this->accessLogs as $pattern) {
            foreach (glob($pattern) ?: [] as $f) {
                if (is_file($f)) {
                    $logFiles[$f] = true;
                }
            }
        }
        if ($logFiles === []) {
            $this->warnings[] = 'Keine Access-Logs gefunden für: ' . implode(', ', $this->accessLogs);
            return;
        }
        foreach (array_keys($logFiles) as $logFile) {
            $h = @gzopen($logFile, 'rb'); // liest auch unkomprimierte Dateien
            if ($h === false) {
                $this->warnings[] = "Log nicht lesbar (Rechte?): $logFile";
                continue;
            }
            $this->logStats['files']++;
            while (($line = gzgets($h)) !== false) {
                $this->logStats['lines']++;
                if (!str_contains($line, '/fileadmin/')) {
                    continue;
                }
                if (!preg_match('~\[([^\]]+)\] "([A-Z]+) (\S+)[^"]*" (\d{3}) \S+(?: "([^"]*)" "([^"]*)")?~', $line, $m)) {
                    continue;
                }
                $ts = \DateTime::createFromFormat('d/M/Y:H:i:s O', $m[1]);
                $ts = $ts ? $ts->getTimestamp() : null;
                if ($ts !== null) {
                    $this->logStats['from'] = min($this->logStats['from'] ?? $ts, $ts);
                    $this->logStats['to'] = max($this->logStats['to'] ?? $ts, $ts);
                }
                $path = (string)parse_url($m[3], PHP_URL_PATH);
                $pos = strpos($path, '/fileadmin/_migrated/');
                if ($pos === false) {
                    continue;
                }
                $identifier = rawurldecode(substr($path, $pos + strlen('/fileadmin')));
                $status = (int)$m[4];
                if (!isset($this->files[$identifier])) {
                    $folder = explode('/', $identifier)[2] ?? '';
                    if (in_array($folder, $this->folders, true)) {
                        $this->logMissing[$identifier] = ($this->logMissing[$identifier] ?? 0) + 1;
                    }
                    continue;
                }
                if (!in_array($status, [200, 206, 304], true)) {
                    continue;
                }
                $log = &$this->files[$identifier]['log'];
                if (preg_match(self::BOT_PATTERN, $m[6] ?? '')) {
                    $log['bots']++;
                } else {
                    $log['hits']++;
                    $referer = $m[5] ?? '-';
                    if ($referer !== '' && $referer !== '-') {
                        $host = parse_url($referer, PHP_URL_HOST) ?: $referer;
                        $log['referers'][$host] = ($log['referers'][$host] ?? 0) + 1;
                    }
                }
                if ($ts !== null && ($log['last'] === null || $ts > $log['last'])) {
                    $log['last'] = $ts;
                }
                unset($log);
            }
            gzclose($h);
        }
    }

    // ------------------------------------------------------------------
    // Auflösen und Klassifizieren
    // ------------------------------------------------------------------

    private function resolveRecord(string $table, int $uid): array
    {
        $cacheKey = "$table:$uid";
        if (isset($this->recordCache[$cacheKey])) {
            return $this->recordCache[$cacheKey];
        }
        $ctx = ['exists' => false, 'reason' => ''];
        if (!$this->db->tableExists($table)) {
            $ctx['reason'] = "Tabelle $table existiert nicht";
        } elseif (!$this->db->has($table, 'uid')) {
            $ctx['reason'] = 'Tabelle ohne uid';
        } else {
            $row = $this->db->one('SELECT * FROM ' . Db::q($table) . ' WHERE uid = ?', [$uid]);
            if ($row === null) {
                $ctx['reason'] = 'Datensatz existiert nicht (verwaiste Referenz)';
            } else {
                $lang = (int)($row['sys_language_uid'] ?? 0);
                $pageUid = $table === 'pages'
                    ? ($lang > 0 && !empty($row['l10n_parent']) ? (int)$row['l10n_parent'] : $uid)
                    : (int)($row['pid'] ?? 0);
                $starttime = (int)($row['starttime'] ?? 0);
                $endtime = (int)($row['endtime'] ?? 0);
                $label = '';
                foreach (['header', 'title', 'name', 'username', 'subject', 'description'] as $f) {
                    if (!empty($row[$f]) && is_string($row[$f])) {
                        $label = mb_substr(trim(strip_tags($row[$f])), 0, 80);
                        break;
                    }
                }
                $ctx = [
                    'exists' => true,
                    'reason' => '',
                    'page' => $pageUid,
                    'deleted' => !empty($row['deleted']),
                    'hidden' => !empty($row['hidden']) || !empty($row['disable']),
                    'expired' => $endtime > 0 && $endtime < $this->now,
                    'future' => $starttime > $this->now,
                    'ws' => (int)($row['t3ver_wsid'] ?? 0),
                    'lang' => $lang,
                    'crdate' => (int)($row['crdate'] ?? 0),
                    'tstamp' => (int)($row['tstamp'] ?? 0),
                    'cruser' => isset($row['cruser_id']) ? (int)$row['cruser_id'] : null,
                    'label' => $label,
                    'ctype' => (string)($row['CType'] ?? $row['list_type'] ?? ''),
                    'colpos' => isset($row['colPos']) ? (int)$row['colPos'] : null,
                ];
            }
        }
        return $this->recordCache[$cacheKey] = $ctx;
    }

    private function pageInfo(int $pageUid): array
    {
        if (isset($this->pageCache[$pageUid])) {
            return $this->pageCache[$pageUid];
        }
        $info = ['exists' => isset($this->pages[$pageUid]), 'deleted' => false, 'hidden' => false, 'doktype' => 0,
            'title' => '', 'path' => '', 'site' => '', 'base' => '', 'url' => '', 'in_site' => false];
        if (!$info['exists']) {
            return $this->pageCache[$pageUid] = $info;
        }
        $chain = [];
        $u = $pageUid;
        $guard = 0;
        while ($u > 0 && isset($this->pages[$u]) && $guard++ < 100) {
            $chain[] = $this->pages[$u];
            $u = (int)$this->pages[$u]['pid'];
        }
        $page = $chain[0];
        $info['title'] = (string)$page['title'];
        $info['doktype'] = (int)($page['doktype'] ?? 1);
        $info['deleted'] = $u > 0; // Elternseite fehlt => verwaister Teilbaum
        foreach ($chain as $i => $p) {
            if (!empty($p['deleted'])) {
                $info['deleted'] = true;
            }
            $hidden = !empty($p['hidden'])
                || (!empty($p['endtime']) && (int)$p['endtime'] < $this->now)
                || (!empty($p['starttime']) && (int)$p['starttime'] > $this->now);
            if ($hidden && ($i === 0 || !empty($p['extendToSubpages']))) {
                $info['hidden'] = true;
            }
        }
        $info['path'] = implode(' / ', array_map(static fn ($p) => $p['title'], array_reverse($chain)));
        foreach ($chain as $p) {
            if (isset($this->sites[(int)$p['uid']])) {
                $site = $this->sites[(int)$p['uid']];
                $info['in_site'] = true;
                $info['site'] = $site['identifier'];
                $info['base'] = $site['base'];
                break;
            }
        }
        if (!$info['in_site']) {
            foreach ($chain as $p) {
                if (!empty($p['is_siteroot'])) {
                    $info['site'] = '(Root-Seite ' . $p['uid'] . ' ohne Site-Konfiguration)';
                    break;
                }
            }
        }
        if ($info['in_site'] && in_array($info['doktype'], [1, 3, 4, 7], true)) {
            $base = rtrim($info['base'], '/');
            $info['url'] = !empty($page['slug']) ? $base . $page['slug'] : $base . '/?id=' . $pageUid;
        }
        return $this->pageCache[$pageUid] = $info;
    }

    /** Wer hat einen Datensatz angelegt / zuletzt geändert (sys_history + sys_log) */
    private function who(string $table, int $uid): array
    {
        $cacheKey = "$table:$uid";
        if (isset($this->whoCache[$cacheKey])) {
            return $this->whoCache[$cacheKey];
        }
        $events = [];
        if ($this->db->has('sys_history', 'recuid')) {
            foreach ($this->db->all('SELECT tstamp, actiontype, userid FROM sys_history WHERE tablename = ? AND recuid = ?', [$table, $uid]) as $r) {
                $events[] = [(int)$r['tstamp'], (int)$r['actiontype'] === 1, $r['userid'] === null ? null : (int)$r['userid']];
            }
        }
        if ($this->db->has('sys_log', 'recuid') && $this->db->has('sys_log', 'tablename')) {
            foreach ($this->db->all('SELECT tstamp, action, userid FROM sys_log WHERE type = 1 AND tablename = ? AND recuid = ?', [$table, $uid]) as $r) {
                $events[] = [(int)$r['tstamp'], (int)$r['action'] === 1, (int)$r['userid']];
            }
        }
        usort($events, static fn ($a, $b) => $a[0] <=> $b[0]);
        $result = ['creator' => null, 'last' => null];
        foreach ($events as $e) {
            if ($e[1] && $result['creator'] === null) {
                $result['creator'] = $e;
            }
            $result['last'] = $e;
        }
        return $this->whoCache[$cacheKey] = $result;
    }

    /** @return array{0:string,1:string} [Status der Verwendung, Grund] */
    private function usageStatus(array $u): array
    {
        if ($u['table'] === null) {
            return ['aktiv', 'Pfad in Code/Konfiguration'];
        }
        if ($u['uid'] === null) {
            return ['unbekannt', 'Fundstelle ohne Datensatz-UID'];
        }
        if ($u['ref_deleted']) {
            return ['geloescht', 'Dateireferenz gelöscht'];
        }
        $rec = $this->resolveRecord($u['table'], $u['uid']);
        if (!$rec['exists']) {
            return ['geloescht', $rec['reason']];
        }
        if ($rec['deleted']) {
            return ['geloescht', 'Datensatz gelöscht (Papierkorb)'];
        }
        if ($u['ref_ws'] > 0 || $rec['ws'] > 0) {
            return ['workspace', 'Nur in einer Workspace-Version'];
        }
        $page = $rec['page'] > 0 ? $this->pageInfo($rec['page']) : null;
        if ($page !== null && (!$page['exists'] || $page['deleted'])) {
            return ['geloescht', 'Seite gelöscht oder verwaist'];
        }
        if ($u['ref_hidden']) {
            return ['versteckt', 'Dateireferenz versteckt'];
        }
        if ($rec['hidden'] || $rec['expired'] || $rec['future']) {
            return ['versteckt', 'Datensatz versteckt oder zeitlich nicht sichtbar'];
        }
        if ($page !== null && $page['hidden']) {
            return ['versteckt', 'Seite (oder übergeordnete Seite) versteckt'];
        }
        if ($page !== null && $page['doktype'] === 255) {
            return ['versteckt', 'Liegt auf einer Papierkorb-Seite'];
        }
        if ($page !== null && !$page['in_site'] && $u['table'] !== 'sys_template') {
            return ['ausserhalb_site', 'Seite liegt in keinem Site-Baum'];
        }
        if ($u['table'] === 'tt_content' && $page !== null && $page['doktype'] === 254) {
            return ['sysordner', 'Inhalt in einem Ordner – evtl. über "Datensatz einfügen" eingebunden'];
        }
        return ['aktiv', ''];
    }

    private function classify(): void
    {
        $folderReferenced = [];
        foreach ($this->folderHits as $hit) {
            if (!in_array($hit['table'], self::FOLDER_INFO_TABLES, true)) {
                $parts = explode('/', $hit['folder']);
                $folderReferenced[$parts[1] ?? ''] = true;
            }
        }
        foreach ($this->files as &$entry) {
            $counts = ['aktiv' => 0, 'pruefen' => 0, 'geloescht' => 0, 'text' => 0];
            foreach ($entry['usages'] as &$u) {
                [$u['status'], $u['status_reason']] = $this->usageStatus($u);
                $bucket = match ($u['status']) {
                    'aktiv' => 'aktiv',
                    'geloescht' => 'geloescht',
                    default => 'pruefen',
                };
                $counts[$bucket]++;
                if (isset($u['sources']['textfeld']) || isset($u['sources']['dateisystem'])) {
                    $counts['text']++;
                }
            }
            unset($u);
            $entry['counts'] = $counts;

            $reasons = [];
            if ($counts['aktiv'] > 0) {
                $status = self::STATUS_IN_USE;
                $reasons[] = $counts['aktiv'] . ' aktive Verwendung(en)';
            } elseif ($counts['pruefen'] > 0) {
                $status = self::STATUS_REVIEW;
                $kinds = array_unique(array_map(static fn ($u) => $u['status_reason'], array_filter($entry['usages'], static fn ($u) => !in_array($u['status'], ['aktiv', 'geloescht'], true))));
                $reasons[] = 'Nur nicht öffentliche Verwendungen: ' . implode('; ', $kinds);
            } elseif ($counts['geloescht'] > 0) {
                $status = self::STATUS_DELETED_ONLY;
                $reasons[] = $counts['geloescht'] . ' Verwendung(en) nur in gelöschten Datensätzen/Seiten';
            } else {
                $status = self::STATUS_UNUSED;
                $reasons[] = 'Keine Verwendung gefunden';
            }
            if (in_array($status, [self::STATUS_UNUSED, self::STATUS_DELETED_ONLY], true)) {
                if ($entry['log']['hits'] > 0) {
                    $status = self::STATUS_REVIEW;
                    $reasons[] = 'wird laut Access-Log noch direkt abgerufen (' . $entry['log']['hits'] . 'x, z. B. externe Links)';
                }
                if (isset($folderReferenced[$entry['folder']])) {
                    $status = self::STATUS_REVIEW;
                    $reasons[] = 'der ganze Ordner wird referenziert (siehe Zusammenfassung)';
                }
            }
            if (!$entry['on_disk']) {
                $reasons[] = $counts['aktiv'] > 0 ? 'ACHTUNG: Datei fehlt auf der Platte, wird aber verwendet' : 'Datei fehlt auf der Platte (nur sys_file-Eintrag)';
            }
            if ($entry['uid'] === null) {
                $reasons[] = 'nicht in sys_file indiziert';
            }
            if ($entry['duplicates'] !== []) {
                $reasons[] = 'identische Kopie vorhanden';
            }
            $entry['status'] = $status;
            $entry['reason'] = implode('; ', $reasons);
        }
        unset($entry);
    }

    // ------------------------------------------------------------------
    // Ausgabe
    // ------------------------------------------------------------------

    private function writeReport(string $outDir): void
    {
        if (!is_dir($outDir) && !mkdir($outDir, 0750, true) && !is_dir($outDir)) {
            throw new \RuntimeException("Ausgabeverzeichnis kann nicht angelegt werden: $outDir");
        }
        $fmtDate = static fn (?int $ts): string => $ts ? date('Y-m-d H:i', $ts) : '';

        $filesCsv = $this->openCsv("$outDir/dateien.csv", [
            'pfad', 'ordner', 'status', 'empfehlung', 'begruendung', 'auf_disk', 'groesse_bytes', 'groesse',
            'datei_geaendert', 'sys_file_uid', 'verwendungen_aktiv', 'verwendungen_pruefen', 'verwendungen_geloescht',
            'davon_text_code_funde', 'sites', 'seiten', 'eingebunden_von', 'duplikate',
            'log_abrufe', 'log_abrufe_bots', 'log_letzter_abruf', 'log_top_referer', 'datei_aktionen_sys_log',
        ]);
        $usageCsv = $this->openCsv("$outDir/verwendungen.csv", [
            'pfad', 'sys_file_uid', 'datei_status', 'quelle', 'tabelle', 'feld', 'datensatz_uid', 'datensatz_titel',
            'ctype', 'sprache', 'verwendung_status', 'grund', 'referenz_uid', 'seite_uid', 'seite_titel', 'seitenpfad',
            'seitentyp', 'site', 'url', 'datensatz_erstellt', 'erstellt_von', 'datensatz_geaendert',
            'zuletzt_geaendert_von', 'referenz_angelegt_von', 'fundstelle',
        ]);

        $stats = [];
        $siteStats = [];
        $missingButUsed = [];
        $deleteList = [];
        $deletedOnlyList = [];

        foreach ($this->files as $entry) {
            $sites = [];
            $pages = [];
            $users = [];
            foreach ($entry['usages'] as $u) {
                $rec = $u['table'] !== null && $u['uid'] !== null ? $this->resolveRecord($u['table'], $u['uid']) : ['exists' => false];
                $page = !empty($rec['exists']) && $rec['page'] > 0 ? $this->pageInfo($rec['page']) : null;
                $who = !empty($rec['exists']) ? $this->who($u['table'], $u['uid']) : ['creator' => null, 'last' => null];
                $refWho = $u['ref_uid'] ? $this->who('sys_file_reference', $u['ref_uid']) : ['creator' => null];
                $creator = $who['creator'] ? $this->userLabel($who['creator'][2]) : (isset($rec['cruser']) && $rec['cruser'] ? $this->userLabel($rec['cruser']) : '');
                $lastEditor = $who['last'] ? $this->userLabel($who['last'][2]) . ' (' . date('Y-m-d', $who['last'][0]) . ')' : '';
                $refCreator = $refWho['creator'] ? $this->userLabel($refWho['creator'][2]) . ' (' . date('Y-m-d', $refWho['creator'][0]) . ')' : '';

                if ($u['status'] !== 'geloescht') {
                    if ($page !== null) {
                        $site = $page['site'] !== '' ? $page['site'] : '(keine Site)';
                        $sites[$site] = true;
                        $pages[$rec['page']] = $rec['page'] . ':' . $page['title'];
                    } elseif ($u['table'] === null) {
                        $sites['(Code/Konfiguration)'] = true;
                    }
                    foreach ([$refCreator !== '' ? preg_replace('/ \(\d{4}-.*$/', '', $refCreator) : '', $creator] as $name) {
                        if ($name !== '') {
                            $users[$name] = true;
                        }
                    }
                    if ($u['status'] === 'aktiv' && $page !== null && $page['site'] !== '') {
                        $siteStats[$page['site']][$entry['identifier']] = true;
                    }
                }

                $this->writeCsv($usageCsv, [
                    $entry['path'], $entry['uid'], $entry['status'], implode('+', array_keys($u['sources'])),
                    $u['table'], $u['field'], $u['uid'], $rec['label'] ?? '', $rec['ctype'] ?? '', $rec['lang'] ?? '',
                    $u['status'], $u['status_reason'], $u['ref_uid'], $page !== null ? $rec['page'] : '',
                    $page['title'] ?? '', $page['path'] ?? '', $page !== null ? $this->doktypeLabel($page['doktype']) : '',
                    $page['site'] ?? '', $page['url'] ?? '', $fmtDate($rec['crdate'] ?? null), $creator,
                    $fmtDate($rec['tstamp'] ?? null), $lastEditor, $refCreator, $u['location'],
                ]);
            }

            arsort($entry['log']['referers']);
            $referers = array_map(static fn ($h, $c) => "$h ($c)", array_keys($entry['log']['referers']), $entry['log']['referers']);
            $this->writeCsv($filesCsv, [
                $entry['path'], $entry['folder'], $entry['status'], self::RECOMMENDATION[$entry['status']], $entry['reason'],
                $entry['on_disk'] ? 'ja' : 'nein', $entry['size'], $this->humanSize($entry['size']), $fmtDate($entry['mtime']),
                $entry['uid'], $entry['counts']['aktiv'], $entry['counts']['pruefen'], $entry['counts']['geloescht'],
                $entry['counts']['text'], implode(', ', array_keys($sites)), $this->limitList(array_values($pages), 10),
                implode(', ', array_keys($users)), $this->limitList(array_values(array_unique($entry['duplicates'])), 5),
                $entry['log']['hits'], $entry['log']['bots'], $fmtDate($entry['log']['last']),
                implode(', ', array_slice($referers, 0, 3)), $this->limitList($entry['file_log'], 5),
            ]);

            $s = &$stats[$entry['folder']];
            $s['files'] = ($s['files'] ?? 0) + 1;
            $s['size'] = ($s['size'] ?? 0) + $entry['size'];
            $s[$entry['status']] = ($s[$entry['status']] ?? 0) + 1;
            $s[$entry['status'] . '_size'] = ($s[$entry['status'] . '_size'] ?? 0) + $entry['size'];
            $s['not_on_disk'] = ($s['not_on_disk'] ?? 0) + ($entry['on_disk'] ? 0 : 1);
            $s['not_indexed'] = ($s['not_indexed'] ?? 0) + ($entry['uid'] === null ? 1 : 0);
            unset($s);

            if (!$entry['on_disk'] && $entry['counts']['aktiv'] > 0) {
                $missingButUsed[] = $entry['path'];
            }
            if ($entry['on_disk'] && $entry['status'] === self::STATUS_UNUSED) {
                $deleteList[] = $entry['identifier'];
            }
            if ($entry['on_disk'] && $entry['status'] === self::STATUS_DELETED_ONLY) {
                $deletedOnlyList[] = $entry['identifier'];
            }
        }
        fclose($filesCsv);
        fclose($usageCsv);

        file_put_contents("$outDir/loeschkandidaten.txt", $this->listFile($deleteList, self::STATUS_UNUSED));
        file_put_contents("$outDir/loeschkandidaten_nur_geloeschte_referenzen.txt", $this->listFile($deletedOnlyList, self::STATUS_DELETED_ONLY));
        file_put_contents("$outDir/zusammenfassung.md", $this->summary($stats, $siteStats, $missingButUsed));
    }

    private function summary(array $stats, array $siteStats, array $missingButUsed): string
    {
        $statuses = [self::STATUS_IN_USE, self::STATUS_REVIEW, self::STATUS_DELETED_ONLY, self::STATUS_UNUSED];
        $md = "# Analyse fileadmin/_migrated\n\n";
        $md .= '- Erstellt: ' . date('Y-m-d H:i') . "\n";
        $md .= '- TYPO3-Projekt: `' . $this->env->root . '` (Version ' . $this->env->typo3Version() . ")\n";
        $md .= '- Datenbank: `' . $this->db->name . '`, fileadmin-Storage-UID: ' . $this->storage . "\n";
        $md .= '- Sites: ' . ($this->sites === [] ? '(keine gefunden)' : implode(', ', array_map(
            static fn ($s) => $s['identifier'] . ' → ' . ($s['base'] ?: '?') . ' (Root ' . $s['rootPageId'] . ')', $this->sites))) . "\n";
        $md .= '- Prüfungen: sys_file_reference, sys_refindex' . ($this->textScan ? ', DB-Textfelder' : '')
            . ', sys_log' . ($this->fsScan ? ', Dateisystem (TypoScript/Templates/Config)' : '')
            . ($this->accessLogs !== [] ? ', Access-Logs' : '') . "\n";
        if ($this->logStats['files'] > 0) {
            $md .= sprintf("- Access-Logs: %d Dateien, %d Zeilen, Zeitraum %s bis %s\n", $this->logStats['files'], $this->logStats['lines'],
                $this->logStats['from'] ? date('Y-m-d', $this->logStats['from']) : '?', $this->logStats['to'] ? date('Y-m-d', $this->logStats['to']) : '?');
        }
        $warnings = array_merge($this->env->warnings, $this->warnings);
        if ($warnings !== []) {
            $md .= "\n## Warnungen\n\n" . implode("\n", array_map(static fn ($w) => "- $w", array_unique($warnings))) . "\n";
        }

        $md .= "\n## Ergebnis je Ordner\n\n| Ordner | Dateien | Größe | " . implode(' | ', $statuses) . " | fehlt auf Disk | nicht indiziert |\n";
        $md .= '|---|---:|---:|' . str_repeat('---:|', count($statuses) + 2) . "\n";
        $total = [];
        foreach ($stats as $folder => $s) {
            $cells = [];
            foreach ($statuses as $st) {
                $cells[] = ($s[$st] ?? 0) . ' (' . $this->humanSize($s[$st . '_size'] ?? 0) . ')';
                $total[$st] = ($total[$st] ?? 0) + ($s[$st] ?? 0);
                $total[$st . '_size'] = ($total[$st . '_size'] ?? 0) + ($s[$st . '_size'] ?? 0);
            }
            foreach (['files', 'size', 'not_on_disk', 'not_indexed'] as $k) {
                $total[$k] = ($total[$k] ?? 0) + ($s[$k] ?? 0);
            }
            $md .= "| $folder | {$s['files']} | " . $this->humanSize($s['size']) . ' | ' . implode(' | ', $cells)
                . " | {$s['not_on_disk']} | {$s['not_indexed']} |\n";
        }
        if ($stats !== []) {
            $cells = array_map(fn ($st) => ($total[$st] ?? 0) . ' (' . $this->humanSize($total[$st . '_size'] ?? 0) . ')', $statuses);
            $md .= '| **Summe** | ' . $total['files'] . ' | ' . $this->humanSize($total['size']) . ' | ' . implode(' | ', $cells)
                . " | {$total['not_on_disk']} | {$total['not_indexed']} |\n";
        }

        $md .= "\n### Bedeutung\n\n";
        foreach ($statuses as $st) {
            $md .= "- **$st**: " . self::RECOMMENDATION[$st] . "\n";
        }

        if ($siteStats !== []) {
            $md .= "\n## Aktiv verwendete Dateien je Site\n\n| Site | Domain | Dateien |\n|---|---|---:|\n";
            $bases = [];
            foreach ($this->sites as $s) {
                $bases[$s['identifier']] = $s['base'];
            }
            ksort($siteStats);
            foreach ($siteStats as $site => $files) {
                $md .= "| $site | " . ($bases[$site] ?? '') . ' | ' . count($files) . " |\n";
            }
        }
        if ($missingButUsed !== []) {
            $md .= "\n## Verwendete Dateien, die auf der Platte fehlen (defekte Bilder/Downloads!)\n\n"
                . $this->mdList($missingButUsed, 50);
        }
        if ($this->folderHits !== []) {
            $md .= "\n## Verweise auf ganze Ordner\n\nDiese Fundstellen verweisen auf einen Ordner statt auf eine einzelne Datei "
                . "(z. B. Dateisammlungen, Dateifreigaben, TypoScript). Unbenutzte Dateien in betroffenen Ordnern werden als PRUEFEN markiert"
                . " (Ausnahme: " . implode(', ', self::FOLDER_INFO_TABLES) . ").\n\n";
            $lines = array_unique(array_map(static fn ($h) => '`' . $h['folder'] . '` ← ' . $h['source'] . ': ' . $h['location'], $this->folderHits));
            $md .= $this->mdList(array_values($lines), 50);
        }
        if ($this->brokenRefs !== []) {
            $md .= "\n## Verweise auf nicht vorhandene _migrated-Dateien\n\n";
            $md .= $this->mdList(array_values(array_map(static fn ($b) => '`' . $b['path'] . '` ← ' . $b['location'], $this->brokenRefs)), 50);
        }
        if ($this->logMissing !== []) {
            arsort($this->logMissing);
            $md .= "\n## Im Access-Log angefragte, aber nicht vorhandene Dateien (Top 30)\n\n";
            $md .= $this->mdList(array_map(static fn ($k, $v) => "`fileadmin$k` ($v Anfragen)", array_keys($this->logMissing), $this->logMissing), 30);
        }
        if ($this->otherMigratedFolders !== []) {
            $md .= "\n## Weitere Unterordner in _migrated (nicht analysiert)\n\n"
                . $this->mdList(array_map(static fn ($f) => "`_migrated/$f` – mit `--folders=all` einbeziehen", $this->otherMigratedFolders), 50);
        }
        $md .= "\n## Dateien\n\n"
            . "- `dateien.csv` – eine Zeile pro Datei mit Status und Empfehlung\n"
            . "- `verwendungen.csv` – eine Zeile pro Fundstelle (Datensatz, Seite, Site, URL, Bearbeiter)\n"
            . "- `loeschkandidaten.txt` – Eingabe für `bin/migrated-quarantine.php`\n"
            . "- `loeschkandidaten_nur_geloeschte_referenzen.txt` – zweite Stufe, nach Leeren des Papierkorbs\n";
        return $md;
    }

    private function doktypeLabel(int $doktype): string
    {
        return match ($doktype) {
            1 => 'Standard',
            3 => 'Link',
            4 => 'Verweis',
            6 => 'Backend-Bereich',
            7 => 'Mount-Seite',
            199 => 'Abstand',
            254 => 'Ordner',
            255 => 'Papierkorb',
            default => "doktype $doktype",
        };
    }

    private function listFile(array $identifiers, string $status): string
    {
        $head = "# Status $status – erzeugt " . date('Y-m-d H:i') . " von migrated-analyze.php\n"
            . "# Pfade relativ zu fileadmin. Zeilen löschen = Datei NICHT verschieben.\n";
        return $head . implode("\n", array_map(static fn ($i) => ltrim($i, '/'), $identifiers)) . ($identifiers ? "\n" : '');
    }

    /** @return resource */
    private function openCsv(string $file, array $header)
    {
        $h = fopen($file, 'wb');
        fwrite($h, "\xEF\xBB\xBF"); // BOM, damit Excel UTF-8 erkennt
        $this->writeCsv($h, $header);
        return $h;
    }

    /** @param resource $h */
    private function writeCsv($h, array $row): void
    {
        fputcsv($h, array_map(static fn ($v) => $v === null ? '' : (string)$v, $row), ';', '"', '');
    }

    private function limitList(array $items, int $max): string
    {
        $more = count($items) - $max;
        return implode(' | ', array_slice($items, 0, $max)) . ($more > 0 ? " | … (+$more)" : '');
    }

    private function mdList(array $items, int $max): string
    {
        $out = implode("\n", array_map(static fn ($i) => "- $i", array_slice($items, 0, $max))) . "\n";
        if (count($items) > $max) {
            $out .= '- … und ' . (count($items) - $max) . " weitere\n";
        }
        return $out;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float)$bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)$bytes : number_format($size, 1, ',', '.')) . ' ' . $units[$i];
    }

    private function step(string $message): void
    {
        fwrite(STDERR, '[' . date('H:i:s') . "] $message\n");
    }
}
