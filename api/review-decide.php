<?php
/**
 * GET/POST /api/review-decide.php?id=…&token=…&action=approve|reject – schválení recenze Petrou z e-mailu (bez přihlášení).
 * GET ukáže recenzi a tlačítko, POST provede změnu.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reviews.php';
require_once __DIR__ . '/../includes/page.php';

reviewsEnsureSchema();
$r      = loadReview((int)($_REQUEST['id'] ?? 0));
$token  = (string)($_REQUEST['token'] ?? '');
$action = (string)($_REQUEST['action'] ?? '');
if (!$r || !hash_equals($r['decide_token'], $token)) renderPage('Odkaz není platný', '<p class="lead">Recenze nebyla nalezena nebo odkaz vypršel.</p>', 'Administrace');

$box = '<div class="notice mt-3"><strong>' . h($r['name']) . '</strong>' . ($r['service'] ? ' · ' . h($r['service']) : '') . ' · ' . h(reviewStars((int)$r['rating'])) . '<br><em>' . nl2br(h($r['text'])) . '</em>'
    . ($r['email'] ? '<br><span class="muted">' . h($r['email']) . ' (nezveřejňuje se)</span>' : '') . '</div>';
$adminLink = '<p class="mt-3"><a class="link-arrow" href="' . h(siteUrl('admin/reviews.php')) . '">Otevřít recenze v administraci</a></p>';
$hidden = '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="token" value="' . h($token) . '">';

if ($action === 'approve') {
    if ($r['status'] === 'approved') renderPage('Recenze už je zveřejněná', '<p class="lead">Není co měnit.</p>' . $box . $adminLink, 'Administrace');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        reviewSetStatus($r, 'approved', 'e-mail');
        renderPage('Zveřejněno', '<p class="lead">Recenze je teď vidět na úvodní stránce webu.</p>' . $box . $adminLink, 'Administrace');
    }
    renderPage('Zveřejnit tuhle recenzi?', $box . '<form method="post">' . $hidden . '<input type="hidden" name="action" value="approve">'
        . '<div class="hero-actions hero-actions--start"><button class="btn btn-primary" type="submit">Ano, zveřejnit</button><a class="btn btn-outline" href="' . h(reviewDecideUrl($r, 'reject')) . '">Raději nezveřejňovat</a></div></form>'
        . '<p class="form-note mt-3">Chceš text nejdřív zkrátit nebo opravit? Uprav ho v administraci a schval tam.</p>', 'Administrace');
}

if ($action === 'reject') {
    if ($r['status'] === 'rejected') renderPage('Recenze je odložená', '<p class="lead">Už je označená jako nezveřejněná.</p>' . $box . $adminLink, 'Administrace');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        reviewSetStatus($r, 'rejected', 'e-mail');
        renderPage('Nezveřejněno', '<p class="lead">Recenze se na webu neukáže. V administraci ji najdeš mezi nezveřejněnými, kdyby sis to rozmyslela.</p>' . $box . $adminLink, 'Administrace');
    }
    renderPage('Nezveřejňovat tuhle recenzi?', $box . '<form method="post">' . $hidden . '<input type="hidden" name="action" value="reject">'
        . '<div class="hero-actions hero-actions--start"><button class="btn btn-primary" type="submit">Ano, nezveřejňovat</button><a class="btn btn-outline" href="' . h(reviewDecideUrl($r, 'approve')) . '">Zpět na zveřejnění</a></div></form>', 'Administrace');
}
renderPage('Neznámá akce', '<p class="lead">Odkaz je neúplný.</p>' . $adminLink, 'Administrace');
