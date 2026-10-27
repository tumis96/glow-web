<?php
/**
 * Administrace – přehled rezervací a akce (potvrdit, odmítnout, zrušit). Svatby se potvrzují stejně jako běžné rezervace (bez zálohy).
 * Filtry ?f=cekajici|nadchazejici|probehle|zrusene|svatby|vse, hledání ?q=, zvýraznění ?id= (odkaz z e-mailu).
 * Veškerá logika stavů je v includes/bookings.php – tady se jen volá.
 */
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/bookings.php';

requireAdmin();

const FILTERS = [
    'cekajici'     => 'Čeká na mě',
    'nadchazejici' => 'Nadcházející',
    'probehle'     => 'Proběhlé',
    'zrusene'      => 'Zrušené',
    'svatby'       => 'Svatby',
    'vse'          => 'Vše',
];

$q = trim((string)($_REQUEST['q'] ?? ''));
$f = (string)($_REQUEST['f'] ?? '');
if (!isset(FILTERS[$f])) $f = $q !== '' ? 'vse' : 'cekajici';
$id = (string)($_GET['id'] ?? '');

// ---------- akce ----------
if (isPost()) {
    csrfCheck();
    $b      = loadBooking((string)($_POST['id'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if (!$b) { flash('err', 'Rezervace nebyla nalezena.'); redirect('index.php'); }
    $name = $b['customer_name'];
    $hasEmail = $b['customer_email'] !== ADMIN_NO_EMAIL;
    switch ($action) {
        case 'confirm':
        case 'deposit':   // starší formulář/odkaz
            if (in_array($b['status'], ['pending', 'awaiting_deposit'], true)) { confirmBooking($b); flash('ok', "Potvrzeno. $name dostala e‑mail s pozvánkou do kalendáře."); }
            else flash('warn', 'Tuhle rezervaci už nejde potvrdit – má jiný stav.');
            break;
        case 'reject':
            if (in_array($b['status'], ['pending', 'awaiting_deposit'], true)) { rejectBooking($b, trim((string)($_POST['reason'] ?? ''))); flash('ok', "Odmítnuto. $name dostala e‑mail, termín je zase volný."); }
            else flash('warn', 'Tuhle rezervaci už nejde odmítnout – má jiný stav.');
            break;
        case 'cancel':
            if ($b['status'] === 'confirmed') { cancelBooking($b, 'owner'); flash('ok', 'Rezervace zrušená, termín je zase volný.' . ($hasEmail ? " $name dostala e‑mail." : '')); }
            else flash('warn', 'Zrušit jde jen potvrzenou rezervaci.');
            break;
        default:
            flash('err', 'Neznámá akce.');
    }
    $back = http_build_query(array_filter(['f' => $f, 'q' => $q], fn($v) => $v !== ''));
    redirect('index.php' . ($back ? '?' . $back : '') . '#b-' . $b['id']);
}

// ---------- čísla nahoře ----------
function countWhere(string $where, array $params): int {
    $st = db()->prepare("SELECT COUNT(*) FROM bookings WHERE $where");
    $st->execute($params);
    return (int)$st->fetchColumn();
}
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$monday   = date('Y-m-d', strtotime('-' . ((int)date('N') - 1) . ' days'));
$nextMon  = date('Y-m-d', strtotime($monday . ' +7 days'));
$holding  = "status IN ('pending','confirmed','awaiting_deposit')";
$stats = [
    'pending'  => countWhere("status IN ('pending','awaiting_deposit')", []),
    'weddings' => countWhere("kind = 'wedding' AND $holding AND start_at >= ?", ["$today 00:00:00"]),
    'today'    => countWhere("$holding AND start_at >= ? AND start_at < ?", ["$today 00:00:00", "$tomorrow 00:00:00"]),
    'week'     => countWhere("$holding AND start_at >= ? AND start_at < ?", ["$monday 00:00:00", "$nextMon 00:00:00"]),
];

// ---------- seznam ----------
$where = []; $params = []; $order = 'start_at ASC';
switch ($f) {
    case 'cekajici':     $where[] = "status IN ('pending','awaiting_deposit')"; break;
    case 'nadchazejici': $where[] = "status = 'confirmed' AND start_at >= ?"; $params[] = "$today 00:00:00"; break;
    case 'probehle':     $where[] = "status = 'confirmed' AND start_at < ?";  $params[] = "$today 00:00:00"; $order = 'start_at DESC'; break;
    case 'zrusene':      $where[] = "status IN ('rejected','cancelled')"; $order = 'updated_at DESC'; break;
    case 'svatby':       $where[] = "kind = 'wedding'"; break;
    default:             $order = 'start_at DESC';
}
if ($q !== '') {
    $digits = preg_replace('/\D+/', '', $q);
    $where[] = '(customer_name LIKE ? OR customer_email LIKE ? OR customer_phone LIKE ?)';
    $like = '%' . addcslashes($q, '%_') . '%';
    array_push($params, $like, $like, $digits !== '' ? '%' . $digits . '%' : $like);
}
$sql = 'SELECT * FROM bookings' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $order LIMIT 200";
$st = db()->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

$highlight = $id !== '' ? loadBooking($id) : null;
if ($highlight) $list = array_values(array_filter($list, fn($b) => $b['id'] !== $highlight['id']));

// ---------- vykreslení ----------
function statusBadge(array $b): string {
    $map = [
        'pending'          => ['Čeká na potvrzení', 'badge--wait'],
        'awaiting_deposit' => ['Čeká na potvrzení', 'badge--wait'],   // starší svatební poptávky z dob zálohy
        'confirmed'        => ['Potvrzeno',         'badge--ok'],
        'rejected'         => ['Odmítnuto',         'badge--off'],
        'cancelled'        => ['Zrušeno',           'badge--off'],
    ];
    [$label, $cls] = $map[$b['status']] ?? [$b['status'], 'badge--off'];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}
function personsText(int $n): string { return $n . "\u{a0}" . ($n === 1 ? 'osoba' : 'osoby'); }
function fmtPhone(string $p): string {
    if (preg_match('/^\+420(\d{3})(\d{3})(\d{3})$/', $p, $m)) return "+420\u{a0}$m[1]\u{a0}$m[2]\u{a0}$m[3]";
    return $p;
}
function czShortDate(string $datetime): string { return date('j. n. Y', strtotime($datetime)); }

function bookingCard(array $b, bool $hl, string $f, string $q): void {
    $isWedding = $b['kind'] === 'wedding';
    $isPast    = strtotime($b['end_at']) < time();
    $hasEmail  = $b['customer_email'] !== ADMIN_NO_EMAIL;
    $cls = 'bk' . ($hl ? ' is-hl' : '') . ($isPast && $b['status'] === 'confirmed' ? ' is-past' : '');
    $hidden = csrfField() . '<input type="hidden" name="id" value="' . h($b['id']) . '"><input type="hidden" name="f" value="' . h($f) . '"><input type="hidden" name="q" value="' . h($q) . '">';
    ?>
    <article class="<?= $cls ?>" id="b-<?= h($b['id']) ?>">
      <div class="bk-head">
        <div class="bk-when">
          <?= $isWedding ? 'Svatba · ' : '' ?><?= h(czDate($b['start_at'])) ?>
          <small><?php if ($isWedding): ?><?= h((string)($b['wedding_place'] ?? '')) ?><?php else: ?><?= h(czTime($b['start_at'])) ?>–<?= h(czTime($b['end_at'])) ?> · <?= h($b['service_label']) ?><?php endif; ?></small>
        </div>
        <div class="bk-badges">
          <?= statusBadge($b) ?>
          <?php if ($isWedding): ?><span class="badge badge--wed">Svatba</span><?php endif; ?>
          <span class="badge badge--src"><?= $b['source'] === 'admin' ? 'ručně' : 'z webu' ?></span>
        </div>
      </div>
      <dl class="bk-meta">
        <?php if ($isWedding): ?>
          <dt>Služba</dt><dd><?= h($b['service_label']) ?> · líčení <?= (int)($b['persons_makeup'] ?? 0) ?>, účes <?= (int)($b['persons_hair'] ?? 0) ?></dd>
          <?php if (!empty($b['ceremony_time']) || !empty($b['ready_by'])): ?>
            <dt>Časy</dt><dd><?= !empty($b['ceremony_time']) ? 'obřad ' . h(substr((string)$b['ceremony_time'], 0, 5)) : '' ?><?= !empty($b['ceremony_time']) && !empty($b['ready_by']) ? ' · ' : '' ?><?= !empty($b['ready_by']) ? 'hotovo do ' . h(substr((string)$b['ready_by'], 0, 5)) : '' ?></dd>
          <?php endif; ?>
          <dt>Zkouška</dt><dd><?= (int)($b['trial_interest'] ?? 0) ? 'má zájem' : 'bez zájmu' ?></dd>
          <?php if (!empty($b['deposit_vs'])): ?>
            <dt>Poptávka č.</dt>
            <dd><strong><?= h((string)$b['deposit_vs']) ?></strong><?php if (!empty($b['deposit_paid_at'])): ?> · záloha přijata <?= h(czShortDate($b['deposit_paid_at'])) ?><?php endif; ?></dd>
          <?php endif; ?>
        <?php else: ?>
          <dt>Služba</dt><dd><?= h($b['service_label']) ?></dd>
          <dt>Osoby</dt><dd><?= h(personsText((int)$b['persons'])) ?> · <?= h((string)$b['price_label']) ?> · <?= (int)$b['duration_min'] ?>&nbsp;min</dd>
        <?php endif; ?>
        <dt>Jméno</dt><dd><strong><?= h($b['customer_name']) ?></strong></dd>
        <dt>Telefon</dt><dd><a href="tel:<?= h($b['customer_phone']) ?>"><?= h(fmtPhone($b['customer_phone'])) ?></a></dd>
        <dt>E‑mail</dt><dd><?= $hasEmail ? '<a href="mailto:' . h($b['customer_email']) . '">' . h($b['customer_email']) . '</a>' : '<span class="muted">bez e‑mailu</span>' ?></dd>
      </dl>
      <?php if (!empty($b['note'])): ?><div class="bk-note"><?= h($b['note']) ?></div><?php endif; ?>

      <?php if ($b['status'] === 'pending' || $b['status'] === 'awaiting_deposit'): ?>
        <div class="bk-actions">
          <form method="post"><?= $hidden ?><input type="hidden" name="action" value="confirm">
            <button class="btn btn-ok btn-sm" type="submit">Potvrdit</button></form>
          <details class="more">
            <summary class="btn btn-danger btn-sm">Odmítnout</summary>
            <form method="post" class="more-panel"><?= $hidden ?><input type="hidden" name="action" value="reject">
              <div class="field">
                <label for="reason-<?= h($b['id']) ?>">Vzkaz pro zákaznici (nepovinné)</label>
                <textarea id="reason-<?= h($b['id']) ?>" name="reason" maxlength="1000" placeholder="Např. v tenhle čas už nemůžu, zkuste od 16:00…"></textarea>
                <span class="hint">Pošle se jí e‑mailem spolu s&nbsp;odkazem na výběr jiného termínu.<?= $isWedding ? ' Den svatby se uvolní.' : '' ?></span>
              </div>
              <button class="btn btn-danger btn-sm" type="submit">Odmítnout a&nbsp;odeslat e‑mail</button>
            </form>
          </details>
        </div>
      <?php elseif ($b['status'] === 'confirmed' && !$isPast): ?>
        <div class="bk-actions">
          <details class="more">
            <summary class="btn btn-outline btn-sm">Zrušit rezervaci</summary>
            <form method="post" class="more-panel"><?= $hidden ?><input type="hidden" name="action" value="cancel">
              <p class="muted" style="font-size:14px">Termín se uvolní<?= $hasEmail ? ' a zákaznici odejde e‑mail o zrušení' : '' ?>. Opravdu?</p>
              <button class="btn btn-danger btn-sm" type="submit">Ano, zrušit</button>
            </form>
          </details>
        </div>
      <?php endif; ?>

      <div class="bk-foot">
        Vytvořeno <?= h(czShortDate($b['created_at'])) ?> <?= h(czTime($b['created_at'])) ?>
        <?php if (!empty($b['decided_at'])): ?> · vyřízeno <?= h(czShortDate($b['decided_at'])) ?><?php endif; ?>
        <?php if (!empty($b['reject_reason'])): ?> · vzkaz: „<?= h($b['reject_reason']) ?>“<?php endif; ?>
      </div>
    </article>
    <?php
}

require_once __DIR__ . '/../includes/reviews.php';
reviewsEnsureSchema();
$pendingReviews = reviewsCount('pending');

adminPage('Rezervace', function () use ($f, $q, $stats, $list, $highlight, $pendingReviews) {
    ?>
    <?php if ($pendingReviews): ?>
      <div class="notice notice--warn" role="status">Na webu <?= $pendingReviews === 1 ? 'čeká 1 nová recenze' : ($pendingReviews < 5 ? "čekají $pendingReviews nové recenze" : "čeká $pendingReviews nových recenzí") ?> na schválení. <a href="reviews.php">Projít recenze</a></div>
    <?php endif; ?>
    <p class="eyebrow<?= $pendingReviews ? ' mt-3' : '' ?>">Tvoje rezervace</p>
    <div class="stats mt-2">
      <a class="stat<?= $stats['pending'] ? ' is-hot' : '' ?>" href="index.php?f=cekajici"><b><?= $stats['pending'] ?></b><span>čeká na potvrzení</span></a>
      <a class="stat" href="index.php?f=svatby"><b><?= $stats['weddings'] ?></b><span>svatby dopředu</span></a>
      <a class="stat" href="index.php?f=nadchazejici"><b><?= $stats['today'] ?></b><span>dnes</span></a>
      <a class="stat" href="index.php?f=nadchazejici"><b><?= $stats['week'] ?></b><span>tento týden</span></a>
    </div>

    <div class="toolbar mt-3">
      <nav class="pills pills--tabs" aria-label="Filtr rezervací">
        <?php foreach (FILTERS as $key => $label): ?>
          <a href="index.php?<?= h(http_build_query(array_filter(['f' => $key, 'q' => $q]))) ?>"<?= $key === $f ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <form method="get" class="search" role="search">
        <input type="hidden" name="f" value="<?= h($f) ?>">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Jméno, telefon nebo e‑mail" aria-label="Hledat">
        <button class="btn btn-outline btn-sm" type="submit">Hledat</button>
      </form>
    </div>

    <?php if ($highlight): ?>
      <h2 class="mt-4">Vybraná rezervace</h2>
      <div class="list mt-2"><?php bookingCard($highlight, true, $f, $q); ?></div>
      <h2 class="mt-4"><?= h(FILTERS[$f]) ?></h2>
    <?php elseif ($q !== ''): ?>
      <p class="muted mt-3">Hledání „<?= h($q) ?>“ · <?= count($list) ?> <?= count($list) === 1 ? 'výsledek' : (count($list) >= 2 && count($list) <= 4 ? 'výsledky' : 'výsledků') ?> · <a class="link" href="index.php?f=<?= h($f) ?>">zrušit hledání</a></p>
    <?php endif; ?>

    <div class="list mt-2">
      <?php if (!$list): ?>
        <div class="empty"><?php
          if ($q !== '') echo 'Nic takového tu není.';
          elseif ($f === 'cekajici') echo 'Nic nečeká na vyřízení. Všechno máš hotové.';
          elseif ($f === 'nadchazejici') echo 'Žádné potvrzené termíny dopředu.';
          elseif ($f === 'svatby') echo 'Žádná svatební poptávka.';
          else echo 'Zatím tu nic není.';
        ?></div>
      <?php else: foreach ($list as $b) bookingCard($b, false, $f, $q); endif; ?>
      <?php if (count($list) === 200): ?><p class="muted center">Zobrazeno prvních 200 – upřesni hledání.</p><?php endif; ?>
    </div>
    <?php
}, ['active' => 'index']);
