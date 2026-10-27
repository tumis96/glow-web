<?php
/**
 * Recenze klientek: ukládání z webu, schvalování Petrou (e-mail nebo admin), výpis schválených.
 * Tabulka reviews se založí sama při prvním použití (reviewsEnsureSchema), včetně 4 původních recenzí z webu.
 * Stavy: pending (čeká na schválení) → approved (zveřejněno) | rejected (nezveřejněno, zůstává v adminu).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const REVIEW_SERVICES = ['Svatební líčení', 'Zkouška svatebního líčení', 'Plesové líčení', 'Denní líčení', 'Líčení na focení', 'Účes', 'Kurz líčení', 'Jiné'];
const REVIEW_TEXT_MIN = 20;
const REVIEW_TEXT_MAX = 1200;
const REVIEW_NAME_MAX = 80;

const CZ_MONTHS_NOM = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

/** Založí tabulku a naplní ji původními recenzemi (jen jednou – pak je v settings příznak). */
function reviewsEnsureSchema(): void {
    if (settingGet('reviews_schema') === '1') return;
    db()->exec("CREATE TABLE IF NOT EXISTS reviews (
      id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
      status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      name          VARCHAR(80)   NOT NULL,
      email         VARCHAR(190)  NULL,
      service       VARCHAR(80)   NULL,
      rating        TINYINT UNSIGNED NOT NULL DEFAULT 5,
      text          TEXT          NOT NULL,
      decide_token  CHAR(40)      NOT NULL,
      ip_hash       CHAR(16)      NULL,
      created_at    DATETIME      NOT NULL,
      decided_at    DATETIME      NULL,
      PRIMARY KEY (id),
      KEY idx_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if ((int)db()->query('SELECT COUNT(*) FROM reviews')->fetchColumn() === 0) {
        $seed = [
            ['nevěsta',  'Svatební líčení', 'S Petrou jsem byla naprosto spokojená. Na zkoušce líčení jsme se dohodly na stylu a na tom, co mi vyhovuje, a vše splnila na výbornou. Už ze zkoušky jsem odcházela nadšená! Svatební líčení vydrželo opravdu celý den a přežilo i kopu slz a doteků tváří. I díky krásnému líčení jsem se mohla ve svůj den cítit jako princezna.', '2026-09-20 12:00:00'],
            ['klientka', 'Svatební líčení', 'Mohu jen a jen doporučit! S Petrou byla skvělá komunikace, má super přístup a vidí potenciál tam, kde bych ho ani netušila. Úplně jsem pod jejíma rukama rozkvetla a všechny jsme zůstaly krásné až do noci. Je to kouzelnice a k tomu ještě nesmírně sympatická. Ještě jednou moc děkuji za sobotní péči i skvělou práci, moc jsme si to užily.', '2026-09-14 12:00:00'],
            ['Barbora',  'Svatební líčení', 'Vše bylo úžasné, vyšlo krásně počasí a bylo to perfektní. Make-up krásný. Všichni účes i líčení chválili, takže za mě úžasný. Jste moc fajn a bylo nám s mamkou s vámi moc dobře, moc děkujeme.', '2026-08-30 12:00:00'],
            ['klientka', 'Svatební líčení', 'Make-up byl skvělý, bylo to úžasné a moc děkuji za vše. Jste skvělá.', '2026-08-16 12:00:00'],
        ];
        $st = db()->prepare("INSERT INTO reviews (status, name, email, service, rating, text, decide_token, created_at, decided_at) VALUES ('approved',?,NULL,?,5,?,?,?,?)");
        foreach ($seed as [$name, $service, $text, $at]) $st->execute([$name, $service, $text, token(), $at, $at]);
    }
    settingSet('reviews_schema', '1');
}

function loadReview(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare('SELECT * FROM reviews WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Schválené recenze, nejnovější první. */
function reviewsApproved(int $limit = 60): array {
    $st = db()->prepare("SELECT id, name, service, rating, text, created_at FROM reviews WHERE status = 'approved' ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit));
    $st->execute();
    return $st->fetchAll();
}

function reviewsCount(string $status): int {
    $st = db()->prepare('SELECT COUNT(*) FROM reviews WHERE status = ?');
    $st->execute([$status]);
    return (int)$st->fetchColumn();
}

/** Ověří vstup z formuláře. Vrací vyčištěná data, při chybě vyhodí InvalidArgumentException s textem pro zákaznici. */
function reviewValidate(array $in): array {
    $name    = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? '');
    $email   = trim((string)($in['email'] ?? ''));
    $service = trim((string)($in['service'] ?? ''));
    $rating  = (int)($in['rating'] ?? 0);
    $text    = trim(str_replace("\r\n", "\n", (string)($in['text'] ?? '')));
    $text    = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    if (mb_strlen($name) < 2)               throw new InvalidArgumentException('Napište prosím své jméno (stačí křestní).');
    if (mb_strlen($name) > REVIEW_NAME_MAX)  throw new InvalidArgumentException('Jméno je příliš dlouhé.');
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) throw new InvalidArgumentException('E-mail nevypadá správně. Můžete ho i nechat prázdný.');
    if ($service !== '' && !in_array($service, REVIEW_SERVICES, true)) throw new InvalidArgumentException('Vyberte prosím službu ze seznamu.');
    if ($rating < 1 || $rating > 5)          throw new InvalidArgumentException('Vyberte prosím počet hvězdiček.');
    if (mb_strlen($text) < REVIEW_TEXT_MIN)  throw new InvalidArgumentException('Napište prosím alespoň pár vět (minimálně ' . REVIEW_TEXT_MIN . ' znaků).');
    if (mb_strlen($text) > REVIEW_TEXT_MAX)  throw new InvalidArgumentException('Recenze je příliš dlouhá (maximálně ' . REVIEW_TEXT_MAX . ' znaků).');
    if (preg_match('#https?://|www\.#i', $text . $name)) throw new InvalidArgumentException('Do recenze prosím nevkládejte odkazy.');
    if (empty($in['gdpr']))                  throw new InvalidArgumentException('Pro zveřejnění je potřeba souhlas se zveřejněním recenze.');
    if (preg_match('/[\r\n]/', $name . $email)) throw new InvalidArgumentException('Neplatné znaky ve jménu nebo e-mailu.');

    return ['name' => $name, 'email' => $email !== '' ? $email : null, 'service' => $service !== '' ? $service : null, 'rating' => $rating, 'text' => $text];
}

/** Uloží recenzi jako čekající a vrátí ji. $data = výstup reviewValidate(). */
function reviewCreate(array $data): array {
    $ip = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '0')), 0, 16);
    db()->prepare("INSERT INTO reviews (status, name, email, service, rating, text, decide_token, ip_hash, created_at) VALUES ('pending',?,?,?,?,?,?,?,?)")
        ->execute([$data['name'], $data['email'], $data['service'], $data['rating'], $data['text'], token(), $ip, now()]);
    $r = loadReview((int)db()->lastInsertId());
    logEvent('review:received', null, '#' . $r['id'] . ' ' . $r['name'] . ' (' . $r['rating'] . '★)');
    return $r;
}

function reviewSetStatus(array $r, string $status, string $by = 'admin'): void {
    db()->prepare('UPDATE reviews SET status = ?, decided_at = ? WHERE id = ?')->execute([$status, now(), $r['id']]);
    logEvent('review:' . $status, null, '#' . $r['id'] . ' ' . $r['name'] . ' (' . $by . ')');
}

function reviewUpdateText(array $r, string $name, ?string $service, string $text): void {
    db()->prepare('UPDATE reviews SET name = ?, service = ?, text = ? WHERE id = ?')->execute([$name, $service, $text, $r['id']]);
    logEvent('review:edited', null, '#' . $r['id']);
}

function reviewDelete(array $r): void {
    db()->prepare('DELETE FROM reviews WHERE id = ?')->execute([$r['id']]);
    logEvent('review:deleted', null, '#' . $r['id'] . ' ' . $r['name']);
}

/** Odkaz pro Petru z e-mailu (schválit / odmítnout bez přihlášení). */
function reviewDecideUrl(array $r, string $action): string {
    return siteUrl('api/review-decide.php?id=' . (int)$r['id'] . '&token=' . $r['decide_token'] . '&action=' . $action);
}

function reviewStars(int $n): string {
    $n = max(0, min(5, $n));
    return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}

/** "2026-10-27 …" → "říjen 2026" */
function czMonthYear(string $dt): string {
    $ts = strtotime($dt);
    return $ts ? CZ_MONTHS_NOM[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) : '';
}

/** Tvar pro veřejné API a výpis na webu (bez e-mailu a tokenů). */
function reviewPublic(array $r): array {
    return [
        'id'      => (int)$r['id'],
        'name'    => $r['name'],
        'service' => ($r['service'] === null || $r['service'] === 'Jiné') ? null : $r['service'],
        'rating'  => (int)$r['rating'],
        'text'    => $r['text'],
        'when'    => czMonthYear($r['created_at']),
    ];
}

// ---------- e-mail Petře ----------
function mailOwnerNewReview(array $r): bool {
    require_once __DIR__ . '/mail.php';
    $m = mailer();
    if (!addTo($m, (string)cfg('mail_petra'), 'Petra')) return false;
    if (!empty($r['email'])) { $m->clearReplyTos(); $m->addReplyTo($r['email'], $r['name']); }
    $m->Subject = 'Nová recenze na webu: ' . $r['name'] . ' (' . $r['rating'] . '/5)';
    $rows = [
        'Jméno'     => $r['name'],
        'Služba'    => $r['service'],
        'Hodnocení' => reviewStars((int)$r['rating']) . ' (' . $r['rating'] . ' z 5)',
        'Recenze'   => $r['text'],
        'E-mail'    => $r['email'] ?: 'neuveden',
    ];
    $body = '<p>Na webu přibyla nová recenze. Na webu se objeví, až ji schválíš – buď tlačítkem tady, nebo v administraci, kde ji můžeš i upravit (zkrátit, opravit překlepy).</p>'
        . emailRows($rows)
        . '<table role="presentation" cellspacing="0" cellpadding="0"><tr><td>' . emailButton(reviewDecideUrl($r, 'approve'), 'Schválit a zveřejnit', '#2f6b3a') . '</td><td style="padding-left:10px;">' . emailButton(reviewDecideUrl($r, 'reject'), 'Nezveřejňovat', '#8a2f28') . '</td></tr></table>'
        . '<p style="font-size:14px;color:#766b6f;">Upravit text před zveřejněním: <a href="' . h(siteUrl('admin/reviews.php')) . '" style="color:#90625b;">administrace → Recenze</a>.</p>';
    $m->Body    = emailWrap('Nová recenze ke schválení', $body, mb_substr($r['text'], 0, 90));
    $m->AltBody = "Nová recenze ke schválení\n" . altRows($rows) . "\nSchválit: " . reviewDecideUrl($r, 'approve') . "\nNezveřejňovat: " . reviewDecideUrl($r, 'reject') . "\n";
    return sendMail($m, 'review_owner', null);
}
