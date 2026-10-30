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
     * @return array<int, array{identifier:string, rootPageId:int, base:string}> indiziert nach rootPageId
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
            $sites[$rootPageId] = [
                'identifier' => basename(dirname($file)),
                'rootPageId' => $rootPageId,
                'base' => (string)($data['base'] ?? ''),
            ];
        }
        return $sites;
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
