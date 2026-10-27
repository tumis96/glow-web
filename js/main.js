/* Glow by Petra Jahodová – společný JS (menu, aktivní odkaz, lightbox, rok v patičce) */
(function () {
  'use strict';

  // Odhalení při scrollu: vybrané prvky dostanou .reveal a při vjezdu do okna .is-visible.
  // Sourozenci v jedné skupině (karty, fotky, položky ceníku) se objevují postupně.
  document.documentElement.classList.add('js');
  if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var skupiny = ['.grid', '.gallery', '.price-list', '.steps', '.choice-grid', '.footer-grid'];
    var jednotlive = ['.section-head', '.split > *', '.page-hero > .container > *', '.hero > .container > *', '.cta-band > .container', '.faq details', '.narrow > .notice', '.narrow > p', 'main > .section > .container > h2', 'main > .section > .container > h3'];
    var items = [];
    skupiny.forEach(function (sel) {
      document.querySelectorAll('main ' + sel).forEach(function (group) {
        Array.prototype.forEach.call(group.children, function (child, i) {
          if (child.classList.contains('reveal')) return;
          child.classList.add('reveal');
          child.style.setProperty('--reveal-delay', Math.min(i, 7) * 0.09 + 's');
          items.push(child);
        });
      });
    });
    jednotlive.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        if (el.classList.contains('reveal') || el.querySelector('.reveal')) return;
        el.classList.add('reveal');
        items.push(el);
      });
    });
    // Odhalí vše, co je v okně nebo už nad ním (i při skoku kotvou / rychlém scrollu nic nezůstane skryté).
    var cekajici = items.slice();
    var tick = false;
    function odhal() {
      tick = false;
      var hranice = window.innerHeight * 0.92;
      cekajici = cekajici.filter(function (el) {
        if (el.getBoundingClientRect().top < hranice) { el.classList.add('is-visible'); return false; }
        return true;
      });
      if (!cekajici.length) { window.removeEventListener('scroll', naScroll); window.removeEventListener('resize', naScroll); }
    }
    function naScroll() { if (!tick) { tick = true; window.requestAnimationFrame(odhal); } }
    window.addEventListener('scroll', naScroll, { passive: true });
    window.addEventListener('resize', naScroll);
    window.addEventListener('load', naScroll);
    odhal();
  }

  // Mobilní menu
  var header = document.querySelector('.site-header');
  var toggle = document.querySelector('.nav-toggle');
  if (header && toggle) {
    toggle.addEventListener('click', function () {
      var open = header.classList.toggle('nav-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && header.classList.contains('nav-open')) {
        header.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Zvýraznění aktuální stránky v menu
  var here = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.site-nav a').forEach(function (a) {
    var target = a.getAttribute('href').split('/').pop();
    if (target === here) a.setAttribute('aria-current', 'page');
  });

  // Rok v patičce
  document.querySelectorAll('[data-year]').forEach(function (el) { el.textContent = String(new Date().getFullYear()); });

  // Lightbox pro galerie: <a class="lb" href="velka.jpg" data-caption="…"><img …></a>
  var links = document.querySelectorAll('a.lb');
  if (links.length && 'HTMLDialogElement' in window) {
    var dlg = document.createElement('dialog');
    dlg.className = 'lightbox';
    dlg.innerHTML = '<button class="lightbox-close" type="button" aria-label="Zavřít">×</button>'
      + '<button class="lightbox-nav lightbox-prev" type="button" aria-label="Předchozí fotka">‹</button>'
      + '<button class="lightbox-nav lightbox-next" type="button" aria-label="Další fotka">›</button>'
      + '<img alt=""><div class="lightbox-caption"><span class="lightbox-text"></span><span class="lightbox-count"></span></div>';
    document.body.appendChild(dlg);
    var img = dlg.querySelector('img');
    var cap = dlg.querySelector('.lightbox-text');
    var count = dlg.querySelector('.lightbox-count');
    var prevBtn = dlg.querySelector('.lightbox-prev');
    var nextBtn = dlg.querySelector('.lightbox-next');
    var list = Array.prototype.slice.call(links);
    var idx = 0;
    if (list.length < 2) { prevBtn.hidden = true; nextBtn.hidden = true; }
    function preload(i) {
      var a = list[(i + list.length) % list.length];
      if (a) { var p = new Image(); p.src = a.getAttribute('href'); }
    }
    function show(i) {
      idx = (i + list.length) % list.length;
      img.src = list[idx].getAttribute('href');
      img.alt = list[idx].querySelector('img') ? list[idx].querySelector('img').alt : '';
      cap.textContent = list[idx].getAttribute('data-caption') || '';
      count.textContent = list.length > 1 ? (idx + 1) + ' / ' + list.length : '';
      preload(idx + 1); preload(idx - 1);
    }
    list.forEach(function (a, i) {
      a.addEventListener('click', function (e) { e.preventDefault(); show(i); dlg.showModal(); });
    });
    dlg.querySelector('.lightbox-close').addEventListener('click', function () { dlg.close(); });
    prevBtn.addEventListener('click', function (e) { e.stopPropagation(); show(idx - 1); });
    nextBtn.addEventListener('click', function (e) { e.stopPropagation(); show(idx + 1); });
    // Klik na samotnou fotku = další fotka; klik mimo (na pozadí) = zavřít
    img.addEventListener('click', function (e) { e.stopPropagation(); if (list.length > 1) show(idx + 1); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); show(idx + 1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); show(idx - 1); }
    });
    // Posun prstem na mobilu
    var touchX = null;
    dlg.addEventListener('touchstart', function (e) { touchX = e.changedTouches[0].clientX; }, { passive: true });
    dlg.addEventListener('touchend', function (e) {
      if (touchX === null) return;
      var dx = e.changedTouches[0].clientX - touchX;
      touchX = null;
      if (Math.abs(dx) > 40) show(dx < 0 ? idx + 1 : idx - 1);
    }, { passive: true });
  }
})();
