const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/manage.js'), 'utf8');
function setup({ confirm = true, writeText = async () => {} } = {}) {
  const handlers = {};
  const status = { textContent: '' };
  const invitation = { textContent: 'Invitation with a test-only token' };
  const form = { dataset: { confirm: 'Disconnect agent everywhere?' }, addEventListener: (name, fn) => handlers.submit = fn };
  const button = { hidden: true, dataset: { copy: 'agent-invitation' }, parentElement: { querySelector: () => status }, addEventListener: (name, fn) => handlers.click = fn };
  let selected = false;
  vm.runInNewContext(source, {
    document: { querySelectorAll: selector => selector.startsWith('form') ? [form] : [button], getElementById: () => invitation, createRange: () => ({ selectNodeContents(node) { assert.equal(node, invitation); } }) },
    navigator: { clipboard: { writeText } },
    window: { confirm: () => confirm, getSelection: () => ({ removeAllRanges() {}, addRange() { selected = true; } }) },
  });
  return { handlers, button, status, invitation, selected: () => selected };
}
test('a confirmation uses Mashpad\'s own dialog, never a browser popup; cancelling stops the action and agreeing submits it', async () => {
  assert.doesNotMatch(source, /window\.confirm|\bconfirm\(|\balert\(|\bprompt\(/);
  for (const agreed of [false, true]) {
    const asked = [];
    let submitted = 0, prevented = 0;
    const handlers = {};
    const form = { dataset: { confirm: 'They lose access.', confirmTitle: 'Remove from this pad?', confirmAction: 'Remove', confirmDanger: 'yes' },
      querySelector: () => ({ textContent: ' Remove @muse ' }), addEventListener: (n, fn) => { handlers.submit = fn; }, submit: () => { submitted++; } };
    vm.runInNewContext(source, { document: { querySelectorAll: sel => sel.startsWith('form') ? [form] : [], getElementById: () => null }, navigator: {},
      window: { mashpadConfirm: opts => { asked.push(opts); return Promise.resolve(agreed); } } });
    handlers.submit({ preventDefault() { prevented++; } });
    await new Promise(r => setImmediate(r));
    assert.equal(prevented, 1, 'the first submit always waits for an answer');
    assert.equal(JSON.stringify(asked), JSON.stringify([{ title: 'Remove from this pad?', body: 'They lose access.', action: 'Remove', danger: true }]));
    assert.equal(submitted, agreed ? 1 : 0);
    if (agreed) { handlers.submit({ preventDefault() { prevented++; } }); assert.equal(prevented, 1, 'once agreed, the submit goes through'); }
  }
});

test('the dialog: text only, Cancel focused, Escape and the backdrop cancel, focus returns', async () => {
  const dialogSource = fs.readFileSync(require('node:path').join(__dirname, '../assets/confirm.js'), 'utf8');
  assert.doesNotMatch(dialogSource, /window\.confirm\(|\balert\(|\bprompt\(/);
  const made = [];
  const node = cls => ({ cls, textContent: '', classList: { add(c) { node.added = c; } }, listeners: {}, focused: 0, addEventListener(n, fn) { this.listeners[n] = fn; }, focus() { this.focused++; doc.activeElement = this; } });
  const parts = { '.mp-modal-cancel': node('cancel'), '.mp-modal-ok': node('ok'), '#mp-modal-title': node('title'), '#mp-modal-body': node('body') };
  const trigger = { focus() { this.back = true; } };
  const doc = { activeElement: trigger, keys: null,
    createElement: () => { const w = { className: '', innerHTML: '', listeners: {}, querySelector: s => parts[s], addEventListener(n, fn) { this.listeners[n] = fn; }, remove() { this.removed = true; } }; made.push(w); return w; },
    addEventListener: (n, fn) => { doc.keys = fn; }, removeEventListener: () => { doc.keys = null; },
    body: { appendChild() {}, classList: { add() {}, remove() {} } } };
  const win = {};
  vm.runInNewContext(dialogSource, { window: win, document: doc });
  let p = win.mashpadConfirm({ title: 'Remove <b>?', body: '<img onerror=x>', action: 'Remove', danger: true });
  assert.match(made[0].innerHTML, /role="alertdialog" aria-modal="true" aria-labelledby="mp-modal-title" aria-describedby="mp-modal-body"/);
  assert.doesNotMatch(made[0].innerHTML, /<b>|<img/, 'what is passed in is set as text, never as markup');
  assert.deepEqual([parts['#mp-modal-title'].textContent, parts['#mp-modal-body'].textContent, parts['.mp-modal-ok'].textContent], ['Remove <b>?', '<img onerror=x>', 'Remove']);
  assert.equal(doc.activeElement, parts['.mp-modal-cancel'], 'Cancel has focus, so Enter never confirms by accident');
  doc.keys({ key: 'Tab', preventDefault() {} });
  assert.equal(doc.activeElement, parts['.mp-modal-ok'], 'Tab stays inside the dialog');
  doc.keys({ key: 'Escape', preventDefault() {} });
  assert.equal(await p, false);
  assert.ok(made[0].removed && trigger.back, 'closed, and focus is back where it was');
  p = win.mashpadConfirm({ title: 'Again' });
  made[1].listeners.mousedown({ target: made[1] });
  assert.equal(await p, false, 'a click outside the box cancels');
  p = win.mashpadConfirm({ title: 'Once more' });
  parts['.mp-modal-ok'].listeners.click();
  assert.equal(await p, true);
});

test('copy invitation reports success only after copying and offers selection when clipboard fails', async () => {
  let copied;
  const ok = setup({ writeText: async text => { copied = text; } });
  assert.equal(ok.button.hidden, false);
  await ok.handlers.click();
  assert.equal(copied, ok.invitation.textContent);
  assert.match(ok.status.textContent, /copied/);
  const denied = setup({ writeText: async () => { throw Error('denied'); } });
  await denied.handlers.click();
  assert.equal(denied.selected(), true);
  assert.match(denied.status.textContent, /Automatic copying is unavailable/);
});

test('copying says what was copied when the button gives its own message', async () => {
  const ok = setup();
  ok.button.dataset.copied = 'Link copied. Send it to the people you’re inviting, or to their agents.';
  await ok.handlers.click();
  assert.equal(ok.status.textContent, 'Link copied. Send it to the people you’re inviting, or to their agents.');
});

test('the show/hide button opens and closes its list and says which it will do', () => {
  const handlers = {};
  const label = { textContent: 'Show everyone' };
  const list = { hidden: true };
  const attrs = { 'aria-expanded': 'false' };
  const button = { dataset: { toggle: 'member-list', show: 'Show everyone', hide: 'Hide the list' }, getAttribute: n => attrs[n], setAttribute: (n, v) => { attrs[n] = v; },
    querySelector: () => label, addEventListener: (name, fn) => { handlers.click = fn; } };
  vm.runInNewContext(source, { document: { querySelectorAll: sel => sel === 'button[data-toggle]' ? [button] : [], getElementById: () => list }, navigator: {}, window: {} });
  handlers.click();
  assert.deepEqual([list.hidden, attrs['aria-expanded'], label.textContent], [false, 'true', 'Hide the list']);
  handlers.click();
  assert.deepEqual([list.hidden, attrs['aria-expanded'], label.textContent], [true, 'false', 'Show everyone']);
});
