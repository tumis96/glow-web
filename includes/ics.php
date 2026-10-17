<?php
/** Pozvánka do kalendáře (.ics) pro zákaznici – přiloží se k potvrzovacímu e-mailu. */
declare(strict_types=1);

function icsEscape(string $s): string {
    return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
}

/** Vrátí obsah souboru .ics pro rezervaci (čas v Europe/Prague převedený na UTC). */
function buildIcs(array $b): string {
    $tz    = new DateTimeZone(cfg('timezone', 'Europe/Prague'));
    $start = new DateTime($b['start_at'], $tz);
    $end   = new DateTime($b['end_at'], $tz);
    $utc   = new DateTimeZone('UTC');
    $start->setTimezone($utc); $end->setTimezone($utc);
    $stamp = (new DateTime('now', $utc))->format('Ymd\THis\Z');

    $summary  = ($b['kind'] === 'wedding' ? 'Svatební líčení – Glow by Petra Jahodová' : $b['service_label'] . ' – Glow by Petra Jahodová');
    $desc     = "Rezervace u Petry Jahodové (Glow).\nSlužba: {$b['service_label']}\n";
    if ($b['kind'] === 'wedding') {
        $desc .= 'Místo svatby: ' . ($b['wedding_place'] ?? '') . "\n";
        if (!empty($b['ceremony_time'])) $desc .= 'Obřad: ' . substr($b['ceremony_time'], 0, 5) . "\n";
    } else {
        $desc .= 'Počet osob: ' . (int)$b['persons'] . "\n";
    }
    $desc    .= 'Kontakt: +420 604 643 157, info@glowbypetra.cz';
    $location = $b['kind'] === 'wedding' ? (string)($b['wedding_place'] ?? '') : 'Salon Petra Jahodová, Lichnov 125, 742 75 Lichnov';

    // svatba = celodenní událost (DTEND je den po – exkluzivní)
    if ($b['kind'] === 'wedding') {
        $day = substr($b['start_at'], 0, 10);
        $dt  = ['DTSTART;VALUE=DATE:' . date('Ymd', strtotime($day)), 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($day . ' +1 day'))];
    } else {
        $dt  = ['DTSTART:' . $start->format('Ymd\THis\Z'), 'DTEND:' . $end->format('Ymd\THis\Z')];
    }
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Glow by Petra Jahodova//Rezervace//CS', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $b['id'] . '@glowbypetra.cz',
        'DTSTAMP:' . $stamp,
        ...$dt,
        'SUMMARY:' . icsEscape($summary),
        'DESCRIPTION:' . icsEscape($desc),
        'LOCATION:' . icsEscape($location),
        'STATUS:CONFIRMED',
        'BEGIN:VALARM', 'TRIGGER:-PT24H', 'ACTION:DISPLAY', 'DESCRIPTION:' . icsEscape('Zítra: ' . $summary), 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR',
    ];
    // řádky max 75 bajtů dle RFC 5545
    $out = '';
    foreach ($lines as $l) {
        while (strlen($l) > 73) { $chunk = mb_strcut($l, 0, 73, 'UTF-8'); $out .= $chunk . "\r\n "; $l = substr($l, strlen($chunk)); }
        $out .= $l . "\r\n";
    }
    return $out;
}
