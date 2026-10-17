<?php
/**
 * GET/POST /api/decide.php?id=…&token=…&action=confirm|reject|deposit – rozhodnutí Petry z e-mailu (bez přihlášení).
 * confirm = potvrdit běžnou rezervaci, deposit = záloha přijata (svatba), reject = odmítnout (s důvodem).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/page.php';

$b = loadBooking((string)($_REQUEST['id'] ?? ''));
$token  = (string)($_REQUEST['token'] ?? '');
$action = (string)($_REQUEST['action'] ?? '');
if (!$b || !hash_equals($b['decide_token'], $token)) renderPage('Odkaz není platný', '<p class="lead">Rezervace nebyla nalezena nebo odkaz vypršel.</p>', 'Administrace');

$rows = '<div class="notice mt-3"><strong>' . h($b['customer_name']) . '</strong> · ' . h($b['customer_phone']) . ' · ' . h($b['customer_email']) . '<br>'
    . h($b['service_label']) . ' · ' . h(czDate($b['start_at'])) . ($b['kind'] === 'wedding' ? '' : ' ' . h(czTime($b['start_at'])) . '–' . h(czTime($b['end_at'])))
    . (!empty($b['note']) ? '<br><em>' . h($b['note']) . '</em>' : '') . '</div>';
$adminLink = '<p class="mt-3"><a class="link-arrow" href="' . h(siteUrl('admin/index.php')) . '">Otevřít administraci</a></p>';

if (!in_array($b['status'], ['pending', 'awaiting_deposit'], true)) {
    $map = ['confirmed' => 'už je potvrzená', 'rejected' => 'už byla odmítnuta', 'cancelled' => 'byla zrušena'];
    renderPage('Rezervace ' . ($map[$b['status']] ?? $b['status']), '<p class="lead">Není co měnit.</p>' . $rows . $adminLink, 'Administrace');
}

if ($action === 'confirm' || $action === 'deposit') {
    if ($action === 'deposit' && $b['kind'] !== 'wedding') renderPage('Neplatná akce', '<p class="lead">Tohle není svatební poptávka.</p>', 'Administrace');
    if ($action === 'confirm' && $b['kind'] === 'wedding') $action = 'deposit';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        confirmBooking($b);
        renderPage('Potvrzeno', '<p class="lead">Zákaznici odešel e-mail s potvrzením a pozvánkou do kalendáře' . (gcalEnabled() ? ', termín je v Google Kalendáři' : '') . '.</p>' . $rows . $adminLink, 'Administrace');
    }
    $q = $action === 'deposit' ? 'Záloha přišla na účet a termín potvrzuješ?' : 'Potvrdit tenhle termín?';
    renderPage($q, $rows . '<form method="post"><input type="hidden" name="id" value="' . h($b['id']) . '"><input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="action" value="' . h($action) . '">'
        . '<div class="hero-actions hero-actions--start"><button class="btn btn-primary" type="submit">Ano, potvrdit</button><a class="btn btn-outline" href="' . h(decideUrl($b, 'reject')) . '">Raději odmítnout</a></div></form>', 'Administrace');
}

if ($action === 'reject') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        rejectBooking($b, trim((string)($_POST['reason'] ?? '')));
        renderPage('Odmítnuto', '<p class="lead">Zákaznici odešel e-mail, termín je zase volný.</p>' . $rows . $adminLink, 'Administrace');
    }
    renderPage('Odmítnout rezervaci', $rows . '<form method="post" class="form"><input type="hidden" name="id" value="' . h($b['id']) . '"><input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="action" value="reject">'
        . '<div class="field"><label for="reason">Vzkaz pro zákaznici (nepovinné)</label><textarea id="reason" name="reason" placeholder="Např. v tenhle čas už nemůžu, zkuste od 16:00…"></textarea><span class="hint">Pošle se jí v e-mailu spolu s odkazem na nový výběr termínu.</span></div>'
        . '<div class="hero-actions hero-actions--start"><button class="btn btn-primary" type="submit">Odmítnout a odeslat</button><a class="btn btn-outline" href="' . h(decideUrl($b, $b['kind'] === 'wedding' ? 'deposit' : 'confirm')) . '">Zpět na potvrzení</a></div></form>', 'Administrace');
}
renderPage('Neznámá akce', '<p class="lead">Odkaz je neúplný.</p>' . $adminLink, 'Administrace');
