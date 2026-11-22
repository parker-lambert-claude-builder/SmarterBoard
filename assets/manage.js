/* Optional conveniences; all management forms also work without JavaScript. */
'use strict';
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
/* Show/hide a list. The button says what it will do and whether the list is open. */
document.querySelectorAll('button[data-toggle]').forEach(button => {
  button.addEventListener('click', () => {
    if (!button.dataset.toggle) return;
    const target = document.getElementById(button.dataset.toggle);
    const open = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(open));
    target.hidden = !open;
    const label = button.querySelector('.toggle-label');
    if (label) label.textContent = open ? button.dataset.hide : button.dataset.show;
  });
});
document.querySelectorAll('button[data-copy]').forEach(button => {
  button.hidden = false;
  button.addEventListener('click', async () => {
    const source = document.getElementById(button.dataset.copy);
    const status = button.parentElement.querySelector('.copy-status');
    try {
      await navigator.clipboard.writeText(source.textContent);
      status.textContent = button.dataset.copied || 'Text copied.';
    } catch {
      const range = document.createRange();
      range.selectNodeContents(source);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      status.textContent = 'Select and copy the text above. Automatic copying is unavailable in this browser.';
    }
  });
});
