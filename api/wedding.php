<?php
/**
 * POST /api/wedding.php
 * { name, phone, email, date (Y-m-d), place, ceremony_time "HH:MM", ready_by "HH:MM", persons_makeup, persons_hair, trial: bool, note, gdpr: true, website: "" }
 * → { ok: true, id, vs, deposit: {amount, account, vs, due, qr_url|null}, summary }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bookings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Použijte POST.', 405);
$in = jsonInput();
if (!empty($in['website'])) jsonOut(['ok' => true, 'id' => uuid(), 'vs' => '0']);
rateLimit('wedding', 5, 600);

$r = createWedding($in);
if (!$r['ok']) jsonError($r['error'], $r['code'] ?? 400);
$b = $r['booking'];
$d = depositInfo($b);
jsonOut(['ok' => true, 'id' => $b['id'], 'vs' => $b['deposit_vs'],
    'deposit' => ['amount' => $d['amount'], 'account' => $d['account'], 'vs' => $d['vs'], 'message' => $d['message'], 'due' => czDate($d['due'] . ' 00:00:00'), 'qr_url' => $d['spayd'] ? $d['qr_url'] : null, 'spayd' => $d['spayd']],
    'summary' => ['date' => czDate($b['start_at']), 'place' => $b['wedding_place'], 'name' => $b['customer_name']],
]);
