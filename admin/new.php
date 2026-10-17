<?php
/**
 * Administrace – ruční zápis rezervace (objednávka po telefonu / z Instagramu).
 * Ukládá se rovnou jako potvrzená (createBooking se source=admin); e-mail zákaznici jen když to Petra zaškrtne a e-mail zná.
 */
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/bookings.php';

requireAdmin();

$cat    = servicesCatalog();
$groups = servicesGroups();
$limits = bookingLimits();

$old   = ['services' => [], 'persons' => 1, 'date' => '', 'time' => '', 'name' => '', 'phone' => '', 'email' => '', 'note' => '', 'notify' => true];
$error = '';
$conflict = false;

if (isPost()) {
    csrfCheck();
    $old['services'] = is_array($_POST['services'] ?? null) ? array_values(array_map('strval', $_POST['services'])) : [];
    $old['persons']  = (int)($_POST['persons'] ?? 1);
    foreach (['date', 'time', 'name', 'phone', 'email', 'note'] as $k) $old[$k] = trim((string)($_POST[$k] ?? ''));
    $old['notify']   = !empty($_POST['notify']);

    $time  = normTime($old['time']);
    $email = $old['email'];
    $notify = $old['notify'];
    if ($email === '') { $email = ADMIN_NO_EMAIL; $notify = false; }

    if (!$old['services'])            $error = 'Vyber aspoň jednu službu.';
    elseif (!validDate($old['date'])) $error = 'Zadej platné datum.';
    elseif ($time === null)           $error = 'Čas zadej ve tvaru HH:MM, třeba 15:30.';
    else {
        $r = createBooking([
            'services' => $old['services'], 'persons' => $old['persons'],
            'date' => $old['date'], 'time' => $time,
            'name' => $old['name'], 'email' => $email, 'phone' => $old['phone'], 'note' => $old['note'],
            'source' => 'admin', 'notify' => $notify,
        ]);
        if ($r['ok']) {
            $b = $r['booking'];
            flash('ok', 'Rezervace uložená: ' . $b['customer_name'] . ', ' . czDate($b['start_at']) . ' ' . czTime($b['start_at']) . '.'
                . ($notify ? ' Zákaznici odešlo potvrzení s pozvánkou do kalendáře.' : ' Zákaznici se nic neposílá.'));
            redirect('index.php?f=nadchazejici&id=' . $b['id']);
        }
        $error = $r['error'];
        $conflict = ($r['code'] ?? 0) === 409;
    }
}

adminPage('Nová rezervace', function () use ($cat, $groups, $limits, $old, $error, $conflict) {
    $today = date('Y-m-d');
    ?>
    <p class="eyebrow">Ruční zápis</p>
    <h1>Nová rezervace</h1>
    <p class="muted mt-2">Pro objednávky po telefonu nebo z&nbsp;Instagramu. Uloží se rovnou jako potvrzená a&nbsp;termín se zákaznicím přestane nabízet. Může být i&nbsp;mimo otevírací dobu.</p>
    <?php if ($error): ?>
      <div class="notice notice--err mt-3" role="alert">
        <?= h($error) ?>
        <?php if ($conflict): ?><br><small>V&nbsp;tom čase už něco je – zkontroluj <a class="link" href="index.php?f=nadchazejici">nadcházející rezervace</a> nebo <a class="link" href="blocks.php">blokace</a> a&nbsp;vyber jiný čas.</small><?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="form mt-3" autocomplete="off">
      <?= csrfField() ?>
      <div class="card">
        <h3>Služby</h3>
        <?php foreach ($groups as $g): ?>
          <p class="group-title"><?= h($g) ?></p>
          <div class="choice-grid choice-grid--svc">
            <?php foreach ($cat as $sid => $s): if ($s['group'] !== $g) continue; ?>
              <label class="choice">
                <input type="checkbox" name="services[]" value="<?= h($sid) ?>"<?= in_array($sid, $old['services'], true) ? ' checked' : '' ?>>
                <b><?= h($s['name']) ?></b>
                <small><?= h(czPrice((int)$s['price'], !empty($s['from']))) ?> · <?= (int)$s['duration'] ?>&nbsp;min<?= isset($s['maxPersons']) ? ' · max. ' . (int)$s['maxPersons'] . ' osoby' : '' ?></small>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <div class="field mt-3">
          <label for="persons">Počet osob</label>
          <select id="persons" name="persons">
            <?php for ($i = 1; $i <= (int)$limits['maxPersons']; $i++): ?>
              <option value="<?= $i ?>"<?= $old['persons'] === $i ? ' selected' : '' ?>><?= $i ?> <?= $i === 1 ? 'osoba' : 'osoby' ?></option>
            <?php endfor; ?>
          </select>
          <span class="hint">Délka i&nbsp;cena se násobí počtem osob. Kurz sebelíčení je nejvýš pro 2.</span>
        </div>
      </div>

      <div class="card">
        <h3>Termín</h3>
        <div class="form-row mt-2">
          <div class="field">
            <label for="date">Datum</label>
            <input type="date" id="date" name="date" required min="<?= h($today) ?>" value="<?= h($old['date']) ?>">
          </div>
          <div class="field">
            <label for="time">Začátek</label>
            <input type="text" id="time" name="time" required inputmode="numeric" placeholder="15:30" pattern="\s*[0-9]{1,2}\s*[:.]\s*[0-9]{2}\s*" value="<?= h($old['time']) ?>">
            <span class="hint">Ve tvaru HH:MM. Konec se dopočítá podle délky služeb.</span>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>Zákaznice</h3>
        <div class="form-row mt-2">
          <div class="field">
            <label for="name">Jméno a&nbsp;příjmení</label>
            <input type="text" id="name" name="name" required minlength="3" maxlength="120" value="<?= h($old['name']) ?>">
          </div>
          <div class="field">
            <label for="phone">Telefon</label>
            <input type="tel" id="phone" name="phone" required placeholder="604 123 456" value="<?= h($old['phone']) ?>">
          </div>
        </div>
        <div class="field mt-2">
          <label for="email">E‑mail (nepovinný)</label>
          <input type="email" id="email" name="email" maxlength="190" value="<?= h($old['email']) ?>">
          <span class="hint">Bez e‑mailu se zákaznici nic neposílá (ani připomínka den předem).</span>
        </div>
        <div class="field mt-2">
          <label for="note">Poznámka</label>
          <textarea id="note" name="note" maxlength="2000" placeholder="Např. objednala po telefonu, chce přirozený look…"><?= h($old['note']) ?></textarea>
        </div>
        <label class="check mt-2">
          <input type="checkbox" name="notify" value="1"<?= $old['notify'] ? ' checked' : '' ?>>
          <span>Poslat zákaznici potvrzení s&nbsp;pozvánkou do kalendáře (jen když je vyplněný e‑mail)</span>
        </label>
      </div>

      <div class="hero-actions hero-actions--start">
        <button class="btn btn-primary" type="submit">Uložit rezervaci</button>
        <a class="btn btn-outline" href="index.php">Zpět na přehled</a>
      </div>
    </form>
    <?php
}, ['active' => 'new']);
