/* A labelled Menu button for the public header on narrow screens. Without this script the links simply wrap. */
(function () {
  var button = document.querySelector('.menu-button'), nav = document.getElementById('site-nav');
  if (!button || !nav || !window.matchMedia) return;
  var narrow = window.matchMedia('(max-width: 640px)');
  function open() { return button.getAttribute('aria-expanded') === 'true'; }
  function sync() {
    if (narrow.matches) { button.hidden = false; nav.hidden = !open(); }
    else { button.hidden = true; nav.hidden = false; }
  }
  button.addEventListener('click', function () { button.setAttribute('aria-expanded', open() ? 'false' : 'true'); sync(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && open() && narrow.matches) { button.setAttribute('aria-expanded', 'false'); sync(); button.focus(); }
  });
  if (narrow.addEventListener) narrow.addEventListener('change', sync); else narrow.addListener(sync);
  sync();
})();
