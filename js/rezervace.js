/* ==========================================================================
   Glow by Petra Jahodová – online rezervace ve 3 krocích
   Data bere z PHP API (api/config.php, api/availability.php, api/bookings.php).
   Čisté vanilla JS (ES2017: async/await, bez knihoven).

   Struktura:
     1. pomocné funkce – data VŽDY v lokálním čase (žádné UTC / toISOString), formátování, volání API
     2. stav rezervace a prvky stránky
     3. KROK 1 – služby (více najednou, zaškrtávací volby) a počet osob
     4. KROK 2 – kalendář (dny z API) a časy (sloty z API)
     5. KROK 3 – formulář, validace, odeslání, potvrzení
     6. START – načtení konfigurace z API, předvýběr z URL (?sluzba=denni,uces-jednoduchy)

   Tvar odpovědí API (viz api/*.php):
     config.php        → { ok, minDate, maxDate, services: {id: {name, price, duration, group, from?, maxPersons?}},
                            groups: [názvy skupin], rules: [{days, from, to, label, services, months?}], limits: {…} }
     availability.php  → ?month=YYYY-MM  { ok, days: {'YYYY-MM-DD': true|false}, hint }
                         ?date=YYYY-MM-DD { ok, slots: ['15:30', …], reason|null, duration, price, priceLabel, label }
     bookings.php POST → { ok, id, summary: {service, date, time, persons, price, duration} }
                         chyba: { ok: false, error } se stavem 400 / 409 (termín mezitím obsazen) / 429 / 500
   ========================================================================== */
(function () {
  'use strict';

  const API = 'api/';
  const TELEFON = '+420 604 643 157';
  const TELEFON_HREF = 'tel:+420604643157';

  const DNY = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
  const DNY_KRATCE = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne']; // kalendář začíná pondělím
  const MESICE = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
  const MESICE_2P = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
  const NBSP = ' ';

  /* ------------------------------------------------------------------
     1. POMOCNÉ FUNKCE – data a formátování (lokální čas)
     ------------------------------------------------------------------ */
  const dvoj = n => (n < 10 ? '0' : '') + n;

  // Půlnoc daného dne v lokálním čase
  const den = (y, m, d) => new Date(y, m, d, 0, 0, 0, 0);
  const prvniVMesici = d => den(d.getFullYear(), d.getMonth(), 1);
  const stejnyDen = (a, b) => a.getTime() === b.getTime();

  // 'RRRR-MM-DD' z lokálních složek (ne toISOString – to by v zimě/létě ujelo o den) a zpět
  const klic = d => d.getFullYear() + '-' + dvoj(d.getMonth() + 1) + '-' + dvoj(d.getDate());
  const klicMesice = d => d.getFullYear() + '-' + dvoj(d.getMonth() + 1);
  function zRetezce(s) {
    const p = String(s || '').split('-');
    return den(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
  }

  // 'HH:MM' <-> minuty od půlnoci
  const naMinuty = hhmm => { const p = String(hhmm).split(':'); return Number(p[0]) * 60 + Number(p[1]); };
  const naCas = m => dvoj(Math.floor(m / 60)) + ':' + dvoj(m % 60);

  const velkePismeno = s => s.charAt(0).toUpperCase() + s.slice(1);

  // „úterý 6. října 2026“
  const formatDatum = d => DNY[d.getDay()] + ' ' + d.getDate() + '.' + NBSP + MESICE_2P[d.getMonth()] + ' ' + d.getFullYear();

  // 45 min / 1 h / 1 h 30 min
  function formatDelka(min) {
    if (min < 60) return min + NBSP + 'min';
    const h = Math.floor(min / 60), m = min % 60;
    return h + NBSP + 'h' + (m ? ' ' + m + NBSP + 'min' : '');
  }

  // 1 000 Kč (nedělitelné mezery), případně „od 1 000 Kč“
  function formatCena(kc, od) {
    const cislo = String(kc).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
    return (od ? 'od ' : '') + cislo + NBSP + 'Kč';
  }

  const formatOsoby = n => n + NBSP + (n === 1 ? 'osoba' : 'osoby');

  /* ---------- Volání API ----------
     Vrací rozparsovaný JSON. Při HTTP chybě nebo { ok: false } vyhodí Error s .status a .data,
     message je hláška z API (`error`), nebo obecná hláška, když server nevrátil JSON. */
  async function api(cesta, options) {
    const nastaveni = Object.assign({ credentials: 'same-origin' }, options || {});
    nastaveni.headers = Object.assign({ Accept: 'application/json' }, nastaveni.headers || {});
    let res;
    try {
      res = await fetch(API + cesta, nastaveni);
    } catch (e) {
      const err = new Error('Nepodařilo se spojit se serverem. Zkontroluj prosím připojení a' + NBSP + 'zkus to znovu.');
      err.status = 0;
      throw err;
    }
    let data = null;
    try { data = await res.json(); } catch (e) { /* server nevrátil JSON */ }
    if (!res.ok || !data || data.ok === false) {
      const err = new Error(data && data.error ? data.error : 'Server teď neodpovídá (' + res.status + '). Zkus to prosím za chvíli.');
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  /* ------------------------------------------------------------------
     2. STAV A PRVKY
     ------------------------------------------------------------------ */
  let CFG = null;          // konfigurace z api/config.php
  let minDatum = null;     // Date – první rezervovatelný den
  let maxDatum = null;     // Date – poslední rezervovatelný den
  let cacheDnu = {};       // 'služby|osoby|RRRR-MM' → { days, hint } (ať se při listování neptáme znovu)
  let pozadavek = 0;       // pořadové číslo požadavku – starší odpověď nesmí přepsat novější

  const stav = {
    sluzby: [],     // id vybraných služeb (v pořadí katalogu)
    osoby: 1,
    datum: null,    // Date (půlnoc, lokálně)
    cas: null,      // minuty od půlnoci
    mesic: null,    // zobrazený měsíc v kalendáři (Date, 1. den)
    sloty: []       // volné začátky pro vybraný den ('HH:MM')
  };

  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const el = {
    kroky: [$('#krok-1'), $('#krok-2'), $('#krok-3')],
    status: $('#rez-status'),
    sluzby: $('#rez-sluzby'),
    osoby: $('#rez-osoby'),
    osobyHint: $('#rez-osoby-hint'),
    dal1: $('#rez-dal-1'),
    dal1Hint: $('#rez-dal-1-hint'),
    krok2Chyba: $('#rez-krok2-chyba'),
    calNotice: $('#rez-cal-notice'),
    calPrev: $('#rez-cal-prev'),
    calNext: $('#rez-cal-next'),
    calNadpis: $('#rez-cal-nadpis'),
    cal: $('#rez-cal'),
    slotyBox: $('#rez-sloty-box'),
    slotyH: $('#rez-sloty-h'),
    sloty: $('#rez-sloty'),
    slotyInfo: $('#rez-sloty-info'),
    form: $('#rez-form'),
    formChyby: $('#rez-form-chyby'),
    odeslat: null, // doplní se po nalezení formuláře
    kontejner: $('#rez-kroky'),
    sum: {
      sluzba: $('#sum-sluzba'), osoby: $('#sum-osoby'), delka: $('#sum-delka'),
      cena: $('#sum-cena'), datum: $('#sum-datum'), cas: $('#sum-cas')
    }
  };
  if (!el.kroky[0] || !el.form) return; // nejsme na stránce rezervace
  el.odeslat = $('button[type="submit"]', el.form);

  const sluzba = id => CFG.services[id];
  const vybraneSluzby = () => stav.sluzby.map(sluzba);
  const delkaCelkem = () => vybraneSluzby().reduce((s, x) => s + x.duration, 0) * stav.osoby;
  const cenaCelkem = () => vybraneSluzby().reduce((s, x) => s + x.price, 0) * stav.osoby;
  const cenaOd = () => vybraneSluzby().some(x => x.from);
  const nazevSluzeb = () => vybraneSluzby().map(x => x.name).join(' + ');

  // Nejvyšší povolený počet osob pro aktuální výběr (kurz je jen pro 1–2 osoby)
  function maxOsobProVyber() {
    let max = CFG.limits.maxPersons;
    vybraneSluzby().forEach(s => { if (s.maxPersons) max = Math.min(max, s.maxPersons); });
    return max;
  }

  // Jde vybrané služby objednat v jeden den? (existuje pravidlo, které povoluje všechny)
  function kombinaceJde() {
    if (!stav.sluzby.length) return false;
    return CFG.rules.some(r => stav.sluzby.every(id => r.services.indexOf(id) !== -1));
  }

  const jeVObdobi = d => d >= minDatum && d <= maxDatum;

  function oznam(text) { el.status.textContent = text; } // aria-live hláška pro čtečky

  /* ---------- Souhrn vpravo ---------- */
  function vykresliSouhrn() {
    const ma = stav.sluzby.length > 0;
    el.sum.sluzba.textContent = ma ? nazevSluzeb() : '–';
    el.sum.osoby.textContent = ma ? formatOsoby(stav.osoby) : '–';
    el.sum.delka.textContent = ma ? formatDelka(delkaCelkem()) : '–';
    el.sum.cena.textContent = ma ? formatCena(cenaCelkem(), cenaOd()) : '–';
    el.sum.datum.textContent = stav.datum ? formatDatum(stav.datum) : '–';
    el.sum.cas.textContent = stav.cas !== null ? naCas(stav.cas) + '–' + naCas(stav.cas + delkaCelkem()) : '–';
  }

  /* ---------- Přepínání kroků ---------- */
  // stavKroku: 'todo' (skrytý) | 'active' (rozbalený) | 'done' (sbalený se souhrnem a tlačítkem Změnit)
  function nastavKrok(n, stavKroku, souhrn) {
    const sekce = el.kroky[n - 1];
    sekce.dataset.state = stavKroku;
    sekce.hidden = stavKroku === 'todo';
    const sum = $('.rez-step-sum', sekce);
    const edit = $('.rez-step-edit', sekce);
    if (sum) { sum.hidden = stavKroku !== 'done'; sum.textContent = souhrn || ''; }
    if (edit) edit.hidden = stavKroku !== 'done';
  }

  function zamerKrok(n) {
    const sekce = el.kroky[n - 1];
    const h = $('h2', sekce);
    sekce.scrollIntoView({ behavior: 'smooth', block: 'start' });
    h.focus({ preventScroll: true });
  }

  // Hláška v kroku 2 (např. „termín mezitím někdo obsadil“)
  function chybaKrok2(text) {
    el.krok2Chyba.textContent = text || '';
    el.krok2Chyba.hidden = !text;
  }

  /* ------------------------------------------------------------------
     3. KROK 1 – služby (více najednou) a počet osob
     ------------------------------------------------------------------ */
  function vykresliSluzby() {
    el.sluzby.innerHTML = '';
    const ids = Object.keys(CFG.services);

    CFG.groups.forEach(nazev => {
      const vSkupine = ids.filter(id => CFG.services[id].group === nazev);
      if (!vSkupine.length) return;
      const box = document.createElement('div');
      box.className = 'rez-group';
      const h = document.createElement('h3');
      h.textContent = nazev;
      const grid = document.createElement('div');
      grid.className = 'choice-grid';
      grid.setAttribute('role', 'group');
      grid.setAttribute('aria-label', nazev);
      vSkupine.forEach(id => {
        const s = CFG.services[id];
        const lab = document.createElement('label');
        lab.className = 'choice';
        lab.dataset.sluzba = id;
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'sluzba';
        input.value = id;
        const b = document.createElement('b');
        b.textContent = s.name;
        const small = document.createElement('small');
        small.textContent = formatDelka(s.duration) + ' · ' + formatCena(s.price, s.from);
        lab.appendChild(input);
        lab.appendChild(b);
        lab.appendChild(small);
        input.addEventListener('change', () => prepniSluzbu(id, input.checked));
        grid.appendChild(lab);
      });
      box.appendChild(h);
      box.appendChild(grid);
      el.sluzby.appendChild(box);
    });

    el.osoby.innerHTML = '';
    for (let n = 1; n <= CFG.limits.maxPersons; n++) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'choice';
      b.dataset.osoby = String(n);
      b.setAttribute('aria-pressed', n === stav.osoby ? 'true' : 'false');
      const t = document.createElement('b');
      t.textContent = formatOsoby(n);
      b.appendChild(t);
      b.addEventListener('click', () => vyberOsoby(n));
      el.osoby.appendChild(b);
    }
  }

  // Zaškrtnutí / odškrtnutí služby – pořadí držíme podle katalogu
  function prepniSluzbu(id, zaskrtnuto) {
    const poradi = Object.keys(CFG.services);
    stav.sluzby = stav.sluzby.filter(x => x !== id);
    if (zaskrtnuto) stav.sluzby.push(id);
    stav.sluzby.sort((a, b) => poradi.indexOf(a) - poradi.indexOf(b));
    aktualizujKrok1();

    const s = sluzba(id);
    if (!stav.sluzby.length) oznam('Žádná služba není vybraná.');
    else if (!kombinaceJde()) oznam((zaskrtnuto ? 'Přidáno: ' : 'Odebráno: ') + s.name + '. ' + el.dal1Hint.textContent);
    else oznam((zaskrtnuto ? 'Přidáno: ' : 'Odebráno: ') + s.name + '. Vybráno: ' + nazevSluzeb() + ', ' + formatOsoby(stav.osoby) + ', ' + formatDelka(delkaCelkem()) + ', ' + formatCena(cenaCelkem(), cenaOd()) + '.');
  }

  function vyberOsoby(n) {
    stav.osoby = n;
    aktualizujKrok1();
    if (stav.sluzby.length) oznam(formatOsoby(n) + ': celkem ' + formatDelka(delkaCelkem()) + ', ' + formatCena(cenaCelkem(), cenaOd()) + '.');
  }

  // Sladí zaškrtnutí, počet osob, nápovědy a tlačítko „Pokračovat“ se stavem
  function aktualizujKrok1() {
    const max = maxOsobProVyber();
    if (stav.osoby > max) stav.osoby = max;

    el.sluzby.querySelectorAll('.choice').forEach(lab => {
      const vybrana = stav.sluzby.indexOf(lab.dataset.sluzba) !== -1;
      lab.classList.toggle('is-selected', vybrana);
      const input = $('input', lab);
      if (input && input.checked !== vybrana) input.checked = vybrana;
    });

    el.osoby.querySelectorAll('.choice').forEach(b => {
      const n = Number(b.dataset.osoby);
      const zakazano = n > max;
      b.disabled = zakazano;
      b.setAttribute('aria-disabled', zakazano ? 'true' : 'false');
      b.setAttribute('aria-pressed', n === stav.osoby ? 'true' : 'false');
    });

    // Nápověda k počtu osob
    if (max < CFG.limits.maxPersons) {
      const omezujici = vybraneSluzby().filter(s => s.maxPersons === max).map(s => s.name).join(' a ');
      el.osobyHint.textContent = omezujici + (max === 1 ? ' je jen pro jednu osobu.' : ' je nejvýše pro ' + formatOsoby(max) + '.');
    } else {
      el.osobyHint.textContent = 'Líčím jednu osobu po druhé, délka i' + NBSP + 'cena se násobí počtem osob.';
    }

    // Tlačítko dál + nápověda pod ním
    let hint = '';
    if (!stav.sluzby.length) hint = 'Nejdřív vyber službu – můžeš i' + NBSP + 'několik najednou.';
    else if (!kombinaceJde()) hint = 'Tuhle kombinaci služeb bohužel nejde objednat v' + NBSP + 'jednom termínu (dělám je v' + NBSP + 'jiné dny) – vyber je prosím zvlášť.';
    el.dal1.disabled = Boolean(hint);
    el.dal1Hint.textContent = hint;
    el.dal1Hint.hidden = !hint;
    el.dal1Hint.classList.toggle('form-note--warn', Boolean(hint) && stav.sluzby.length > 0);

    vykresliSouhrn();
  }

  el.dal1.addEventListener('click', () => {
    if (!stav.sluzby.length || !kombinaceJde()) return;
    stav.datum = null;
    stav.cas = null;
    stav.sloty = [];
    stav.mesic = prvniVMesici(minDatum);
    el.slotyBox.hidden = true;
    chybaKrok2('');
    nastavKrok(1, 'done', nazevSluzeb() + ' · ' + formatOsoby(stav.osoby) + ' · ' + formatDelka(delkaCelkem()) + ' · ' + formatCena(cenaCelkem(), cenaOd()));
    nastavKrok(2, 'active');
    nastavKrok(3, 'todo');
    vykresliSouhrn();
    zamerKrok(2);
    oznam('Krok 2: vyber den a čas.');
    vykresliKalendar();
  });

  /* ------------------------------------------------------------------
     4. KROK 2 – kalendář a časy (vše z API)
     ------------------------------------------------------------------ */
  const dotazSluzby = () => 'services=' + encodeURIComponent(stav.sluzby.join(',')) + '&persons=' + stav.osoby;

  // Volné dny v měsíci – z cache, nebo z API
  async function nactiDny(klicM) {
    const k = stav.sluzby.join(',') + '|' + stav.osoby + '|' + klicM;
    if (!cacheDnu[k]) {
      const data = await api('availability.php?' + dotazSluzby() + '&month=' + klicM);
      cacheDnu[k] = { days: data.days || {}, hint: data.hint || '' };
    }
    return cacheDnu[k];
  }

  // Mřížka kalendáře: hlavička dnů + prázdná políčka + dny. Bez dat jsou všechny dny neaktivní („načítám“).
  function postavMrizku(m, dny) {
    el.cal.innerHTML = '';
    DNY_KRATCE.forEach(z => {
      const w = document.createElement('div');
      w.className = 'cal-wd';
      w.setAttribute('aria-hidden', 'true');
      w.textContent = z;
      el.cal.appendChild(w);
    });
    // Prázdná políčka před 1. dnem (kalendář začíná pondělím: Po = 0 … Ne = 6)
    const odsazeni = (m.getDay() + 6) % 7;
    for (let i = 0; i < odsazeni; i++) el.cal.appendChild(document.createElement('div'));

    const pocetDni = den(m.getFullYear(), m.getMonth() + 1, 0).getDate();
    let volnych = 0;
    for (let d = 1; d <= pocetDni; d++) {
      const datum = den(m.getFullYear(), m.getMonth(), d);
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'cal-day';
      b.textContent = String(d);
      let popis = formatDatum(datum);
      let aktivni = false;

      if (!jeVObdobi(datum)) {
        popis += ' – mimo rezervovatelné období';
      } else if (!dny) {
        popis += ' – načítám';
      } else if (dny[klic(datum)] === true) {
        aktivni = true;
        b.classList.add('cal-day--volny');
      } else {
        b.classList.add('cal-day--obsazeny');
        popis += ' – nemám volno';
      }

      b.setAttribute('aria-label', popis);
      if (aktivni) {
        volnych++;
        b.setAttribute('aria-pressed', stav.datum && stejnyDen(stav.datum, datum) ? 'true' : 'false');
        b.addEventListener('click', () => vyberDen(datum));
      } else {
        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
      }
      el.cal.appendChild(b);
    }
    return volnych;
  }

  async function vykresliKalendar() {
    const m = stav.mesic;
    const klicM = klicMesice(m);
    el.calNadpis.textContent = velkePismeno(MESICE[m.getMonth()]) + ' ' + m.getFullYear();
    el.calPrev.disabled = m <= prvniVMesici(minDatum);
    el.calNext.disabled = m >= prvniVMesici(maxDatum);

    postavMrizku(m, null);
    el.cal.setAttribute('aria-busy', 'true');
    el.calNotice.textContent = 'Načítám volné dny…';

    const id = ++pozadavek;
    let data;
    try {
      data = await nactiDny(klicM);
    } catch (e) {
      if (id !== pozadavek) return;
      el.cal.removeAttribute('aria-busy');
      zobrazChybuNacitani(el.calNotice, 'Volné dny se nepodařilo načíst. ' + e.message, vykresliKalendar);
      return;
    }
    // Mezitím uživatelka přelistovala nebo změnila výběr → tuhle odpověď zahodíme
    if (id !== pozadavek || klicMesice(stav.mesic) !== klicM) return;

    el.cal.removeAttribute('aria-busy');
    const volnych = postavMrizku(m, data.days);

    // Vysvětlení pod kalendářem: kdy služby dělám (hint z API) + co dál
    let text = data.hint;
    if (!kombinaceJde()) {
      // API vrátí hlášku, že kombinaci nejde objednat v jednom termínu – dny nenabízíme
    } else if (volnych > 0) {
      text += ' Vyber si den v' + NBSP + 'kalendáři.';
    } else if (!el.calNext.disabled) {
      text += ' V' + NBSP + 'tomto měsíci volný termín nemám, zkus prosím další měsíc.';
    } else {
      text += ' V' + NBSP + 'nejbližších měsících bohužel volný termín nemám. Napiš mi nebo zavolej a' + NBSP + 'domluvíme se.';
    }
    el.calNotice.textContent = text;
  }

  // Hláška o chybě načítání + tlačítko „Zkusit znovu“
  function zobrazChybuNacitani(kam, text, znovu) {
    kam.textContent = text + ' ';
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-outline btn-sm';
    b.textContent = 'Zkusit znovu';
    b.addEventListener('click', znovu);
    kam.appendChild(b);
  }

  el.calPrev.addEventListener('click', () => {
    stav.mesic = den(stav.mesic.getFullYear(), stav.mesic.getMonth() - 1, 1);
    vykresliKalendar();
  });
  el.calNext.addEventListener('click', () => {
    stav.mesic = den(stav.mesic.getFullYear(), stav.mesic.getMonth() + 1, 1);
    vykresliKalendar();
  });

  function vyberDen(datum) {
    stav.datum = datum;
    stav.cas = null;
    chybaKrok2('');
    el.cal.querySelectorAll('.cal-day[aria-pressed]').forEach(b => {
      b.setAttribute('aria-pressed', b.textContent === String(datum.getDate()) ? 'true' : 'false');
    });
    vykresliSouhrn();
    oznam('Vybrán den ' + formatDatum(datum) + '. Načítám volné časy.');
    nactiSloty(true);
  }

  // Volné časy pro vybraný den z API; zaměřit = přesunout fokus na první volný čas
  async function nactiSloty(zamerit) {
    el.slotyBox.hidden = false;
    el.slotyH.textContent = 'Čas – ' + formatDatum(stav.datum);
    el.sloty.innerHTML = '';
    el.sloty.setAttribute('aria-busy', 'true');
    el.slotyInfo.textContent = 'Načítám volné časy…';
    el.slotyInfo.hidden = false;

    const id = ++pozadavek;
    const datumKlic = klic(stav.datum);
    let data;
    try {
      data = await api('availability.php?' + dotazSluzby() + '&date=' + datumKlic);
    } catch (e) {
      if (id !== pozadavek) return;
      el.sloty.removeAttribute('aria-busy');
      zobrazChybuNacitani(el.slotyInfo, 'Volné časy se nepodařilo načíst. ' + e.message, () => nactiSloty(true));
      return;
    }
    if (id !== pozadavek || !stav.datum || klic(stav.datum) !== datumKlic) return;

    el.sloty.removeAttribute('aria-busy');
    stav.sloty = data.slots || [];
    const delka = typeof data.duration === 'number' ? data.duration : delkaCelkem();

    if (!stav.sloty.length) {
      el.slotyInfo.textContent = data.reason || 'Tento den už je obsazený. Vyber prosím jiný den.';
      el.slotyInfo.hidden = false;
      // Den už není volný – v kalendáři ho zašedneme
      el.cal.querySelectorAll('.cal-day[aria-pressed="true"]').forEach(b => {
        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
        b.removeAttribute('aria-pressed');
        b.classList.remove('cal-day--volny');
        b.classList.add('cal-day--obsazeny');
      });
      oznam(el.slotyInfo.textContent);
      return;
    }

    el.slotyInfo.hidden = true;
    stav.sloty.forEach(hhmm => {
      const od = naMinuty(hhmm);
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'choice';
      b.dataset.cas = hhmm;
      const t = document.createElement('b');
      t.textContent = hhmm;
      const s = document.createElement('small');
      s.textContent = 'do ' + naCas(od + delka);
      b.appendChild(t);
      b.appendChild(s);
      b.setAttribute('aria-pressed', stav.cas === od ? 'true' : 'false');
      b.setAttribute('aria-label', 'Začátek ' + hhmm + ', konec ' + naCas(od + delka));
      b.addEventListener('click', () => vyberCas(od));
      el.sloty.appendChild(b);
    });
    oznam('Volné časy: ' + stav.sloty.length + '. Vyber čas.');
    if (zamerit) {
      const prvni = $('.choice', el.sloty);
      if (prvni) prvni.focus({ preventScroll: true });
    }
  }

  function vyberCas(od) {
    stav.cas = od;
    el.sloty.querySelectorAll('.choice[aria-pressed]').forEach(b => b.setAttribute('aria-pressed', b.dataset.cas === naCas(od) ? 'true' : 'false'));
    nastavKrok(2, 'done', formatDatum(stav.datum) + ' v' + NBSP + naCas(od) + '–' + naCas(od + delkaCelkem()));
    nastavKrok(3, 'active');
    vykresliSouhrn();
    zamerKrok(3);
    oznam('Krok 3: vyplň své údaje.');
  }

  /* ---------- Tlačítka „Změnit“ ---------- */
  $('.rez-step-edit', el.kroky[0]).addEventListener('click', () => {
    pozadavek++; // případné běžící načítání kalendáře zahodit
    stav.datum = null;
    stav.cas = null;
    stav.sloty = [];
    el.slotyBox.hidden = true;
    chybaKrok2('');
    nastavKrok(1, 'active');
    nastavKrok(2, 'todo');
    nastavKrok(3, 'todo');
    vykresliSouhrn();
    zamerKrok(1);
    oznam('Zpět na výběr služby.');
  });
  $('.rez-step-edit', el.kroky[1]).addEventListener('click', () => {
    stav.cas = null;
    nastavKrok(2, 'active');
    nastavKrok(3, 'todo');
    vykresliSouhrn();
    zamerKrok(2);
    oznam('Zpět na výběr termínu.');
    vykresliKalendar().then(() => { if (stav.datum) nactiSloty(false); });
  });

  /* ------------------------------------------------------------------
     5. KROK 3 – formulář, validace, odeslání, potvrzení
     ------------------------------------------------------------------ */
  function nastavChybu(id, zprava) {
    const input = $('#' + id);
    const err = $('#err-' + id);
    const field = input.closest('.field');
    if (zprava) {
      err.textContent = zprava;
      err.hidden = false;
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', 'err-' + id);
      if (field) field.classList.add('is-invalid');
    } else {
      err.textContent = '';
      err.hidden = true;
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-describedby');
      if (field) field.classList.remove('is-invalid');
    }
  }

  function overFormular() {
    const jmeno = $('#jmeno').value.trim();
    const telefon = $('#telefon').value.replace(/[\s\-().]/g, '');
    const email = $('#email').value.trim();
    const gdpr = $('#gdpr').checked;
    const chyby = [];

    nastavChybu('jmeno', jmeno.length < 3 || jmeno.indexOf(' ') === -1 ? 'Napiš prosím jméno i' + NBSP + 'příjmení.' : '');
    if (!telefon) nastavChybu('telefon', 'Zadej prosím telefon, ať se ti můžu ozvat.');
    else if (!/^(\+\d{2,3})?\d{9}$/.test(telefon)) nastavChybu('telefon', 'Telefon zadej ve tvaru 604' + NBSP + '123' + NBSP + '456 nebo +420' + NBSP + '604' + NBSP + '123' + NBSP + '456.');
    else nastavChybu('telefon', '');
    if (!email) nastavChybu('email', 'Zadej prosím svůj e‑mail.');
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) nastavChybu('email', 'E‑mail nevypadá správně, zkontroluj ho prosím.');
    else nastavChybu('email', '');
    nastavChybu('gdpr', gdpr ? '' : 'Bez souhlasu se zpracováním údajů rezervaci nemůžu přijmout.');

    ['jmeno', 'telefon', 'email', 'gdpr'].forEach(id => { if (!$('#err-' + id).hidden) chyby.push(id); });
    return chyby;
  }

  // Průběžné odmazávání chyb, jakmile uživatelka pole opraví
  ['jmeno', 'telefon', 'email', 'gdpr'].forEach(id => {
    $('#' + id).addEventListener('input', () => { if (!$('#err-' + id).hidden) overFormular(); });
  });

  function zobrazChybuFormulare(text) {
    el.formChyby.textContent = text;
    el.formChyby.hidden = false;
    el.formChyby.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  el.form.addEventListener('submit', e => {
    e.preventDefault();
    if (el.form.getAttribute('aria-busy') === 'true') return; // už se odesílá
    const chyby = overFormular();
    if (chyby.length) {
      zobrazChybuFormulare('Zkontroluj prosím označená pole (' + chyby.length + ').');
      $('#' + chyby[0]).focus();
      return;
    }
    el.formChyby.hidden = true;
    odesli();
  });

  async function odesli() {
    if (stav.datum === null || stav.cas === null) {
      zobrazChybuFormulare('Nejdřív prosím vyber den a' + NBSP + 'čas.');
      return;
    }
    const telo = {
      services: stav.sluzby.slice(),
      persons: stav.osoby,
      date: klic(stav.datum),
      time: naCas(stav.cas),
      name: $('#jmeno').value.trim(),
      email: $('#email').value.trim(),
      phone: $('#telefon').value.trim(),
      note: $('#poznamka').value.trim(),
      gdpr: true,
      website: ($('#website') && $('#website').value) || '' // honeypot – má zůstat prázdné
    };

    const puvodniText = el.odeslat.textContent;
    el.form.setAttribute('aria-busy', 'true');
    el.odeslat.disabled = true;
    el.odeslat.textContent = 'Odesílám…';
    oznam('Odesílám poptávku…');

    try {
      const data = await api('bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(telo)
      });
      zobrazPotvrzeni(data.summary || {});
    } catch (e) {
      el.form.removeAttribute('aria-busy');
      el.odeslat.disabled = false;
      el.odeslat.textContent = puvodniText;
      if (e.status === 409) {
        // Termín mezitím někdo obsadil → zpět na krok 2, znovu načíst dny i časy
        cacheDnu = {};
        stav.cas = null;
        nastavKrok(2, 'active');
        nastavKrok(3, 'todo');
        chybaKrok2('Tento termín mezitím někdo obsadil. Vyber si prosím jiný čas nebo den.');
        vykresliSouhrn();
        zamerKrok(2);
        oznam('Tento termín mezitím někdo obsadil. Vyber prosím jiný čas.');
        vykresliKalendar().then(() => { if (stav.datum) nactiSloty(false); });
      } else {
        zobrazChybuFormulare(e.message);
        el.formChyby.focus({ preventScroll: true }); // má tabindex="-1" v HTML
      }
    }
  }

  // Potvrzení – text vyká, je odsouhlasený klientkou (nechat tak). Údaje bereme ze `summary` z API.
  function zobrazPotvrzeni(summary) {
    const box = document.createElement('div');
    box.className = 'notice notice--ok rez-hotovo mt-3';
    box.setAttribute('tabindex', '-1');
    box.innerHTML =
      '<h2>Vaše poptávka byla přijata.</h2>' +
      '<dl><dt>Datum</dt><dd data-k="datum"></dd><dt>Služba</dt><dd data-k="sluzba"></dd><dt>Čas</dt><dd data-k="cas"></dd></dl>' +
      '<p>Brzy vás budu kontaktovat.</p>' +
      '<p class="muted mt-2" style="font-size:14px">Potvrzení poptávky jsme vám poslali i' + NBSP + 'e‑mailem.</p>' +
      '<p class="mt-3"><a class="btn btn-primary" href="index.html">Zpět na úvod</a></p>';

    const sluzbaText = (summary.service || nazevSluzeb()) + (Number(summary.persons || stav.osoby) > 1 ? ' (' + formatOsoby(Number(summary.persons || stav.osoby)) + ')' : '');
    const zacatek = summary.time ? naMinuty(summary.time) : stav.cas;
    const delka = typeof summary.duration === 'number' ? summary.duration : delkaCelkem();
    $('[data-k="datum"]', box).textContent = summary.date || formatDatum(stav.datum);
    $('[data-k="sluzba"]', box).textContent = sluzbaText;
    $('[data-k="cas"]', box).textContent = naCas(zacatek) + '–' + naCas(zacatek + delka);

    // Nahradíme kroky potvrzením; aria-live hlášku necháme na místě
    el.kroky.forEach(k => k.remove());
    el.kontejner.appendChild(box);
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    box.focus({ preventScroll: true });
    oznam('Vaše poptávka byla přijata. Brzy vás budu kontaktovat.');
  }

  /* ------------------------------------------------------------------
     6. START – konfigurace z API a předvýběr z URL (?sluzba=plesove nebo ?sluzba=denni,uces-jednoduchy)
     ------------------------------------------------------------------ */
  function zobrazChybuApi() {
    el.kroky.forEach(k => { k.hidden = true; });
    const box = document.createElement('div');
    box.className = 'notice mt-3';
    box.setAttribute('role', 'alert');
    box.innerHTML =
      '<strong>Omlouvám se, online rezervace se teď nepodařila načíst.</strong> ' +
      'Zavolej mi prosím na <a href="' + TELEFON_HREF + '">' + TELEFON.replace(/ /g, NBSP) + '</a> nebo mi napiš přes ' +
      '<a class="link" href="kontakt.html">kontakt</a> a' + NBSP + 'termín domluvíme spolu. ' +
      '<button class="btn btn-outline btn-sm" type="button">Zkusit znovu</button>';
    $('button', box).addEventListener('click', () => location.reload());
    el.kontejner.appendChild(box);
    oznam('Rezervace se nepodařila načíst.');
  }

  async function start() {
    el.sluzby.setAttribute('aria-busy', 'true');
    el.sluzby.innerHTML = '<p class="muted mt-2">Načítám nabídku služeb…</p>';
    try {
      CFG = await api('config.php');
      if (!CFG.services || !CFG.rules || !CFG.limits || !CFG.minDate || !CFG.maxDate) throw new Error('Neúplná konfigurace.');
    } catch (e) {
      el.sluzby.removeAttribute('aria-busy');
      el.sluzby.innerHTML = '';
      zobrazChybuApi();
      return;
    }
    el.sluzby.removeAttribute('aria-busy');
    minDatum = zRetezce(CFG.minDate);
    maxDatum = zRetezce(CFG.maxDate);
    stav.mesic = prvniVMesici(minDatum);

    vykresliSluzby();
    aktualizujKrok1();

    // Předvýběr služeb z URL (jedna nebo více přes čárku); neznámé ignorujeme
    const zUrl = new URLSearchParams(location.search).get('sluzba');
    if (zUrl) {
      const poradi = Object.keys(CFG.services);
      stav.sluzby = zUrl.split(',').map(s => s.trim()).filter((id, i, arr) => CFG.services[id] && arr.indexOf(id) === i);
      stav.sluzby.sort((a, b) => poradi.indexOf(a) - poradi.indexOf(b));
      aktualizujKrok1();
      if (stav.sluzby.length) oznam('Předvybráno: ' + nazevSluzeb() + '. Pokračuj na výběr termínu.');
    }
  }

  start();
})();
