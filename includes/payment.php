<?php
/**
 * Platba zálohy QR kódem (český standard SPAYD / „QR platba“).
 * QR obrázek se vykresluje až v prohlížeči na stránce platba.html (knihovna qrcode z CDN),
 * takže server potřebuje jen sestavit SPAYD řetězec. Bez IBAN v configu se QR nenabízí, jen číslo účtu + VS.
 */
declare(strict_types=1);

/** Další variabilní symbol pro svatební zálohu: RRRR + pořadové číslo (2026001, 2026002, …). */
function nextWeddingVs(): string {
    $year = date('Y');
    // atomické zvýšení čítače (dvě současné poptávky nedostanou stejný VS)
    db()->prepare('INSERT INTO settings (`key`,`value`,updated_at) VALUES (?, \'1\', ?) ON DUPLICATE KEY UPDATE `value` = LAST_INSERT_ID(`value` + 1), updated_at = ?')
        ->execute(['wedding_vs_' . $year, now(), now()]);
    $n = (int)db()->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($n < 1) $n = 1;   // první vložení (žádný duplicate) vrací 0
    return $year . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/** Odstranění diakritiky bez závislosti na locale. */
function asciiFold(string $s): string {
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        if ($t !== false) return $t;
    }
    return strtr($s, ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','Á'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ň'=>'N','Ó'=>'O','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ý'=>'Y','Ž'=>'Z']);
}

function ibanConfigured(): bool { return trim((string)cfg('bank_iban', '')) !== ''; }

/** SPAYD řetězec pro QR platbu (ACC = IBAN, AM = částka, CC = CZK, X-VS, MSG bez diakritiky velkými písmeny). */
function spaydString(int $amountKc, string $vs, string $message): ?string {
    if (!ibanConfigured()) return null;
    $iban = strtoupper(preg_replace('/\s+/', '', (string)cfg('bank_iban')));
    $msg  = strtoupper(asciiFold($message));
    $msg  = preg_replace('/[^A-Z0-9 .,\/:+-]/', '', $msg);
    $msg  = substr(trim($msg), 0, 60);
    return 'SPD*1.0*ACC:' . $iban . '*AM:' . number_format($amountKc, 2, '.', '') . '*CC:CZK*X-VS:' . $vs . '*MSG:' . $msg;
}

/** Údaje k platbě zálohy pro e-mail i stránku platba.html. */
function depositInfo(array $b): array {
    $amount = (int)bookingLimits()['weddingDepositKc'];
    $msg    = 'ZALOHA SVATBA ' . ($b['customer_name'] ?? '');
    $spayd  = spaydString($amount, (string)$b['deposit_vs'], $msg);
    return [
        'amount'  => $amount,
        'account' => (string)cfg('bank_account'),
        'vs'      => (string)$b['deposit_vs'],
        'message' => $msg,
        'spayd'   => $spayd,
        'qr_url'  => siteUrl('platba.html?id=' . $b['id'] . '&t=' . substr($b['cancel_token'], 0, 12)),
        'due'     => date('Y-m-d', strtotime($b['created_at'] . ' +' . (int)bookingLimits()['weddingDepositDays'] . ' days')),
    ];
}
