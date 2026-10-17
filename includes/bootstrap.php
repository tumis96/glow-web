<?php
/**
 * Glow by Petra Jahodová – společný start pro API, admin i cron.
 * Načte konfiguraci z /private/config.php (MIMO webroot), připojí databázi a nabídne pomocné funkce.
 *
 * Struktura na serveru:   /glowbypetra.cz/web  (tento web)   /glowbypetra.cz/private/config.php
 * Lokálně (vývoj):        <projekt>/web                       <projekt>/secrets/config.php
 */
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
/** Náhradní e-mail u ručně zapsaných rezervací bez kontaktu – na tuto adresu se nikdy nic neposílá. */
const NO_EMAIL = 'nezadano@glowbypetra.cz';

// ---------- konfigurace ----------
function cfg(?string $key = null, $default = null) {
    static $config = null;
    if ($config === null) {
        $candidates = [
            APP_ROOT . '/../private/config.php',   // produkce (Active24)
            APP_ROOT . '/../secrets/config.php',   // lokální vývoj
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) { $config = require $file; break; }
        }
        if (!is_array($config)) {
            http_response_code(500);
            exit('Chybí konfigurační soubor config.php (viz secrets/config.example.php).');
        }
    }
    if ($key === null) return $config;
    return array_key_exists($key, $config) ? $config[$key] : $default;
}

date_default_timezone_set(cfg('timezone', 'Europe/Prague'));
mb_internal_encoding('UTF-8');

if (cfg('debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// ---------- databáze ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('db_host'), (int)cfg('db_port', 3306), cfg('db_name'));
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");   // ukládáme místní čas jako text, DB s ním nepočítá
    }
    return $pdo;
}

// ---------- nastavení v DB (settings) ----------
function settingGet(string $key, ?string $default = null): ?string {
    $st = db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}
function settingSet(string $key, ?string $value): void {
    db()->prepare('INSERT INTO settings (`key`,`value`,updated_at) VALUES (?,?,?) AS new ON DUPLICATE KEY UPDATE `value` = new.`value`, updated_at = new.updated_at')
        ->execute([$key, $value, now()]);
}

// ---------- log ----------
function logEvent(string $event, ?string $bookingId = null, ?string $message = null, string $level = 'info'): void {
    try {
        db()->prepare('INSERT INTO log (level, event, booking_id, message, created_at) VALUES (?,?,?,?,?)')
            ->execute([$level, $event, $bookingId, $message, now()]);
    } catch (Throwable $e) { /* log nesmí shodit aplikaci */ }
}

// ---------- čas ----------
function now(): string { return date('Y-m-d H:i:s'); }
function uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function token(): string { return bin2hex(random_bytes(20)); }

const CZ_DAYS   = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
const CZ_MONTHS = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

/** "sobota 7. listopadu 2026" */
function czDate(string $datetime): string {
    $t = strtotime($datetime);
    return CZ_DAYS[(int)date('w', $t)] . ' ' . (int)date('j', $t) . '. ' . CZ_MONTHS[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}
/** "15:30" */
function czTime(string $datetime): string { return date('H:i', strtotime($datetime)); }
/** "1 000 Kč" */
function czPrice(?int $kc, bool $from = false): string {
    if ($kc === null) return 'dle domluvy';
    return ($from ? 'od ' : '') . number_format($kc, 0, ',', "\u{a0}") . "\u{a0}Kč";
}

// ---------- HTTP pomocné ----------
function jsonOut($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function jsonError(string $message, int $status = 400, array $extra = []): void {
    jsonOut(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}
function jsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $_POST;
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function siteUrl(string $path = ''): string { return rtrim((string)cfg('site_url', ''), '/') . '/' . ltrim($path, '/'); }

/** Jednoduchá ochrana proti zahlcení: max N požadavků z IP za okno (uloženo v settings). */
function rateLimit(string $bucket, int $max, int $windowSec): void {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
    $key = 'rl:' . $bucket . ':' . substr(hash('sha256', $ip), 0, 16);
    $v   = settingGet($key);
    [$count, $since] = $v ? array_map('intval', explode('|', $v)) : [0, time()];
    if (time() - $since > $windowSec) { $count = 0; $since = time(); }
    $count++;
    settingSet($key, $count . '|' . $since);
    if ($count > $max) jsonError('Příliš mnoho požadavků, zkuste to prosím za chvíli.', 429);
}
