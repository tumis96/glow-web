/* Recenze na úvodní stránce: načtení schválených z API a formulář pro přidání nové (čeká na schválení Petrou). */
(function () {
  'use strict';

  var list = document.getElementById('recenze-list');
  if (!list) return;
  var more = document.getElementById('recenze-vice');
  var LIMIT = 6;   // kolik recenzí ukázat hned, zbytek po kliknutí

  function stars(n) {
    var s = '';
    for (var i = 1; i <= 5; i++) s += i <= n ? '★' : '☆';
    return s;
  }

  function render(items) {
    list.innerHTML = '';
    items.forEach(function (r, i) {
      var q = document.createElement('blockquote');
      q.className = 'quote';
      if (i >= LIMIT) q.hidden = true;
      var p = document.createElement('p');
      p.textContent = '„' + r.text + '“';
      var f = document.createElement('footer');
      var who = document.createElement('span');
      who.textContent = r.name + (r.service ? ', ' + r.service.charAt(0).toLowerCase() + r.service.slice(1) : '');
      f.appendChild(who);
      if (r.rating) {
        var s = document.createElement('span');
        s.className = 'quote-stars';
        s.setAttribute('role', 'img');
        s.setAttribute('aria-label', r.rating + ' z 5 hvězdiček');
        s.textContent = stars(r.rating);
        f.appendChild(s);
      }
      if (r.when) {
        var w = document.createElement('span');
        w.className = 'quote-when';
        w.textContent = r.when;
        f.appendChild(w);
      }
      q.appendChild(p);
      q.appendChild(f);
      list.appendChild(q);
    });
    if (more) more.hidden = items.length <= LIMIT;
  }

  if (more) {
    more.addEventListener('click', function () {
      list.querySelectorAll('.quote[hidden]').forEach(function (q) { q.hidden = false; });
      more.hidden = true;
    });
  }

  fetch('api/reviews.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function (res) { return res.json(); })
    .then(function (d) { if (d && d.ok && d.reviews && d.reviews.length) render(d.reviews); })
    .catch(function () { /* zůstanou recenze zapsané přímo v HTML */ });

  // ---------- formulář ----------
  var box    = document.getElementById('recenze-form-box');
  var open   = document.getElementById('recenze-pridat');
  var form   = document.getElementById('recenze-form');
  if (!box || !open || !form) return;
  var ok     = document.getElementById('recenze-ok');
  var chyba  = document.getElementById('recenze-chyba');
  var submit = document.getElementById('recenze-odeslat');

  open.addEventListener('click', function () {
    var show = box.hidden;
    box.hidden = !show;
    open.setAttribute('aria-expanded', show ? 'true' : 'false');
    if (show) {
      box.scrollIntoView({ block: 'start', behavior: 'smooth' });
      var first = form.querySelector('input[type="text"]');
      if (first) first.focus({ preventScroll: true });
    }
  });

  function setInvalid(el, invalid) {
    var wrap = el.closest('.field');
    if (wrap) wrap.classList.toggle('is-invalid', invalid);
    var check = el.closest('.check');
    if (check) check.classList.toggle('is-invalid', invalid);
    if (el.setAttribute) el.setAttribute('aria-invalid', invalid ? 'true' : 'false');
  }

  function rating() {
    var r = form.querySelector('input[name="hodnoceni"]:checked');
    return r ? parseInt(r.value, 10) : 0;
  }

  function validate() {
    var first = null;
    var checks = [
      { el: form.jmeno, valid: function () { return form.jmeno.value.trim().length >= 2; } },
      { el: form.querySelector('.stars input'), wrap: form.querySelector('.stars').closest('.field'), valid: function () { return rating() >= 1; } },
      { el: form.text, valid: function () { return form.text.value.trim().length >= 20; } },
      { el: form.email, valid: function () { var v = form.email.value.trim(); return v === '' || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); } },
      { el: form.gdpr, valid: function () { return form.gdpr.checked; } }
    ];
    checks.forEach(function (c) {
      var bad = !c.valid();
      if (c.wrap) c.wrap.classList.toggle('is-invalid', bad); else setInvalid(c.el, bad);
      if (bad && !first) first = c.el;
    });
    return first;
  }

  function showError(msg) {
    chyba.textContent = msg;
    chyba.hidden = false;
    chyba.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    chyba.focus({ preventScroll: true });
  }

  function setBusy(busy) {
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    submit.disabled = busy;
    submit.textContent = busy ? 'Odesílám…' : 'Odeslat recenzi';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (form.getAttribute('aria-busy') === 'true') return;
    chyba.hidden = true;
    var first = validate();
    if (first) { first.focus(); return; }

    var payload = {
      name: form.jmeno.value.trim(),
      email: form.email.value.trim(),
      service: form.sluzba.value,
      rating: rating(),
      text: form.text.value.trim(),
      gdpr: true,
      website: form.website ? form.website.value : ''   // honeypot – má zůstat prázdné
    };
    setBusy(true);
    var status = 0;
    fetch('api/reviews.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) { status = res.status; return res.json().catch(function () { return null; }); })
      .then(function (data) {
        setBusy(false);
        if (!data || data.ok === false) {
          showError(data && data.error ? data.error : 'Server teď neodpovídá (' + status + '). Zkuste to prosím za chvíli.');
          return;
        }
        form.reset();
        form.hidden = true;
        ok.hidden = false;
        ok.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        ok.focus({ preventScroll: true });
      })
      .catch(function () {
        setBusy(false);
        showError('Nepodařilo se spojit se serverem. Zkontrolujte prosím připojení a zkuste to znovu.');
      });
  });

  form.addEventListener('input', function (e) {
    var el = e.target;
    if (el.closest('.stars')) { form.querySelector('.stars').closest('.field').classList.remove('is-invalid'); return; }
    if (el.type === 'checkbox' ? el.checked : el.value.trim() !== '') setInvalid(el, false);
  });
})();
