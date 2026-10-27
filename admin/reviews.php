<?php
/**
 * Administrace – recenze klientek: schválit / nezveřejnit / upravit text / smazat.
 * Filtr ?f=cekajici|zverejnene|nezverejnene|vse. Zveřejněné recenze se zobrazují na úvodní stránce (nejnovější první).
 */
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/reviews.php';

requireAdmin();
reviewsEnsureSchema();

const RFILTERS = [
    'cekajici'     => 'Ke schválení',
    'zverejnene'   => 'Zveřejněné',
    'nezverejnene' => 'Nezveřejněné',
    'vse'          => 'Vše',
];
$f = (string)($_REQUEST['f'] ?? '');
if (!isset(RFILTERS[$f])) $f = 'cekajici';

if (isPost()) {
    csrfCheck();
    $r      = loadReview((int)($_POST['id'] ?? 0));
    $action = (string)($_POST['action'] ?? '');
    if (!$r) { flash('err', 'Recenze nebyla nalezena.'); redirect('reviews.php'); }
    switch ($action) {
        case 'approve':
            reviewSetStatus($r, 'approved');
            flash('ok', 'Recenze od ' . $r['name'] . ' je zveřejněná na webu.');
            break;
        case 'reject':
            reviewSetStatus($r, 'rejected');
            flash('ok', 'Recenze od ' . $r['name'] . ' se na webu nezobrazuje.');
            break;
        case 'edit':
            $name    = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')) ?? '');
            $service = trim((string)($_POST['service'] ?? ''));
            $text    = trim(str_replace("\r\n", "\n", (string)($_POST['text'] ?? '')));
            if (mb_strlen($name) < 2 || mb_strlen($name) > REVIEW_NAME_MAX) { flash('err', 'Jméno musí mít 2 až ' . REVIEW_NAME_MAX . ' znaků.'); break; }
            if ($service !== '' && !in_array($service, REVIEW_SERVICES, true)) { flash('err', 'Neznámá služba.'); break; }
            if (mb_strlen($text) < 5 || mb_strlen($text) > REVIEW_TEXT_MAX) { flash('err', 'Text recenze musí mít 5 až ' . REVIEW_TEXT_MAX . ' znaků.'); break; }
            reviewUpdateText($r, $name, $service !== '' ? $service : null, $text);
            flash('ok', 'Recenze upravená.' . ($r['status'] !== 'approved' ? ' Nezapomeň ji schválit, pokud má být na webu.' : ''));
            break;
        case 'delete':
            reviewDelete($r);
            flash('ok', 'Recenze od ' . $r['name'] . ' smazaná.');
            redirect('reviews.php?f=' . $f);
        default:
            flash('err', 'Neznámá akce.');
    }
    redirect('reviews.php?f=' . $f . '#r-' . $r['id']);
}

$where = match ($f) {
    'cekajici'     => "status = 'pending'",
    'zverejnene'   => "status = 'approved'",
    'nezverejnene' => "status = 'rejected'",
    default        => '1=1',
};
$list = db()->query("SELECT * FROM reviews WHERE $where ORDER BY (status = 'pending') DESC, created_at DESC, id DESC LIMIT 300")->fetchAll();
$counts = ['pending' => reviewsCount('pending'), 'approved' => reviewsCount('approved'), 'rejected' => reviewsCount('rejected')];

adminPage('Recenze', function () use ($f, $list, $counts) {
    $badge = ['pending' => ['Ke schválení', 'badge--wait'], 'approved' => ['Zveřejněná', 'badge--ok'], 'rejected' => ['Nezveřejněná', 'badge--off']];
    ?>
    <p class="eyebrow">Recenze klientek</p>
    <div class="stats mt-2">
      <a class="stat<?= $counts['pending'] ? ' is-hot' : '' ?>" href="reviews.php?f=cekajici"><b><?= $counts['pending'] ?></b><span>ke schválení</span></a>
      <a class="stat" href="reviews.php?f=zverejnene"><b><?= $counts['approved'] ?></b><span>na webu</span></a>
      <a class="stat" href="reviews.php?f=nezverejnene"><b><?= $counts['rejected'] ?></b><span>nezveřejněné</span></a>
    </div>
    <p class="muted mt-2">Nová recenze z webu se nikde neukáže, dokud ji neschválíš. Text můžeš před zveřejněním zkrátit nebo opravit. E‑mail klientky se na webu nikdy nezobrazuje.</p>

    <div class="toolbar mt-3">
      <nav class="pills pills--tabs" aria-label="Filtr recenzí">
        <?php foreach (RFILTERS as $key => $label): ?>
          <a href="reviews.php?f=<?= h($key) ?>"<?= $key === $f ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="list mt-3">
      <?php if (!$list): ?>
        <div class="empty"><?= $f === 'cekajici' ? 'Žádná recenze nečeká na schválení.' : 'Nic tu není.' ?></div>
      <?php else: foreach ($list as $r): [$label, $cls] = $badge[$r['status']] ?? [$r['status'], '']; $hidden = csrfField() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'; ?>
        <article class="bk" id="r-<?= (int)$r['id'] ?>">
          <div class="bk-head">
            <div class="bk-when">
              <?= h($r['name']) ?><?= $r['service'] ? ' · ' . h($r['service']) : '' ?>
              <small><span style="color:var(--accent);letter-spacing:2px"><?= h(reviewStars((int)$r['rating'])) ?></span> · <?= h(czDate($r['created_at'])) ?><?= $r['email'] ? ' · ' . h($r['email']) : '' ?></small>
            </div>
            <div class="bk-badges"><span class="badge <?= h($cls) ?>"><?= h($label) ?></span></div>
          </div>
          <div class="bk-note"><?= nl2br(h($r['text'])) ?></div>
          <div class="bk-actions">
            <?php if ($r['status'] !== 'approved'): ?>
              <form method="post"><?= $hidden ?><input type="hidden" name="action" value="approve"><button class="btn btn-ok btn-sm" type="submit">Zveřejnit</button></form>
            <?php endif; ?>
            <?php if ($r['status'] !== 'rejected'): ?>
              <form method="post"><?= $hidden ?><input type="hidden" name="action" value="reject"><button class="btn btn-outline btn-sm" type="submit"><?= $r['status'] === 'approved' ? 'Stáhnout z webu' : 'Nezveřejňovat' ?></button></form>
            <?php endif; ?>
            <details class="more">
              <summary class="btn btn-outline btn-sm">Upravit</summary>
              <form method="post" class="more-panel"><?= $hidden ?><input type="hidden" name="action" value="edit">
                <div class="form-row">
                  <div class="field"><label for="name-<?= (int)$r['id'] ?>">Jméno</label><input type="text" id="name-<?= (int)$r['id'] ?>" name="name" maxlength="<?= REVIEW_NAME_MAX ?>" required value="<?= h($r['name']) ?>"></div>
                  <div class="field"><label for="svc-<?= (int)$r['id'] ?>">Služba</label>
                    <select id="svc-<?= (int)$r['id'] ?>" name="service"><option value="">– neuvádět –</option>
                      <?php foreach (REVIEW_SERVICES as $s): ?><option<?= $s === $r['service'] ? ' selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?>
                    </select></div>
                </div>
                <div class="field"><label for="text-<?= (int)$r['id'] ?>">Text recenze</label><textarea id="text-<?= (int)$r['id'] ?>" name="text" maxlength="<?= REVIEW_TEXT_MAX ?>" required><?= h($r['text']) ?></textarea></div>
                <div><button class="btn btn-primary btn-sm" type="submit">Uložit změny</button></div>
              </form>
            </details>
            <form method="post" onsubmit="return confirm('Opravdu recenzi smazat? Nejde to vrátit.');"><?= $hidden ?><input type="hidden" name="action" value="delete"><button class="btn btn-danger btn-sm" type="submit">Smazat</button></form>
          </div>
        </article>
      <?php endforeach; endif; ?>
    </div>
    <?php
}, ['active' => 'reviews']);
