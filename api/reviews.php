<?php
/**
 * GET  /api/reviews.php            → { ok: true, reviews: [ { id, name, service, rating, text, when } ] }  (jen schválené, nejnovější první)
 * POST /api/reviews.php  (JSON)    { name, email?, service?, rating 1–5, text, gdpr: true, website: "" }
 *                                  → { ok: true }  |  { ok: false, error } se stavem 400 / 429 / 500
 * Nová recenze se uloží jako čekající a Petře odejde e-mail s tlačítky Schválit / Nezveřejňovat.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reviews.php';

reviewsEnsureSchema();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonOut(['ok' => true, 'reviews' => array_map('reviewPublic', reviewsApproved())]);
}
if ($method !== 'POST') jsonError('Použijte GET nebo POST.', 405);

$in = jsonInput();
if (!empty($in['website'])) jsonOut(['ok' => true]);   // honeypot – robot vyplnil skryté pole
rateLimit('review', 3, 3600);

try {
    $data = reviewValidate($in);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage());
}

$r = reviewCreate($data);
mailOwnerNewReview($r);   // když e-mail selže, recenze stejně čeká v adminu – chyba je v logu
jsonOut(['ok' => true]);
