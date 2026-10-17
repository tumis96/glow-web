<?php
/**
 * POST /api/contact.php  (JSON nebo formulář)
 * { name, email, phone, message, gdpr: true, website: "" }
 * → { ok: true }   |   { ok: false, error } se stavem 400 / 429 / 500
 *
 * Kontaktní formulář z kontakt.html: zprávu pošle Petře (cfg mail_petra) s Reply-To na odesílatelku.
 * Nic se neukládá do bookings, jen záznam do logu.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mail.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Použijte POST.', 405);
$in = jsonInput();
if (!empty($in['website'])) jsonOut(['ok' => true]);   // honeypot – robot vyplnil skryté pole, tváříme se, že je vše OK
rateLimit('contact', 5, 600);

$name    = trim((string)($in['name'] ?? ''));
$email   = trim((string)($in['email'] ?? ''));
$phone   = trim((string)($in['phone'] ?? ''));
$message = trim(str_replace("\r\n", "\n", (string)($in['message'] ?? '')));

if (mb_strlen($name) < 2 || mb_strlen($name) > 120) jsonError('Zadejte prosím své jméno.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) jsonError('Zadejte prosím platný e-mail.');
if ($phone !== '' && !preg_match('/^\+?[\d\s\-().]{9,20}$/', $phone)) jsonError('Telefon nevypadá správně, zkontrolujte ho prosím.');
if (mb_strlen($message) < 5) jsonError('Napište prosím pár slov, s čím můžeme pomoci.');
if (mb_strlen($message) > 5000) jsonError('Zpráva je příliš dlouhá (max. 5 000 znaků).');
if (empty($in['gdpr'])) jsonError('Je potřeba souhlas se zpracováním údajů.');
// hlavičky e-mailu nesmí obsahovat nové řádky (ochrana proti header injection)
if (preg_match('/[\r\n]/', $name . $email . $phone)) jsonError('Neplatné znaky ve jménu nebo kontaktu.');

$m = mailer();
if (!addTo($m, (string)cfg('mail_petra'), 'Petra')) jsonError('Zprávu se nepodařilo odeslat.', 500);
$m->clearReplyTos();
$m->addReplyTo($email, $name);   // „Odpovědět“ v poště jde rovnou odesílatelce
$m->Subject = 'Zpráva z webu: ' . $name;

$rows = ['Jméno' => $name, 'E-mail' => $email, 'Telefon' => $phone !== '' ? $phone : null, 'Zpráva' => $message];
$body = '<p>Přišla ti zpráva z kontaktního formuláře na webu. Stačí odpovědět na tento e-mail, odpověď půjde přímo odesílatelce.</p>' . emailRows($rows);
$m->Body    = emailWrap('Zpráva z webu', $body, mb_substr($message, 0, 90));
$m->AltBody = "Zpráva z kontaktního formuláře\n" . altRows($rows);

if (!sendMail($m, 'contact', null)) {
    jsonError('Zprávu se nepodařilo odeslat. Zkuste to prosím znovu, nebo zavolejte na +420 604 643 157.', 500);
}
logEvent('contact:received', null, $name . ' <' . $email . '>');
jsonOut(['ok' => true]);
