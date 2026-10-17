<?php
/**
 * POST /api/bookings.php  (JSON nebo formulář)
 * { services: ["denni"], persons: 1, date: "2026-11-10", time: "15:30", name, email, phone, note, gdpr: true, website: "" }
 * → { ok: true, id, summary: {…} }   |   409 když je termín mezitím obsazený
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bookings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Použijte POST.', 405);
$in = jsonInput();
if (!empty($in['website'])) jsonOut(['ok' => true, 'id' => uuid()]);   // honeypot – robot vyplnil skryté pole
rateLimit('bookings', 8, 600);

$r = createBooking(array_merge($in, ['source' => 'web', 'notify' => false]));   // klient nesmí podstrčit source=admin
if (!$r['ok']) jsonError($r['error'], $r['code'] ?? 400);
$b = $r['booking'];
jsonOut(['ok' => true, 'id' => $b['id'], 'summary' => [
    'service' => $b['service_label'], 'date' => czDate($b['start_at']), 'time' => czTime($b['start_at']),
    'persons' => $b['persons'], 'price' => $b['price_label'], 'duration' => $b['duration_min'],
]]);
