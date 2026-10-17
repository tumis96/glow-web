<?php
/** Jednoduchá HTML stránka ve stylu webu pro odkazy z e-mailů (zrušení, potvrzení) a chyby. */
declare(strict_types=1);

function renderPage(string $title, string $bodyHtml, string $eyebrow = 'Glow by Petra Jahodová'): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . ' | Glow by Petra Jahodová</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..500;1,9..144,300..500&family=Hind:wght@400;500;600&display=swap">'
        . '<link rel="stylesheet" href="' . h(siteUrl('css/style.css?v=202610172128')) . '">'
        . '<style>.box{max-width:620px;margin:48px auto;padding:0 20px}.box h1{font-size:clamp(30px,5vw,42px)}.box .lead{margin-top:14px}.box form{margin-top:20px}</style>'
        . '</head><body>'
        . '<header class="site-header"><div class="container"><a class="brand" href="' . h(siteUrl()) . '"><img src="' . h(siteUrl('img/logo.png')) . '" alt="" width="48" height="48"><span class="brand-name">Glow <small>by Petra Jahodová</small></span></a></div></header>'
        . '<main><div class="box"><p class="eyebrow">' . h($eyebrow) . '</p><h1>' . h($title) . '</h1>' . $bodyHtml . '</div></main>'
        . '<footer class="site-footer"><div class="container"><div class="footer-legal"><span>© ' . date('Y') . ' Petra Jahodová · IČO 24884995 · Lichnov 581, 742 75 Lichnov</span></div></div></footer>'
        . '</body></html>';
    exit;
}
