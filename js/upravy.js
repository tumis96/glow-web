/* Režim úprav pro klientku (jen pilotní verze – před spuštěním webu odstranit).
   Zapne se odkazem s ?upravy=1 a drží se v prohlížeči, dokud ho klientka neukončí.
   Texty na stránce jdou přepsat přímo v místě, změny se ukládají do localStorage
   a tlačítkem „Odeslat změny“ se sestaví souhrn (sdílení na mobilu / schránka). */
(function () {
  'use strict';

  var KEY_ON = 'glow-upravy-on';
  var KEY_DATA = 'glow-upravy-data';
  var params = new URLSearchParams(location.search);
  if (params.get('upravy') === '1') { store(KEY_ON, '1'); }
  if (params.get('upravy') === '0') { store(KEY_ON, ''); }
  if (load(KEY_ON) !== '1') return;

  var page = location.pathname.split('/').pop() || 'index.html';
  var NAMES = {
    'index.html': 'Úvod', 'sluzby.html': 'Služby a ceník', 'rezervace.html': 'Rezervace', 'svatby.html': 'Svatby',
    'portfolio.html': 'Portfolio', 'o-mne.html': 'O mně', 'kontakt.html': 'Kontakt',
    'ochrana-osobnich-udaju.html': 'Ochrana osobních údajů', 'obchodni-podminky.html': 'Obchodní podmínky'
  };
  var SELECTOR = 'main h1, main h2, main h3, main h4, main p, main li, main summary, main dt, main dd, main blockquote footer, main .price, main .dur, main .price-row span, main .price-row b, main .choice b, main .choice small, main label, main .eyebrow, main .badge';

  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function data() { try { return JSON.parse(load(KEY_DATA) || '{}'); } catch (e) { return {}; } }
  function saveData(d) { store(KEY_DATA, JSON.stringify(d)); }
  function clean(s) { return (s || '').replace(/\s+/g, ' ').trim(); }

  // ---------- styly ----------
  var css = document.createElement('style');
  css.textContent = [
    '.upr-edit{outline:1px dashed rgba(168,115,106,.45);outline-offset:2px;cursor:text;border-radius:4px}',
    '.upr-edit:hover,.upr-edit:focus{outline:2px solid #a8736a;background:rgba(246,239,236,.7)}',
    '.upr-zmena{background:#fff3c4!important;outline-color:#d9a400}',
    '.upr-bar{position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 12px;background:#3a3335;color:#fff;border-radius:16px;box-shadow:0 12px 30px -10px rgba(0,0,0,.5);font:14px/1.4 Hind,Segoe UI,sans-serif}',
    '.upr-bar b{margin-right:auto;font-weight:600}',
    '.upr-bar button{font:inherit;font-weight:600;padding:9px 14px;border-radius:999px;border:1px solid rgba(255,255,255,.35);background:transparent;color:#fff;cursor:pointer}',
    '.upr-bar button.upr-main{background:#a8736a;border-color:#a8736a}',
    '.upr-panel{position:fixed;left:12px;right:12px;bottom:76px;z-index:9999;max-width:560px;margin-inline:auto;background:#fff;color:#3a3335;border-radius:16px;padding:18px;box-shadow:0 20px 50px -20px rgba(0,0,0,.5);font:15px/1.5 Hind,Segoe UI,sans-serif;max-height:70vh;overflow:auto}',
    '.upr-panel h3{margin:0 0 8px;font:600 17px/1.3 Hind,Segoe UI,sans-serif}',
    '.upr-panel textarea{width:100%;min-height:110px;padding:10px;border:1px solid #b8aeaa;border-radius:10px;font:inherit;margin-top:8px}',
    '.upr-panel .upr-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}',
    '.upr-panel button{font:inherit;font-weight:600;padding:9px 14px;border-radius:999px;border:1px solid #3a3335;background:#3a3335;color:#fff;cursor:pointer}',
    '.upr-panel button.upr-sec{background:#fff;color:#3a3335}',
    '.upr-panel .upr-status{margin-top:10px;font-size:14px;color:#766b6f}',
    'body{padding-bottom:90px}'
  ].join('');
  document.head.appendChild(css);

  // ---------- editovatelné prvky ----------
  var all = data();
  var pageData = all[page] || { texty: {}, poznamka: '' };
  var items = [];
  var nodes = Array.prototype.slice.call(document.querySelectorAll(SELECTOR)).filter(function (el) {
    if (el.closest('.pilot-note, .upr-bar, .upr-panel, form .notice, dialog')) return false;
    if (el.querySelector(SELECTOR)) return false;           // jen „listové“ texty
    if (el.closest('[contenteditable]')) return false;
    return clean(el.textContent).length > 0;
  });
  nodes.forEach(function (el, i) {
    var id = 't' + i;
    var puv = clean(el.textContent);
    el.setAttribute('contenteditable', 'plaintext-only');
    if (el.contentEditable !== 'plaintext-only') el.setAttribute('contenteditable', 'true');
    el.classList.add('upr-edit');
    el.setAttribute('spellcheck', 'true');
    var saved = pageData.texty[id];
    if (saved && saved.puv === puv && saved.novy !== puv) { el.textContent = saved.novy; el.classList.add('upr-zmena'); }
    items.push({ id: id, el: el, puv: puv });
    el.addEventListener('input', function () {
      var novy = clean(el.textContent);
      if (novy !== puv) { pageData.texty[id] = { puv: puv, novy: novy }; el.classList.add('upr-zmena'); }
      else { delete pageData.texty[id]; el.classList.remove('upr-zmena'); }
      persist();
    });
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && el.tagName !== 'P' && el.tagName !== 'LI' && el.tagName !== 'DD') e.preventDefault(); });
  });
  // interní odkazy ponesou ?upravy=1 i pro případ, že prohlížeč neukládá localStorage
  document.querySelectorAll('a[href]').forEach(function (a) {
    var h = a.getAttribute('href');
    if (/^[a-z0-9-]+\.html(#.*)?$/i.test(h)) a.setAttribute('href', h.replace(/\.html/, '.html?upravy=1'));
  });
  // odkazy uvnitř editovaného textu neproklikávat
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (a && a.closest('.upr-edit')) e.preventDefault();
  }, true);

  function persist() { all[page] = pageData; saveData(all); refreshBar(); }
  function countAll() {
    var n = 0, d = data();
    Object.keys(d).forEach(function (p) { n += Object.keys(d[p].texty || {}).length + (d[p].poznamka ? 1 : 0); });
    return n;
  }

  // ---------- lišta ----------
  var bar = document.createElement('div');
  bar.className = 'upr-bar';
  bar.innerHTML = '<b>Režim úprav: klikni na text a přepiš ho</b>' +
    '<button type="button" data-a="note">Poznámka ke stránce</button>' +
    '<button type="button" data-a="send" class="upr-main">Odeslat změny (<span data-n>0</span>)</button>' +
    '<button type="button" data-a="help" aria-label="Nápověda">?</button>' +
    '<button type="button" data-a="off">Ukončit</button>';
  document.body.appendChild(bar);
  function refreshBar() { bar.querySelector('[data-n]').textContent = countAll(); }
  refreshBar();

  var panel = null;
  function closePanel() { if (panel) { panel.remove(); panel = null; } }
  function openPanel(html) { closePanel(); panel = document.createElement('div'); panel.className = 'upr-panel'; panel.innerHTML = html; document.body.appendChild(panel); return panel; }

  bar.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var a = b.getAttribute('data-a');
    if (a === 'help') {
      openPanel('<h3>Jak to funguje</h3><p>Klikni na jakýkoliv text na stránce a přepiš ho, jak chceš. Změněné texty zežloutnou. Můžeš procházet další stránky přes menu, změny se ti průběžně ukládají v tomto prohlížeči.</p><p>Když nechceš měnit text, ale třeba fotku, pořadí nebo ti něco chybí, použij „Poznámka ke stránce“.</p><p>Nakonec klikni na „Odeslat změny“ – souhrn pošleš Petrovi přes WhatsApp nebo Messenger.</p><div class="upr-actions"><button type="button" class="upr-sec" data-close>Zavřít</button></div>');
    }
    if (a === 'note') {
      var p = openPanel('<h3>Poznámka ke stránce „' + (NAMES[page] || page) + '“</h3><p>Co změnit kromě textů: fotky, pořadí, co chybí, co vyhodit…</p><textarea id="upr-note"></textarea><div class="upr-actions"><button type="button" data-save-note>Uložit poznámku</button><button type="button" class="upr-sec" data-close>Zavřít</button></div>');
      p.querySelector('#upr-note').value = pageData.poznamka || '';
      p.querySelector('#upr-note').focus();
    }
    if (a === 'send') {
      var text = summary();
      var p2 = openPanel('<h3>Souhrn změn (' + countAll() + ')</h3><textarea id="upr-out" readonly></textarea><div class="upr-actions"><button type="button" data-share>Poslat Petrovi</button><button type="button" class="upr-sec" data-copy>Zkopírovat</button><button type="button" class="upr-sec" data-close>Zavřít</button></div><div class="upr-status" aria-live="polite"></div>');
      p2.querySelector('#upr-out').value = text;
    }
    if (a === 'off') {
      if (countAll() > 0 && !window.confirm('Máš neodeslané změny. Opravdu ukončit režim úprav? Změny zůstanou uložené v prohlížeči.')) return;
      store(KEY_ON, '');
      location.href = location.pathname + '?upravy=0';
    }
  });

  document.addEventListener('click', function (e) {
    if (!panel) return;
    var b = e.target.closest('button'); if (!b || !panel.contains(b)) return;
    if (b.hasAttribute('data-close')) closePanel();
    if (b.hasAttribute('data-save-note')) { pageData.poznamka = clean(panel.querySelector('#upr-note').value); persist(); closePanel(); }
    if (b.hasAttribute('data-copy') || b.hasAttribute('data-share')) {
      var out = panel.querySelector('#upr-out'); var st = panel.querySelector('.upr-status'); var txt = out.value;
      if (b.hasAttribute('data-share') && navigator.share) {
        navigator.share({ title: 'Změny na webu Glow', text: txt }).then(function () { st.textContent = 'Odesláno.'; }, function () { st.textContent = 'Sdílení se nepovedlo – použij „Zkopírovat“.'; });
        return;
      }
      var ok = function () { st.textContent = 'Zkopírováno. Vlož to Petrovi do zprávy.'; };
      var fail = function () { out.focus(); out.select(); try { document.execCommand('copy'); ok(); } catch (err) { st.textContent = 'Označ text a zkopíruj ho ručně.'; } };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(ok, fail); else fail();
    }
  });

  function summary() {
    var d = data(); var lines = ['Změny na webu Glow – ' + new Date().toLocaleDateString('cs-CZ'), ''];
    Object.keys(NAMES).concat(Object.keys(d)).filter(function (p, i, arr) { return d[p] && arr.indexOf(p) === i; }).forEach(function (p) {
      var pd = d[p]; var keys = Object.keys(pd.texty || {});
      if (!keys.length && !pd.poznamka) return;
      lines.push('=== ' + (NAMES[p] || p) + ' ===');
      keys.forEach(function (k, i) { lines.push((i + 1) + ') PŮVODNĚ: ' + pd.texty[k].puv); lines.push('   NOVĚ: ' + pd.texty[k].novy); });
      if (pd.poznamka) lines.push('Poznámka: ' + pd.poznamka);
      lines.push('');
    });
    if (lines.length === 2) lines.push('(zatím žádné změny)');
    return lines.join('\n');
  }
})();
