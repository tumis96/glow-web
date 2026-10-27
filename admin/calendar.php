<?php
/**
 * Administrace – měsíční kalendář: rezervace (běžné i svatby) a blokace na jednom místě.
 * ?m=YYYY-MM (výchozí aktuální měsíc). Kliknutí na položku otevře detail, odkaz vede do přehledu rezervací.
 */
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/bookings.php';

requireAdmin();

$m = (string)($_GET['m'] ?? '');
if (!preg_match('/^(\d{4})-(\d{2})$/', $m, $mm) || (int)$mm[2] < 1 || (int)$mm[2] > 12) { $m = date('Y-m'); $mm = [$m, date('Y'), date('m')]; }
$year = (int)$mm[1]; $month = (int)$mm[2];
$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$first = sprintf('%04d-%02d-01', $year, $month);
$last  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
$prev  = date('Y-m', mktime(0, 0, 0, $month - 1, 1, $year));
$next  = date('Y-m', mktime(0, 0, 0, $month + 1, 1, $year));
$today = date('Y-m-d');

// ---------- data ----------
$st = db()->prepare("SELECT * FROM bookings WHERE status IN ('pending','confirmed','awaiting_deposit') AND start_at <= ? AND end_at >= ? ORDER BY start_at");
$st->execute(["$last 23:59:59", "$first 00:00:00"]);
$bookings = $st->fetchAll();
$st = db()->prepare('SELECT * FROM blocked_slots WHERE start_at <= ? AND end_at >= ? ORDER BY start_at');
$st->execute(["$last 23:59:59", "$first 00:00:00"]);
$blocks = $st->fetchAll();

/** Položky po dnech: [date => [ [sort, html-id, label, cls, detail-json], … ]] */
$byDay = [];
function addItem(array &$byDay, string $date, int $sort, array $item): void { $byDay[$date][] = ['sort' => $sort] + $item; }

function fmtPhoneCal(string $p): string {
    return preg_match('/^\+420(\d{3})(\d{3})(\d{3})$/', $p, $x) ? "+420 $x[1] $x[2] $x[3]" : $p;
}
$statusLabel = ['pending' => 'Čeká na potvrzení', 'awaiting_deposit' => 'Čeká na zálohu', 'confirmed' => 'Potvrzeno'];

/** Barva v kalendáři: wed = svatba, ples = plesové/festivalové líčení a plesový balíček, lic = ostatní líčení, účesy, focení, zkoušky, kurz. */
const PLES_SERVICES = ['plesove', 'festivalove', 'balicek-ples'];
function bookingCategory(array $b): string {
    if ($b['kind'] === 'wedding') return 'wed';
    $ids = json_decode((string)($b['services_json'] ?? '[]'), true);
    if (is_array($ids) && array_intersect($ids, PLES_SERVICES)) return 'ples';
    return 'lic';
}
$catLabel = ['wed' => 'Svatba', 'ples' => 'Ples', 'lic' => 'Líčení / účes'];

foreach ($bookings as $b) {
    $isWedding = $b['kind'] === 'wedding';
    $cat = bookingCategory($b);
    $hasEmail  = $b['customer_email'] !== ADMIN_NO_EMAIL;
    $rows = [];
    if ($isWedding) {
        $rows[] = ['Svatba', czDate($b['start_at']) . (!empty($b['wedding_place']) ? ' · ' . $b['wedding_place'] : '')];
        $rows[] = ['Služba', $b['service_label'] . ' · líčení ' . (int)($b['persons_makeup'] ?? 0) . ', účes ' . (int)($b['persons_hair'] ?? 0)];
        $times = [];
        if (!empty($b['ceremony_time'])) $times[] = 'obřad ' . substr((string)$b['ceremony_time'], 0, 5);
        if (!empty($b['ready_by'])) $times[] = 'hotovo do ' . substr((string)$b['ready_by'], 0, 5);
        if ($times) $rows[] = ['Časy', implode(' · ', $times)];
        $rows[] = ['Zkouška', (int)($b['trial_interest'] ?? 0) ? 'má zájem' : 'bez zájmu'];
        if (!empty($b['deposit_vs'])) {
            $d = depositInfo($b);
            $rows[] = ['Záloha', czPrice($d['amount']) . ', VS ' . $d['vs'] . (!empty($b['deposit_paid_at']) ? ' · přijata ' . date('j. n. Y', strtotime($b['deposit_paid_at'])) : ($b['status'] === 'awaiting_deposit' ? ' · splatná do ' . date('j. n. Y', strtotime($d['due'])) : ''))];
        }
    } else {
        $rows[] = ['Termín', czDate($b['start_at']) . ' ' . czTime($b['start_at']) . '–' . czTime($b['end_at'])];
        $rows[] = ['Služba', $b['service_label']];
        $rows[] = ['Osoby', (int)$b['persons'] . ' · ' . (string)$b['price_label'] . ' · ' . (int)$b['duration_min'] . ' min'];
    }
    $rows[] = ['Jméno', $b['customer_name']];
    $rows[] = ['Telefon', fmtPhoneCal($b['customer_phone'])];
    $rows[] = ['E-mail', $hasEmail ? $b['customer_email'] : 'bez e-mailu'];
    if (!empty($b['note'])) $rows[] = ['Poznámka', $b['note']];
    $rows[] = ['Stav', ($statusLabel[$b['status']] ?? $b['status']) . ' · ' . ($b['source'] === 'admin' ? 'zapsáno ručně' : 'z webu')];

    $detail = [
        'title' => ($isWedding ? 'Svatba · ' : ($cat === 'ples' ? 'Ples · ' : '')) . $b['customer_name'],
        'rows'  => $rows,
        'link'  => 'index.php?f=vse&id=' . $b['id'],
        'tel'   => $b['customer_phone'],
    ];
    $cls = 'ci--' . $cat . ($b['status'] === 'confirmed' ? '' : ' ci--wait');
    if ($isWedding) {
        addItem($byDay, substr($b['start_at'], 0, 10), 0, ['label' => 'Svatba · ' . $b['customer_name'], 'cls' => $cls, 'detail' => $detail]);
    } else {
        addItem($byDay, substr($b['start_at'], 0, 10), (int)date('Hi', strtotime($b['start_at'])), ['label' => czTime($b['start_at']) . ' ' . $b['customer_name'], 'sub' => $b['service_label'], 'cls' => $cls, 'detail' => $detail]);
    }
}
foreach ($blocks as $r) {
    $allDay = substr($r['start_at'], 11, 5) === '00:00' && substr($r['end_at'], 11, 5) >= '23:59';
    $sd = substr($r['start_at'], 0, 10); $ed = substr($r['end_at'], 0, 10);
    $span = $sd === $ed ? ($allDay ? 'celý den' : czTime($r['start_at']) . '–' . czTime($r['end_at'])) : czDate($r['start_at']) . ' – ' . czDate($r['end_at']);
    $detail = [
        'title' => 'Blokace' . (!empty($r['reason']) ? ' · ' . $r['reason'] : ''),
        'rows'  => [['Kdy', $span], ['Zdroj', $r['source'] === 'gcal' ? 'Google Kalendář' : 'zadáno v administraci']],
        'link'  => 'blocks.php',
    ];
    $label = ($allDay || $sd !== $ed ? 'Blokace' : czTime($r['start_at']) . ' Blokace') . (!empty($r['reason']) ? ' · ' . $r['reason'] : '');
    for ($t = strtotime($sd); $t <= strtotime($ed); $t += 86400) {
        $date = date('Y-m-d', $t);
        if ($date < $first || $date > $last) continue;
        addItem($byDay, $date, $allDay || $sd !== $ed ? -1 : (int)date('Hi', strtotime($r['start_at'])), ['label' => $label, 'cls' => 'ci--block', 'detail' => $detail]);
    }
}
foreach ($byDay as &$items) usort($items, fn($a, $b) => $a['sort'] <=> $b['sort']);
unset($items);

$monthNames = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
$title = mb_convert_case($monthNames[$month - 1], MB_CASE_TITLE, 'UTF-8') . ' ' . $year;
$offset = ((int)date('N', strtotime($first))) - 1;   // Po = 0
$counts = ['lic' => 0, 'ples' => 0, 'wed' => 0, 'wait' => 0, 'block' => count($blocks)];
foreach ($bookings as $b) { $counts[bookingCategory($b)]++; if ($b['status'] !== 'confirmed') $counts['wait']++; }

adminPage('Kalendář', function () use ($m, $prev, $next, $title, $year, $month, $daysInMonth, $offset, $byDay, $today, $counts) {
    ?>
    <style>
      .calx-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
      .calx-head h1 { font-size: clamp(26px, 4vw, 36px); }
      .calx-nav { display: flex; gap: 8px; align-items: center; }
      .calx-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px; color: var(--muted); margin-top: 10px; }
      .calx-legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; vertical-align: -1px; margin-right: 6px; }
      .calx { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; margin-top: 16px; }
      .calx-wd { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); padding: 4px 6px; }
      .calx-day { min-height: 96px; padding: 6px; background: var(--white); border: 1px solid var(--line); border-radius: 10px; display: flex; flex-direction: column; gap: 4px; }
      .calx-day.is-empty { background: transparent; border-color: transparent; }
      .calx-day.is-past { background: var(--surface); }
      .calx-day.is-today { border-color: var(--accent); box-shadow: 0 0 0 2px rgba(168, 115, 106, .18); }
      .calx-dn { display: flex; justify-content: space-between; align-items: baseline; font-family: var(--font-display); font-size: 17px; line-height: 1; }
      .calx-dn a { font-family: var(--font-body); font-size: 12px; color: var(--muted); text-decoration: none; opacity: 0; }
      .calx-day:hover .calx-dn a, .calx-dn a:focus { opacity: 1; }
      .calx-dn a:hover { color: var(--accent-ink); }
      .is-we .calx-dn b { color: var(--accent-ink); }
      .ci { display: block; width: 100%; text-align: left; font: inherit; font-size: 12px; line-height: 1.25; padding: 4px 6px; border-radius: 6px; border: 1px solid transparent; cursor: pointer; color: var(--ink); overflow: hidden; }
      .ci b { font-weight: 600; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .ci small { display: block; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .ci--lic { background: #e6f2e7; border-color: #9fc8a6; }
      .ci--ples { background: #e4ecf8; border-color: #9db5dd; }
      .ci--wed { background: #f4e4f0; border-color: #d39cc4; }
      .ci--wait { border-style: dashed; border-width: 1.5px; }
      .ci--wait b::before { content: "? "; color: #8a5a12; }
      .ci--block { background: #eee9e8; border-color: #ddd5d3; color: var(--muted); }
      .calx-legend i.is-wait { background: transparent; border: 1.5px dashed var(--muted); }
      .ci:hover { filter: brightness(.96); }
      .calx-dialog { border: 1px solid var(--line); border-radius: 16px; padding: 0; width: min(520px, calc(100vw - 32px)); background: var(--white); color: var(--ink); }
      .calx-dialog::backdrop { background: rgba(58, 51, 53, .45); }
      .calx-dialog .in { padding: 22px 22px 18px; }
      .calx-dialog h2 { font-size: 24px; padding-right: 36px; }
      .calx-dialog dl { margin: 14px 0 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 15px; }
      .calx-dialog dt { color: var(--muted); font-size: 13px; padding-top: 2px; }
      .calx-dialog dd { margin: 0; overflow-wrap: anywhere; white-space: pre-line; }
      .calx-dialog .x { position: absolute; top: 10px; right: 10px; width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--line); background: var(--white); font-size: 20px; cursor: pointer; }
      .calx-dialog .acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
      @media (max-width: 720px) {
        .calx { display: block; }
        .calx-wd, .calx-day.is-empty, .calx-day.is-free { display: none; }
        .calx-day { min-height: 0; margin-bottom: 8px; }
        .calx-dn { font-size: 16px; }
        .calx-dn a { opacity: 1; }
      }
    </style>
    <div class="calx-head">
      <div>
        <p class="eyebrow">Kalendář</p>
        <h1><?= h($title) ?></h1>
      </div>
      <div class="calx-nav">
        <a class="btn btn-outline btn-sm" href="calendar.php?m=<?= h($prev) ?>" aria-label="Předchozí měsíc">‹ Předchozí</a>
        <a class="btn btn-outline btn-sm" href="calendar.php">Dnes</a>
        <a class="btn btn-outline btn-sm" href="calendar.php?m=<?= h($next) ?>" aria-label="Další měsíc">Další ›</a>
      </div>
    </div>
    <div class="calx-legend">
      <span><i style="background:#f4e4f0;border:1px solid #d39cc4"></i>Svatba (<?= $counts['wed'] ?> tento měsíc)</span>
      <span><i style="background:#e4ecf8;border:1px solid #9db5dd"></i>Ples (<?= $counts['ples'] ?>)</span>
      <span><i style="background:#e6f2e7;border:1px solid #9fc8a6"></i>Líčení, účes, focení, zkouška, kurz (<?= $counts['lic'] ?>)</span>
      <span><i class="is-wait"></i>Čárkovaně s otazníkem = čeká na potvrzení / zálohu (<?= $counts['wait'] ?>)</span>
      <span><i style="background:#eee9e8;border:1px solid #ddd5d3"></i>Blokace (<?= $counts['block'] ?>)</span>
    </div>

    <div class="calx" role="grid" aria-label="<?= h($title) ?>">
      <?php foreach (['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'] as $wd): ?><div class="calx-wd"><?= $wd ?></div><?php endforeach; ?>
      <?php for ($i = 0; $i < $offset; $i++): ?><div class="calx-day is-empty" aria-hidden="true"></div><?php endfor; ?>
      <?php for ($d = 1; $d <= $daysInMonth; $d++):
          $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
          $items = $byDay[$date] ?? [];
          $dow = (int)date('N', strtotime($date));
          $cls = 'calx-day' . ($date < $today ? ' is-past' : '') . ($date === $today ? ' is-today' : '') . ($dow >= 6 ? ' is-we' : '') . (!$items ? ' is-free' : '');
      ?>
        <div class="<?= $cls ?>" role="gridcell">
          <div class="calx-dn"><b><?= $d ?>.</b><?php if ($date >= $today): ?><a href="new.php?date=<?= h($date) ?>" title="Nová rezervace na tento den">+ přidat</a><?php endif; ?></div>
          <?php foreach ($items as $it): ?>
            <button class="ci <?= h($it['cls']) ?>" type="button" data-detail="<?= h(json_encode($it['detail'], JSON_UNESCAPED_UNICODE)) ?>">
              <b><?= h($it['label']) ?></b><?php if (!empty($it['sub'])): ?><small><?= h($it['sub']) ?></small><?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
    <p class="muted mt-3" style="font-size:14px">Zobrazují se rezervace, které drží termín (čekající i potvrzené), svatby a blokace. Odmítnuté a zrušené najdeš v <a href="index.php?f=zrusene">přehledu rezervací</a>. Na telefonu se ukazují jen dny, kdy něco máš.</p>

    <dialog class="calx-dialog" id="calx-dialog">
      <div class="in">
        <button class="x" type="button" data-close aria-label="Zavřít">×</button>
        <h2 id="calx-title"></h2>
        <dl id="calx-rows"></dl>
        <div class="acts">
          <a class="btn btn-primary btn-sm" id="calx-link" href="#">Otevřít v přehledu</a>
          <a class="btn btn-outline btn-sm" id="calx-tel" href="#" hidden>Zavolat</a>
          <button class="btn btn-outline btn-sm" type="button" data-close>Zavřít</button>
        </div>
      </div>
    </dialog>
    <script>
    (function () {
      var dlg = document.getElementById('calx-dialog');
      if (!dlg || !('showModal' in dlg)) return;
      var title = document.getElementById('calx-title'), rows = document.getElementById('calx-rows'), link = document.getElementById('calx-link'), tel = document.getElementById('calx-tel');
      document.querySelectorAll('.ci[data-detail]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var d; try { d = JSON.parse(btn.getAttribute('data-detail')); } catch (e) { return; }
          title.textContent = d.title || '';
          rows.innerHTML = '';
          (d.rows || []).forEach(function (r) {
            var dt = document.createElement('dt'); dt.textContent = r[0];
            var dd = document.createElement('dd'); dd.textContent = r[1];
            rows.appendChild(dt); rows.appendChild(dd);
          });
          link.href = d.link || '#';
          link.textContent = d.link && d.link.indexOf('blocks.php') === 0 ? 'Otevřít blokace' : 'Otevřít v přehledu';
          if (d.tel) { tel.href = 'tel:' + d.tel; tel.hidden = false; } else { tel.hidden = true; }
          dlg.showModal();
        });
      });
      dlg.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
      dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    })();
    </script>
    <?php
}, ['active' => 'calendar']);
