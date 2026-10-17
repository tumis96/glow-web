<?php
/**
 * Administrace – přihlášení, session, CSRF, omezení pokusů.
 * Heslo: hash v config.php ('admin_pass_hash'); když je prázdný, hash uložený z prvního spuštění v settings.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

const ADMIN_LOGIN_MAX    = 8;      // pokusů…
const ADMIN_LOGIN_WINDOW = 900;    // …za 15 minut z jedné IP
const ADMIN_SESSION_IDLE = 43200;  // 12 h bez aktivity → znovu přihlásit
const ADMIN_NO_EMAIL     = 'nezadano@glowbypetra.cz';   // náhradní e-mail u ručně zapsané rezervace bez e-mailu (nic se na něj neposílá)

function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function adminSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('glowadmin');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    header('Cache-Control: no-store');
    header('X-Frame-Options: SAMEORIGIN');
}

function adminLoggedIn(): bool {
    adminSession();
    if (empty($_SESSION['admin'])) return false;
    if (time() - (int)($_SESSION['admin_seen'] ?? 0) > ADMIN_SESSION_IDLE) { adminLogout(); return false; }
    $_SESSION['admin_seen'] = time();
    return true;
}

/** Vstup pro každou stránku adminu: bez přihlášení přesměruje na login a zapamatuje si, kam se chtělo. */
function requireAdmin(): void {
    if (adminLoggedIn()) return;
    adminSession();   // po vypršení session byla zničena – založit novou pro after_login
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('~^/[^/\\\\][^\s]*$~', $uri) && str_contains($uri, '/admin/')) $_SESSION['after_login'] = $uri;
    redirect('login.php');
}

function adminLogin(): void {
    adminSession();
    session_regenerate_id(true);
    $_SESSION['admin']      = true;
    $_SESSION['admin_seen'] = time();
    unset($_SESSION['csrf']);
}

function adminLogout(): void {
    adminSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 86400, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']]);
    }
    session_destroy();
}

// ---------- heslo ----------
function adminPasswordHash(): ?string {
    $h = trim((string)cfg('admin_pass_hash', ''));
    if ($h === '') $h = trim((string)settingGet('admin_pass_hash', ''));
    return $h === '' ? null : $h;
}

// ---------- omezení pokusů o přihlášení ----------
function loginFailKey(): string {
    return 'login_fail:' . substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '0')), 0, 16);
}
/** [počet neúspěchů v okně, začátek okna] */
function loginFailState(): array {
    $v = settingGet(loginFailKey());
    if (!$v) return [0, time()];
    [$count, $since] = array_map('intval', explode('|', $v) + [0, 0]);
    if (time() - $since > ADMIN_LOGIN_WINDOW) return [0, time()];
    return [$count, $since];
}
function loginBlocked(): bool { return loginFailState()[0] >= ADMIN_LOGIN_MAX; }
function loginFailed(): void {
    [$count, $since] = loginFailState();
    settingSet(loginFailKey(), ($count + 1) . '|' . $since);
    logEvent('admin:login_failed', null, 'IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'), 'error');
}
function loginClearFails(): void {
    db()->prepare('DELETE FROM settings WHERE `key` = ?')->execute([loginFailKey()]);
}

// ---------- CSRF ----------
function csrfToken(): string {
    adminSession();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">';
}
/** Zavolat na začátku zpracování každého POSTu. Při neshodě skončí s 403. */
function csrfCheck(): void {
    adminSession();
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Formulář vypršel nebo byl odeslán odjinud. Vrať se zpět a zkus to znovu.');
    }
}

// ---------- pomocné ----------
function redirect(string $url): void {
    header('Location: ' . $url, true, 303);
    exit;
}
/** Krátká zpráva zobrazená po přesměrování (type: ok | err | warn). */
function flash(string $type, string $message): void {
    adminSession();
    $_SESSION['flash'][] = [$type, $message];
}
function flashPull(): array {
    adminSession();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
function isPost(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

/** "2026-11-07" a existující den v kalendáři. */
function validDate(string $d): bool {
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
/** "9:30", "09.30", "15:30" → "09:30" / "15:30"; jinak null. */
function normTime(string $t): ?string {
    if (!preg_match('/^\s*(\d{1,2})\s*[:.]\s*(\d{2})\s*$/', $t, $m)) return null;
    $hh = (int)$m[1]; $mm = (int)$m[2];
    if ($hh > 23 || $mm > 59) return null;
    return sprintf('%02d:%02d', $hh, $mm);
}
