#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Analysiert fileadmin/_migrated/* einer TYPO3-Installation (v12/v13):
 *  - Welche Dateien gibt es (Dateisystem + sys_file)?
 *  - Wo sind sie eingebunden (sys_file_reference, sys_refindex, Textfelder, Code)?
 *  - Auf welchen Seiten / Sites / Domains – auch versteckte und gelöschte (Papierkorb)?
 *  - Werden sie noch direkt abgerufen (Webserver-Access-Logs), von welcher Domain,
 *    und stammt der Link (Referer) von einer TYPO3-Seite?
 *  - Können sie gelöscht werden? (nur wenn weder TYPO3 noch das Log eine Verwendung zeigt)
 *
 * Das Skript arbeitet ausschließlich lesend.
 */

use Typo3Tools\AccessLog;
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
    private const STATUS_HIDDEN = 'VERSTECKT_VERWENDET';
    private const STATUS_TRASH = 'IM_PAPIERKORB';
    private const STATUS_REQUESTED = 'ABGERUFEN_OHNE_REFERENZ';
    private const STATUS_REVIEW = 'PRUEFEN';
    private const STATUS_UNUSED = 'UNBENUTZT';

    /** Reihenfolge = Priorität */
    private const STATUSES = [
        self::STATUS_IN_USE, self::STATUS_HIDDEN, self::STATUS_TRASH, self::STATUS_REQUESTED, self::STATUS_REVIEW, self::STATUS_UNUSED,
    ];

    private const RECOMMENDATION = [
        self::STATUS_IN_USE => 'Behalten – öffentlich eingebunden.',
        self::STATUS_HIDDEN => 'Behalten – eingebunden in versteckten/zeitgesteuerten Inhalten, Ordnern oder Workspaces.',
        self::STATUS_TRASH => 'Behalten – nur noch in gelöschten Inhalten (Papierkorb), wiederherstellbar.',
        self::STATUS_REQUESTED => 'Behalten – keine Referenz in TYPO3, wird aber laut Access-Log noch aufgerufen (Links müssen funktionieren).',
        self::STATUS_REVIEW => 'Manuell prüfen (siehe Begründung).',
        self::STATUS_UNUSED => 'Löschkandidat – zuerst in Quarantäne verschieben und Log beobachten (bin/migrated-quarantine.php).',
    ];

    /** Verwendungs-Status => Datei-Status */
    private const USAGE_TO_FILE_STATUS = [
        'aktiv' => self::STATUS_IN_USE,
        'versteckt' => self::STATUS_HIDDEN,
        'workspace' => self::STATUS_HIDDEN,
        'sysordner' => self::STATUS_HIDDEN,
        'ausserhalb_site' => self::STATUS_HIDDEN,
        'papierkorb' => self::STATUS_TRASH,
        'unbekannt' => self::STATUS_REVIEW,
        'verwaist' => self::STATUS_UNUSED,
    ];

    private const USAGE_LABEL = [
        'aktiv' => 'aktiv', 'versteckt' => 'versteckt', 'workspace' => 'Workspace', 'sysordner' => 'Ordner',
        'ausserhalb_site' => 'ohne Site', 'papierkorb' => 'Papierkorb', 'unbekannt' => 'unbekannt', 'verwaist' => 'verwaist',
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
    /** @var array<string, int> Host => rootPageId */
    private array $hostToSite = [];
    /** @var array<int, array<int, array>> l10n_parent => [Sprache => Seitenzeile] */
    private array $translations = [];
    /** @var array<int, array<int, array<string, int>>> rootPageId => Sprache => Slug => Seiten-UID */
    private array $slugMap = [];
    /** @var array<int, string> */
    private array $users = [];
    private array $recordCache = [];
    private array $pageCache = [];
    private array $whoCache = [];
    /** @var list<array> Treffer auf ganze Ordner */
    private array $folderHits = [];
    /** @var array<string, array> Verweise auf nicht vorhandene _migrated-Dateien */
    private array $brokenRefs = [];
    /** @var array<int, array{name:string, base:?string}> sys_file_storage.uid => Name, lokaler Basispfad (null = nicht prüfbar) */
    private array $storages = [];
    /** @var string[] sys_file-Einträge, deren Datei fehlt oder deren Inhalt sich geändert hat */
    private array $staleIndex = [];
    /** @var array<string, int> Log-Anfragen auf nicht vorhandene _migrated-Dateien */
    private array $logMissing = [];
    /** @var string[] */
    private array $warnings = [];
    private ?AccessLog $accessLog = null;

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
        foreach ($this->sites as $root => $site) {
            foreach ($site['hosts'] as $host) {
                $this->hostToSite[$host] = $root;
            }
        }
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

        $this->step('Ordne Seiten, Sites und Domains zu, klassifiziere');
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
            if ((int)($row['sys_language_uid'] ?? 0) > 0 && !empty($row['l10n_parent'])
                && empty($row['deleted']) && (int)($row['t3ver_wsid'] ?? 0) === 0) {
                $this->translations[(int)$row['l10n_parent']][(int)$row['sys_language_uid']] = $row;
            }
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
            'log' => ['hits' => 0, 'bots' => 0, 'last' => null, 'domains' => [], 'intern' => [], 'extern' => [], 'intern_pages' => []],
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
        // Duplikate innerhalb von _migrated (nur Dateien, die wirklich auf der Platte liegen)
        foreach ($bySha as $keys) {
            $keys = array_values(array_filter($keys, fn ($k) => $this->files[$k]['on_disk']));
            foreach ($keys as $key) {
                foreach ($keys as $other) {
                    if ($other !== $key) {
                        $this->files[$key]['duplicates'][] = 'fileadmin' . $other;
                    }
                }
            }
        }
        // Duplikate außerhalb von _migrated (alle Storages) – sys_file kann veraltet sein,
        // daher wird jede Fundstelle auf der Platte geprüft und der SHA1 nachgerechnet
        $this->loadStorages();
        $missingCond = $this->db->has('sys_file', 'missing') ? 'AND missing = 0' : '';
        foreach (array_chunk(array_keys($bySha), 500) as $chunk) {
            $rows = $this->db->all(
                'SELECT uid, storage, identifier, sha1 FROM sys_file WHERE sha1 IN (' . Db::placeholders($chunk) . ")
                 AND NOT (storage = ? AND identifier LIKE ?) $missingCond",
                [...$chunk, $this->storage, '/\_migrated/%']
            );
            foreach ($rows as $row) {
                $storage = $this->storages[(int)$row['storage']] ?? ['name' => 'Storage ' . $row['storage'], 'base' => null];
                if ($storage['base'] === null) {
                    $label = $storage['name'] . ' (uid ' . $row['storage'] . '): ' . $row['identifier'] . ' (nicht prüfbar)';
                } else {
                    $path = $storage['base'] . $row['identifier'];
                    $index = 'sys_file uid ' . $row['uid'] . ': ' . $this->displayPath($path);
                    if (!is_file($path)) {
                        $this->staleIndex[] = $index . ' – Datei existiert nicht';
                        continue;
                    }
                    if (filesize($path) < 200 * 1024 * 1024 && sha1_file($path) !== $row['sha1']) {
                        $this->staleIndex[] = $index . ' – Inhalt hat sich geändert';
                        continue;
                    }
                    $label = $this->displayPath($path);
                }
                foreach ($bySha[$row['sha1']] as $key) {
                    if ($this->files[$key]['on_disk']) {
                        $this->files[$key]['duplicates'][] = $label;
                    }
                }
            }
        }
        if ($this->staleIndex !== []) {
            $this->warnings[] = count($this->staleIndex) . ' Einträge im Dateiindex (sys_file) zeigen auf fehlende oder geänderte Dateien. '
                . 'Abhilfe: Scheduler-Task "Dateiindex aktualisieren" (File Abstraction Layer: Update storage index) ausführen.';
        }
    }

    private function loadStorages(): void
    {
        if (!$this->db->tableExists('sys_file_storage')) {
            return;
        }
        foreach ($this->db->all('SELECT * FROM sys_file_storage') as $row) {
            $config = (string)($row['configuration'] ?? '');
            $field = static fn (string $name): string => preg_match(
                '~<field index="' . $name . '">\s*<value index="vDEF">([^<]*)</value>~', $config, $m) ? trim(html_entity_decode($m[1])) : '';
            $base = null;
            if (($row['driver'] ?? 'Local') === 'Local') {
                $basePath = $field('basePath');
                $base = $field('pathType') === 'absolute'
                    ? rtrim($basePath, '/')
                    : $this->env->publicDir . '/' . trim($basePath, '/');
            }
            $this->storages[(int)$row['uid']] = ['name' => (string)($row['name'] ?? ''), 'base' => $base];
        }
    }

    /** Pfad relativ zum Web-Root anzeigen (z. B. fileadmin/...), sonst absolut */
    private function displayPath(string $path): string
    {
        return str_starts_with($path, $this->env->publicDir . '/') ? substr($path, strlen($this->env->publicDir) + 1) : $path;
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
        $this->accessLog = new AccessLog($this->accessLogs);
        $this->buildSlugMap();
        foreach ($this->accessLog->entries('/fileadmin/_migrated/') as $e) {
            $identifier = substr($e['path'], strpos($e['path'], '/fileadmin/_migrated/') + strlen('/fileadmin'));
            if (!isset($this->files[$identifier])) {
                $folder = explode('/', $identifier)[2] ?? '';
                if (in_array($folder, $this->folders, true)) {
                    $this->logMissing[$identifier] = ($this->logMissing[$identifier] ?? 0) + 1;
                }
                continue;
            }
            if (!in_array($e['status'], [200, 206, 304], true)) {
                continue;
            }
            $log = &$this->files[$identifier]['log'];
            $ref = $e['referer'] !== '' ? $this->classifyReferer($e['referer']) : null;
            // Domain, unter der die Datei abgerufen wurde: vhost/Logdateiname, sonst Host eines internen Referers
            $domain = $e['host'] !== '' ? $e['host'] : ($ref !== null && $ref['internal'] ? $ref['host'] : '');
            if ($domain !== '') {
                $log['domains'][$domain] = ($log['domains'][$domain] ?? 0) + 1;
            }
            if ($e['bot']) {
                $log['bots']++;
            } else {
                $log['hits']++;
                if ($ref !== null) {
                    $bucket = $ref['internal'] ? 'intern' : 'extern';
                    $log[$bucket][$ref['label']] = ($log[$bucket][$ref['label']] ?? 0) + 1;
                    if ($ref['page'] !== null) {
                        $log['intern_pages'][$ref['page']] = true;
                    }
                }
            }
            if ($e['ts'] !== null && ($log['last'] === null || $e['ts'] > $log['last'])) {
                $log['last'] = $e['ts'];
            }
            unset($log);
        }
        array_push($this->warnings, ...$this->accessLog->warnings);
    }

    /** Slug-Index je Site und Sprache, um Referer-URLs TYPO3-Seiten zuzuordnen */
    private function buildSlugMap(): void
    {
        foreach ($this->pages as $uid => $p) {
            if (!empty($p['deleted']) || (int)($p['t3ver_wsid'] ?? 0) > 0 || !isset($p['slug'])) {
                continue;
            }
            $lang = (int)($p['sys_language_uid'] ?? 0);
            $defaultUid = $lang > 0 && !empty($p['l10n_parent']) ? (int)$p['l10n_parent'] : $uid;
            $info = $this->pageInfo($defaultUid);
            if ($info['root'] > 0) {
                $this->slugMap[$info['root']][$lang][rtrim((string)$p['slug'], '/') ?: '/'] = $defaultUid;
            }
        }
    }

    /**
     * Ordnet einen Referer einer Site und (wenn möglich) einer TYPO3-Seite zu.
     * @return array{internal:bool, host:string, page:?int, label:string}
     */
    private function classifyReferer(string $referer): array
    {
        $host = Typo3Env::host($referer);
        if ($host === '' || !isset($this->hostToSite[$host])) {
            return ['internal' => false, 'host' => $host, 'page' => null, 'label' => $host !== '' ? $host : $referer];
        }
        $root = $this->hostToSite[$host];
        $path = rawurldecode((string)(parse_url($referer, PHP_URL_PATH) ?? '/'));
        // passende Sprache = längstes Basis-Pfad-Präfix auf diesem Host
        $languages = $this->sites[$root]['languages'] ?: [0 => $this->sites[$root]['base']];
        $bestLang = 0;
        $bestPrefix = '';
        foreach ($languages as $lang => $base) {
            if (Typo3Env::host($base) !== '' && Typo3Env::host($base) !== $host) {
                continue;
            }
            $prefix = rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/');
            if (($path === $prefix || str_starts_with($path, $prefix . '/')) && strlen($prefix) >= strlen($bestPrefix)) {
                $bestLang = (int)$lang;
                $bestPrefix = $prefix;
            }
        }
        $slug = rtrim(substr($path, strlen($bestPrefix)), '/') ?: '/';
        $page = $this->slugMap[$root][$bestLang][$slug]
            ?? $this->slugMap[$root][$bestLang][preg_replace('/\.html?$/', '', $slug)]
            ?? null;
        if ($page === null && preg_match('/(?:^|&)id=(\d+)/', (string)parse_url($referer, PHP_URL_QUERY), $m) && isset($this->pages[(int)$m[1]])) {
            $page = (int)$m[1];
        }
        $label = $host . $path . ' → ' . ($page !== null ? "Seite $page \"" . $this->pages[$page]['title'] . '"' : 'keine TYPO3-Seite gefunden');
        return ['internal' => true, 'host' => $host, 'page' => $page, 'label' => $label];
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
            'orphan' => false, 'title' => '', 'path' => '', 'site' => '', 'root' => 0, 'in_site' => false, 'slug' => ''];
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
        $info['slug'] = (string)($page['slug'] ?? '');
        $info['orphan'] = $u > 0; // Elternseite fehlt => verwaister Teilbaum
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
                $info['root'] = (int)$p['uid'];
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
        return $this->pageCache[$pageUid] = $info;
    }

    /** Basis-URL einer Site für eine Sprache (Sprache -1 = alle => Standardsprache) */
    private function languageBase(int $root, int $lang): string
    {
        $site = $this->sites[$root] ?? null;
        if ($site === null) {
            return '';
        }
        return $site['languages'][max(0, $lang)] ?? $site['languages'][0] ?? $site['base'];
    }

    /** Domain, unter der eine Seite in einer Sprache erreichbar ist */
    private function domainFor(array $page, int $lang): string
    {
        if ($page['root'] === 0) {
            return $page['site'] !== '' ? $page['site'] : '(keine Site)';
        }
        $host = Typo3Env::host($this->languageBase($page['root'], $lang));
        return $host !== '' ? $host : $page['site'];
    }

    private function urlFor(int $pageUid, array $page, int $lang): string
    {
        if ($page['root'] === 0 || !in_array($page['doktype'], [1, 3, 4, 7], true)) {
            return '';
        }
        $slug = $lang > 0 && isset($this->translations[$pageUid][$lang]['slug'])
            ? (string)$this->translations[$pageUid][$lang]['slug']
            : $page['slug'];
        $base = rtrim($this->languageBase($page['root'], $lang), '/');
        return $slug !== '' ? $base . $slug : $base . '/?id=' . $pageUid;
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
        $rec = $this->resolveRecord($u['table'], $u['uid']);
        if (!$rec['exists']) {
            return ['verwaist', $rec['reason']];
        }
        $page = $rec['page'] > 0 ? $this->pageInfo($rec['page']) : null;
        if ($page !== null && (!$page['exists'] || $page['orphan'])) {
            return ['verwaist', 'Seite existiert nicht mehr (verwaister Datensatz)'];
        }
        if ($u['ref_deleted']) {
            return ['papierkorb', 'Dateireferenz gelöscht (Papierkorb)'];
        }
        if ($rec['deleted']) {
            return ['papierkorb', 'Datensatz gelöscht (Papierkorb)'];
        }
        if ($page !== null && $page['deleted']) {
            return ['papierkorb', 'Seite (oder übergeordnete Seite) gelöscht (Papierkorb)'];
        }
        if ($u['ref_ws'] > 0 || $rec['ws'] > 0) {
            return ['workspace', 'Nur in einer Workspace-Version'];
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
        $priority = array_flip(self::STATUSES);
        foreach ($this->files as &$entry) {
            $counts = array_fill_keys(array_keys(self::USAGE_LABEL), 0) + ['text' => 0];
            $status = self::STATUS_UNUSED;
            foreach ($entry['usages'] as &$u) {
                [$u['status'], $u['status_reason']] = $this->usageStatus($u);
                $counts[$u['status']]++;
                if (isset($u['sources']['textfeld']) || isset($u['sources']['dateisystem'])) {
                    $counts['text']++;
                }
                $candidate = self::USAGE_TO_FILE_STATUS[$u['status']];
                if ($priority[$candidate] < $priority[$status]) {
                    $status = $candidate;
                }
            }
            unset($u);
            $entry['counts'] = $counts;

            $reasons = [];
            foreach ($counts as $usageStatus => $n) {
                if ($n > 0 && $usageStatus !== 'text') {
                    $reasons[] = $n . 'x ' . self::USAGE_LABEL[$usageStatus];
                }
            }
            if ($status === self::STATUS_HIDDEN || $status === self::STATUS_REVIEW) {
                $kinds = array_unique(array_map(static fn ($u) => $u['status_reason'],
                    array_filter($entry['usages'], static fn ($u) => self::USAGE_TO_FILE_STATUS[$u['status']] === $status)));
                $reasons[] = implode('; ', $kinds);
            }
            $log = $entry['log'];
            if ($status === self::STATUS_UNUSED) {
                if ($log['hits'] > 0) {
                    $status = self::STATUS_REQUESTED;
                } elseif ($log['bots'] > 0) {
                    $status = self::STATUS_REVIEW;
                    $reasons[] = 'nur von Bots abgerufen (' . $log['bots'] . 'x) – URL ist vermutlich in Suchmaschinen indexiert';
                } elseif (isset($folderReferenced[$entry['folder']])) {
                    $status = self::STATUS_REVIEW;
                    $reasons[] = 'der ganze Ordner wird referenziert (siehe Zusammenfassung)';
                } elseif ($entry['usages'] === []) {
                    $reasons[] = 'keine Verwendung gefunden';
                }
            }
            if ($log['hits'] > 0) {
                $reasons[] = 'Access-Log: ' . $log['hits'] . ' Abrufe (' . count($log['extern']) . ' externe, '
                    . count($log['intern']) . ' interne Referer-Seiten)';
            }
            if ($log['intern_pages'] !== [] && $counts['aktiv'] === 0) {
                $reasons[] = 'ACHTUNG: wird von TYPO3-Seite(n) ' . implode(', ', array_keys($log['intern_pages']))
                    . ' verlinkt, aber keine aktive Referenz gefunden (gecachte Seite oder dynamisch erzeugter Link?)';
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
            $entry['reason'] = implode('; ', array_filter($reasons));
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
        $topList = static function (array $counts, int $max): string {
            arsort($counts);
            $items = array_map(static fn ($k, $v) => "$k ($v)", array_keys($counts), $counts);
            return implode(' | ', array_slice($items, 0, $max)) . (count($items) > $max ? ' | … (+' . (count($items) - $max) . ')' : '');
        };

        $filesCsv = $this->openCsv("$outDir/dateien.csv", [
            'pfad', 'ordner', 'status', 'empfehlung', 'begruendung', 'domains', 'seiten', 'auf_disk', 'groesse_bytes', 'groesse',
            'datei_geaendert', 'sys_file_uid', 'verwendungen_aktiv', 'verwendungen_versteckt', 'verwendungen_papierkorb',
            'verwendungen_verwaist', 'davon_text_code_funde', 'duplikate', 'log_abrufe', 'log_abrufe_bots', 'log_letzter_abruf',
            'log_domains', 'log_referer_typo3_seiten', 'log_referer_extern', 'datei_aktionen_sys_log',
        ]);
        $usageCsv = $this->openCsv("$outDir/verwendungen.csv", [
            'pfad', 'sys_file_uid', 'datei_status', 'quelle', 'tabelle', 'feld', 'datensatz_uid', 'datensatz_titel',
            'ctype', 'sprache', 'verwendung_status', 'grund', 'referenz_uid', 'domain', 'site', 'url', 'seite_uid', 'seite_titel',
            'seitenpfad', 'seitentyp', 'datensatz_erstellt', 'datensatz_geaendert', 'erstellt_von', 'zuletzt_geaendert_von', 'fundstelle',
        ]);
        $domainCsv = $this->openCsv("$outDir/domain_dateien.csv", ['domain', 'pfad', 'verwendung', 'datei_status', 'seiten', 'groesse_bytes']);

        $stats = [];
        $domainStats = [];
        $missingButUsed = [];
        $internalNoRef = [];
        $deleteList = [];
        $usageRank = array_flip(array_keys(self::USAGE_LABEL));

        foreach ($this->files as $entry) {
            $domains = [];     // Domain => bester Verwendungs-Status
            $domainPages = []; // Domain => [Seiten]
            $pages = [];
            foreach ($entry['usages'] as $u) {
                $rec = $u['table'] !== null && $u['uid'] !== null ? $this->resolveRecord($u['table'], $u['uid']) : ['exists' => false];
                $page = !empty($rec['exists']) && $rec['page'] > 0 ? $this->pageInfo($rec['page']) : null;
                $lang = (int)($rec['lang'] ?? 0);
                $domain = $page !== null && $page['exists'] ? $this->domainFor($page, $lang) : ($u['table'] === null ? '(Code/Konfiguration)' : '');
                $who = !empty($rec['exists']) ? $this->who($u['table'], $u['uid']) : ['creator' => null, 'last' => null];
                $creator = $who['creator'] ? $this->userLabel($who['creator'][2]) : (!empty($rec['cruser']) ? $this->userLabel($rec['cruser']) : '');
                $lastEditor = $who['last'] ? $this->userLabel($who['last'][2]) . ' (' . date('Y-m-d', $who['last'][0]) . ')' : '';

                if ($u['status'] !== 'verwaist' && $domain !== '') {
                    if (!isset($domains[$domain]) || $usageRank[$u['status']] < $usageRank[$domains[$domain]]) {
                        $domains[$domain] = $u['status'];
                    }
                    if ($page !== null) {
                        $pages[$rec['page']] = $rec['page'] . ':' . $page['title'];
                        $domainPages[$domain][$rec['page']] = $rec['page'] . ':' . $page['title'];
                    }
                }

                $this->writeCsv($usageCsv, [
                    $entry['path'], $entry['uid'], $entry['status'], implode('+', array_keys($u['sources'])),
                    $u['table'], $u['field'], $u['uid'], $rec['label'] ?? '', $rec['ctype'] ?? '', $rec['lang'] ?? '',
                    self::USAGE_LABEL[$u['status']], $u['status_reason'], $u['ref_uid'], $domain, $page['site'] ?? '',
                    $page !== null && $page['exists'] ? $this->urlFor($rec['page'], $page, $lang) : '',
                    $page !== null ? $rec['page'] : '', $page['title'] ?? '', $page['path'] ?? '',
                    $page !== null ? $this->doktypeLabel($page['doktype']) : '', $fmtDate($rec['crdate'] ?? null),
                    $fmtDate($rec['tstamp'] ?? null), $creator, $lastEditor, $u['location'],
                ]);
            }

            // Domains aus dem Access-Log, falls TYPO3 die Datei nicht (mehr) kennt
            $usageGroup = static fn (string $usage): string => match ($usage) {
                'aktiv' => 'aktiv',
                'papierkorb' => 'Papierkorb',
                'unbekannt' => 'unbekannt',
                default => 'versteckt',
            };
            $domainRows = [];
            foreach ($domains as $domain => $usage) {
                $domainRows[$domain] = $usageGroup($usage);
            }
            foreach (array_keys($entry['log']['domains']) as $domain) {
                $domainRows[$domain] ??= 'nur Log-Abrufe';
            }
            ksort($domainRows);
            foreach ($domainRows as $domain => $group) {
                $domainStats[$domain][$group] = ($domainStats[$domain][$group] ?? 0) + 1;
                $this->writeCsv($domainCsv, [$domain, $entry['path'], $group, $entry['status'],
                    $this->limitList(array_values($domainPages[$domain] ?? []), 10), $entry['size']]);
            }

            $log = $entry['log'];
            $versteckt = $entry['counts']['versteckt'] + $entry['counts']['workspace'] + $entry['counts']['sysordner'] + $entry['counts']['ausserhalb_site'];
            $this->writeCsv($filesCsv, [
                $entry['path'], $entry['folder'], $entry['status'], self::RECOMMENDATION[$entry['status']], $entry['reason'],
                implode(', ', array_map(static fn ($d, $g) => "$d ($g)", array_keys($domainRows), $domainRows)),
                $this->limitList(array_values($pages), 10), $entry['on_disk'] ? 'ja' : 'nein', $entry['size'],
                $this->humanSize($entry['size']), $fmtDate($entry['mtime']), $entry['uid'], $entry['counts']['aktiv'], $versteckt,
                $entry['counts']['papierkorb'], $entry['counts']['verwaist'], $entry['counts']['text'],
                $this->limitList(array_values(array_unique($entry['duplicates'])), 5),
                $log['hits'], $log['bots'], $fmtDate($log['last']), $topList($log['domains'], 5),
                $topList($log['intern'], 5), $topList($log['extern'], 5), $this->limitList($entry['file_log'], 5),
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
            if ($log['intern_pages'] !== [] && $entry['counts']['aktiv'] === 0) {
                $internalNoRef[] = $entry['path'] . ' ← ' . implode(' | ', array_keys($log['intern']));
            }
            if ($entry['on_disk'] && $entry['status'] === self::STATUS_UNUSED) {
                $deleteList[] = $entry['identifier'];
            }
        }
        fclose($filesCsv);
        fclose($usageCsv);
        fclose($domainCsv);

        file_put_contents("$outDir/loeschkandidaten.txt", $this->listFile($deleteList, self::STATUS_UNUSED));
        file_put_contents("$outDir/zusammenfassung.md", $this->summary($stats, $domainStats, $missingButUsed, $internalNoRef));
    }

    private function summary(array $stats, array $domainStats, array $missingButUsed, array $internalNoRef): string
    {
        $statuses = self::STATUSES;
        $md = "# Analyse fileadmin/_migrated\n\n";
        $md .= '- Erstellt: ' . date('Y-m-d H:i') . "\n";
        $md .= '- TYPO3-Projekt: `' . $this->env->root . '` (Version ' . $this->env->typo3Version() . ")\n";
        $md .= '- Datenbank: `' . $this->db->name . '`, fileadmin-Storage-UID: ' . $this->storage . "\n";
        $md .= '- Sites: ' . ($this->sites === [] ? '(keine gefunden)' : implode(', ', array_map(
            static fn ($s) => $s['identifier'] . ' → ' . (implode(', ', $s['hosts']) ?: $s['base'] ?: '?') . ' (Root ' . $s['rootPageId'] . ')', $this->sites))) . "\n";
        $md .= '- Prüfungen: sys_file_reference, sys_refindex' . ($this->textScan ? ', DB-Textfelder' : '')
            . ', sys_log' . ($this->fsScan ? ', Dateisystem (TypoScript/Templates/Config)' : '')
            . ($this->accessLogs !== [] ? ', Access-Logs' : '') . "\n";
        if ($this->accessLog !== null && $this->accessLog->files > 0) {
            $days = $this->accessLog->from && $this->accessLog->to ? (int)ceil(($this->accessLog->to - $this->accessLog->from) / 86400) : 0;
            $md .= sprintf("- Access-Logs: %d Dateien, %d Zeilen, Zeitraum %s bis %s (%d Tage)\n", $this->accessLog->files, $this->accessLog->lines,
                $this->accessLog->from ? date('Y-m-d', $this->accessLog->from) : '?', $this->accessLog->to ? date('Y-m-d', $this->accessLog->to) : '?', $days);
            if ($days < 60) {
                $this->warnings[] = "Die Access-Logs decken nur $days Tage ab. Selten genutzte Links (z. B. PDFs in alten E-Mails) "
                    . 'fallen dadurch evtl. nicht auf – Logs länger aufbewahren oder die Quarantäne-Frist entsprechend lang wählen.';
            }
        } else {
            $this->warnings[] = 'Keine Access-Logs ausgewertet – UNBENUTZT bedeutet nur "in TYPO3 nicht referenziert". '
                . 'Externe Links werden erst mit --access-log erkannt.';
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

        if ($domainStats !== []) {
            $groups = ['aktiv', 'versteckt', 'Papierkorb', 'nur Log-Abrufe', 'unbekannt'];
            $md .= "\n## Dateien je Domain\n\nEine Datei kann mehreren Domains zugeordnet sein (Details: `domain_dateien.csv`).\n\n"
                . '| Domain | ' . implode(' | ', $groups) . " |\n|---|" . str_repeat('---:|', count($groups)) . "\n";
            ksort($domainStats);
            foreach ($domainStats as $domain => $counts) {
                $md .= "| $domain | " . implode(' | ', array_map(static fn ($g) => $counts[$g] ?? 0, $groups)) . " |\n";
            }
        }
        if ($internalNoRef !== []) {
            $md .= "\n## Von TYPO3-Seiten verlinkt, aber keine aktive Referenz gefunden\n\n"
                . "Laut Referer im Access-Log werden diese Dateien von Seiten der eigenen Domains aufgerufen, in der Datenbank "
                . "wurde aber keine aktive Einbindung gefunden (z. B. veralteter Seiten-Cache, dynamisch erzeugter Link, Extension).\n\n"
                . $this->mdList($internalNoRef, 50);
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
        if ($this->staleIndex !== []) {
            $md .= "\n## Veraltete Einträge im Dateiindex (keine echten Duplikate)\n\n"
                . "Diese sys_file-Einträge haben denselben SHA1 wie eine _migrated-Datei, die Datei existiert aber nicht (mehr) "
                . "bzw. hat einen anderen Inhalt – typisch nach Löschen/Umbenennen per FTP/Shell. Sie werden nicht als Duplikat gezählt.\n\n"
                . $this->mdList($this->staleIndex, 50);
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
            . "- `dateien.csv` – eine Zeile pro Datei mit Status, Domains, Seiten und Access-Log-Auswertung\n"
            . "- `domain_dateien.csv` – eine Zeile pro Datei und Domain (zum Filtern je Domain)\n"
            . "- `verwendungen.csv` – eine Zeile pro Fundstelle (Datensatz, Seite, Domain, URL)\n"
            . "- `loeschkandidaten.txt` – nur Status UNBENUTZT, Eingabe für `bin/migrated-quarantine.php`\n";
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
