<?php
/** GET /api/cancel.php?id=…&token=… – zrušení rezervace zákaznicí (odkaz z e-mailu). Zobrazí potvrzení, zruší až po kliknutí. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/page.php';

$b = loadBooking((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!$b || !hash_equals($b['cancel_token'], $token)) {
    renderPage('Rezervace nenalezena', '<p class="lead">Odkaz není platný. Pokud chcete rezervaci zrušit, zavolejte prosím na +420 604 643 157.</p>');
}
$rows = '<div class="notice mt-3"><strong>' . h($b['service_label']) . '</strong><br>' . h(czDate($b['start_at'])) . ($b['kind'] === 'wedding' ? '' : ' v ' . h(czTime($b['start_at']))) . '</div>';

if (in_array($b['status'], ['cancelled', 'rejected'], true)) {
    renderPage('Rezervace už je zrušená', '<p class="lead">Tato rezervace už neplatí.</p>' . $rows . '<p class="mt-3"><a class="btn btn-primary" href="' . h(siteUrl('rezervace.html')) . '">Nová rezervace</a></p>');
}
if (!customerCanCancel($b)) {
    renderPage('Zrušení už není možné online', '<p class="lead">Online zrušení je možné nejpozději den před termínem. Zavolejte prosím na <strong>+420 604 643 157</strong>.</p>' . $rows);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    cancelBooking($b, 'customer');
    renderPage('Rezervace zrušena', '<p class="lead">Vaše rezervace je zrušená. Petra o tom dostala zprávu.</p>' . $rows . '<p class="mt-3"><a class="btn btn-primary" href="' . h(siteUrl('rezervace.html')) . '">Vybrat nový termín</a></p>');
}
renderPage('Zrušit rezervaci?', '<p class="lead">Opravdu chcete zrušit tuto rezervaci? Termín se uvolní pro ostatní.</p>' . $rows
    . '<form method="post"><input type="hidden" name="id" value="' . h($b['id']) . '"><input type="hidden" name="token" value="' . h($token) . '">'
    . '<div class="hero-actions hero-actions--start"><button class="btn btn-primary" type="submit">Ano, zrušit</button><a class="btn btn-outline" href="' . h(siteUrl()) . '">Ne, nechat</a></div></form>');
