<?php
/**
 * GET /api/wedding-availability.php?month=2027-06
 * → { ok: true, month, days: { 'YYYY-MM-DD': 'free'|'taken'|'past' }, minDate, maxDate }
 * Kalendář svatebních termínů na stránce Svatby. „taken“ = ten den už je svatba (čeká na zálohu / potvrzená) nebo blokace.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/slots.php';

const WEDDING_MONTHS_AHEAD = 24;

if (!preg_match('/^(\d{4})-(\d{2})$/', (string)($_GET['month'] ?? ''), $m) || (int)$m[2] < 1 || (int)$m[2] > 12) jsonError('Neplatný měsíc.');
$min = date('Y-m-d', strtotime('tomorrow'));
$max = (new DateTime('today'))->modify('+' . WEDDING_MONTHS_AHEAD . ' months')->format('Y-m-d');
$ym  = $m[1] . '-' . $m[2];
if ($ym < substr($min, 0, 7) || $ym > substr($max, 0, 7)) jsonError('Měsíc je mimo rozsah, ve kterém jde termín poptat.');

jsonOut(['ok' => true, 'month' => $ym, 'days' => weddingDaysInMonth((int)$m[1], (int)$m[2]), 'minDate' => $min, 'maxDate' => $max]);
