<?php
/**
 * Mashpad — one-time server setup (logic for setup/index.php).
 *
 * Setup is guarded by a code written to data/setup-code.txt, which only someone
 * with access to the server's files can read. The code is deleted once setup
 * finishes, and the page then refuses to run again.
 */
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

const MB_SETUP_CODE_FILE = __DIR__ . '/../data/setup-code.txt';

/** The current setup code, creating one if needed. */
function mb_setup_code(): string
{
    if (is_file(MB_SETUP_CODE_FILE)) {
        $code = trim((string) file_get_contents(MB_SETUP_CODE_FILE));
        if ($code !== '') {
            return $code;
        }
    }
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I/L
    $code = '';
    for ($i = 0; $i < 12; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if ($i % 4 === 3 && $i < 11) {
            $code .= '-';
        }
    }
    if (!is_dir(dirname(MB_SETUP_CODE_FILE))) {
        @mkdir(dirname(MB_SETUP_CODE_FILE), 0775, true);
    }
    if (@file_put_contents(MB_SETUP_CODE_FILE, $code . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write data/setup-code.txt. Check that data/ is writable by PHP.');
    }
    @chmod(MB_SETUP_CODE_FILE, 0600);
    return $code;
}

function mb_setup_code_ok(string $given): bool
{
    $norm = fn (string $c) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $c));
    return is_file(MB_SETUP_CODE_FILE) && $given !== '' && hash_equals($norm(mb_setup_code()), $norm($given));
}

/** 'database' | 'admin' | 'done' */
function mb_setup_stage(): string
{
    $pdo = mb_db();
    if ($pdo === null) {
        return 'database';
    }
    return mb_admin_exists($pdo) ? 'done' : 'admin';
}

/** Turn a PDO connection failure into something a person can act on. */
function mb_explain_db_error(PDOException $e): string
{
    $code = (int) ($e->errorInfo[1] ?? 0);
    $msg  = $e->getMessage();
    return match (true) {
        $code === 1045                               => 'The database refused that user name or password.',
        $code === 1049                               => 'The server has no database with that name.',
        $code === 1044                               => 'That user isn\'t allowed to use this database.',
        $code === 2002 || str_contains($msg, '2002') => 'Couldn\'t reach the database server. Check the host name and port.',
        $code === 2005 || str_contains($msg, '2005') => 'Unknown database host name.',
        default                                      => 'The database connection failed: ' . $msg,
    };
}

/** Stage 1: test the credentials, migrate, then save them. Returns the server description. */
function mb_setup_database(array $in): string
{
    $db = [
        'host' => trim((string) ($in['host'] ?? '')),
        'port' => (int) ($in['port'] ?? 3306) ?: 3306,
        'name' => trim((string) ($in['name'] ?? '')),
        'user' => trim((string) ($in['user'] ?? '')),
        'pass' => (string) ($in['pass'] ?? ''),
    ];
    if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
        throw new MbAccountError('Host, database name and user are all required.');
    }
    try {
        $pdo = mb_db_connect($db);
    } catch (PDOException $e) {
        throw new MbAccountError(mb_explain_db_error($e));
    }
    [$ok, $label, $min] = mb_db_version_check($pdo);
    if (!$ok) {
        throw new MbAccountError("This server runs $label. Mashpad needs $min or newer.");
    }
    try {
        mb_migrate($pdo);
    } catch (PDOException $e) {
        throw new MbAccountError('Connected, but creating the tables failed: ' . $e->getMessage());
    }
    mb_write_config($db);
    return $label;
}

/** Stage 2: create the first (admin) account and retire the setup code. */
function mb_setup_admin(array $in): int
{
    $pdo = mb_db();
    if ($pdo === null || mb_admin_exists($pdo)) {
        throw new MbAccountError('Setup is not at the account step.');
    }
    if ((string) ($in['password'] ?? '') !== (string) ($in['password2'] ?? '')) {
        throw new MbAccountError('The two passwords don\'t match.');
    }
    $id = mb_create_human($pdo, (string) ($in['display_name'] ?? ''), (string) ($in['handle'] ?? ''),
        (string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''), true);
    @unlink(MB_SETUP_CODE_FILE);
    return $id;
}
