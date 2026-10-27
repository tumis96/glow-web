<?php
/**
 * Operace s rezervacemi – jedno místo pro API, admin i cron.
 * Stavy: pending (čeká na Petru) → confirmed | rejected; cancelled (zákaznice/Petra);
 *        wedding: pending → confirmed | rejected (bez zálohy – Petra termín potvrzuje sama; starší poptávky
 *        mohou být ve stavu awaiting_deposit, chová se stejně jako pending).
 */
declare(strict_types=1);

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/slots.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/gcal.php';

function loadBooking(string $id): ?array {
    $st = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    $b = $st->fetch();
    return $b ?: null;
}

function validEmail(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }
function normalizePhone(string $p): ?string {
    $d = preg_replace('/[^\d+]/', '', $p);
    if (str_starts_with($d, '00')) $d = '+' . substr($d, 2);   // 00420… → +420…
    if (preg_match('/^\d{9}$/', $d)) return '+420' . $d;        // 604643157
    if (preg_match('/^420\d{9}$/', $d)) return '+' . $d;        // 420604643157
    if (preg_match('/^\+\d{9,14}$/', $d)) return $d;            // +420…, +421…
    return null;
}
function validDateYmd(string $date): bool {
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
/** Záměrně bez e-mailu (ruční zápis v adminu) – na tuto adresu se nic neposílá. */
function noEmail(array $b): bool { return ($b['customer_email'] ?? '') === NO_EMAIL; }

/**
 * Nová běžná rezervace z webu nebo z adminu.
 * $in: services[], persons, date (Y-m-d), time (H:i), name, email, phone, note, gdpr (bool), source ('web'|'admin')
 * Vrací ['ok'=>true,'booking'=>…] nebo ['ok'=>false,'error'=>…,'code'=>int]
 */
function createBooking(array $in): array {
    $services = is_array($in['services'] ?? null) ? $in['services'] : explode(',', (string)($in['services'] ?? ''));
    $persons  = max(1, (int)($in['persons'] ?? 1));
    $res = resolveServices($services, $persons);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'code' => 400];

    $date = trim((string)($in['date'] ?? '')); $time = trim((string)($in['time'] ?? ''));
    if (!validDateYmd($date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return ['ok' => false, 'error' => 'Neplatné datum nebo čas.', 'code' => 400];
    $name  = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $email = trim((string)($in['email'] ?? ''));
    $phone = normalizePhone((string)($in['phone'] ?? ''));
    $note  = trim((string)($in['note'] ?? ''));
    $source = ($in['source'] ?? 'web') === 'admin' ? 'admin' : 'web';
    if (mb_strlen($name) < 3) return ['ok' => false, 'error' => 'Zadejte prosím jméno a příjmení.', 'code' => 400];
    if (!validEmail($email)) return ['ok' => false, 'error' => 'Zadejte prosím platný e-mail.', 'code' => 400];
    if (!$phone) return ['ok' => false, 'error' => 'Zadejte prosím platné telefonní číslo.', 'code' => 400];
    if ($source === 'web' && empty($in['gdpr'])) return ['ok' => false, 'error' => 'Je potřeba souhlas se zpracováním údajů.', 'code' => 400];

    $pdo = db();
    // Zámek proti souběhu dvou rezervací na stejný slot (InnoDB transakce sama nic nezamyká).
    $pdo->query("SELECT GET_LOCK('glow_booking', 5)");
    $pdo->beginTransaction();
    try {
        // admin může zapsat i mimo pravidla (telefonická objednávka), web musí sedět na volný slot
        if ($source === 'web' && !slotIsFree($date, $time, $res['ids'], $persons)) {
            $pdo->rollBack(); $pdo->query("SELECT RELEASE_LOCK('glow_booking')");
            return ['ok' => false, 'error' => 'Tento termín už mezitím někdo obsadil. Vyberte prosím jiný čas.', 'code' => 409];
        }
        $start = "$date $time:00";
        $end   = date('Y-m-d H:i:s', strtotime($start) + $res['duration'] * 60);
        if ($source === 'admin') {
            // i admin nesmí přepsat už držený termín
            if (overlaps(strtotime($start), strtotime($end), busyIntervals($date))) {
                $pdo->rollBack(); $pdo->query("SELECT RELEASE_LOCK('glow_booking')");
                return ['ok' => false, 'error' => 'Termín se překrývá s jinou rezervací nebo blokací.', 'code' => 409];
            }
        }
        $b = [
            'id' => uuid(), 'kind' => 'standard', 'status' => $source === 'admin' ? 'confirmed' : 'pending', 'source' => $source,
            'start_at' => $start, 'end_at' => $end, 'persons' => $persons,
            'services_json' => json_encode($res['ids'], JSON_UNESCAPED_UNICODE), 'service_label' => $res['label'],
            'duration_min' => $res['duration'], 'price_total' => $res['price'], 'price_label' => czPrice($res['price'], $res['priceFrom']),
            'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => $phone, 'note' => $note ?: null,
            'gdpr_at' => $source === 'web' ? now() : null,
            'cancel_token' => token(), 'decide_token' => token(),
            'decided_at' => $source === 'admin' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ];
        $cols = implode(',', array_keys($b)); $qs = implode(',', array_fill(0, count($b), '?'));
        $pdo->prepare("INSERT INTO bookings ($cols) VALUES ($qs)")->execute(array_values($b));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->query("SELECT RELEASE_LOCK('glow_booking')");
        logEvent('booking:create', null, $e->getMessage(), 'error');
        return ['ok' => false, 'error' => 'Rezervaci se nepodařilo uložit. Zkuste to prosím znovu.', 'code' => 500];
    }
    $pdo->query("SELECT RELEASE_LOCK('glow_booking')");
    logEvent('booking:created', $b['id'], $b['status'] . ' ' . $b['service_label']);

    if ($source === 'web') {
        sendCustomerReceived($b);
        sendOwnerNewBooking($b);
    } else {
        $b['gcal_event_id'] = gcalUpsertBooking($b);
        if ($b['gcal_event_id']) db()->prepare('UPDATE bookings SET gcal_event_id = ? WHERE id = ?')->execute([$b['gcal_event_id'], $b['id']]);
        if (!empty($in['notify'])) sendCustomerConfirmed($b);
    }
    return ['ok' => true, 'booking' => $b];
}

/** Svatební poptávka → pending (čeká na Petru), číslo poptávky, e-maily. */
function createWedding(array $in): array {
    $name  = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $email = trim((string)($in['email'] ?? ''));
    $phone = normalizePhone((string)($in['phone'] ?? ''));
    $date  = trim((string)($in['date'] ?? ''));
    $place = mb_substr(trim((string)($in['place'] ?? '')), 0, 190);
    if (mb_strlen($name) < 3) return ['ok' => false, 'error' => 'Zadejte prosím jméno nevěsty.', 'code' => 400];
    if (!validEmail($email)) return ['ok' => false, 'error' => 'Zadejte prosím platný e-mail.', 'code' => 400];
    if (!$phone) return ['ok' => false, 'error' => 'Zadejte prosím platné telefonní číslo.', 'code' => 400];
    if (!validDateYmd($date) || $date < date('Y-m-d', strtotime('+1 day'))) return ['ok' => false, 'error' => 'Zadejte prosím datum svatby.', 'code' => 400];
    if (mb_strlen($place) < 2) return ['ok' => false, 'error' => 'Zadejte prosím místo svatby.', 'code' => 400];
    if (empty($in['gdpr'])) return ['ok' => false, 'error' => 'Je potřeba souhlas se zpracováním údajů.', 'code' => 400];
    $timeOk = fn($t) => $t === '' || preg_match('/^\d{2}:\d{2}$/', $t);
    $ceremony = trim((string)($in['ceremony_time'] ?? '')); $ready = trim((string)($in['ready_by'] ?? ''));
    if (!$timeOk($ceremony) || !$timeOk($ready)) return ['ok' => false, 'error' => 'Neplatný čas.', 'code' => 400];

    // jedna svatba za den – den D nesmí být držený jinou svatbou
    $st = db()->prepare("SELECT COUNT(*) FROM bookings WHERE kind = 'wedding' AND status IN ('pending','awaiting_deposit','confirmed') AND DATE(start_at) = ?");
    $st->execute([$date]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'error' => 'Tento den už mám svatbu. Napište mi prosím, zkusíme najít řešení.', 'code' => 409];
    // ani blokace (dovolená, jiná akce z kalendáře)
    $st = db()->prepare('SELECT COUNT(*) FROM blocked_slots WHERE start_at < ? AND end_at > ?');
    $st->execute(["$date 23:59:59", "$date 00:00:00"]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'error' => 'Tento den bohužel nemám k dispozici. Napište mi prosím, zkusíme najít řešení.', 'code' => 409];

    $pm = min(50, max(0, (int)($in['persons_makeup'] ?? 1))); $ph = min(50, max(0, (int)($in['persons_hair'] ?? 0)));
    $b = [
        'id' => uuid(), 'kind' => 'wedding', 'status' => 'pending', 'source' => 'web',
        'start_at' => "$date 00:00:00", 'end_at' => "$date 23:59:00", 'persons' => max(1, $pm),
        'services_json' => json_encode(['svatba'], JSON_UNESCAPED_UNICODE), 'service_label' => 'Svatební líčení' . ($ph > 0 ? ' a účes' : ''),
        'duration_min' => 0, 'price_total' => null, 'price_label' => 'dle ceníku',
        'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => $phone,
        'note' => trim((string)($in['note'] ?? '')) ?: null, 'gdpr_at' => now(),
        'wedding_place' => $place, 'ceremony_time' => $ceremony ?: null, 'ready_by' => $ready ?: null,
        'persons_makeup' => $pm, 'persons_hair' => $ph, 'trial_interest' => !empty($in['trial']) ? 1 : 0,
        'deposit_vs' => nextWeddingVs(),   // číslo poptávky (ROK + pořadí); sloupec zůstal z dob zálohy
        'cancel_token' => token(), 'decide_token' => token(),
        'created_at' => now(), 'updated_at' => now(),
    ];
    $cols = implode(',', array_keys($b)); $qs = implode(',', array_fill(0, count($b), '?'));
    try {
        db()->prepare("INSERT INTO bookings ($cols) VALUES ($qs)")->execute(array_values($b));
    } catch (Throwable $e) {
        logEvent('wedding:create', null, $e->getMessage(), 'error');
        return ['ok' => false, 'error' => 'Poptávku se nepodařilo uložit. Zkuste to prosím znovu.', 'code' => 500];
    }
    logEvent('wedding:created', $b['id'], 'č. ' . $b['deposit_vs']);
    sendWeddingReceived($b);
    sendOwnerNewWedding($b);
    return ['ok' => true, 'booking' => $b];
}

function setStatus(array $b, string $status, array $extra = []): array {
    $extra['status'] = $status; $extra['updated_at'] = now();
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($extra)));
    db()->prepare("UPDATE bookings SET $set WHERE id = ?")->execute([...array_values($extra), $b['id']]);
    return array_merge($b, $extra);
}

/** Petra potvrdila termín (běžná rezervace i svatba). */
function confirmBooking(array $b): array {
    if (!in_array($b['status'], ['pending', 'awaiting_deposit'], true)) return $b;
    $b = setStatus($b, 'confirmed', ['decided_at' => now()]);
    $eventId = gcalUpsertBooking($b);
    if ($eventId) { db()->prepare('UPDATE bookings SET gcal_event_id = ? WHERE id = ?')->execute([$eventId, $b['id']]); $b['gcal_event_id'] = $eventId; }
    $b['kind'] === 'wedding' ? sendWeddingConfirmed($b) : sendCustomerConfirmed($b);
    logEvent('booking:confirmed', $b['id']);
    return $b;
}

function rejectBooking(array $b, string $reason = ''): array {
    if (!in_array($b['status'], ['pending', 'awaiting_deposit'], true)) return $b;
    $b = setStatus($b, 'rejected', ['decided_at' => now(), 'reject_reason' => $reason ?: null]);
    gcalDeleteBooking($b);
    sendCustomerRejected($b);
    logEvent('booking:rejected', $b['id'], $reason);
    return $b;
}

/** Zrušení – zákaznicí (odkazem) nebo Petrou (admin). */
function cancelBooking(array $b, string $by = 'customer'): array {
    if (in_array($b['status'], ['cancelled', 'rejected'], true)) return $b;
    $b = setStatus($b, 'cancelled', ['decided_at' => now()]);
    gcalDeleteBooking($b);
    if ($by === 'customer') sendOwnerCancelled($b); else sendCustomerCancelled($b);
    logEvent('booking:cancelled', $b['id'], 'by ' . $by);
    return $b;
}

/** Smí zákaznice ještě zrušit odkazem? Zdarma do dne předem (tj. do půlnoci před termínem). */
function customerCanCancel(array $b): bool {
    if (!in_array($b['status'], ['pending', 'confirmed', 'awaiting_deposit'], true)) return false;
    return date('Y-m-d') < date('Y-m-d', strtotime($b['start_at']));
}
