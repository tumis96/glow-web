<?php
/**
 * E-maily (PHPMailer přes SMTP Active24). Odesílatel objednavky@, odpovědi na info@.
 * Zákaznici se v e-mailech VYKÁ (odsouhlasený text), Petře se tyká.
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once APP_ROOT . '/vendor/autoload.php';
require_once __DIR__ . '/ics.php';
require_once __DIR__ . '/payment.php';

function mailer(): PHPMailer {
    $m = new PHPMailer(true);
    $m->CharSet  = 'UTF-8';
    $m->Encoding = 'base64';
    $m->isSMTP();
    $m->Host       = cfg('smtp_host');
    $m->Port       = (int)cfg('smtp_port', 465);
    $m->SMTPSecure = cfg('smtp_secure', 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $m->SMTPAuth   = true;
    $m->Username   = cfg('smtp_user');
    $m->Password   = cfg('smtp_pass');
    $m->setFrom(cfg('mail_from'), cfg('mail_from_name', 'Glow by Petra Jahodová'));
    $m->addReplyTo(cfg('mail_reply_to', cfg('mail_from')), 'Petra Jahodová');
    $m->isHTML(true);
    return $m;
}

/** Přidání adresáta bez výjimky ven (neplatná adresa v configu nesmí shodit rezervaci). */
function addTo(PHPMailer $m, string $email, string $name): bool {
    try { $m->addAddress($email, $name); return true; } catch (Throwable $e) { logEvent('mail:address', null, $e->getMessage(), 'error'); return false; }
}

/** Bezpečné odeslání: chyba se zaloguje, aplikaci neshodí. */
function sendMail(PHPMailer $m, string $event, ?string $bookingId): bool {
    try {
        $m->send();
        logEvent('mail:' . $event, $bookingId, 'OK → ' . implode(', ', array_map(fn($a) => $a[0], $m->getToAddresses())));
        return true;
    } catch (Throwable $e) {
        logEvent('mail:' . $event, $bookingId, 'CHYBA: ' . $e->getMessage(), 'error');
        return false;
    }
}

// ---------- šablona ----------
function emailWrap(string $title, string $bodyHtml, string $preheader = ''): string {
    $site = siteUrl();
    return '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><title>' . h($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f6efec;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#3a3335;">'
        . ($preheader ? '<div style="display:none;max-height:0;overflow:hidden;">' . h($preheader) . '</div>' : '')
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6efec;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fffdfc;border-radius:16px;border:1px solid #e4d6d0;">'
        . '<tr><td style="padding:28px 28px 8px;font-family:Georgia,serif;font-size:14px;letter-spacing:.16em;text-transform:uppercase;color:#90625b;">Glow by Petra Jahodová</td></tr>'
        . '<tr><td style="padding:0 28px 8px;font-family:Georgia,serif;font-size:26px;line-height:1.2;color:#3a3335;">' . h($title) . '</td></tr>'
        . '<tr><td style="padding:8px 28px 28px;font-size:16px;line-height:1.6;">' . $bodyHtml . '</td></tr>'
        . '<tr><td style="padding:18px 28px;border-top:1px solid #e4d6d0;font-size:13px;color:#766b6f;line-height:1.5;">Petra Jahodová · Lichnov 125, 742 75 Lichnov · +420 604 643 157 · <a href="mailto:info@glowbypetra.cz" style="color:#90625b;">info@glowbypetra.cz</a><br><a href="' . h($site) . '" style="color:#90625b;">glowbypetra.cz</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}
function emailButton(string $url, string $label, string $bg = '#3a3335'): string {
    return '<p style="margin:20px 0;"><a href="' . h($url) . '" style="display:inline-block;padding:12px 22px;background:' . $bg . ';color:#ffffff;text-decoration:none;border-radius:999px;font-weight:600;letter-spacing:.06em;">' . h($label) . '</a></p>';
}
function emailRows(array $rows): string {
    $out = '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:14px 0;border-collapse:collapse;width:100%;">';
    foreach ($rows as $k => $v) {
        if ($v === null || $v === '') continue;
        $out .= '<tr><td style="padding:6px 10px 6px 0;color:#766b6f;font-size:14px;white-space:nowrap;vertical-align:top;">' . h($k) . '</td><td style="padding:6px 0;font-size:15px;">' . nl2br(h((string)$v)) . '</td></tr>';
    }
    return $out . '</table>';
}
function bookingRows(array $b): array {
    $rows = ['Služba' => $b['service_label']];
    if ($b['kind'] === 'wedding') {
        $rows['Datum svatby'] = czDate($b['start_at']);
        $rows['Místo']        = $b['wedding_place'] ?? '';
        $rows['Obřad']        = !empty($b['ceremony_time']) ? substr($b['ceremony_time'], 0, 5) : '';
        $rows['Hotovo do']    = !empty($b['ready_by']) ? substr($b['ready_by'], 0, 5) : '';
        $rows['Líčení / účes'] = (int)($b['persons_makeup'] ?? 0) . ' / ' . (int)($b['persons_hair'] ?? 0) . ' osob';
        $rows['Zkouška líčení'] = isset($b['trial_interest']) ? ((int)$b['trial_interest'] ? 'ano' : 'ne') : '';
    } else {
        $rows['Datum']  = czDate($b['start_at']);
        $rows['Čas']    = czTime($b['start_at']) . '–' . czTime($b['end_at']);
        $rows['Osob']   = (string)(int)$b['persons'];
        $rows['Cena']   = $b['price_label'] ?? '';
    }
    if (!empty($b['note'])) $rows['Poznámka'] = $b['note'];
    return $rows;
}
function contactRows(array $b): array {
    return ['Jméno' => $b['customer_name'], 'Telefon' => $b['customer_phone'], 'E-mail' => $b['customer_email']];
}
function cancelUrl(array $b): string { return siteUrl('api/cancel.php?id=' . $b['id'] . '&token=' . $b['cancel_token']); }
function decideUrl(array $b, string $action): string { return siteUrl('api/decide.php?id=' . $b['id'] . '&token=' . $b['decide_token'] . '&action=' . $action); }
function adminUrl(array $b): string { return siteUrl('admin/index.php?id=' . $b['id']); }

// ---------- zákaznici ----------
function sendCustomerReceived(array $b): bool {
    $m = mailer();
    if (($b['customer_email'] ?? '') === NO_EMAIL) return true;   // ruční zápis bez e-mailu – neposílat
    if (!addTo($m, (string)$b['customer_email'], (string)$b['customer_name'])) return false;
    $m->Subject = 'Vaše poptávka byla přijata – Glow by Petra Jahodová';
    $body = '<p>Vaše poptávka byla přijata.</p>' . emailRows(bookingRows($b))
        . '<p>Brzy vás budu kontaktovat. Jakmile termín potvrdím, přijde vám e-mail s pozvánkou do kalendáře.</p>'
        . '<p style="color:#766b6f;font-size:14px;">Pokud jste si to rozmyslela, můžete poptávku zrušit zde: <a href="' . h(cancelUrl($b)) . '" style="color:#90625b;">zrušit poptávku</a>.</p>';
    $m->Body    = emailWrap('Poptávka přijata', $body, 'Datum, služba, čas – brzy vás budu kontaktovat.');
    $m->AltBody = "Vaše poptávka byla přijata.\n" . altRows(bookingRows($b)) . "\nBrzy vás budu kontaktovat.\nZrušit: " . cancelUrl($b);
    return sendMail($m, 'customer_received', $b['id']);
}

function sendCustomerConfirmed(array $b): bool {
    $m = mailer();
    if (($b['customer_email'] ?? '') === NO_EMAIL) return true;   // ruční zápis bez e-mailu – neposílat
    if (!addTo($m, (string)$b['customer_email'], (string)$b['customer_name'])) return false;
    $m->Subject = 'Termín potvrzen – ' . czDate($b['start_at']) . ($b['kind'] === 'wedding' ? '' : ' v ' . czTime($b['start_at']));
    $where = $b['kind'] === 'wedding' ? '' : '<p>Těším se na vás v salonu: <strong>Lichnov 125, 742 75 Lichnov</strong> (vchod od hlavní cesty, parkování u kostela nebo u obecního úřadu). Přijďte prosím odlíčená.</p>';
    $cancel = $b['kind'] === 'wedding' ? '' : '<p style="color:#766b6f;font-size:14px;">Zrušení je zdarma nejpozději den předem: <a href="' . h(cancelUrl($b)) . '" style="color:#90625b;">zrušit rezervaci</a>. V den termínu už prosím volejte, účtuji storno 300 Kč.</p>';
    $body = '<p>Váš termín je potvrzený. V příloze máte pozvánku do kalendáře.</p>' . emailRows(bookingRows($b)) . $where . $cancel;
    $m->Body    = emailWrap('Termín potvrzen', $body, 'Váš termín je potvrzený, pozvánka do kalendáře je v příloze.');
    $m->AltBody = "Váš termín je potvrzený.\n" . altRows(bookingRows($b));
    $m->addStringAttachment(buildIcs($b), 'rezervace-glow.ics', PHPMailer::ENCODING_BASE64, 'text/calendar; method=PUBLISH');
    return sendMail($m, 'customer_confirmed', $b['id']);
}

function sendCustomerRejected(array $b): bool {
    $m = mailer();
    if (($b['customer_email'] ?? '') === NO_EMAIL) return true;   // ruční zápis bez e-mailu – neposílat
    if (!addTo($m, (string)$b['customer_email'], (string)$b['customer_name'])) return false;
    $m->Subject = 'Termín bohužel nemohu potvrdit – Glow by Petra Jahodová';
    $reason = trim((string)($b['reject_reason'] ?? ''));
    $body = '<p>Mrzí mě to, ale tento termín nemohu potvrdit.</p>' . emailRows(bookingRows($b))
        . ($reason ? '<p><strong>Vzkaz od Petry:</strong><br>' . nl2br(h($reason)) . '</p>' : '')
        . '<p>Vyberte si prosím jiný termín, nebo mi napište či zavolejte, domluvíme se.</p>' . emailButton(siteUrl('rezervace.html'), 'Vybrat jiný termín');
    $m->Body    = emailWrap('Termín nemohu potvrdit', $body);
    $m->AltBody = "Tento termín bohužel nemohu potvrdit.\n" . ($reason ? "Vzkaz: $reason\n" : '') . 'Vyberte prosím jiný: ' . siteUrl('rezervace.html');
    return sendMail($m, 'customer_rejected', $b['id']);
}

function sendCustomerCancelled(array $b): bool {
    $m = mailer();
    if (($b['customer_email'] ?? '') === NO_EMAIL) return true;   // ruční zápis bez e-mailu – neposílat
    if (!addTo($m, (string)$b['customer_email'], (string)$b['customer_name'])) return false;
    $m->Subject = 'Rezervace zrušena – Glow by Petra Jahodová';
    $body = '<p>Vaše rezervace byla zrušena.</p>' . emailRows(bookingRows($b)) . '<p>Kdykoliv se můžete objednat znovu.</p>' . emailButton(siteUrl('rezervace.html'), 'Nová rezervace');
    $m->Body    = emailWrap('Rezervace zrušena', $body);
    $m->AltBody = "Vaše rezervace byla zrušena.\n" . altRows(bookingRows($b));
    return sendMail($m, 'customer_cancelled', $b['id']);
}

function sendCustomerReminder(array $b): bool {
    $m = mailer();
    if (($b['customer_email'] ?? '') === NO_EMAIL) return true;   // ruční zápis bez e-mailu – neposílat
    if (!addTo($m, (string)$b['customer_email'], (string)$b['customer_name'])) return false;
    $m->Subject = 'Připomínka: zítra ' . czTime($b['start_at']) . ' – Glow by Petra Jahodová';
    $body = '<p>Jen připomínám zítřejší termín.</p>' . emailRows(bookingRows($b))
        . '<p>Salon: <strong>Lichnov 125, 742 75 Lichnov</strong>. Přijďte prosím odlíčená.</p>'
        . '<p style="color:#766b6f;font-size:14px;">Kdyby se něco změnilo, zavolejte prosím na +420 604 643 157.</p>';
    $m->Body    = emailWrap('Zítra se těším', $body);
    $m->AltBody = "Připomínka zítřejšího termínu.\n" . altRows(bookingRows($b));
    return sendMail($m, 'customer_reminder', $b['id']);
}

// ---------- svatby ----------
function depositHtml(array $b): string {
    $d = depositInfo($b);
    $html = '<p>Termín svatby si zarezervujete zálohou <strong>' . czPrice($d['amount']) . '</strong> do <strong>' . czDate($d['due'] . ' 00:00:00') . '</strong>. Záloha je zároveň cenou zkoušky líčení a odečítá se z výsledné ceny.</p>'
        . emailRows(['Číslo účtu' => $d['account'], 'Částka' => czPrice($d['amount']), 'Variabilní symbol' => $d['vs'], 'Zpráva pro příjemce' => $d['message']]);
    if ($d['spayd']) $html .= emailButton($d['qr_url'], 'Zobrazit QR kód k platbě', '#90625b');
    $html .= '<p style="color:#766b6f;font-size:14px;">Jakmile záloha přijde na účet, termín vám potvrdím e-mailem. Pokud záloha do uvedeného data nepřijde, ozvu se vám – termín do té doby držím jen předběžně.</p>';
    return $html;
}

function sendWeddingReceived(array $b): bool {
    $m = mailer();
    if (($b['customer_email'] ?? '') === NO_EMAIL) return true;   // ruční zápis bez e-mailu – neposílat
    if (!addTo($m, (string)$b['customer_email'], (string)$b['customer_name'])) return false;
    $m->Subject = 'Svatební poptávka přijata – Glow by Petra Jahodová';
    $body = '<p>Děkuji za poptávku svatebního líčení.</p>' . emailRows(bookingRows($b)) . depositHtml($b);
    $m->Body    = emailWrap('Svatební poptávka přijata', $body, 'Termín si zarezervujete zálohou 1 000 Kč.');
    $d = depositInfo($b);
    $m->AltBody = "Děkuji za poptávku svatebního líčení.\n" . altRows(bookingRows($b)) . "\nZáloha {$d['amount']} Kč na účet {$d['account']}, VS {$d['vs']}, do {$d['due']}.";
    return sendMail($m, 'wedding_received', $b['id']);
}

function sendWeddingConfirmed(array $b): bool {
    $m = mailer();
    if (($b['customer_email'] ?? '') === NO_EMAIL) return true;   // ruční zápis bez e-mailu – neposílat
    if (!addTo($m, (string)$b['customer_email'], (string)$b['customer_name'])) return false;
    $m->Subject = 'Svatební termín potvrzen – ' . czDate($b['start_at']);
    $body = '<p>Záloha dorazila a váš svatební termín je potvrzený. V příloze máte pozvánku do kalendáře.</p>' . emailRows(bookingRows($b))
        . '<p>Ozvu se vám kvůli domluvě zkoušky líčení (ideálně 1–3 měsíce před svatbou). Kdykoliv mi napište na info@glowbypetra.cz nebo zavolejte.</p>';
    $m->Body    = emailWrap('Svatební termín potvrzen', $body);
    $m->AltBody = "Váš svatební termín je potvrzený.\n" . altRows(bookingRows($b));
    $m->addStringAttachment(buildIcs($b), 'svatba-glow.ics', PHPMailer::ENCODING_BASE64, 'text/calendar; method=PUBLISH');
    return sendMail($m, 'wedding_confirmed', $b['id']);
}

// ---------- Petře ----------
function sendOwnerNewBooking(array $b): bool {
    $m = mailer();
    if (!addTo($m, (string)cfg('mail_petra'), 'Petra')) return false;
    $m->Subject = 'Nová objednávka: ' . $b['customer_name'] . ' – ' . czDate($b['start_at']) . ' ' . czTime($b['start_at']);
    $body = '<p>Přišla ti nová objednávka. Termín je zatím držený jako <strong>čekající</strong>.</p>'
        . emailRows(bookingRows($b)) . emailRows(contactRows($b))
        . '<p>' . emailButton(decideUrl($b, 'confirm'), 'Potvrdit termín') . emailButton(decideUrl($b, 'reject'), 'Odmítnout', '#90625b') . '</p>'
        . '<p style="color:#766b6f;font-size:14px;">Nebo otevři <a href="' . h(adminUrl($b)) . '" style="color:#90625b;">administraci</a>.</p>';
    $m->Body    = emailWrap('Nová objednávka', $body);
    $m->AltBody = "Nová objednávka\n" . altRows(bookingRows($b)) . altRows(contactRows($b)) . "\nPotvrdit: " . decideUrl($b, 'confirm') . "\nOdmítnout: " . decideUrl($b, 'reject');
    return sendMail($m, 'owner_new', $b['id']);
}

function sendOwnerNewWedding(array $b): bool {
    $m = mailer();
    if (!addTo($m, (string)cfg('mail_petra'), 'Petra')) return false;
    $m->Subject = 'Svatební poptávka: ' . $b['customer_name'] . ' – ' . czDate($b['start_at']);
    $d = depositInfo($b);
    $body = '<p>Přišla svatební poptávka. Zákaznice dostala údaje k záloze (VS <strong>' . h($d['vs']) . '</strong>, ' . czPrice($d['amount']) . ', do ' . czDate($d['due'] . ' 00:00:00') . '). Den D je zatím držený.</p>'
        . emailRows(bookingRows($b)) . emailRows(contactRows($b))
        . '<p>Až záloha přijde na účet, klikni:</p>' . emailButton(decideUrl($b, 'deposit'), 'Záloha přijata – potvrdit termín')
        . emailButton(decideUrl($b, 'reject'), 'Odmítnout', '#90625b')
        . '<p style="color:#766b6f;font-size:14px;"><a href="' . h(adminUrl($b)) . '" style="color:#90625b;">Otevřít v administraci</a></p>';
    $m->Body    = emailWrap('Svatební poptávka', $body);
    $m->AltBody = "Svatební poptávka\n" . altRows(bookingRows($b)) . altRows(contactRows($b)) . "\nVS {$d['vs']}\nZáloha přijata: " . decideUrl($b, 'deposit');
    return sendMail($m, 'owner_new_wedding', $b['id']);
}

function sendOwnerCancelled(array $b): bool {
    $m = mailer();
    if (!addTo($m, (string)cfg('mail_petra'), 'Petra')) return false;
    $m->Subject = 'Zrušeno zákaznicí: ' . $b['customer_name'] . ' – ' . czDate($b['start_at']);
    $body = '<p>Zákaznice zrušila rezervaci, termín je zase volný.</p>' . emailRows(bookingRows($b)) . emailRows(contactRows($b));
    $m->Body    = emailWrap('Rezervace zrušena', $body);
    $m->AltBody = "Zákaznice zrušila rezervaci.\n" . altRows(bookingRows($b));
    return sendMail($m, 'owner_cancelled', $b['id']);
}

/** Připomínka Petře: nepotvrzená objednávka (24 h) nebo nezaplacená záloha (3 dny). */
function sendOwnerNudge(array $b, string $why): bool {
    $m = mailer();
    if (!addTo($m, (string)cfg('mail_petra'), 'Petra')) return false;
    $m->Subject = 'Připomínka: ' . $why . ' – ' . $b['customer_name'];
    $isWedding = $b['kind'] === 'wedding';
    $body = '<p>' . h($why) . '. Zákaznice čeká na tvou reakci.</p>' . emailRows(bookingRows($b)) . emailRows(contactRows($b))
        . ($isWedding
            ? emailButton(decideUrl($b, 'deposit'), 'Záloha přijata – potvrdit') . emailButton(decideUrl($b, 'reject'), 'Odmítnout / uvolnit termín', '#90625b')
            : emailButton(decideUrl($b, 'confirm'), 'Potvrdit termín') . emailButton(decideUrl($b, 'reject'), 'Odmítnout', '#90625b'));
    $m->Body    = emailWrap('Připomínka', $body);
    $m->AltBody = "$why\n" . altRows(bookingRows($b)) . altRows(contactRows($b));
    return sendMail($m, 'owner_nudge', $b['id']);
}

function altRows(array $rows): string {
    $out = '';
    foreach ($rows as $k => $v) { if ($v !== null && $v !== '') $out .= "$k: $v\n"; }
    return $out;
}
