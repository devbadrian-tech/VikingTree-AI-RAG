<?php
declare(strict_types=1);

namespace Arbor;

const ROOT = __DIR__ . '/..';

if (is_file(ROOT . '/.env')) {
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) && getenv($key) === false) putenv($key . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}

final class ApiError extends \RuntimeException {
    public function __construct(public string $kind, string $message, public int $status = 400, public array $details = []) { parent::__construct($message); }
}

function id(string $prefix = 'n'): string { return $prefix . '_' . bin2hex(random_bytes(8)); }
function now(): string { return gmdate('c'); }
function encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
function slug(string $text): string {
    $text = preg_replace('/\bstep\s+(\d+)\b/i', 'step$1', $text);
    $text = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $text), '-'));
    return $text !== '' ? substr($text, 0, 80) : 'untitled';
}
function excerpt(string $text, int $length = 180): string {
    $text = trim(preg_replace('/\s+/', ' ', preg_replace('/[#*`>]/', '', $text)));
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}
function db(): \PDO {
    static $db;
    if ($db) return $db;
    $path = getenv('ARBOR_DB') ?: ROOT . '/data/arbor.sqlite';
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    @chmod($path, 0600);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
    $db->exec('CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS snapshots (version TEXT PRIMARY KEY, label TEXT, created_at TEXT, payload TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS sources (id TEXT PRIMARY KEY, name TEXT, hash TEXT UNIQUE, original TEXT, created_at TEXT);
        CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, status TEXT, progress INTEGER DEFAULT 0, message TEXT, payload TEXT, result TEXT, created_at TEXT, updated_at TEXT);');
    return $db;
}
function state(string $key, mixed $default = null): mixed {
    $q = db()->prepare('SELECT value FROM state WHERE key=?'); $q->execute([$key]); $value = $q->fetchColumn();
    return $value === false ? $default : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
}
function setState(string $key, mixed $value): void {
    $q = db()->prepare('INSERT INTO state(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value'); $q->execute([$key, encode($value)]);
}

require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/Models.php';
require_once __DIR__ . '/Tools.php';
require_once __DIR__ . '/Importer.php';
require_once __DIR__ . '/Mcp.php';

require_once __DIR__ . '/Summaries.php';

require_once __DIR__ . '/Transfer.php';
