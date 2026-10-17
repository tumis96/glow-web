<?php
/** GET /api/spayd.php?id=…&t=… → údaje k platbě zálohy (pro platba.html, QR se kreslí v prohlížeči). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bookings.php';

$b = loadBooking((string)($_GET['id'] ?? ''));
if (!$b || $b['kind'] !== 'wedding' || !hash_equals(substr($b['cancel_token'], 0, 12), (string)($_GET['t'] ?? ''))) jsonError('Platba nenalezena.', 404);
$d = depositInfo($b);
jsonOut(['ok' => true, 'status' => $b['status'], 'paid' => $b['status'] === 'confirmed', 'name' => $b['customer_name'], 'date' => czDate($b['start_at']),
    'amount' => $d['amount'], 'account' => $d['account'], 'vs' => $d['vs'], 'message' => $d['message'], 'due' => czDate($d['due'] . ' 00:00:00'), 'spayd' => $d['spayd']]);
