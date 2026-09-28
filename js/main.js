/* Glow by Petra Jahodová – společný JS (menu, aktivní odkaz, lightbox, rok v patičce) */
(function () {
  'use strict';

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
    dlg.innerHTML = '<button class="lightbox-close" type="button" aria-label="Zavřít">×</button><img alt=""><div class="lightbox-caption"></div>';
    document.body.appendChild(dlg);
    var img = dlg.querySelector('img');
    var cap = dlg.querySelector('.lightbox-caption');
    var list = Array.prototype.slice.call(links);
    var idx = 0;
    function show(i) {
      idx = (i + list.length) % list.length;
      img.src = list[idx].getAttribute('href');
      img.alt = list[idx].querySelector('img') ? list[idx].querySelector('img').alt : '';
      cap.textContent = list[idx].getAttribute('data-caption') || '';
    }
    list.forEach(function (a, i) {
      a.addEventListener('click', function (e) { e.preventDefault(); show(i); dlg.showModal(); });
    });
    dlg.querySelector('.lightbox-close').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') show(idx + 1);
      if (e.key === 'ArrowLeft') show(idx - 1);
    });
  }
})();
