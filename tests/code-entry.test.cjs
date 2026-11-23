'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/code-entry.js'), 'utf8');

function setup() {
  const listeners = {};
  const input = {
    value: '', attrs: { maxlength: '6' },
    addEventListener(name, fn) { listeners[name] = fn; },
    removeAttribute(name) { delete this.attrs[name]; },
    setSelectionRange() {},
  };
  const boxes = Array.from({ length: 6 }, () => ({ textContent: '', classList: { toggle() {} } }));
  const wrap = { classList: { add() {} }, querySelector: () => input, querySelectorAll: () => boxes };
  const document = { activeElement: input, querySelectorAll: () => [wrap] };
  vm.runInNewContext(source, { document, setTimeout: fn => fn() });
  const shown = () => boxes.map(b => b.textContent).join('');
  const paste = text => {
    let prevented = false;
    listeners.paste({ clipboardData: { getData: () => text }, preventDefault() { prevented = true; } });
    return prevented;
  };
  return { input, listeners, shown, paste };
}

test('a pasted code with a space in it keeps all six digits (the digits come out before the field can cut it)', () => {
  const { input, shown, paste } = setup();
  assert.equal(paste('777 938'), true);
  assert.equal(input.value, '777938');
  assert.equal(shown(), '777938');
});

test('a paste with letters, dashes or more than six digits is cleaned and capped at six', () => {
  const { input, shown, paste } = setup();
  paste('code: 12-34-56 (7)');
  assert.equal(input.value, '123456');
  assert.equal(shown(), '123456');
});

test('a paste with no digits changes nothing', () => {
  const { input, shown, paste } = setup();
  input.value = '12';
  paste('no digits here');
  assert.equal(input.value, '12');
  assert.equal(shown(), '12');
});

test('the field\'s own length limit is lifted once the boxes are drawn, so a spaced autofill is not cut first', () => {
  const { input, listeners, shown } = setup();
  assert.equal('maxlength' in input.attrs, false);
  input.value = '777 938';
  listeners.input();
  assert.equal(input.value, '777938');
  assert.equal(shown(), '777938');
});

test('letters typed are dropped and digits beyond six are ignored', () => {
  const { input, listeners, shown } = setup();
  input.value = '12a3';
  listeners.input();
  assert.equal(input.value, '123');
  input.value = '1234567';
  listeners.input();
  assert.equal(input.value, '123456');
  assert.equal(shown(), '123456');
});
