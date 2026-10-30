<?php

declare(strict_types=1);

namespace Typo3Tools;

/**
 * Erkennt die Verzeichnisstruktur einer TYPO3-Installation (Composer- oder
 * Classic-Mode), liest die DB-Zugangsdaten aus settings.php/additional.php
 * und die Site-Konfigurationen aus config/sites.
 *
 * Es wird KEIN TYPO3-Bootstrap ausgeführt – die Skripte laufen unabhängig
 * vom TYPO3-Core und ändern nichts an der Installation.
 */
final class Typo3Env
{
    public string $root;
    public string $publicDir;
    public string $fileadmin;
    public ?string $settingsFile = null;
    public ?string $sitesDir = null;
    /** @var string[] */
    public array $warnings = [];
    private bool $autoloadTried = false;

    public function __construct(string $root, ?string $publicDir = null)
    {
        $root = rtrim((string)(realpath($root) ?: $root), '/');
        if (!is_dir($root)) {
            throw new \RuntimeException("TYPO3-Verzeichnis nicht gefunden: $root");
        }
        // --root zeigt auf public/ statt auf das Projektverzeichnis
        if ($publicDir === null
            && is_dir($root . '/fileadmin')
            && !is_file($root . '/config/system/settings.php')
            && is_file(dirname($root) . '/config/system/settings.php')
        ) {
            $publicDir = $root;
            $root = dirname($root);
        }
        if ($publicDir === null) {
            foreach ([$root . '/public', $root . '/web', $root] as $candidate) {
                if (is_dir($candidate . '/fileadmin')) {
                    $publicDir = $candidate;
                    break;
                }
            }
        }
        if ($publicDir === null || !is_dir($publicDir . '/fileadmin')) {
            throw new \RuntimeException("Kein fileadmin unter $root gefunden. Bitte --root bzw. --public angeben.");
        }
        $this->root = $root;
        $this->publicDir = rtrim((string)(realpath($publicDir) ?: $publicDir), '/');
        $this->fileadmin = $this->publicDir . '/fileadmin';

        foreach ([
            $root . '/config/system/settings.php',
            $this->publicDir . '/typo3conf/system/settings.php',
            $this->publicDir . '/typo3conf/LocalConfiguration.php',
        ] as $candidate) {
            if (is_file($candidate)) {
                $this->settingsFile = $candidate;
                break;
            }
        }
        foreach ([$root . '/config/sites', $this->publicDir . '/typo3conf/sites'] as $candidate) {
            if (is_dir($candidate)) {
                $this->sitesDir = $candidate;
                break;
            }
        }
    }

    public function autoload(): bool
    {
        if (!$this->autoloadTried) {
            $this->autoloadTried = true;
            foreach ([$this->root . '/vendor/autoload.php', $this->publicDir . '/vendor/autoload.php'] as $file) {
                if (is_file($file)) {
                    try {
                        require_once $file;
                    } catch (\Throwable $e) {
                        $this->warnings[] = 'Autoloader konnte nicht geladen werden: ' . $e->getMessage();
                    }
                    break;
                }
            }
        }
        return class_exists(\Symfony\Component\Yaml\Yaml::class);
    }

    /**
     * Liefert $GLOBALS['TYPO3_CONF_VARS'] nach settings.php + additional.php.
     */
    public function configuration(): array
    {
        if ($this->settingsFile === null) {
            $this->warnings[] = 'Keine settings.php gefunden – DB-Zugang muss per --db-* angegeben werden.';
            return [];
        }
        $conf = require $this->settingsFile;
        $GLOBALS['TYPO3_CONF_VARS'] = is_array($conf) ? $conf : [];

        $dir = dirname($this->settingsFile);
        foreach ([$dir . '/additional.php', $dir . '/AdditionalConfiguration.php'] as $additional) {
            if (!is_file($additional)) {
                continue;
            }
            $this->autoload();
            ob_start();
            try {
                (static function (string $file): void {
                    require $file;
                })($additional);
            } catch (\Throwable $e) {
                $this->warnings[] = basename($additional) . ' konnte nicht vollständig ausgewertet werden ('
                    . $e->getMessage() . '). Falls die DB-Verbindung fehlschlägt: --db-* Optionen verwenden.';
            } finally {
                ob_end_clean();
            }
            break;
        }
        return is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
    }

    /**
     * @param array{host?:string,port?:string,name?:string,user?:string,password?:string,socket?:string} $overrides
     */
    public function connect(array $overrides = []): Db
    {
        $c = $this->configuration()['DB']['Connections']['Default'] ?? [];
        $driver = (string)($c['driver'] ?? 'mysqli');
        if (str_contains($driver, 'pgsql') || str_contains($driver, 'sqlite')) {
            throw new \RuntimeException("DB-Treiber '$driver' wird nicht unterstützt (nur MySQL/MariaDB).");
        }
        $host = preg_replace('/^p:/', '', (string)($overrides['host'] ?? $c['host'] ?? 'localhost'));
        $port = (int)($overrides['port'] ?? $c['port'] ?? 3306);
        $socket = (string)($overrides['socket'] ?? $c['unix_socket'] ?? '');
        $name = (string)($overrides['name'] ?? $c['dbname'] ?? '');
        $user = (string)($overrides['user'] ?? $c['user'] ?? '');
        $password = (string)($overrides['password'] ?? $c['password'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Kein Datenbankname bekannt – bitte --db-name angeben.');
        }
        $dsn = $socket !== ''
            ? "mysql:unix_socket=$socket;dbname=$name;charset=utf8mb4"
            : "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        // Sicherheitsnetz: diese Skripte schreiben nie in die Datenbank
        try {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } catch (\Throwable) {
        }
        return new Db($pdo, $name);
    }

    /**
     * @return array<int, array{identifier:string, rootPageId:int, base:string, languages:array<int,string>, hosts:string[]}>
     *         indiziert nach rootPageId; languages = languageId => absolute Basis-URL
     */
    public function sites(): array
    {
        $sites = [];
        if ($this->sitesDir === null) {
            $this->warnings[] = 'Kein Verzeichnis config/sites gefunden – Domains können nicht zugeordnet werden.';
            return $sites;
        }
        foreach (glob($this->sitesDir . '/*/config.yaml') ?: [] as $file) {
            $data = $this->parseYaml($file);
            $rootPageId = (int)($data['rootPageId'] ?? 0);
            if ($rootPageId <= 0) {
                continue;
            }
            $base = (string)($data['base'] ?? '');
            $languages = [];
            $bases = [$base];
            foreach ($data['languages'] ?? [] as $language) {
                if (is_array($language) && isset($language['languageId'])) {
                    $languages[(int)$language['languageId']] = self::absoluteBase($base, (string)($language['base'] ?? '/'));
                    $bases[] = $languages[(int)$language['languageId']];
                }
            }
            foreach ($data['baseVariants'] ?? [] as $variant) {
                if (is_array($variant) && !empty($variant['base'])) {
                    $bases[] = (string)$variant['base'];
                }
            }
            $hosts = [];
            foreach ($bases as $b) {
                $host = self::host($b);
                if ($host !== '') {
                    $hosts[$host] = true;
                }
            }
            $sites[$rootPageId] = [
                'identifier' => basename(dirname($file)),
                'rootPageId' => $rootPageId,
                'base' => $base,
                'languages' => $languages,
                'hosts' => array_keys($hosts),
            ];
        }
        return $sites;
    }

    /** Sprach-Basis ("/en/" oder "https://example.com/") relativ zur Site-Basis auflösen */
    public static function absoluteBase(string $siteBase, string $languageBase): string
    {
        if (preg_match('~^(https?:)?//~i', $languageBase)) {
            return $languageBase;
        }
        if (preg_match('~^(https?:)?//~i', $siteBase)) {
            return rtrim($siteBase, '/') . '/' . ltrim($languageBase, '/');
        }
        return $languageBase;
    }

    public static function host(string $url): string
    {
        return strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    }

    public function typo3Version(): string
    {
        $lock = $this->root . '/composer.lock';
        if (is_file($lock)) {
            $data = json_decode((string)file_get_contents($lock), true);
            foreach ($data['packages'] ?? [] as $package) {
                if (($package['name'] ?? '') === 'typo3/cms-core') {
                    return (string)$package['version'];
                }
            }
        }
        foreach ([$this->publicDir . '/typo3/sysext/core/ext_emconf.php', $this->root . '/vendor/typo3/cms-core/ext_emconf.php'] as $file) {
            if (is_file($file) && preg_match("/'version'\s*=>\s*'([^']+)'/", (string)file_get_contents($file), $m)) {
                return $m[1];
            }
        }
        return 'unbekannt';
    }

    private function parseYaml(string $file): array
    {
        if ($this->autoload()) {
            try {
                $data = \Symfony\Component\Yaml\Yaml::parseFile($file);
                if (is_array($data)) {
                    return $data;
                }
            } catch (\Throwable) {
            }
        }
        // Fallback ohne Symfony YAML: nur die benötigten Top-Level-Schlüssel
        $data = [];
        $content = (string)file_get_contents($file);
        if (preg_match('/^rootPageId:\s*[\'"]?(\d+)/m', $content, $m)) {
            $data['rootPageId'] = (int)$m[1];
        }
        if (preg_match('/^base:\s*[\'"]?([^\'"\r\n]+)/m', $content, $m)) {
            $data['base'] = trim($m[1]);
        }
        if (preg_match('/^languages:\s*$(.*?)(?=^\S|\z)/ms', $content, $m)) {
            foreach (preg_split('/^\s*-\s+/m', $m[1]) ?: [] as $chunk) {
                if (preg_match('/(?:^|\s)languageId:\s*[\'"]?(\d+)/', $chunk, $l)) {
                    preg_match('/(?:^|\s)base:\s*[\'"]?([^\'"\r\n]+)/', $chunk, $b);
                    $data['languages'][] = ['languageId' => (int)$l[1], 'base' => trim($b[1] ?? '/')];
                }
            }
        }
        return $data;
    }
}

/**
 * Kleiner PDO-Wrapper mit Spalten-Cache, damit die Skripte mit
 * unterschiedlichen Schema-Ständen (Extensions, alte Spalten) umgehen können.
 */
final class Db
{
    /** @var array<string, array<string, true>> */
    private array $columns = [];

    public function __construct(public readonly \PDO $pdo, public readonly string $name)
    {
    }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, true> */
    public function columns(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $this->columns[$table] = [];
            try {
                foreach ($this->pdo->query('SHOW COLUMNS FROM ' . self::q($table))->fetchAll() as $row) {
                    $this->columns[$table][$row['Field']] = true;
                }
            } catch (\PDOException) {
                // Tabelle existiert nicht
            }
        }
        return $this->columns[$table];
    }

    public function tableExists(string $table): bool
    {
        return $this->columns($table) !== [];
    }

    public function has(string $table, string $column): bool
    {
        return isset($this->columns($table)[$column]);
    }

    public static function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}

/**
 * Liest Apache/nginx-Access-Logs im (vhost_)combined-Format, auch .gz.
 */
final class AccessLog
{
    public const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python|java\/|monitor|uptime|scan/i';

    public int $files = 0;
    public int $lines = 0;
    public ?int $from = null;
    public ?int $to = null;
    /** @var string[] */
    public array $warnings = [];

    /** @param string[] $globs */
    public function __construct(private readonly array $globs)
    {
    }

    /**
     * Liefert alle Anfragen, deren Pfad $needle enthält.
     * @return \Generator<array{ts:?int, path:string, status:int, referer:string, agent:string, bot:bool, host:string}>
     */
    public function entries(string $needle): \Generator
    {
        $logFiles = [];
        foreach ($this->globs as $pattern) {
            foreach (glob($pattern) ?: [] as $f) {
                if (is_file($f)) {
                    $logFiles[$f] = true;
                }
            }
        }
        if ($logFiles === []) {
            $this->warnings[] = 'Keine Access-Logs gefunden für: ' . implode(', ', $this->globs);
            return;
        }
        foreach (array_keys($logFiles) as $logFile) {
            $h = @gzopen($logFile, 'rb'); // liest auch unkomprimierte Dateien
            if ($h === false) {
                $this->warnings[] = "Log nicht lesbar (Rechte?): $logFile";
                continue;
            }
            $this->files++;
            $fileHost = self::hostFromFilename($logFile);
            while (($line = gzgets($h)) !== false) {
                $this->lines++;
                if (!str_contains($line, $needle)) {
                    continue;
                }
                $entry = self::parse($line, $fileHost);
                if ($entry === null) {
                    continue;
                }
                if ($entry['ts'] !== null) {
                    $this->from = min($this->from ?? $entry['ts'], $entry['ts']);
                    $this->to = max($this->to ?? $entry['ts'], $entry['ts']);
                }
                if (str_contains($entry['path'], $needle)) {
                    yield $entry;
                }
            }
            gzclose($h);
        }
    }

    public static function parse(string $line, string $fileHost = ''): ?array
    {
        // optionales "host:port " (vhost_combined), dann IP, ident, user, [Datum], "Request", Status, Bytes, "Referer" "User-Agent"
        if (!preg_match('~^(?:(\S+?)(?::\d+)?\s+)?\S+ \S+ \S+ \[([^\]]+)\] "[A-Z]+ (\S+)[^"]*" (\d{3}) \S+(?: "([^"]*)" "([^"]*)")?~', $line, $m)) {
            return null;
        }
        $ts = \DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
        $agent = $m[6] ?? '';
        $host = strtolower($m[1] ?? '');
        return [
            'ts' => $ts ? $ts->getTimestamp() : null,
            'path' => rawurldecode((string)parse_url($m[3], PHP_URL_PATH)),
            'status' => (int)$m[4],
            'referer' => ($m[5] ?? '-') === '-' ? '' : $m[5],
            'agent' => $agent,
            'bot' => (bool)preg_match(self::BOT_PATTERN, $agent),
            'host' => $host !== '' ? $host : $fileHost,
        ];
    }

    /** z. B. /var/log/apache2/www.example.de-access.log.2.gz => www.example.de */
    public static function hostFromFilename(string $file): string
    {
        $name = preg_replace(['~(\.\d+)?(\.gz)?$~i', '~\.log$~i', '~[-_.]?(ssl[-_.])?access.*$~i'], '', basename($file));
        return preg_match('~^(?:[a-z0-9-]+\.)+[a-z]{2,}$~i', (string)$name) ? strtolower((string)$name) : '';
    }
}
