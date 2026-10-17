<?php
/**
 * Hodinová CRON úloha – volá ji plánovač Active24 přes HTTP:
 *   https://glowbypetra.cz/cron/run.php?token=<cron_token z config.php>
 * Lze spustit i z příkazové řádky na serveru: php cron/run.php (v CLI se token neověřuje).
 *
 * Kroky:
 *  (1) připomínka zákaznicím – potvrzený běžný termín za 23–25 h (reminder_sent=0 → 1)
 *  (2) připomínka Petře – objednávka čeká na potvrzení déle než 24 h (owner_nudged_at)
 *  (3) připomínka Petře – svatební záloha nepřišla do 3 dnů (owner_nudged_at); termín se NEuvolňuje (rozhodnutí klientky)
 *  (4) Google Kalendář → blocked_slots (jen když je kalendář nastavený)
 * Každý krok je oddělený: chyba jednoho neshodí ostatní, všechno se loguje do tabulky log.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bookings.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}

// ---------- ověření tokenu (HTTP) ----------
if (!$isCli) {
    $expected = (string)cfg('cron_token', '');
    $given    = (string)($_GET['token'] ?? '');
    if ($expected === '') {
        http_response_code(403);
        exit("403 – cron_token není v config.php nastaven.\n");
    }
    if ($given === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit("403 – přístup odepřen.\n");
    }
}

ignore_user_abort(true);
set_time_limit(300);

$report = [];
$line = function (string $s) use (&$report): void {
    $report[] = $s;
    echo $s, "\n";
    if (function_exists('flush')) flush();
};
$line('Glow cron ' . now() . ($isCli ? ' (CLI)' : ''));

// ---------- (1) připomínky zákaznicím ----------
try {
    $from = date('Y-m-d H:i:s', time() + 23 * 3600);
    $to   = date('Y-m-d H:i:s', time() + 25 * 3600);
    $st = db()->prepare("SELECT * FROM bookings WHERE status = 'confirmed' AND kind = 'standard' AND reminder_sent = 0 AND customer_email <> 'nezadano@glowbypetra.cz' AND start_at >= ? AND start_at < ?");
    $st->execute([$from, $to]);
    $sent = 0; $failed = 0;
    foreach ($st->fetchAll() as $b) {
        if (sendCustomerReminder($b)) {
            db()->prepare('UPDATE bookings SET reminder_sent = 1, updated_at = ? WHERE id = ?')->execute([now(), $b['id']]);
            $sent++;
        } else {
            $failed++;   // chyba je už zalogovaná v sendMail; zkusí se znovu za hodinu, dokud je termín v okně 23–25 h
        }
    }
    $line('[1] Připomínky zákaznicím (termín za 23–25 h): odesláno ' . $sent . ($failed ? ', selhalo ' . $failed : ''));
} catch (Throwable $e) {
    logEvent('cron:reminders', null, $e->getMessage(), 'error');
    $line('[1] Připomínky zákaznicím: CHYBA – ' . $e->getMessage());
}

// ---------- (2) Petře: nepotvrzená objednávka > 24 h ----------
try {
    $cut = date('Y-m-d H:i:s', time() - 24 * 3600);
    $st = db()->prepare("SELECT * FROM bookings WHERE status = 'pending' AND owner_nudged_at IS NULL AND created_at < ?");
    $st->execute([$cut]);
    $sent = 0; $failed = 0;
    foreach ($st->fetchAll() as $b) {
        if (sendOwnerNudge($b, 'Nepotvrzená objednávka čeká už den')) {
            db()->prepare('UPDATE bookings SET owner_nudged_at = ?, updated_at = ? WHERE id = ?')->execute([now(), now(), $b['id']]);
            $sent++;
        } else {
            $failed++;
        }
    }
    $line('[2] Připomínka Petře – nepotvrzené > 24 h: odesláno ' . $sent . ($failed ? ', selhalo ' . $failed : ''));
} catch (Throwable $e) {
    logEvent('cron:nudge_pending', null, $e->getMessage(), 'error');
    $line('[2] Připomínka Petře – nepotvrzené: CHYBA – ' . $e->getMessage());
}

// ---------- (3) Petře: svatební záloha nepřišla do 3 dnů ----------
try {
    $cut = date('Y-m-d H:i:s', time() - 3 * 24 * 3600);
    $st = db()->prepare("SELECT * FROM bookings WHERE status = 'awaiting_deposit' AND owner_nudged_at IS NULL AND created_at < ?");
    $st->execute([$cut]);
    $sent = 0; $failed = 0;
    foreach ($st->fetchAll() as $b) {
        if (sendOwnerNudge($b, 'Záloha za svatbu nepřišla do 3 dnů')) {
            db()->prepare('UPDATE bookings SET owner_nudged_at = ?, updated_at = ? WHERE id = ?')->execute([now(), now(), $b['id']]);
            $sent++;
        } else {
            $failed++;
        }
    }
    $line('[3] Připomínka Petře – záloha > 3 dny: odesláno ' . $sent . ($failed ? ', selhalo ' . $failed : ''));
} catch (Throwable $e) {
    logEvent('cron:nudge_deposit', null, $e->getMessage(), 'error');
    $line('[3] Připomínka Petře – záloha: CHYBA – ' . $e->getMessage());
}

// ---------- (4) Google Kalendář → blokace ----------
try {
    if (gcalEnabled()) {
        $n = gcalPullBlocks();   // chyby si loguje sama (gcal:pull) a vrací 0
        $line('[4] Google Kalendář: synchronizováno ' . $n . ' událostí → blokace');
    } else {
        $line('[4] Google Kalendář: nenastaven, přeskočeno');
    }
} catch (Throwable $e) {
    logEvent('cron:gcal', null, $e->getMessage(), 'error');
    $line('[4] Google Kalendář: CHYBA – ' . $e->getMessage());
}

// ---------- (5) úklid: staré záznamy rate-limitu a logu ----------
try {
    db()->prepare("DELETE FROM settings WHERE `key` LIKE 'rl:%' AND updated_at < ?")->execute([date('Y-m-d H:i:s', time() - 86400)]);
    db()->prepare("DELETE FROM log WHERE created_at < ?")->execute([date('Y-m-d H:i:s', time() - 90 * 86400)]);
} catch (Throwable $e) {
    logEvent('cron:cleanup', null, $e->getMessage(), 'error');
}

// ---------- závěr ----------
try {
    settingSet('cron_last_run', now());
    logEvent('cron:run', null, implode(' | ', array_slice($report, 1)));
} catch (Throwable $e) {
    // nic – report už je vypsaný
}
$line('Hotovo.');
