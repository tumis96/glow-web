<?php
/**
 * GET /api/availability.php?services=denni,uces-jednoduchy&persons=1&date=2026-11-10   → volné časy v daný den
 * GET /api/availability.php?services=…&persons=1&month=2026-11                          → které dny v měsíci mají volno
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/slots.php';

$services = array_filter(explode(',', (string)($_GET['services'] ?? '')));
$persons  = max(1, (int)($_GET['persons'] ?? 1));
$res = resolveServices($services, $persons);
if (!$res['ok']) jsonError($res['error']);

if (!empty($_GET['month'])) {
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$_GET['month'], $m)) jsonError('Neplatný měsíc.');
    $days = availableDaysInMonth((int)$m[1], (int)$m[2], $res['ids'], $persons);
    [$min, $max] = bookingWindow();
    jsonOut(['ok' => true, 'month' => $_GET['month'], 'days' => $days, 'minDate' => $min, 'maxDate' => $max, 'hint' => availabilityText($res['ids'])]);
}

$date = (string)($_GET['date'] ?? '');
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) jsonError('Neplatné datum.');
$out = availableSlots($date, $res['ids'], $persons);
jsonOut(['ok' => true] + $out + ['duration' => $res['duration'], 'price' => $res['price'], 'priceLabel' => czPrice($res['price'], $res['priceFrom']), 'label' => $res['label']]);
