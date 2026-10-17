<?php
/**
 * Přihlášení do administrace.
 * První spuštění (žádný hash v config.php ani v settings): nastavení hesla → uloží se do settings a rovnou přihlásí.
 */
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

adminSession();
if (adminLoggedIn()) redirect('index.php');

$hash     = adminPasswordHash();
$firstRun = $hash === null;
$error    = '';

if (isPost()) {
    csrfCheck();
    if ($firstRun) {
        $p1 = (string)($_POST['pass1'] ?? '');
        $p2 = (string)($_POST['pass2'] ?? '');
        if (mb_strlen($p1) < 10)  $error = 'Heslo musí mít aspoň 10 znaků.';
        elseif ($p1 !== $p2)      $error = 'Hesla se neshodují, zkus to znovu.';
        else {
            settingSet('admin_pass_hash', password_hash($p1, PASSWORD_DEFAULT));
            logEvent('admin:password_set', null, 'první spuštění');
            adminLogin();
            flash('ok', 'Heslo je nastavené a jsi přihlášená. Vítej v administraci.');
            redirect('index.php');
        }
    } else {
        if (loginBlocked()) {
            http_response_code(429);
            $error = 'Příliš mnoho pokusů. Počkej 15 minut a zkus to znovu.';
        } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
            loginClearFails();
            adminLogin();
            $next = (string)($_SESSION['after_login'] ?? '');
            unset($_SESSION['after_login']);
            redirect($next !== '' ? $next : 'index.php');
        } else {
            loginFailed();
            $error = loginBlocked() ? 'Příliš mnoho pokusů. Počkej 15 minut a zkus to znovu.' : 'Špatné heslo.';
            if (loginBlocked()) http_response_code(429);
        }
    }
} elseif (!$firstRun && loginBlocked()) {
    http_response_code(429);
    $error = 'Příliš mnoho pokusů o přihlášení. Počkej 15 minut a zkus to znovu.';
}

adminPage($firstRun ? 'Nastav heslo' : 'Přihlášení', function () use ($firstRun, $error) {
    ?>
    <div class="login-box">
      <div class="card">
        <p class="eyebrow">Administrace</p>
        <?php if ($firstRun): ?>
          <h1>Nastav heslo do&nbsp;administrace</h1>
          <p class="mt-2 muted">Administrace ještě nemá heslo. Zvol si ho teď – budeš ho používat pro přihlášení na tomhle i&nbsp;na dalších zařízeních. Aspoň 10&nbsp;znaků, klidně celá věta.</p>
        <?php else: ?>
          <h1>Přihlášení</h1>
        <?php endif; ?>
        <?php if ($error): ?><div class="notice notice--err mt-3" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form mt-3" autocomplete="on">
          <?= csrfField() ?>
          <?php if ($firstRun): ?>
            <div class="field">
              <label for="pass1">Nové heslo</label>
              <input type="password" id="pass1" name="pass1" required minlength="10" autocomplete="new-password" autofocus>
              <span class="hint">Minimálně 10&nbsp;znaků.</span>
            </div>
            <div class="field">
              <label for="pass2">Heslo ještě jednou</label>
              <input type="password" id="pass2" name="pass2" required minlength="10" autocomplete="new-password">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Nastavit heslo a&nbsp;přihlásit</button>
          <?php else: ?>
            <div class="field">
              <label for="password">Heslo</label>
              <input type="password" id="password" name="password" required autocomplete="current-password" autofocus>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Přihlásit</button>
            <p class="form-note">Zapomenuté heslo? Napiš Petrovi, heslo se dá v&nbsp;databázi vynulovat a&nbsp;nastavit znovu.</p>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php
}, ['nav' => false]);
