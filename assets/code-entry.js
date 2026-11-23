/* A six-digit code shown as six boxes.

   There is one real text field underneath, so everything a field does still works: a browser or phone offering
   to fill in the code from the email (autocomplete="one-time-code"), pasting, a password manager, a screen
   reader. This script only draws what has been typed into six boxes over it. Without the script the page shows
   the plain field, which works just the same. */
'use strict';
document.querySelectorAll('[data-code-entry]').forEach(wrap => {
  const input = wrap.querySelector('input');
  const boxes = [...wrap.querySelectorAll('.code-boxes span')];
  if (!input || !boxes.length) return;
  wrap.classList.add('enhanced');
  input.removeAttribute('maxlength');          // draw() keeps six digits; a spaced value from autofill or paste must not be cut first
  const draw = () => {
    const digits = input.value.replace(/\D+/g, '').slice(0, boxes.length);
    if (digits !== input.value) input.value = digits;            // letters and spaces from a paste are dropped
    const focused = document.activeElement === input;
    boxes.forEach((box, i) => {
      box.textContent = digits[i] || '';
      box.classList.toggle('filled', i < digits.length);
      box.classList.toggle('active', focused && i === Math.min(digits.length, boxes.length - 1));
    });
  };
  input.addEventListener('paste', event => {
    const text = event.clipboardData ? event.clipboardData.getData('text') : '';
    if (!text) return;
    event.preventDefault();
    const digits = text.replace(/\D+/g, '').slice(0, boxes.length);     // the digits first, then six of them
    if (digits) input.value = digits;
    draw();
  });
  ['input', 'focus', 'blur', 'change'].forEach(name => input.addEventListener(name, draw));
  input.addEventListener('keydown', () => setTimeout(draw, 0));
  input.addEventListener('select', () => input.setSelectionRange(input.value.length, input.value.length));   // the caret stays at the end
  draw();
});
