<?php
/**
 * Mashpad — database connection and schema migrations.
 *
 * Credentials live in data/config.php, written once by setup/ on the server.
 * They are never in the repo (it is public) and the deploy never touches data/.
 * Until setup has run, mb_db() returns null and the file-based pads work as before.
 */
declare(strict_types=1);

const MB_CONFIG_FILE     = __DIR__ . '/../data/config.php';
const MB_SCHEMA_CACHE    = __DIR__ . '/../data/schema-version';
const MB_MIGRATIONS_DIR  = __DIR__ . '/../migrations';
const MB_MIN_MARIADB     = '10.4.0';
const MB_MIN_MYSQL       = '8.0.0';

function mb_config(): ?array
{
    static $config = false;
    if ($config === false) {
        $config = is_file(MB_CONFIG_FILE) ? (include MB_CONFIG_FILE) : null;
        if (!is_array($config) || empty($config['db'])) {
            $config = null;
        }
    }
    return $config;
}

/** Open a PDO connection from explicit settings. Throws PDOException on failure. */
function mb_db_connect(array $db): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'], (int) ($db['port'] ?? 3306), $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** The shared connection, migrated to the latest schema; null until setup has run. */
function mb_db(): ?PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $config = mb_config();
        if ($config === null) {
            return null;
        }
        $pdo = mb_db_connect($config['db']);
        if (mb_schema_cached() !== mb_latest_migration()) {
            mb_migrate($pdo);
        }
    }
    return $pdo;
}

/** [ok, description] for the server behind $pdo. */
function mb_db_version_check(PDO $pdo): array
{
    $raw     = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $mariadb = stripos($raw, 'mariadb') !== false;
    preg_match('/^\d+\.\d+\.\d+/', $raw, $m);
    $version = $m[0] ?? '0.0.0';
    $min     = $mariadb ? MB_MIN_MARIADB : MB_MIN_MYSQL;
    $label   = ($mariadb ? 'MariaDB ' : 'MySQL ') . $version;
    return [version_compare($version, $min, '>='), $label, $mariadb ? 'MariaDB ' . MB_MIN_MARIADB : 'MySQL ' . MB_MIN_MYSQL];
}

/** @return array<string,string> version => file path, in order. */
function mb_migration_files(): array
{
    $files = [];
    foreach (glob(MB_MIGRATIONS_DIR . '/*.sql') ?: [] as $path) {
        $files[basename($path, '.sql')] = $path;
    }
    ksort($files, SORT_STRING);
    return $files;
}

function mb_latest_migration(): string
{
    $files = mb_migration_files();
    return $files ? (string) array_key_last($files) : '';
}

function mb_schema_cached(): string
{
    return is_file(MB_SCHEMA_CACHE) ? trim((string) file_get_contents(MB_SCHEMA_CACHE)) : '';
}

/** Split a migration file into statements. Our migrations use no procedures or triggers. */
function mb_sql_statements(string $sql): array
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)), fn ($s) => $s !== ''));
}

/**
 * Apply every migration not yet recorded in schema_migrations, in order.
 * Safe to call concurrently: a named lock lets one request do the work.
 * @return string[] versions applied by this call
 */
function mb_migrate(PDO $pdo): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(64) NOT NULL PRIMARY KEY,
        applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    if ((int) $pdo->query("SELECT GET_LOCK('mashpad_migrate', 30)")->fetchColumn() !== 1) {
        throw new RuntimeException('Another request is updating the database schema; try again in a moment.');
    }
    $applied = [];
    try {
        $done = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        foreach (mb_migration_files() as $version => $path) {
            if (isset($done[$version])) {
                continue;
            }
            foreach (mb_sql_statements((string) file_get_contents($path)) as $statement) {
                $pdo->exec($statement);
            }
            $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $applied[] = $version;
        }
        @file_put_contents(MB_SCHEMA_CACHE, mb_latest_migration(), LOCK_EX);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('mashpad_migrate')");
    }
    return $applied;
}

function mb_audit(PDO $pdo, ?int $actorId, string $action, ?string $subject = null, ?string $detail = null): void
{
    $pdo->prepare('INSERT INTO audit_log (actor_id, action, subject, detail) VALUES (?, ?, ?, ?)')
        ->execute([$actorId, $action, $subject, $detail]);
}

/** Write data/config.php atomically, readable only by the PHP user. */
function mb_write_config(array $db): void
{
    $old  = is_file(MB_CONFIG_FILE) ? (include MB_CONFIG_FILE) : [];
    $cfg  = ['db' => $db, 'created' => gmdate('c')] + (is_array($old) && !empty($old['secret_key']) ? ['secret_key' => $old['secret_key']] : []);   // the key for stored tokens survives a rewrite
    $body = "<?php\n// Written by setup/. Database credentials and the key for stored tokens: never commit this file.\nreturn "
          . var_export($cfg, true) . ";\n";
    $tmp = MB_CONFIG_FILE . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, MB_CONFIG_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write data/config.php. Check that data/ is writable by PHP.');
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(MB_CONFIG_FILE, true);
    }
}
