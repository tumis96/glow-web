<?php
/**
 * Google Kalendář – implementace rozhraní z gcal.php (service account, bez externí knihovny).
 *
 * Autentizace: JWT (RS256) podepsaný privátním klíčem service accountu → access token na oauth2.googleapis.com.
 * Token se cachuje v tabulce settings (gcal_token, gcal_token_exp) a obnovuje 5 minut před vypršením.
 *
 * Zásady: nic nevypisovat, nikdy nevyhazovat výjimku ven (rezervace musí projít i bez kalendáře),
 * všechny chyby jen logEvent('gcal:…', …, 'error'). Časy v DB jsou místní (cfg timezone), do Googlu jdou jako RFC3339 s offsetem.
 */
declare(strict_types=1);

const GCAL_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GCAL_SCOPE     = 'https://www.googleapis.com/auth/calendar';
const GCAL_API       = 'https://www.googleapis.com/calendar/v3';

// ---------- veřejné rozhraní ----------

function gcalEnabled(): bool {
    $keyFile = (string)cfg('google_sa_json', '');
    $calId   = trim((string)cfg('google_calendar_id', ''));
    return $keyFile !== '' && is_file($keyFile) && $calId !== '';
}

/** Vytvoří nebo (má-li gcal_event_id) aktualizuje událost rezervace. Vrátí ID události, při chybě null. */
function gcalUpsertBooking(array $b): ?string {
    if (!gcalEnabled()) return null;
    $bookingId = isset($b['id']) ? (string)$b['id'] : null;
    try {
        $body     = gcalEventBody($b);
        $base     = gcalEventsUrl();
        $existing = trim((string)($b['gcal_event_id'] ?? ''));

        if ($existing !== '') {
            $r = gcalRequest('PUT', $base . '/' . rawurlencode($existing), $body);
            if ($r['status'] === 200 && !empty($r['data']['id'])) {
                return (string)$r['data']['id'];
            }
            if (!in_array($r['status'], [404, 410], true)) {
                throw new RuntimeException('events.update HTTP ' . $r['status'] . ': ' . gcalErrorText($r['data']));
            }
            // událost už v kalendáři není (někdo ji smazal) – založíme novou
        }

        $r = gcalRequest('POST', $base, $body);
        if (!in_array($r['status'], [200, 201], true) || empty($r['data']['id'])) {
            throw new RuntimeException('events.insert HTTP ' . $r['status'] . ': ' . gcalErrorText($r['data']));
        }
        logEvent('gcal:upsert', $bookingId, 'OK ' . (string)$r['data']['id']);
        return (string)$r['data']['id'];
    } catch (Throwable $e) {
        logEvent('gcal:upsert', $bookingId, $e->getMessage(), 'error');
        return null;
    }
}

/** Smaže událost rezervace podle gcal_event_id (404/410 = už neexistuje, ignorujeme). */
function gcalDeleteBooking(array $b): void {
    if (!gcalEnabled()) return;
    $eventId   = trim((string)($b['gcal_event_id'] ?? ''));
    $bookingId = isset($b['id']) ? (string)$b['id'] : null;
    if ($eventId === '') return;
    try {
        $r = gcalRequest('DELETE', gcalEventsUrl() . '/' . rawurlencode($eventId));
        if (!in_array($r['status'], [200, 204, 404, 410], true)) {
            throw new RuntimeException('events.delete HTTP ' . $r['status'] . ': ' . gcalErrorText($r['data']));
        }
        if ($bookingId !== null) {
            db()->prepare('UPDATE bookings SET gcal_event_id = NULL WHERE id = ?')->execute([$bookingId]);
        }
        logEvent('gcal:delete', $bookingId, 'OK ' . $eventId);
    } catch (Throwable $e) {
        logEvent('gcal:delete', $bookingId, $e->getMessage(), 'error');
    }
}

/**
 * Obousměrná synchronizace: cizí události z kalendáře (ruční zápisy Petry) → blocked_slots (source='gcal').
 * Okno: dnes až +7 měsíců (o měsíc víc než maxMonthsAhead, aby blokace z kalendáře pokryly celé rezervační okno).
 * Vlastní události (extendedProperties.private.glowBookingId) se přeskakují.
 * Blokace source='gcal', jejichž událost už v kalendáři není, se smažou. Vrátí počet synchronizovaných událostí.
 */
function gcalPullBlocks(): int {
    if (!gcalEnabled()) return 0;
    try {
        $tz   = gcalTz();
        $from = new DateTime('today', $tz);
        $to   = (clone $from)->modify('+7 months');
        $params = [
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'timeMin'      => $from->format(DATE_RFC3339),
            'timeMax'      => $to->format(DATE_RFC3339),
            'maxResults'   => '250',
            'showDeleted'  => 'false',
        ];

        $upsert = db()->prepare(
            "INSERT INTO blocked_slots (start_at, end_at, reason, source, gcal_event_id, created_at) VALUES (?,?,?,'gcal',?,?)"
            . ' AS new ON DUPLICATE KEY UPDATE start_at = new.start_at, end_at = new.end_at, reason = new.reason'
        );

        $seen = [];
        $count = 0;
        $pageToken = null;
        do {
            $q = $params;
            if ($pageToken !== null) $q['pageToken'] = $pageToken;
            $r = gcalRequest('GET', gcalEventsUrl() . '?' . http_build_query($q));
            if ($r['status'] !== 200) {
                throw new RuntimeException('events.list HTTP ' . $r['status'] . ': ' . gcalErrorText($r['data']));
            }
            foreach (($r['data']['items'] ?? []) as $ev) {
                if (!is_array($ev)) continue;
                if (($ev['status'] ?? '') === 'cancelled') continue;
                if (!empty($ev['extendedProperties']['private']['glowBookingId'])) continue;   // naše vlastní rezervace
                $eventId = (string)($ev['id'] ?? '');
                if ($eventId === '') continue;
                $range = gcalEventRange($ev, $tz);
                if ($range === null) continue;
                [$start, $end] = $range;
                $reason = trim((string)($ev['summary'] ?? ''));
                if ($reason === '') $reason = 'Google Kalendář';
                $upsert->execute([$start, $end, mb_substr($reason, 0, 190), $eventId, now()]);
                $seen[$eventId] = true;
                $count++;
            }
            $pageToken = isset($r['data']['nextPageToken']) ? (string)$r['data']['nextPageToken'] : null;
        } while ($pageToken !== null && $pageToken !== '');

        // blokace z kalendáře, které už v kalendáři nejsou (smazané nebo mimo okno), pryč
        $ids = array_keys($seen);
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("DELETE FROM blocked_slots WHERE source = 'gcal' AND (gcal_event_id IS NULL OR gcal_event_id NOT IN ($marks))")->execute($ids);
        } else {
            db()->prepare("DELETE FROM blocked_slots WHERE source = 'gcal'")->execute();
        }

        settingSet('gcal_last_sync', now());
        logEvent('gcal:pull', null, $count . ' událostí');
        return $count;
    } catch (Throwable $e) {
        logEvent('gcal:pull', null, $e->getMessage(), 'error');
        return 0;
    }
}

// ---------- HTTP ----------

/**
 * Autorizovaný požadavek na Calendar API. Vrací ['status' => int, 'data' => array].
 * Při 401 (token odvolán / neplatný) jednou zahodí cache a zkusí to znovu.
 * Vyhazuje RuntimeException (síť, token) – volající ji musí chytit.
 */
function gcalRequest(string $method, string $url, ?array $body = null): array {
    $r = gcalHttp($method, $url, $body, gcalAccessToken());
    if ($r['status'] === 401) {
        settingSet('gcal_token', null);
        settingSet('gcal_token_exp', '0');
        $r = gcalHttp($method, $url, $body, gcalAccessToken());
    }
    return $r;
}

function gcalHttp(string $method, string $url, ?array $body, string $token): array {
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json; charset=utf-8';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init selhalo');
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new RuntimeException('cURL ' . $method . ' ' . $url . ': ' . curl_error($ch));
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $data   = json_decode((string)$resp, true);
    return ['status' => $status, 'data' => is_array($data) ? $data : []];
}

// ---------- autentizace service accountem ----------

/** Platný access token – z cache v settings, nebo nový přes JWT. Vyhazuje RuntimeException. */
function gcalAccessToken(): string {
    $cached = settingGet('gcal_token');
    $exp    = (int)settingGet('gcal_token_exp', '0');
    if ($cached !== null && $cached !== '' && ($exp - 300) > time()) {
        return $cached;
    }

    $keyFile = (string)cfg('google_sa_json', '');
    $raw = $keyFile !== '' && is_file($keyFile) ? file_get_contents($keyFile) : false;
    if ($raw === false) throw new RuntimeException('Nelze načíst klíč service accountu: ' . $keyFile);
    $key = json_decode($raw, true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        throw new RuntimeException('Soubor klíče service accountu neobsahuje client_email / private_key.');
    }

    $iat = time() - 60;   // malá rezerva proti posunu hodin serveru
    $claims = [
        'iss'   => (string)$key['client_email'],
        'scope' => GCAL_SCOPE,
        'aud'   => GCAL_TOKEN_URL,
        'iat'   => $iat,
        'exp'   => $iat + 3600,
    ];
    $segments = gcalB64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . gcalB64Url(json_encode($claims));

    $pkey = openssl_pkey_get_private((string)$key['private_key']);
    if ($pkey === false) throw new RuntimeException('Privátní klíč service accountu nelze načíst: ' . (string)openssl_error_string());
    $signature = '';
    if (!openssl_sign($segments, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Podpis JWT selhal: ' . (string)openssl_error_string());
    }
    $jwt = $segments . '.' . gcalB64Url($signature);

    $ch = curl_init(GCAL_TOKEN_URL);
    if ($ch === false) throw new RuntimeException('curl_init selhalo');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) throw new RuntimeException('cURL token: ' . curl_error($ch));
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $data   = json_decode((string)$resp, true);
    if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        $err = is_array($data) ? (($data['error'] ?? '') . ' ' . ($data['error_description'] ?? '')) : substr((string)$resp, 0, 200);
        throw new RuntimeException('Výměna JWT za token selhala (HTTP ' . $status . '): ' . trim($err));
    }

    $token = (string)$data['access_token'];
    $ttl   = max(60, (int)($data['expires_in'] ?? 3600));
    settingSet('gcal_token', $token);
    settingSet('gcal_token_exp', (string)(time() + $ttl));
    return $token;
}

function gcalB64Url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

// ---------- pomocné ----------

function gcalTz(): DateTimeZone {
    return new DateTimeZone((string)cfg('timezone', 'Europe/Prague'));
}

function gcalEventsUrl(): string {
    return GCAL_API . '/calendars/' . rawurlencode(trim((string)cfg('google_calendar_id', ''))) . '/events';
}

function gcalErrorText(array $data): string {
    if (isset($data['error']['message'])) return (string)$data['error']['message'];
    if (isset($data['error']) && is_string($data['error'])) return $data['error'];
    $j = json_encode($data, JSON_UNESCAPED_UNICODE);
    return $j ? substr($j, 0, 300) : '';
}

/** Tělo události pro rezervaci (standard = časová událost, svatba = celodenní). */
function gcalEventBody(array $b): array {
    $tz = gcalTz();
    $isWedding = ($b['kind'] ?? 'standard') === 'wedding';
    $name = (string)($b['customer_name'] ?? '');

    $lines = [];
    $lines[] = 'Telefon: ' . (string)($b['customer_phone'] ?? '');
    $lines[] = 'E-mail: ' . (string)($b['customer_email'] ?? '');
    if ($isWedding) {
        $lines[] = 'Místo svatby: ' . (string)($b['wedding_place'] ?? '');
        if (!empty($b['ceremony_time'])) $lines[] = 'Obřad: ' . substr((string)$b['ceremony_time'], 0, 5);
        if (!empty($b['ready_by']))      $lines[] = 'Hotovo do: ' . substr((string)$b['ready_by'], 0, 5);
        $lines[] = 'Líčení / účes: ' . (int)($b['persons_makeup'] ?? 0) . ' / ' . (int)($b['persons_hair'] ?? 0) . ' osob';
        if (!empty($b['deposit_vs']))    $lines[] = 'Poptávka č. ' . (string)$b['deposit_vs'];
    } else {
        $lines[] = 'Služba: ' . (string)($b['service_label'] ?? '');
        $lines[] = 'Osob: ' . (int)($b['persons'] ?? 1);
    }
    if (!empty($b['note']))  $lines[] = 'Poznámka: ' . (string)$b['note'];
    $lines[] = 'Cena: ' . (string)($b['price_label'] ?? 'dle domluvy');
    $lines[] = '';
    $lines[] = 'Administrace: ' . siteUrl('admin/index.php?id=' . (string)($b['id'] ?? ''));

    $event = [
        'description' => implode("\n", $lines),
        'extendedProperties' => ['private' => ['glowBookingId' => (string)($b['id'] ?? '')]],
        'reminders' => ['useDefault' => true],
    ];

    if ($isWedding) {
        $place = trim((string)($b['wedding_place'] ?? ''));
        $event['summary']  = 'SVATBA – ' . $name . ($place !== '' ? ' – ' . $place : '');
        $event['location'] = $place;
        $startDay = substr((string)$b['start_at'], 0, 10);
        $endDay   = substr((string)$b['end_at'], 0, 10);
        if ($endDay < $startDay) $endDay = $startDay;
        // celodenní událost: end.date je v Googlu EXKLUZIVNÍ (den následující po posledním dni)
        $endExclusive = (new DateTime($endDay . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
        $event['start'] = ['date' => $startDay];
        $event['end']   = ['date' => $endExclusive];
    } else {
        $event['summary']  = (string)($b['service_label'] ?? 'Rezervace') . ' – ' . $name . ' (' . (int)($b['persons'] ?? 1) . ' os.)';
        $event['location'] = 'Salon Petra Jahodová, Lichnov 125, 742 75 Lichnov';
        $event['start'] = ['dateTime' => (new DateTime((string)$b['start_at'], $tz))->format(DATE_RFC3339), 'timeZone' => $tz->getName()];
        $event['end']   = ['dateTime' => (new DateTime((string)$b['end_at'],   $tz))->format(DATE_RFC3339), 'timeZone' => $tz->getName()];
    }
    return $event;
}

/**
 * Rozsah události z Googlu v místním čase ['Y-m-d H:i:s', 'Y-m-d H:i:s'], nebo null, když se nedá určit.
 * Celodenní (date) → 00:00:00 prvního dne až 23:59:00 posledního dne (end.date je exkluzivní).
 */
function gcalEventRange(array $ev, DateTimeZone $tz): ?array {
    $s = $ev['start'] ?? null; $e = $ev['end'] ?? null;
    if (!is_array($s) || !is_array($e)) return null;

    if (!empty($s['date'])) {
        $startDay = (string)$s['date'];
        $endDay = !empty($e['date']) ? (string)$e['date'] : $startDay;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDay) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDay)) return null;
        $last = (new DateTime($endDay . ' 00:00:00', $tz))->modify('-1 day')->format('Y-m-d');
        if ($last < $startDay) $last = $startDay;
        return [$startDay . ' 00:00:00', $last . ' 23:59:00'];
    }

    if (!empty($s['dateTime']) && !empty($e['dateTime'])) {
        try {
            $start = (new DateTime((string)$s['dateTime']))->setTimezone($tz);
            $end   = (new DateTime((string)$e['dateTime']))->setTimezone($tz);
        } catch (Throwable $x) {
            return null;
        }
        if ($end <= $start) $end = (clone $start)->modify('+30 minutes');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }
    return null;
}
