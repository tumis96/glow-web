<?php
/**
 * Společná kostra stránek administrace: hlavička s logem a menu, zprávy po přesměrování, patička.
 * Použití: adminPage('Rezervace', function () { ...echo HTML... }, ['active' => 'index']);
 * $opts: 'nav' => bool (menu; na loginu false), 'active' => 'index'|'new'|'blocks'|'reviews'
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function adminPage(string $title, callable $body, array $opts = []): void {
    $nav    = $opts['nav'] ?? true;
    $active = (string)($opts['active'] ?? '');
    $menu   = [
        'index'  => ['index.php',  'Rezervace'],
        'new'    => ['new.php',    'Nová rezervace'],
        'blocks' => ['blocks.php', 'Blokace'],
        'reviews' => ['reviews.php', 'Recenze'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | Administrace Glow</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..500;1,9..144,300..500&family=Hind:wght@400;500;600&display=swap">
<link rel="stylesheet" href="../css/style.css">
<style>
  .adm-main { padding-block: 24px 56px; }
  .adm-main h1 { font-size: clamp(28px, 5vw, 40px); }
  .adm-main h2 { font-size: clamp(22px, 3vw, 28px); }
  .adm-navbar { background: var(--surface); border-bottom: 1px solid var(--line); }
  .pills { display: flex; gap: 8px; overflow-x: auto; padding: 10px 0; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
  .pills::-webkit-scrollbar { display: none; }
  .pills a { flex: none; padding: 8px 14px; border-radius: var(--r-pill); border: 1px solid var(--line); background: var(--white);
             font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; text-decoration: none; color: var(--muted); }
  .pills a:hover { color: var(--ink); border-color: var(--accent); }
  .pills a.is-active { background: var(--ink); color: var(--white); border-color: var(--ink); }
  .pills a.is-out { margin-left: auto; border-color: transparent; background: transparent; }
  .pills--tabs { padding: 0; }
  .toolbar { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; justify-content: space-between; }
  .search { display: flex; gap: 8px; flex: 1 1 240px; max-width: 420px; }
  .search input { flex: 1; min-width: 0; }
  .stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
  @media (min-width: 640px) { .stats { grid-template-columns: repeat(4, 1fr); } }
  .stat { display: block; padding: 14px 16px; background: var(--white); border: 1px solid var(--line); border-radius: var(--r-card); text-decoration: none; color: inherit; }
  .stat:hover { border-color: var(--accent); }
  .stat b { display: block; font-family: var(--font-display); font-weight: 300; font-size: 32px; line-height: 1.05; font-variant-numeric: tabular-nums; }
  .stat.is-hot b { color: var(--accent-ink); }
  .stat span { display: block; margin-top: 4px; font-size: 11px; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; font-weight: 600; }
  .list { display: grid; gap: 12px; }
  .bk { padding: 18px; background: var(--white); border: 1px solid var(--line); border-radius: var(--r-card); }
  .bk.is-hl { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(168, 115, 106, .18); }
  .bk.is-past { opacity: .78; }
  .bk-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
  .bk-when { font-family: var(--font-display); font-size: 21px; line-height: 1.2; }
  .bk-when small { display: block; font-family: var(--font-body); font-size: 14px; color: var(--muted); margin-top: 2px; }
  .bk-badges { display: flex; gap: 6px; flex-wrap: wrap; }
  .bk-meta { margin: 12px 0 0; display: grid; grid-template-columns: auto 1fr; gap: 5px 14px; font-size: 15px; }
  .bk-meta dt { color: var(--muted); font-size: 13px; padding-top: 2px; }
  .bk-meta dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
  .bk-meta a { color: var(--accent-ink); text-decoration: none; }
  .bk-meta a:hover { text-decoration: underline; }
  .bk-note { margin-top: 12px; padding: 10px 12px; background: var(--surface); border-radius: 10px; font-size: 14px; font-style: italic; white-space: pre-line; }
  .bk-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; align-items: center; }
  .bk-actions form { display: inline-flex; margin: 0; }
  .bk-foot { margin-top: 10px; font-size: 12px; color: var(--muted); }
  .more { display: inline-flex; flex-direction: column; }
  .more[open] { flex: 1 0 100%; }
  .more summary { list-style: none; display: inline-flex; align-self: flex-start; cursor: pointer; }
  .more summary::-webkit-details-marker { display: none; }
  .more-panel { margin-top: 10px; padding: 14px; background: var(--surface); border-radius: 12px; display: grid; gap: 10px; }
  .more-panel textarea { min-height: 80px; }
  .badge--wait { background: #fff3df; color: #8a5a12; }
  .badge--ok { background: #e9f3ea; color: #2f6b3a; }
  .badge--off { background: #eee9e8; color: #766b6f; }
  .badge--src { background: transparent; border: 1px solid var(--line); color: var(--muted); }
  .badge--wed { background: #f3e9f1; color: #7a4d70; }
  .notice--err { background: #fbeae8; color: #7a2d26; }
  .notice--warn { background: #fff3df; color: #6b4e1f; }
  .notice + .notice { margin-top: 10px; }
  .btn-danger { background: transparent; color: #8a2f28; border-color: #d49c97; }
  .btn-danger:hover { background: #fbeae8; }
  .btn-ok { background: #2f6b3a; border-color: #2f6b3a; color: var(--white); }
  .btn-ok:hover { background: #3d8449; border-color: #3d8449; }
  .btn { white-space: normal; text-align: center; }
  .empty { padding: 32px 18px; text-align: center; color: var(--muted); background: var(--surface); border-radius: var(--r-card); }
  .table td form { margin: 0; }
  .choice-grid--svc { grid-template-columns: 1fr; }
  @media (min-width: 640px) { .choice-grid--svc { grid-template-columns: repeat(2, 1fr); } }
  .group-title { margin: 18px 0 8px; font-size: 12px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: var(--accent-ink); }
  .group-title:first-child { margin-top: 0; }
  input[type="password"], input[type="search"] { width: 100%; padding: 12px 14px; font: inherit; font-size: 16px; color: var(--ink);
    background: var(--white); border: 1px solid #b8aeaa; border-radius: 12px; -webkit-appearance: none; appearance: none; }
  .login-box { max-width: 440px; margin: 40px auto 0; }
  .login-box .card { padding: 28px 22px; }
  @media (min-width: 640px) { .login-box .card { padding: 36px; } }
</style>
</head>
<body class="adm">
<header class="site-header">
  <div class="container">
    <a class="brand" href="index.php" aria-label="Administrace – přehled rezervací">
      <img src="../img/logo.png" alt="" width="48" height="48">
      <span class="brand-name">Glow <small>· administrace</small></span>
    </a>
    <?php if ($nav): ?><a class="btn btn-outline btn-sm header-cta" href="new.php">+ Rezervace</a><?php endif; ?>
  </div>
</header>
<?php if ($nav): ?>
<div class="adm-navbar">
  <div class="container">
    <nav class="pills" aria-label="Menu administrace">
      <?php foreach ($menu as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $key === $active ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
      <a class="is-out" href="logout.php">Odhlásit</a>
    </nav>
  </div>
</div>
<?php endif; ?>
<main class="adm-main">
  <div class="container">
    <?php foreach (flashPull() as [$type, $message]): ?>
      <div class="notice notice--<?= $type === 'ok' ? 'ok' : ($type === 'warn' ? 'warn' : 'err') ?> mt-1" role="status"><?= h($message) ?></div>
    <?php endforeach; ?>
    <?php $body(); ?>
  </div>
</main>
<footer class="site-footer">
  <div class="container">
    <div class="footer-legal">
      <span>Administrace · Glow by Petra Jahodová</span>
      <a href="../">Zpět na web</a>
    </div>
  </div>
</footer>
</body>
</html>
<?php
}
