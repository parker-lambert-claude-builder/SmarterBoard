/* Mashpad's own confirmation dialog. Nothing on the site uses a browser popup
   (confirm, alert or prompt): every "are you sure?" goes through this.

   mashpadConfirm({ title, body, action, cancel, danger }) returns a promise that
   settles true when the action button is pressed and false for anything else:
   Cancel, Escape, or a click outside the box. Focus moves into the dialog,
   stays inside it while it is open, and goes back where it was afterwards. */
'use strict';
(() => {
  let dismiss = null;
  window.mashpadConfirm = function ({ title = 'Are you sure?', body = '', action = 'Continue', cancel = 'Cancel', danger = false } = {}) {
    return new Promise(resolve => {
      if (dismiss) dismiss(false);                      // never two at once
      const before = document.activeElement;
      const wrap = document.createElement('div');
      wrap.className = 'mp-modal-backdrop';
      wrap.innerHTML = '<div class="mp-modal" role="alertdialog" aria-modal="true" aria-labelledby="mp-modal-title" aria-describedby="mp-modal-body">'
        + '<h2 id="mp-modal-title"></h2><p id="mp-modal-body"></p>'
        + '<div class="mp-modal-actions"><button type="button" class="mp-modal-cancel"></button><button type="button" class="mp-modal-ok"></button></div></div>';
      const no = wrap.querySelector('.mp-modal-cancel'), yes = wrap.querySelector('.mp-modal-ok');
      wrap.querySelector('#mp-modal-title').textContent = title;       // text only: nothing passed in is ever treated as markup
      wrap.querySelector('#mp-modal-body').textContent = body;
      no.textContent = cancel; yes.textContent = action;
      if (danger) yes.classList.add('danger');

      const onKey = e => {
        if (e.key === 'Escape') { e.preventDefault(); close(false); }
        if (e.key === 'Tab') {                          // two controls: Tab and Shift+Tab swap between them
          e.preventDefault();
          (document.activeElement === no ? yes : no).focus();
        }
      };
      function close(result) {
        document.removeEventListener('keydown', onKey, true);
        wrap.remove();
        document.body.classList.remove('mp-modal-open');
        dismiss = null;
        if (before && before.focus) before.focus();
        resolve(result);
      }
      dismiss = close;
      no.addEventListener('click', () => close(false));
      yes.addEventListener('click', () => close(true));
      wrap.addEventListener('mousedown', e => { if (e.target === wrap) close(false); });
      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(wrap);
      document.body.classList.add('mp-modal-open');
      no.focus();                                       // the safe choice has focus: Enter never confirms by accident
    });
  };
})();
