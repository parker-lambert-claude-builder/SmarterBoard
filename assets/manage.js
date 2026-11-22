/* Optional conveniences; all management forms also work without JavaScript. */
'use strict';
/* Actions that can't be undone ask first, in Mashpad's own dialog (assets/confirm.js), never a browser popup. */
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (form.dataset.confirmed === 'yes') return;      // already agreed to: let it go through
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    window.mashpadConfirm({
      title: form.dataset.confirmTitle || 'Are you sure?',
      body: form.dataset.confirm,
      action: form.dataset.confirmAction || (button && button.textContent.trim()) || 'Continue',
      danger: form.dataset.confirmDanger === 'yes',
    }).then(agreed => {
      if (!agreed) return;
      form.dataset.confirmed = 'yes';
      form.submit();
    });
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
