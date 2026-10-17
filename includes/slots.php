<?php
/**
 * Výpočet volných termínů.
 * Slot je volný, když: den+měsíc odpovídá pravidlu, které povoluje VŠECHNY vybrané služby,
 * celá délka (× osoby) se vejde do okna pravidla, nepřekrývá se s držící rezervací
 * (pending / confirmed / awaiting_deposit) ani s blokací, a je v limitech „2 dny až 2 měsíce dopředu“.
 */
declare(strict_types=1);

require_once __DIR__ . '/services.php';

/** Pravidlo platné pro daný den a sadu služeb (všechny služby musí být povolené), nebo null. */
function ruleForDay(string $date, array $serviceIds): ?array {
    $t = strtotime($date);
    $dow = (int)date('w', $t); $month = (int)date('n', $t);
    foreach (availabilityRules() as $rule) {
        if (!in_array($dow, $rule['days'], true)) continue;
        if (isset($rule['months']) && !in_array($month, $rule['months'], true)) continue;
        $missing = array_diff($serviceIds, $rule['services']);
        if ($missing) continue;
        return $rule;
    }
    return null;
}

/** Vysvětlení, kdy služby jdou objednat (pro hlášku „Plesové líčení dělám v pátek…“). */
function availabilityText(array $serviceIds): string {
    $labels = [];
    foreach (availabilityRules() as $rule) {
        if (!array_diff($serviceIds, $rule['services'])) $labels[] = $rule['label'];
    }
    if (!$labels) return 'Tuhle kombinaci služeb bohužel nejde objednat v jednom termínu – vyber je zvlášť.';
    return 'Tyto služby dělám ' . implode(' a ', $labels) . '.';
}

/** [minDate, maxDate] ve formátu Y-m-d podle limitů. */
function bookingWindow(): array {
    $l = bookingLimits();
    $min = (new DateTime('today'))->modify('+' . $l['minDaysAhead'] . ' days');
    $max = (new DateTime('today'))->modify('+' . $l['maxMonthsAhead'] . ' months');
    return [$min->format('Y-m-d'), $max->format('Y-m-d')];
}

/** Obsazené intervaly v daný den: rezervace, které drží termín, + blokace. */
function busyIntervals(string $date): array {
    $dayStart = $date . ' 00:00:00'; $dayEnd = $date . ' 23:59:59';
    $out = [];
    $st = db()->prepare("SELECT start_at, end_at FROM bookings WHERE status IN ('pending','confirmed','awaiting_deposit') AND start_at <= ? AND end_at >= ?");
    $st->execute([$dayEnd, $dayStart]);
    foreach ($st as $r) $out[] = [strtotime($r['start_at']), strtotime($r['end_at'])];
    $st = db()->prepare('SELECT start_at, end_at FROM blocked_slots WHERE start_at <= ? AND end_at >= ?');
    $st->execute([$dayEnd, $dayStart]);
    foreach ($st as $r) $out[] = [strtotime($r['start_at']), strtotime($r['end_at'])];
    return $out;
}

function overlaps(int $aStart, int $aEnd, array $intervals): bool {
    foreach ($intervals as [$bStart, $bEnd]) {
        if ($aStart < $bEnd && $aEnd > $bStart) return true;
    }
    return false;
}

/**
 * Volné začátky slotů v daný den pro sadu služeb a počet osob.
 * Vrací ['date'=>, 'slots'=>['15:30','16:00',…], 'reason'=>string|null]
 */
function availableSlots(string $date, array $serviceIds, int $persons): array {
    $res = resolveServices($serviceIds, $persons);
    if (!$res['ok']) return ['date' => $date, 'slots' => [], 'reason' => $res['error']];
    [$minDate, $maxDate] = bookingWindow();
    if ($date < $minDate || $date > $maxDate) return ['date' => $date, 'slots' => [], 'reason' => 'Termín musí být nejpozději 2 dny předem a nejdéle 2 měsíce dopředu.'];
    $rule = ruleForDay($date, $res['ids']);
    if (!$rule) return ['date' => $date, 'slots' => [], 'reason' => availabilityText($res['ids'])];

    $step  = bookingLimits()['slotStepMin'] * 60;
    $open  = strtotime("$date {$rule['from']}");
    $close = strtotime("$date {$rule['to']}");
    $dur   = $res['duration'] * 60;
    $busy  = busyIntervals($date);
    $slots = [];
    for ($t = $open; $t + $dur <= $close; $t += $step) {
        if (!overlaps($t, $t + $dur, $busy)) $slots[] = date('H:i', $t);
    }
    return ['date' => $date, 'slots' => $slots, 'reason' => $slots ? null : 'Tento den už je obsazený.'];
}

/** Pro kalendář: mapa dní v měsíci → true/false (je aspoň jeden volný slot). */
function availableDaysInMonth(int $year, int $month, array $serviceIds, int $persons): array {
    $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $out = [];
    for ($d = 1; $d <= $days; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $out[$date] = count(availableSlots($date, $serviceIds, $persons)['slots']) > 0;
    }
    return $out;
}

/** Je konkrétní slot stále volný? (kontrola před uložením rezervace) */
function slotIsFree(string $date, string $time, array $serviceIds, int $persons): bool {
    return in_array($time, availableSlots($date, $serviceIds, $persons)['slots'], true);
}
