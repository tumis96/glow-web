/* ==========================================================================
   Glow by Petra Jahodová – online rezervace ve 3 krocích
   PILOTNÍ VERZE – jen frontend. Nic se neodesílá, žádné externí požadavky.
   Čisté vanilla JS (ES2017), bez knihoven.

   Struktura:
     1. KONFIG – služby, otevírací doba podle dne a služby, ukázkové blokace
        (později se jen přepíše daty z backendu, např. availability.php)
     2. pomocné funkce pro data – VŽDY lokální čas (žádné UTC / toISOString)
     3. stav rezervace + vykreslení kroků (služba → datum a čas → údaje)
     4. validace formuláře a potvrzení
   ========================================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------------------------
     1. KONFIGURACE
     Dny v týdnu používají číslování JS Date.getDay(): 0 = neděle, 1 = pondělí … 6 = sobota.
     Měsíce v pravidlech jsou 1–12 (leden = 1), ať se to dobře čte.
     ------------------------------------------------------------------ */
  const KONFIG = {
    minDniPredem: 2,        // nejpozději 2 dny předem
    maxMesicuDopredu: 2,    // nejdéle 2 měsíce dopředu
    krokSlotuMin: 30,       // časy po 30 minutách
    maxOsob: 4,

    // Skupiny, ve kterých se služby nabízejí (pořadí = pořadí na stránce)
    skupiny: [
      { nazev: 'Líčení', sluzby: ['denni', 'plesove', 'festivalove', 'foceni'] },
      { nazev: 'Účesy', sluzby: ['uces-jednoduchy', 'uces-slozity'] },
      { nazev: 'Balíčky – líčení a účes', sluzby: ['balicek-ples', 'balicek-foto'] },
      { nazev: 'Svatební zkoušky', sluzby: ['zkouska-liceni', 'zkouska-uces'] },
      { nazev: 'Kurz', sluzby: ['kurz'] }
    ],

    // Služby: cena v Kč (konečná, neplátce DPH), délka v minutách pro 1 osobu.
    // „od: true“ = cena je „od“ (dle složitosti). „akuzativ“ = tvar do věty „… dělám v úterý“.
    // „jenJedna: true“ = službu nelze objednat pro víc osob.
    sluzby: {
      'denni':           { nazev: 'Denní líčení',                akuzativ: 'denní líčení',                 cena: 500,  delka: 30 },
      'plesove':         { nazev: 'Plesové líčení',              akuzativ: 'plesové líčení',               cena: 1000, delka: 50 },
      'festivalove':     { nazev: 'Festivalové líčení',          akuzativ: 'festivalové líčení',           cena: 1000, delka: 60, od: true },
      'foceni':          { nazev: 'Líčení na focení',            akuzativ: 'líčení na focení',             cena: 800,  delka: 45 },
      'uces-jednoduchy': { nazev: 'Účes – jednoduchá úprava',    akuzativ: 'jednoduchou úpravu účesu',     cena: 500,  delka: 60 }, // ceník: 30–60 min, blokujeme maximum
      'uces-slozity':    { nazev: 'Účes – složitější',           akuzativ: 'složitější účes',              cena: 800,  delka: 60 },
      'balicek-ples':    { nazev: 'Plesový balíček',             akuzativ: 'plesový balíček',              cena: 1500, delka: 90 },
      'balicek-foto':    { nazev: 'Foto balíček',                akuzativ: 'foto balíček',                 cena: 1200, delka: 75 },
      'zkouska-liceni':  { nazev: 'Zkouška svatebního líčení',   akuzativ: 'zkoušku svatebního líčení',    cena: 1000, delka: 75 },
      'zkouska-uces':    { nazev: 'Zkouška svatebního účesu',    akuzativ: 'zkoušku svatebního účesu',     cena: 900,  delka: 60 }, // ceník: 30–60 min, blokujeme maximum
      'kurz':            { nazev: 'Kurz sebelíčení',             akuzativ: 'kurz sebelíčení',              cena: 3000, delka: 120, jenJedna: true }
    },

    // Pravidla dostupnosti: kdy (dny, případně jen některé měsíce) a které služby.
    // Pondělí a sobota tu nejsou = nic (sobota je jen pro svatby, bez online rezervace).
    pravidla: [
      {
        dny: [2, 3, 4], od: '15:30', do: '19:00',
        popis: 'v úterý, ve středu a ve čtvrtek 15:30–19:00',
        sluzby: ['denni', 'foceni', 'balicek-foto', 'uces-jednoduchy', 'uces-slozity', 'zkouska-liceni', 'zkouska-uces', 'kurz']
      },
      {
        dny: [5], od: '15:00', do: '20:00', mesice: [11, 12, 1, 2, 3],
        popis: 'v pátek 15:00–20:00, jen od listopadu do března',
        sluzby: ['plesove', 'festivalove', 'balicek-ples', 'uces-jednoduchy', 'uces-slozity']
      },
      {
        dny: [0], od: '10:00', do: '13:00',
        popis: 'v neděli 10:00–13:00',
        sluzby: ['zkouska-liceni', 'zkouska-uces']
      }
    ],

    // Ukázkové obsazené termíny (deterministicky, ať je vidět, jak vypadá obsazenost).
    // Z backendu přijdou konkrétní data ve tvaru { datum: 'RRRR-MM-DD', od: 'HH:MM', do: 'HH:MM' }.
    // Aby ukázka nezastarala, jde ji zadat i relativně: „poradi“-té úterý (denVTydnu) v rezervovatelném období.
    // Bez „od“/„do“ je obsazený celý den.
    blokace: [
      { denVTydnu: 2, poradi: 1, od: '15:30', do: '17:00' },  // první rezervovatelné úterý: obsazeno 15:30–17:00
      { denVTydnu: 0, poradi: 2 }                             // druhá neděle: obsazená celá
      // { datum: '2026-11-13', od: '15:00', do: '20:00' }    // takto bude vypadat blokace z backendu
    ]
  };

  const DNY = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
  const DNY_KRATCE = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne']; // kalendář začíná pondělím
  const MESICE = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
  const MESICE_2P = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
  const NBSP = ' ';

  /* ------------------------------------------------------------------
     2. POMOCNÉ FUNKCE – data a formátování (lokální čas)
     ------------------------------------------------------------------ */
  const dvoj = n => (n < 10 ? '0' : '') + n;

  // Půlnoc daného dne v lokálním čase
  const den = (y, m, d) => new Date(y, m, d, 0, 0, 0, 0);
  const pridejDny = (d, n) => den(d.getFullYear(), d.getMonth(), d.getDate() + n);
  const prvniVMesici = d => den(d.getFullYear(), d.getMonth(), 1);
  const stejnyDen = (a, b) => a.getTime() === b.getTime();

  // Klíč 'RRRR-MM-DD' z lokálních složek (ne toISOString – to by v zimě/létě ujelo o den)
  const klic = d => d.getFullYear() + '-' + dvoj(d.getMonth() + 1) + '-' + dvoj(d.getDate());

  // 'HH:MM' <-> minuty od půlnoci
  const naMinuty = hhmm => { const p = hhmm.split(':'); return Number(p[0]) * 60 + Number(p[1]); };
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

  // „a, b a c“
  function spoj(pole) {
    if (pole.length <= 1) return pole.join('');
    return pole.slice(0, -1).join(', ') + ' a ' + pole[pole.length - 1];
  }

  /* ------------------------------------------------------------------
     Rezervovatelné období a blokace
     ------------------------------------------------------------------ */
  const dnes = (() => { const t = new Date(); return den(t.getFullYear(), t.getMonth(), t.getDate()); })();
  const minDatum = pridejDny(dnes, KONFIG.minDniPredem);
  // Max. X měsíců dopředu – na konci měsíce ořežeme den na délku cílového měsíce (31. 12. → 28. 2.)
  const cil = den(dnes.getFullYear(), dnes.getMonth() + KONFIG.maxMesicuDopredu, 1);
  const posledniDen = den(cil.getFullYear(), cil.getMonth() + 1, 0).getDate();
  const maxDatum = den(cil.getFullYear(), cil.getMonth(), Math.min(dnes.getDate(), posledniDen));

  // Mapa 'RRRR-MM-DD' -> [{ od, do }] v minutách; relativní blokace se tu převedou na konkrétní dny
  const blokaceMapa = {};
  (function pripravBlokace() {
    const pocty = {}; // kolikáté úterý/neděle… v období už jsme potkali
    for (let d = minDatum; d <= maxDatum; d = pridejDny(d, 1)) {
      const wd = d.getDay();
      pocty[wd] = (pocty[wd] || 0) + 1;
      KONFIG.blokace.forEach(b => {
        const sedi = b.datum ? b.datum === klic(d) : (b.denVTydnu === wd && b.poradi === pocty[wd]);
        if (!sedi) return;
        const k = klic(d);
        (blokaceMapa[k] = blokaceMapa[k] || []).push({
          od: b.od ? naMinuty(b.od) : 0,
          do: b.do ? naMinuty(b.do) : 24 * 60
        });
      });
    }
  })();

  // Překrývá se interval [od, do) s nějakou blokací v daný den?
  function jeObsazeno(d, od, doM) {
    return (blokaceMapa[klic(d)] || []).some(b => od < b.do && doM > b.od);
  }

  // Pravidlo, podle kterého se služba v daný den dělá (nebo null)
  function pravidloProDen(d, sluzbaId) {
    const wd = d.getDay(), mesic = d.getMonth() + 1;
    return KONFIG.pravidla.find(p =>
      p.dny.indexOf(wd) !== -1 &&
      p.sluzby.indexOf(sluzbaId) !== -1 &&
      (!p.mesice || p.mesice.indexOf(mesic) !== -1)
    ) || null;
  }

  // Sloty pro den: null = služba se ten den nedělá; jinak pole { od, do, obsazeno }.
  // Slot je platný, jen když začátek + celková délka ≤ zavírací čas.
  function slotyProDen(d, sluzbaId, delkaCelkem) {
    const p = pravidloProDen(d, sluzbaId);
    if (!p) return null;
    const otevreno = naMinuty(p.od), zavreno = naMinuty(p.do);
    const sloty = [];
    for (let t = otevreno; t + delkaCelkem <= zavreno; t += KONFIG.krokSlotuMin) {
      sloty.push({ od: t, do: t + delkaCelkem, obsazeno: jeObsazeno(d, t, t + delkaCelkem) });
    }
    return sloty;
  }

  const jeVObdobi = d => d >= minDatum && d <= maxDatum;

  // „Plesové líčení dělám v pátek 15:00–20:00, jen od listopadu do března.“
  function textDostupnosti(sluzbaId) {
    const s = KONFIG.sluzby[sluzbaId];
    const popisy = KONFIG.pravidla.filter(p => p.sluzby.indexOf(sluzbaId) !== -1).map(p => p.popis);
    return velkePismeno(s.akuzativ) + ' dělám ' + spoj(popisy) + '.';
  }

  /* ------------------------------------------------------------------
     3. STAV A PRVKY
     ------------------------------------------------------------------ */
  const stav = {
    sluzba: null,   // id služby
    osoby: 1,
    datum: null,    // Date (půlnoc, lokálně)
    cas: null,      // minuty od půlnoci
    mesic: prvniVMesici(minDatum) // zobrazený měsíc v kalendáři
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
    calNotice: $('#rez-cal-notice'),
    calPrev: $('#rez-cal-prev'),
    calNext: $('#rez-cal-next'),
    calNadpis: $('#rez-cal-nadpis'),
    cal: $('#rez-cal'),
    slotyBox: $('#rez-sloty-box'),
    slotyH: $('#rez-sloty-h'),
    sloty: $('#rez-sloty'),
    form: $('#rez-form'),
    formChyby: $('#rez-form-chyby'),
    kontejner: $('#rez-kroky'),
    sum: {
      sluzba: $('#sum-sluzba'), osoby: $('#sum-osoby'), delka: $('#sum-delka'),
      cena: $('#sum-cena'), datum: $('#sum-datum'), cas: $('#sum-cas')
    }
  };
  if (!el.kroky[0] || !el.form) return; // nejsme na stránce rezervace

  const sluzba = () => (stav.sluzba ? KONFIG.sluzby[stav.sluzba] : null);
  const delkaCelkem = () => (sluzba() ? sluzba().delka * stav.osoby : 0);
  const cenaCelkem = () => (sluzba() ? sluzba().cena * stav.osoby : 0);

  function oznam(text) { el.status.textContent = text; } // aria-live hláška pro čtečky

  /* ---------- Souhrn vpravo ---------- */
  function vykresliSouhrn() {
    const s = sluzba();
    el.sum.sluzba.textContent = s ? s.nazev : '–';
    el.sum.osoby.textContent = s ? formatOsoby(stav.osoby) : '–';
    el.sum.delka.textContent = s ? formatDelka(delkaCelkem()) : '–';
    el.sum.cena.textContent = s ? formatCena(cenaCelkem(), s.od) : '–';
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

  /* ------------------------------------------------------------------
     KROK 1 – služba a počet osob
     ------------------------------------------------------------------ */
  function vykresliSluzby() {
    KONFIG.skupiny.forEach(g => {
      const box = document.createElement('div');
      box.className = 'rez-group';
      const h = document.createElement('h3');
      h.textContent = g.nazev;
      const grid = document.createElement('div');
      grid.className = 'choice-grid';
      grid.setAttribute('role', 'group');
      grid.setAttribute('aria-label', g.nazev);
      g.sluzby.forEach(id => {
        const s = KONFIG.sluzby[id];
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'choice';
        b.dataset.sluzba = id;
        b.setAttribute('aria-pressed', 'false');
        b.innerHTML = '<b></b><small></small>';
        $('b', b).textContent = s.nazev;
        $('small', b).textContent = formatDelka(s.delka) + ' · ' + formatCena(s.cena, s.od);
        b.addEventListener('click', () => vyberSluzbu(id));
        grid.appendChild(b);
      });
      box.appendChild(h);
      box.appendChild(grid);
      el.sluzby.appendChild(box);
    });

    for (let n = 1; n <= KONFIG.maxOsob; n++) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'choice';
      b.dataset.osoby = String(n);
      b.setAttribute('aria-pressed', n === stav.osoby ? 'true' : 'false');
      b.innerHTML = '<b></b>';
      $('b', b).textContent = formatOsoby(n);
      b.addEventListener('click', () => vyberOsoby(n));
      el.osoby.appendChild(b);
    }
  }

  function vyberSluzbu(id) {
    stav.sluzba = id;
    const s = KONFIG.sluzby[id];
    el.sluzby.querySelectorAll('.choice').forEach(b => b.setAttribute('aria-pressed', b.dataset.sluzba === id ? 'true' : 'false'));

    // Kurz je jen pro jednu osobu
    if (s.jenJedna) stav.osoby = 1;
    el.osoby.querySelectorAll('.choice').forEach(b => {
      const n = Number(b.dataset.osoby);
      const zakazano = Boolean(s.jenJedna) && n > 1;
      b.disabled = zakazano;
      b.setAttribute('aria-disabled', zakazano ? 'true' : 'false');
      b.setAttribute('aria-pressed', n === stav.osoby ? 'true' : 'false');
    });
    el.osobyHint.textContent = s.jenJedna
      ? 'Kurz sebelíčení je individuální – jen pro jednu osobu.'
      : 'Líčím jednu osobu po druhé, délka i' + NBSP + 'cena se násobí počtem osob.';

    el.dal1.disabled = false;
    el.dal1Hint.hidden = true;
    vykresliSouhrn();
    oznam('Vybráno: ' + s.nazev + ', ' + formatOsoby(stav.osoby) + '. Pokračuj na výběr termínu.');
  }

  function vyberOsoby(n) {
    stav.osoby = n;
    el.osoby.querySelectorAll('.choice').forEach(b => b.setAttribute('aria-pressed', Number(b.dataset.osoby) === n ? 'true' : 'false'));
    vykresliSouhrn();
  }

  el.dal1.addEventListener('click', () => {
    if (!stav.sluzba) return;
    const s = sluzba();
    stav.datum = null;
    stav.cas = null;
    stav.mesic = prvniVMesici(minDatum);
    nastavKrok(1, 'done', s.nazev + ' · ' + formatOsoby(stav.osoby) + ' · ' + formatDelka(delkaCelkem()) + ' · ' + formatCena(cenaCelkem(), s.od));
    nastavKrok(2, 'active');
    nastavKrok(3, 'todo');
    vykresliKalendar();
    vykresliSouhrn();
    zamerKrok(2);
    oznam('Krok 2: vyber den a čas.');
  });

  /* ------------------------------------------------------------------
     KROK 2 – kalendář a časy
     ------------------------------------------------------------------ */
  function vykresliKalendar() {
    const m = stav.mesic;
    const delka = delkaCelkem();
    el.calNadpis.textContent = velkePismeno(MESICE[m.getMonth()]) + ' ' + m.getFullYear();
    el.calPrev.disabled = m <= prvniVMesici(minDatum);
    el.calNext.disabled = m >= prvniVMesici(maxDatum);

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
    let volnychVMesici = 0;
    for (let d = 1; d <= pocetDni; d++) {
      const datum = den(m.getFullYear(), m.getMonth(), d);
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'cal-day';
      b.textContent = String(d);
      let popis = formatDatum(datum);
      let aktivni = false;

      if (jeVObdobi(datum)) {
        const sloty = slotyProDen(datum, stav.sluzba, delka);
        if (sloty && sloty.some(s => !s.obsazeno)) {
          aktivni = true;
          b.classList.add('cal-day--volny');
        } else if (sloty && sloty.length) {
          b.classList.add('cal-day--obsazeny');
          popis += ' – obsazeno';
        } else {
          popis += ' – tento den službu nedělám';
        }
      } else {
        popis += ' – mimo rezervovatelné období';
      }

      b.setAttribute('aria-label', popis);
      if (aktivni) {
        volnychVMesici++;
        b.setAttribute('aria-pressed', stav.datum && stejnyDen(stav.datum, datum) ? 'true' : 'false');
        b.addEventListener('click', () => vyberDen(datum));
      } else {
        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
      }
      el.cal.appendChild(b);
    }

    vykresliCalNotice(volnychVMesici);
  }

  // Vysvětlení pod kalendářem – kdy službu dělám, případně proč není nic volného
  function vykresliCalNotice(volnychVMesici) {
    const delka = delkaCelkem();
    let text = textDostupnosti(stav.sluzba);

    if (volnychVMesici === 0) {
      // Je vůbec v celém období nějaký den podle pravidel? A vejde se tam délka?
      let denPodlePravidel = false, slotVObdobi = false;
      for (let d = minDatum; d <= maxDatum; d = pridejDny(d, 1)) {
        const sloty = slotyProDen(d, stav.sluzba, delka);
        if (sloty) denPodlePravidel = true;
        if (sloty && sloty.some(s => !s.obsazeno)) { slotVObdobi = true; break; }
      }
      if (slotVObdobi) {
        text += ' V' + NBSP + 'tomto měsíci volný termín nemám, zkus prosím další měsíc.';
      } else if (denPodlePravidel && stav.osoby > 1) {
        text += ' Pro ' + formatOsoby(stav.osoby) + ' (' + formatDelka(delka) + ') se do otevírací doby nevejdu – zkus méně osob, nebo mi napiš a' + NBSP + 'domluvíme se.';
      } else {
        text += ' V' + NBSP + 'nejbližších dvou měsících bohužel volný termín nemám. Napiš mi nebo zavolej a' + NBSP + 'domluvíme se.';
      }
    } else {
      text += ' Vyber si den v' + NBSP + 'kalendáři.';
    }
    el.calNotice.textContent = text;
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
    el.cal.querySelectorAll('.cal-day[aria-pressed]').forEach(b => b.setAttribute('aria-pressed', 'false'));
    const vybrany = Array.prototype.find.call(el.cal.querySelectorAll('.cal-day[aria-pressed]'), b => b.textContent === String(datum.getDate()));
    if (vybrany) vybrany.setAttribute('aria-pressed', 'true');
    vykresliSloty();
    vykresliSouhrn();
    oznam('Vybrán den ' + formatDatum(datum) + '. Teď vyber čas.');
  }

  function vykresliSloty() {
    const sloty = slotyProDen(stav.datum, stav.sluzba, delkaCelkem()) || [];
    el.slotyBox.hidden = false;
    el.slotyH.textContent = 'Čas – ' + formatDatum(stav.datum);
    el.sloty.innerHTML = '';
    sloty.forEach(slot => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'choice';
      b.innerHTML = '<b></b><small></small>';
      $('b', b).textContent = naCas(slot.od);
      if (slot.obsazeno) {
        $('small', b).textContent = 'obsazeno';
        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
        b.setAttribute('aria-label', naCas(slot.od) + ' – obsazeno');
      } else {
        $('small', b).textContent = 'do ' + naCas(slot.do);
        b.setAttribute('aria-pressed', stav.cas === slot.od ? 'true' : 'false');
        b.setAttribute('aria-label', 'Začátek ' + naCas(slot.od) + ', konec ' + naCas(slot.do));
        b.addEventListener('click', () => vyberCas(slot.od));
      }
      el.sloty.appendChild(b);
    });
    // Po výběru dne přesuň fokus na nabídku časů
    const prvni = $('.choice:not([disabled])', el.sloty);
    if (prvni) prvni.focus({ preventScroll: true });
  }

  function vyberCas(od) {
    stav.cas = od;
    el.sloty.querySelectorAll('.choice[aria-pressed]').forEach(b => b.setAttribute('aria-pressed', $('b', b).textContent === naCas(od) ? 'true' : 'false'));
    nastavKrok(2, 'done', formatDatum(stav.datum) + ' v' + NBSP + naCas(od) + '–' + naCas(od + delkaCelkem()));
    nastavKrok(3, 'active');
    vykresliSouhrn();
    zamerKrok(3);
    oznam('Krok 3: vyplň své údaje.');
  }

  /* ---------- Tlačítka „Změnit“ ---------- */
  $('.rez-step-edit', el.kroky[0]).addEventListener('click', () => {
    stav.datum = null;
    stav.cas = null;
    el.slotyBox.hidden = true;
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
    vykresliKalendar();
    if (stav.datum) vykresliSloty();
    vykresliSouhrn();
    zamerKrok(2);
    oznam('Zpět na výběr termínu.');
  });

  /* ------------------------------------------------------------------
     4. KROK 3 – formulář, validace, potvrzení
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

  el.form.addEventListener('submit', e => {
    e.preventDefault();
    const chyby = overFormular();
    if (chyby.length) {
      el.formChyby.textContent = 'Zkontroluj prosím označená pole (' + chyby.length + ').';
      el.formChyby.hidden = false;
      $('#' + chyby[0]).focus();
      return;
    }
    el.formChyby.hidden = true;
    zobrazPotvrzeni();
  });

  // Potvrzení – text vyká, je odsouhlasený klientkou (nechat tak)
  function zobrazPotvrzeni() {
    const s = sluzba();
    const box = document.createElement('div');
    box.className = 'notice notice--ok rez-hotovo mt-3';
    box.setAttribute('tabindex', '-1');
    box.innerHTML =
      '<h2>Vaše poptávka byla přijata.</h2>' +
      '<dl><dt>Datum</dt><dd data-k="datum"></dd><dt>Služba</dt><dd data-k="sluzba"></dd><dt>Čas</dt><dd data-k="cas"></dd></dl>' +
      '<p>Brzy vás budu kontaktovat.</p>' +
      '<p class="muted mt-2" style="font-size:13px">Pilotní verze: poptávka se zatím nikam neodeslala.</p>' +
      '<p class="mt-3"><a class="btn btn-primary" href="index.html">Zpět na úvod</a></p>';
    $('[data-k="datum"]', box).textContent = formatDatum(stav.datum);
    $('[data-k="sluzba"]', box).textContent = s.nazev + (stav.osoby > 1 ? ' (' + formatOsoby(stav.osoby) + ')' : '');
    $('[data-k="cas"]', box).textContent = naCas(stav.cas) + '–' + naCas(stav.cas + delkaCelkem());

    el.kontejner.innerHTML = '';
    el.kontejner.appendChild(box);
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    box.focus({ preventScroll: true });
  }

  /* ------------------------------------------------------------------
     START – vykreslení a předvýběr služby z URL (?sluzba=plesove)
     ------------------------------------------------------------------ */
  vykresliSluzby();
  vykresliSouhrn();

  const zUrl = new URLSearchParams(location.search).get('sluzba');
  if (zUrl && KONFIG.sluzby[zUrl]) vyberSluzbu(zUrl);
})();
