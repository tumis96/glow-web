<?php
/**
 * Administrace – blokace termínů (dovolená, soukromá akce, část dne).
 * Blokace se zákaznicím nenabízí jako volné. Blokace z Google Kalendáře (source=gcal) jsou jen pro čtení.
 */
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

requireAdmin();

$error = '';
$old   = ['date_from' => '', 'date_to' => '', 'reason_day' => '', 'date' => '', 'time_from' => '', 'time_to' => '', 'reason_time' => ''];

if (isPost()) {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? ''));

    if ($action === 'delete') {
        $st = db()->prepare("DELETE FROM blocked_slots WHERE id = ? AND source = 'admin'");
        $st->execute([(int)($_POST['block_id'] ?? 0)]);
        flash($st->rowCount() ? 'ok' : 'warn', $st->rowCount() ? 'Blokace smazaná, termín je zase volný.' : 'Blokaci se nepodařilo smazat (možná už neexistuje nebo je z Google Kalendáře).');
        redirect('blocks.php');
    }

    $start = $end = null; $reason = '';
    if ($action === 'day') {
        $from = $old['date_from']; $to = $old['date_to'] !== '' ? $old['date_to'] : $from;
        $reason = $old['reason_day'];
        if (!validDate($from) || !validDate($to)) $error = 'Zadej platné datum.';
        elseif ($to < $from)                     $error = 'Datum „do“ musí být stejné nebo pozdější než „od“.';
        elseif ((strtotime($to) - strtotime($from)) > 366 * 86400) $error = 'Blokace nesmí být delší než rok.';
        else { $start = "$from 00:00:00"; $end = "$to 23:59:00"; }
    } elseif ($action === 'time') {
        $date = $old['date']; $tf = normTime($old['time_from']); $tt = normTime($old['time_to']);
        $reason = $old['reason_time'];
        if (!validDate($date))          $error = 'Zadej platné datum.';
        elseif ($tf === null || $tt === null) $error = 'Čas zadej ve tvaru HH:MM, třeba 15:30.';
        elseif ($tf >= $tt)             $error = 'Čas „od“ musí být dřív než „do“.';
        else { $start = "$date $tf:00"; $end = "$date $tt:00"; }
    } else {
        $error = 'Neznámá akce.';
    }

    if ($error === '' && $start !== null) {
        if ($end < now()) {
            $error = 'Tahle blokace je celá v minulosti – nemá smysl ji zakládat.';
        } else {
            $reason = mb_substr($reason, 0, 190);
            db()->prepare("INSERT INTO blocked_slots (start_at, end_at, reason, source, created_at) VALUES (?,?,?,'admin',?)")
                ->execute([$start, $end, $reason !== '' ? $reason : null, now()]);
            logEvent('block:created', null, "$start – $end " . $reason);
            $multiDay = substr($end, 0, 10) !== substr($start, 0, 10);
            $span = $action === 'time' ? ' ' . czTime($start) . '–' . czTime($end) : ($multiDay ? ' až ' . czDate($end) : ' celý den');
            flash('ok', 'Blokace uložená: ' . czDate($start) . $span . '.');
            // Existující rezervace blokace neruší – jen upozornit.
            $st = db()->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('pending','confirmed','awaiting_deposit') AND start_at < ? AND end_at > ?");
            $st->execute([$end, $start]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) flash('warn', "Pozor: v\u{a0}tomhle čase už máš $n " . ($n === 1 ? 'rezervaci' : ($n < 5 ? 'rezervace' : 'rezervací')) . ". Blokace je neruší – pokud nemůžeš, zruš je v\u{a0}přehledu rezervací.");
            redirect('blocks.php');
        }
    }
}

$st = db()->prepare('SELECT * FROM blocked_slots WHERE end_at >= ? ORDER BY start_at ASC LIMIT 300');
$st->execute([now()]);
$blocks = $st->fetchAll();

function blockLabel(array $r): string {
    $s = $r['start_at']; $e = $r['end_at'];
    $sd = substr($s, 0, 10); $ed = substr($e, 0, 10);
    $allDay = substr($s, 11, 5) === '00:00' && substr($e, 11, 5) >= '23:59';
    if ($allDay) return $sd === $ed ? czDate($s) . ' · celý den' : czDate($s) . ' – ' . czDate($e);
    if ($sd === $ed) return czDate($s) . ' · ' . czTime($s) . '–' . czTime($e);
    return czDate($s) . ' ' . czTime($s) . ' – ' . czDate($e) . ' ' . czTime($e);
}

adminPage('Blokace', function () use ($error, $old, $blocks) {
    $today = date('Y-m-d');
    ?>
    <p class="eyebrow">Blokace termínů</p>
    <h1>Kdy nechceš být k&nbsp;dispozici</h1>
    <p class="muted mt-2">Zablokovaný čas se zákaznicím v&nbsp;rezervaci nenabídne. Už přijaté rezervace to neruší.</p>
    <?php if ($error): ?><div class="notice notice--err mt-3" role="alert"><?= h($error) ?></div><?php endif; ?>

    <div class="grid grid-2 mt-3">
      <form method="post" class="card form">
        <?= csrfField() ?><input type="hidden" name="action" value="day">
        <h3>Celý den nebo víc dní</h3>
        <p>Dovolená, svatba mimo salon, nemoc…</p>
        <div class="form-row">
          <div class="field">
            <label for="date_from">Od</label>
            <input type="date" id="date_from" name="date_from" required min="<?= h($today) ?>" value="<?= h($old['date_from']) ?>">
          </div>
          <div class="field">
            <label for="date_to">Do (nepovinné)</label>
            <input type="date" id="date_to" name="date_to" min="<?= h($today) ?>" value="<?= h($old['date_to']) ?>">
            <span class="hint">Nech prázdné pro jeden den.</span>
          </div>
        </div>
        <div class="field">
          <label for="reason_day">Důvod (jen pro tebe)</label>
          <input type="text" id="reason_day" name="reason_day" maxlength="190" placeholder="např. dovolená" value="<?= h($old['reason_day']) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Zablokovat dny</button>
      </form>

      <form method="post" class="card form">
        <?= csrfField() ?><input type="hidden" name="action" value="time">
        <h3>Část dne</h3>
        <p>Třeba doktor nebo odpolední volno.</p>
        <div class="field">
          <label for="date">Datum</label>
          <input type="date" id="date" name="date" required min="<?= h($today) ?>" value="<?= h($old['date']) ?>">
        </div>
        <div class="form-row">
          <div class="field">
            <label for="time_from">Od</label>
            <input type="time" id="time_from" name="time_from" required step="900" value="<?= h($old['time_from']) ?>">
          </div>
          <div class="field">
            <label for="time_to">Do</label>
            <input type="time" id="time_to" name="time_to" required step="900" value="<?= h($old['time_to']) ?>">
          </div>
        </div>
        <div class="field">
          <label for="reason_time">Důvod (jen pro tebe)</label>
          <input type="text" id="reason_time" name="reason_time" maxlength="190" placeholder="např. doktor" value="<?= h($old['reason_time']) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Zablokovat čas</button>
      </form>
    </div>

    <h2 class="mt-5">Nadcházející blokace</h2>
    <div class="list mt-2">
      <?php if (!$blocks): ?>
        <div class="empty">Žádné blokace. Všechny dny podle otevírací doby jsou k&nbsp;dispozici.</div>
      <?php else: foreach ($blocks as $r): ?>
        <article class="bk">
          <div class="bk-head">
            <div class="bk-when">
              <?= h(blockLabel($r)) ?>
              <?php if (!empty($r['reason'])): ?><small><?= h($r['reason']) ?></small><?php endif; ?>
            </div>
            <div class="bk-badges">
              <?php if ($r['source'] === 'gcal'): ?>
                <span class="badge badge--src">z Google Kalendáře</span>
              <?php else: ?>
                <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="block_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Smazat</button></form>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($r['source'] === 'gcal'): ?><div class="bk-foot">Tahle blokace přišla z&nbsp;Google Kalendáře – uprav nebo smaž ji tam, sem se to přenese při další synchronizaci.</div><?php endif; ?>
        </article>
      <?php endforeach; endif; ?>
    </div>
    <?php
}, ['active' => 'blocks']);
