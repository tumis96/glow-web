<?php
/**
 * POST /api/wedding.php
 * { name, phone, email, date (Y-m-d), place, ceremony_time "HH:MM", ready_by "HH:MM", persons_makeup, persons_hair, trial: bool, note, gdpr: true, website: "" }
 * → { ok: true, id, number (číslo poptávky), summary: {date, place, name} }
 * Bez zálohy – poptávka čeká na potvrzení Petrou (stejně jako běžná rezervace).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bookings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Použijte POST.', 405);
$in = jsonInput();
if (!empty($in['website'])) jsonOut(['ok' => true, 'id' => uuid(), 'number' => '0']);
rateLimit('wedding', 5, 600);

$r = createWedding($in);
if (!$r['ok']) jsonError($r['error'], $r['code'] ?? 400);
$b = $r['booking'];
jsonOut(['ok' => true, 'id' => $b['id'], 'number' => $b['deposit_vs'],
    'summary' => ['date' => czDate($b['start_at']), 'place' => $b['wedding_place'], 'name' => $b['customer_name']],
]);
