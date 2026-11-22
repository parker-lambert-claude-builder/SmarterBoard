const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../assets/boards.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const reply = (data, status = 200) => ({ ok: status === 200, status, json: async () => data });
const pad = (name = 'Launch review') => ({ slug: 'launch-review', name, total: 0, messages: [], latest_id: 0, expires: Date.now() / 1000 + 86400 });

async function app() {
  const elements = new Map();
  const listeners = {};
  function element(id) {
    if (!elements.has(id)) elements.set(id, {
      innerHTML: '', value: '', style: {}, handlers: {}, disabled: false,
      contains: () => false, matches: () => false,
      querySelector: selector => selector.startsWith('button') ? element('post-button') : null,
      classList: { toggle(name, on) { elements.get(id)[name] = on; } }, blur() {},
      addEventListener(type, handler) { this.handlers[type] = handler; },
      focus() {}, requestSubmit() {}, setAttribute(name, value) { this[name] = value; },
    });
    return elements.get(id);
  }
  const context = {
    URL, URLSearchParams, Date, setInterval() {}, setTimeout() {},
    location: { href: 'https://example.test/mashpad/pads/', search: '', hash: '' },
    fetch: async () => reply({ boards: [], ttl: 2592000 }),
    document: { title: '', hidden: false, activeElement: null, body: { scrollHeight: 900 },
      getElementById: element, querySelectorAll: () => [],
      addEventListener(type, handler) { listeners[type] = handler; } },
    addEventListener() {}, scrollTo() {}, innerHeight: 600, scrollY: 0,
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(source + '\nthis.app = {state, route, pollBoard, boardListHTML, refreshPadNavigation, bindComposer, setReplyOpen, unseenMessages, accountHTML, applyAccount, loadMe};', context);
  await flush();
  return { context, api: context.app, element, listeners };
}

test('pad row has independent, escaped conversation and settings links', async () => {
  const { api } = await app();
  api.state.boards = [{ slug: 'launch-review', name: 'A "name" <img src=x>', total: 2, last_activity: 0, authors: [] }];
  api.state.slug = 'launch-review'; api.state.view = 'settings';
  const html = api.boardListHTML();
  assert.equal((html.match(/<a\s/g) || []).length, 2);
  assert.match(html, /<\/a>\s*<a class="pad-settings-link"/);
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/pads\/\?b=launch-review&amp;view=settings"/);
  assert.match(html, /aria-label="Pad info for A &quot;name&quot; &lt;img src=x&gt;"/);
  assert.equal((html.match(/aria-current="page"/g) || []).length, 1);
  assert.doesNotMatch(html, /<img/);
});

test('direct settings route reads the selected pad without posting or polling messages', async () => {
  const { api, context, element } = await app();
  const requests = [];
  context.fetch = async (url, options) => { requests.push({ url, options }); return reply(pad()); };
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  await api.pollBoard();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].options, undefined);
  assert.match(requests[0].url, /action=read&board=launch-review/);
  assert.match(element('main').innerHTML, /<h1>Pad info<\/h1>/);
  assert.match(element('main').innerHTML, /Back to pad/);
  assert.doesNotMatch(element('main').innerHTML, /<form|<input|<button/);
  assert.equal(api.state.view, 'settings');
});

test('late conversation response cannot replace settings for the same pad', async () => {
  const { api, context, element } = await app();
  let finishRead;
  context.fetch = () => new Promise(resolve => { finishRead = resolve; });
  context.location.search = '?b=launch-review';
  const reading = api.route();
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  const settings = element('main').innerHTML;
  finishRead(reply(pad()));
  await reading;
  assert.equal(element('main').innerHTML, settings);
});

test('late settings response cannot replace home after Back navigation', async () => {
  const { api, context, element } = await app();
  let finishRead;
  context.fetch = () => new Promise(resolve => { finishRead = resolve; });
  context.location.search = '?b=launch-review&view=settings';
  const reading = api.route();
  context.location.search = '';
  await api.route();
  finishRead(reply(pad()));
  await reading;
  assert.match(element('main').innerHTML, /Your pads/);
  assert.equal(api.state.view, 'home');
});

test('missing pad settings show a recovery link without fabricating a pad', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply({ error: 'not_found' }, 404);
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  assert.match(element('main').innerHTML, /This pad no longer exists/);
  assert.match(element('main').innerHTML, />All pads<\/a>/);
});

test('sidebar refresh preserves a focused settings link', async () => {
  const { api, context, element } = await app();
  const nav = element('board-nav'); nav.innerHTML = 'focused navigation'; nav.contains = () => true;
  context.document.querySelectorAll = () => [nav];
  api.refreshPadNavigation();
  assert.equal(nav.innerHTML, 'focused navigation');
  nav.contains = () => false;
  api.refreshPadNavigation();
  assert.match(nav.innerHTML, /No pads yet/);
});

test('pad events (a join, a request) show as a line between posts, not as a post by someone', async () => {
  const { api, context, element } = await app();
  context.renderMessage = text => `<p>${text}</p>`;
  const added = [];
  const transcript = element('transcript'); transcript.querySelector = () => null; transcript.lastElementChild = null;
  transcript.insertAdjacentHTML = (where, html) => { added.push(html); transcript.lastElementChild = { dataset: { author: '' } }; };
  context.document.createElement = () => ({ dataset: {}, style: { setProperty() {} }, insertAdjacentHTML(w, h) { added.push(h); } });
  transcript.appendChild = node => { transcript.lastElementChild = node; };
  context.fetch = async () => reply({ ...pad('Dev'), slug: 'dev', key: 'dylan/dev', owner: 'dylan', owned: true, total: 2, latest_id: 2, messages: [
    { id: 1, author: 'sam', body: '@sam joined through the <invite> link.', ts: 1700000000, kind: 'event' },
    { id: 2, author: 'sam', body: 'Hello.', ts: 1700000100, kind: 'post' }] });
  context.location.pathname = '/mashpad/pads/dylan/dev';
  await api.route();
  assert.match(added[0], /^<p class="pad-event" id="m1"[^>]*><span class="event-text">@sam joined through the &lt;invite&gt; link\.<\/span>/);
  assert.ok(added.some(h => /class="msg" id="m2"/.test(h)), 'the post after it still renders as a post');
  assert.doesNotMatch(element('head-meta').innerHTML || element('main').innerHTML, /event/);
});

test('on a threaded pad each message has a Reply link and replies sit under the message they answer', async () => {
  const { api, context, element } = await app();
  context.renderMessage = text => `<p>${text}</p>`;
  const placed = [];
  const slotFor = id => { const el = element(id); el.insertAdjacentHTML = (w, html) => placed.push([id, html]); return el; };
  const transcript = slotFor('transcript'); transcript.querySelector = () => null;
  slotFor('replies-m1'); slotFor('replies-m2');
  const real = context.document.getElementById;
  context.document.getElementById = id => (id === 'replies-m9' ? null : real(id));
  const thread = threaded => reply({ ...pad('Dev'), slug: 'dev', key: 'dylan/dev', owner: 'dylan', owned: true, threaded, total: 4, latest_id: 4, messages: [
    { id: 1, author: 'dylan', body: 'First', ts: 1700000000, kind: 'post', reply_to: null },
    { id: 2, author: 'muse', body: 'Answer', ts: 1700000100, kind: 'post', reply_to: 1 },
    { id: 3, author: 'dylan', body: 'Deeper', ts: 1700000200, kind: 'post', reply_to: 2 },
    { id: 4, author: 'muse', body: 'Orphan', ts: 1700000300, kind: 'post', reply_to: 9 }] });
  context.fetch = async () => thread(true);
  context.location.pathname = '/mashpad/pads/dylan/dev';
  await api.route();
  assert.deepEqual(placed.map(([where, html]) => [where, html.match(/id="m(\d+)"/)[1]]), [['transcript', '1'], ['replies-m1', '2'], ['replies-m2', '3'], ['transcript', '4']],
    'a reply goes under its parent; one whose parent is not on the page goes to the end');
  const first = placed[0][1];
  assert.match(first, /<article class="tmsg" id="m1"/);
  assert.match(first, /<button type="button" class="reply-link" data-reply-to="1" data-reply-name="@dylan" aria-expanded="false">Reply<\/button>/);
  assert.match(first, /<div class="reply-slot reply-inline" id="reply-m1" hidden><\/div>\s*<div class="replies" id="replies-m1"><\/div>/);
  // The same messages on a pad without threads: flat, no Reply links, replies marked with what they answered.
  placed.length = 0;
  context.document.createElement = () => ({ dataset: {}, style: { setProperty() {} }, insertAdjacentHTML(w, h) { placed.push(['group', h]); } });
  transcript.appendChild = node => { transcript.lastElementChild = node; }; transcript.lastElementChild = null;
  context.fetch = async () => thread(false);
  await api.route();
  const flat = placed.map(p => p[1]).join('');
  assert.doesNotMatch(flat, /data-reply-to|class="tmsg"/);
  assert.match(flat, /<a class="in-reply" href="#m1">in reply to #1<\/a>/);
});

test('a Reply link opens a box under its message that posts with reply_to; pressing it again closes the box', async () => {
  const { api, context, element, listeners } = await app();
  const posts = [];
  context.fetch = async (url, opts) => { if (/action=post/.test(url)) { posts.push(JSON.parse(opts.body)); return reply({ ok: true, message: { id: 8 } }); } return reply({ ...pad('Dev'), slug: 'dev', key: 'dylan/dev', owner: 'dylan', owned: true, threaded: true }); };
  context.location.pathname = '/mashpad/pads/dylan/dev';
  await api.route();
  const press = () => listeners.click({ target: { closest: s => s === '[data-reply-to]' ? { dataset: { replyTo: '5', replyName: '@muse' } } : null } });
  element('reply-m5').innerHTML = '';
  press();
  assert.match(element('reply-m5').innerHTML, /<form class="reply-box" id="reply-m5-form"/);
  assert.match(element('reply-m5').innerHTML, /placeholder="Reply to @muse"/);
  assert.equal(element('reply-m5').hidden, false);
  assert.equal(element('reply-m5-form')['is-open'], true);
  element('reply-m5-body').value = 'to you';
  await element('reply-m5-form').handlers.submit({ preventDefault() {} });
  assert.deepEqual([posts[0].message, posts[0].reply_to], ['to you', 5]);
  assert.equal(element('reply-m5').innerHTML, '', 'the box is removed after posting');
  press(); press();
  assert.equal(element('reply-m5').innerHTML, '');
  assert.equal(element('reply-m5').hidden, true);
  api.bindComposer('dylan/dev');
  element('reply-top-body').value = 'top-level';
  await element('reply-top-form').handlers.submit({ preventDefault() {} });
  assert.equal('reply_to' in posts[1], false, 'the standing boxes post to the pad as a whole');
});

test('keyboard posting prevents a newline and does not submit again while pending', async () => {
  const { api, element } = await app();
  let submissions = 0, prevented = 0;
  element('reply-bottom-form').requestSubmit = () => submissions++;
  api.bindComposer('launch-review');
  const event = { key: 'Enter', ctrlKey: true, preventDefault: () => prevented++ };
  element('reply-bottom-body').handlers.keydown(event);
  element('post-button').disabled = true;
  element('reply-bottom-body').handlers.keydown(event);
  assert.equal(submissions, 1);
  assert.equal(prevented, 2);
});

test('a message id is rendered at most once, however often it arrives', async () => {
  const { api } = await app();
  const m = (id) => ({ id, author: 'muse', body: 'hi', ts: 1 });
  const ids = list => Array.from(api.unseenMessages(list), x => x.id);
  assert.deepEqual(ids([m(44), m(45)]), [44, 45]);
  assert.deepEqual(ids([m(45), m(45), m(46)]), [46]);
  assert.equal(api.unseenMessages([m(44), m(45), m(46)]).length, 0);
});

function fakeTranscript(context, element) {
  const t = element('transcript');
  t.children = [];
  Object.defineProperty(t, 'lastElementChild', { get() { return this.children[this.children.length - 1] || null; } });
  t.appendChild = child => { t.children.push(child); return child; };
  context.renderMessage = context.renderMessage || (text => text);
  context.document.createElement = () => {
    const g = { dataset: {}, style: { setProperty() {} }, innerHTML: '', messages: 0 };
    g.insertAdjacentHTML = () => { g.messages++; };
    return g;
  };
  return () => t.children.reduce((n, g) => n + g.messages, 0);
}

test('overlapping polls send one request; a poll racing the initial load adds nothing twice', async () => {
  const { api, context, element } = await app();
  const drawn = fakeTranscript(context, element);
  const msgs = [{ id: 1, author: 'muse', body: 'a', ts: 1 }, { id: 2, author: 'muse', body: 'b', ts: 2 }];
  context.fetch = async () => reply({ ...pad(), messages: msgs, total: 2, latest_id: 2 });
  context.location.search = '?b=launch-review';
  await api.route();
  assert.equal(api.state.rendered.size, 2);
  let calls = 0, release;
  context.fetch = () => { calls++; return new Promise(r => { release = () => r(reply({ ...pad(), messages: msgs, total: 2, latest_id: 2 })); }); };
  const first = api.pollBoard();
  const second = api.pollBoard();
  const third = api.pollBoard();
  release();
  await Promise.all([first, second, third]);
  assert.equal(calls, 1);
  assert.equal(api.state.rendered.size, 2);
  assert.equal(drawn(), 2);
  assert.equal(api.state.latest, 2);
});

test('opening a pad starts a fresh rendered set', async () => {
  const { api, context, element } = await app();
  fakeTranscript(context, element);
  api.state.rendered.add(99);
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review';
  await api.route();
  assert.equal(api.state.rendered.has(99), false);
});

test('viewer links use the clean pads/ address from any page depth', async () => {
  const { api } = await app();
  api.state.boards = [{ slug: 'launch-review', name: 'Launch review', total: 1, last_activity: 0, authors: [] }];
  const html = api.boardListHTML();
  assert.doesNotMatch(html, /\.php/);
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/pads\/\?b=launch-review"/);
});

test('participant links are independent, encoded, and show a single @ prefix', async () => {
  const { api } = await app();
  api.state.boards = [{ slug: 'launch-review', name: 'Launch', total: 2, last_activity: 0, authors: ['muse', '@tester', '<img " &'] }];
  const html = api.boardListHTML();
  assert.match(html, /<\/a>\s*<div class="who-list">/);
  assert.match(html, /view=agent&amp;agent=muse[^>]*>@muse<\/a>/);
  assert.match(html, /agent=%40tester[^>]*>@tester<\/a>/);
  assert.doesNotMatch(html, /@@tester|<img/);
  assert.match(html, /@&lt;img &quot; &amp;/);
});

test('a participant page shows real details for an agent on a private pad, with nothing made up', async () => {
  const { api, context, element } = await app();
  const requests = [];
  const member = { handle: 'muse', kind: 'agent', display_name: 'Muse <QA>', on_pad: true, pad_role: 'member', is_creator: false, joined: 1700000000, joined_via: 'lobby',
    posts: 12, first_post: 1700000100, last_post: 1700009000, role: 'QA <tester>', doing: 'Acceptance tests & <reviews>', platform: 'ChatGPT', self_described: true, responsible: 'dylan', last_active: 1700009500 };
  context.fetch = async (url, options) => { requests.push({ url, options }); return reply({ key: 'dylan/dev', pad_name: 'Dev', member }); };
  context.location.pathname = '/mashpad/pads/dylan/dev'; context.location.search = '?view=agent&agent=%40Muse';
  await api.route();
  await api.pollBoard();
  const html = element('main').innerHTML;
  assert.equal(requests.length, 1);
  assert.match(requests[0].url, /api\/\?action=member&board=dylan%2Fdev&handle=muse$/);
  assert.equal(requests[0].options, undefined);
  assert.match(html, /<h1>@muse<\/h1>/);
  assert.match(html, /AI agent · Muse &lt;QA&gt;/);
  assert.match(html, /<dt>Role<\/dt><dd>QA &lt;tester&gt; <span class="placeholder-label">\(as the agent describes itself\)<\/span>/);
  assert.match(html, /<dt>Runs on<\/dt><dd>ChatGPT<\/dd>/);
  assert.match(html, /<dt>Responsible person<\/dt><dd><a href="https:\/\/example\.test\/mashpad\/pads\/dylan\/dev\?view=agent&amp;agent=dylan" data-nav="dylan\/dev">@dylan<\/a>/);
  assert.match(html, /<dt>Joined<\/dt><dd>Joined with a lobby code,/);
  assert.match(html, /<dt>Messages here<\/dt><dd>12, most recently/);
  assert.match(html, /Acceptance tests &amp; &lt;reviews&gt;/);
  assert.doesNotMatch(html, /placeholder\)|Profile preview|Project assistant|Model name|<img|<input|<form/);
  assert.equal(api.state.view, 'agent');
});

test('a participant page for a person, and for someone no longer on the pad', async () => {
  const { api, context, element } = await app();
  const person = { handle: 'dylan', kind: 'human', display_name: 'Dylan Rhodes', on_pad: true, pad_role: 'owner', is_creator: true, joined: 1700000000, joined_via: 'created', posts: 0, first_post: null, last_post: null };
  context.fetch = async () => reply({ key: 'dylan/dev', pad_name: 'Dev', member: person });
  context.location.pathname = '/mashpad/pads/dylan/dev'; context.location.search = '?view=agent&agent=dylan';
  await api.route();
  let html = element('main').innerHTML;
  assert.match(html, /Person · Dylan Rhodes/);
  assert.match(html, /<dt>On this pad<\/dt><dd>Owner<\/dd>/);
  assert.match(html, /<dt>Messages here<\/dt><dd>None yet<\/dd>/);
  assert.doesNotMatch(html, /Responsible person|Runs on|Last connected/);
  context.fetch = async () => reply({ key: 'dylan/dev', pad_name: 'Dev', member: { ...person, handle: 'eve', display_name: 'eve', on_pad: false, pad_role: 'member', is_creator: false } });
  context.location.search = '?view=agent&agent=eve';
  await api.route();
  html = element('main').innerHTML;
  assert.match(html, /No longer on this pad\. Their earlier messages stay\./);
  assert.doesNotMatch(html, /<dt>On this pad<\/dt>/);
});

test('late pad response cannot overwrite an agent profile', async () => {
  const { api, context, element } = await app();
  let finish;
  context.fetch = () => new Promise(resolve => { finish = resolve; });
  context.location.search = '?b=launch-review';
  const reading = api.route();
  context.fetch = async () => reply({...pad(), messages:[{author:'muse'}]});
  context.location.search = '?b=launch-review&view=agent&agent=muse';
  await api.route();
  finish(reply(pad())); await reading;
  assert.match(element('main').innerHTML, /<h1>@muse<\/h1>/);
});

test('an unknown name, or any name on an older open pad, gets an honest page instead of a profile', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply({ error: 'not_found' }, 404);
  context.location.pathname = '/mashpad/pads/dylan/dev'; context.location.search = '?view=agent&agent=unknown';
  await api.route();
  assert.match(element('main').innerHTML, /Nobody by that name is on this pad\./);
  const requests = [];
  context.fetch = async url => { requests.push(url); return reply(pad()); };
  const name = '<img onerror="bad()">';
  context.location.pathname = '/mashpad/pads/'; context.location.search = '?b=launch-review&view=agent&agent=' + encodeURIComponent(name);
  await api.route();
  const html = element('main').innerHTML;
  assert.equal(requests.length, 0, 'open pads have no member records to ask for');
  assert.match(html, /@&lt;img onerror=&quot;bad\(\)&quot;&gt;/);
  assert.match(html, /a name is only a signature/);
  assert.doesNotMatch(html, /<img|Project assistant|placeholder/);
});

test('signed out with accounts enabled: sign-in and create-account links that return here', async () => {
  const { api, context } = await app();
  context.fetch = async () => reply({ signed_in: false, accounts: true });
  await api.loadMe();
  const html = api.accountHTML();
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/signin\/\?next=/);
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/register\/\?next=/);
  assert.equal(api.state.user, null);
});

test('no account links before the site has accounts', async () => {
  const { api, context } = await app();
  context.fetch = async () => reply({ signed_in: false, accounts: false });
  await api.loadMe();
  assert.equal(api.accountHTML(), '');
});

test('signed in: account shown, composer name fixed to the handle, handle escaped', async () => {
  const { api, context, element } = await app();
  context.fetch = async url => /action=me/.test(url) ? reply({ signed_in: true, handle: 'dy<lan', display_name: 'Dylan' }) : reply(pad());
  await api.loadMe();
  assert.match(api.accountHTML(), /Signed in as <strong>@dy&lt;lan<\/strong>/);
  assert.match(api.accountHTML(), /signout\//);
  context.location.search = '?b=launch-review';
  await api.route();
  assert.doesNotMatch(element('main').innerHTML, /Reply as|reply-(top|bottom)-author/, 'signed in, a reply needs no name field');
});

test('a reply box is always present above and below the conversation; nothing is pinned to the page bottom', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply({ ...pad('Launch'), slug: 'launch', key: 'alice/launch', owner: 'alice', owned: true });
  context.location.pathname = '/mashpad/pads/alice/launch';
  await api.route();
  const main = element('main').innerHTML;
  const at = id => main.indexOf(`id="${id}"`);
  assert.ok(at('head-meta') < at('brief') && at('brief') < at('reply-top-form') && at('reply-top-form') < at('transcript') && at('transcript') < at('reply-bottom-form'),
    'order: info header, brief, reply box, messages, reply box');
  assert.equal((main.match(/<form class="reply-box"/g) || []).length, 2);
  assert.equal((main.match(/placeholder="Join the conversation"/g) || []).length, 2);
  assert.equal((main.match(/data-reply-cancel="(top|bottom)">Cancel<\/button><button type="submit">Post</g) || []).length, 2);
  assert.doesNotMatch(main, /class="composer"|href="#composer"|data-reply="/);
  assert.doesNotMatch(main, /class="reply-button"/, 'no separate Reply buttons: the boxes are already there');
});

test('a reply box opens when focused, and Cancel empties and closes it without touching the other box', async () => {
  const { api, context, element, listeners } = await app();
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review';
  await api.route();
  element('reply-top-body').handlers.focus();
  assert.equal(element('reply-top-form')['is-open'], true);
  assert.notEqual(element('reply-bottom-form')['is-open'], true);
  element('reply-top-body').value = 'never mind';
  element('reply-bottom-body').value = 'keep this';
  listeners.click({ target: { closest: s => s === '[data-reply-cancel]' ? { dataset: { replyCancel: 'top' } } : null } });
  assert.equal(element('reply-top-form')['is-open'], false);
  assert.equal(element('reply-top-body').value, '');
  assert.equal(element('reply-bottom-body').value, 'keep this');
  element('reply-bottom-body').handlers.focus();
  element('reply-bottom-body').handlers.keydown({ key: 'Escape' });
  assert.equal(element('reply-bottom-form')['is-open'], true, 'Escape never throws away typed text');
});

test('posting from either box sends that box\'s text, then empties and closes it', async () => {
  const { api, context, element } = await app();
  const posts = [];
  context.fetch = async (url, opts) => { if (/action=post/.test(url)) { posts.push(JSON.parse(opts.body)); return reply({ ok: true }); } return reply(pad()); };
  context.location.search = '?b=launch-review';
  await api.route();
  for (const where of ['top', 'bottom']) {
    element(`reply-${where}-body`).handlers.focus();
    element(`reply-${where}-author`).value = 'visitor';
    element(`reply-${where}-body`).value = `from the ${where}`;
    await element(`reply-${where}-form`).handlers.submit({ preventDefault() {} });
    assert.equal(element(`reply-${where}-body`).value, '');
    assert.equal(element(`reply-${where}-form`)['is-open'], false);
  }
  assert.deepEqual(posts.map(p => [p.board, p.author, p.message]), [['launch-review', 'visitor', 'from the top'], ['launch-review', 'visitor', 'from the bottom']]);
});

test('the pad list no longer marks pads Private; mentions of people on the pad link to their info page', async () => {
  const { api, context, element } = await app();
  api.state.boards = [{ slug: 'dev', key: 'dylan/dev', owner: 'dylan', name: 'Dev', total: 2, last_activity: 0, authors: ['dylan', 'muse'], private: true }];
  assert.doesNotMatch(api.boardListHTML(), /pad-private|Private/);
  assert.match(api.boardListHTML(), /<span class="pad-address">dylan\/dev<\/span>/);
  const seen = [];
  context.renderMessage = (text, opts) => { seen.push(['muse', 'Reviewer', 'stranger'].map(h => opts.mention(h))); return ''; };
  context.fetch = async () => reply({ ...pad('Dev'), slug: 'dev', key: 'dylan/dev', owner: 'dylan', owned: true, members: ['dylan', 'muse', 'reviewer'],
    total: 1, latest_id: 1, messages: [{ id: 1, author: 'dylan', body: '@muse and @Reviewer, not @stranger', ts: 1700000000 }] });
  context.location.pathname = '/mashpad/pads/dylan/dev';
  const transcript = element('transcript'); transcript.querySelector = () => null; transcript.lastElementChild = null;
  context.document.createElement = () => ({ dataset: {}, style: { setProperty() {} }, insertAdjacentHTML() {} });
  transcript.appendChild = node => { transcript.lastElementChild = node; };
  await api.route();
  assert.equal(seen.length, 1);
  const [muse, reviewer, stranger] = seen[0];
  assert.equal(muse.href, 'https://example.test/mashpad/pads/dylan/dev?view=agent&agent=muse');
  assert.equal(muse.nav, 'dylan/dev');
  assert.equal(reviewer.href, 'https://example.test/mashpad/pads/dylan/dev?view=agent&agent=reviewer', 'a member who has not posted, any capitalisation');
  assert.equal(stranger, null);
});

test('signed-in posts send no author name', async () => {
  const { api, context, element } = await app();
  const posts = [];
  context.fetch = async (url, opts) => {
    if (/action=me/.test(url)) return reply({ signed_in: true, handle: 'dylan', display_name: 'Dylan' });
    if (/action=post/.test(url)) { posts.push(JSON.parse(opts.body)); return reply({ ok: true }); }
    return reply(pad());
  };
  await api.loadMe();
  api.bindComposer('launch-review');
  element('reply-top-author').value = 'someone-else';
  element('reply-top-body').value = 'hello';
  await element('reply-top-form').handlers.submit({ preventDefault() {} });
  assert.equal(posts.length, 1);
  assert.equal(posts[0].author, '');
  assert.equal(posts[0].message, 'hello');
});

test('pad settings offer Markdown and JSON export of the whole pad', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  const html = element('main').innerHTML;
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/api\/\?action=export&amp;board=launch-review" download>Download Markdown/);
  assert.match(html, /action=export&amp;board=launch-review&amp;format=json" download>Download JSON/);
});

test('private pads show their address, credit an agent creator, and link to member management', async () => {
  const { api, context, element } = await app();
  api.state.boards = [{ slug: 'dev', key: 'dylan/dev', owner: 'dylan', name: 'Dev', total: 2, last_activity: 0, authors: ['dylan', 'muse'], private: true, created_by: 'muse' }];
  const html = api.boardListHTML();
  assert.match(html, /<div class="meta"><span class="pad-address">dylan\/dev<\/span> · 2 messages/);
  assert.match(html, /created by @muse/);
  context.fetch = async () => reply({ ...pad(), slug: 'dev', key: 'dylan/dev', owner: 'dylan', name: 'Dev', owned: true, is_owner: true });
  context.location.pathname = '/mashpad/pads/dylan/dev';
  await api.route();
  assert.match(element('main').innerHTML, /href="https:\/\/example\.test\/mashpad\/manage\/dylan\/dev">Manage access</);
  context.location.search = '?view=settings';
  await api.route();
  assert.match(element('main').innerHTML, />Manage access<\/a>/);
  assert.match(element('main').innerHTML, /api\/\?action=export&amp;board=dylan%2Fdev" download>Download Markdown/);
});

test('a private pad is addressed as pads/owner/name everywhere, never with ?b= or .php', async () => {
  const { api, context, element } = await app();
  api.state.boards = [{ slug: 'launch', key: 'alice/launch', owner: 'alice', name: 'Launch', total: 1, last_activity: 0, authors: ['alice'], private: true }];
  const list = api.boardListHTML();
  assert.match(list, /class="board-link" href="https:\/\/example\.test\/mashpad\/pads\/alice\/launch" data-nav="alice\/launch"/);
  assert.match(list, /href="https:\/\/example\.test\/mashpad\/pads\/alice\/launch\?view=settings"/);
  assert.match(list, /href="https:\/\/example\.test\/mashpad\/pads\/alice\/launch\?view=agent&amp;agent=alice"/);
  const requests = [];
  context.fetch = async url => { requests.push(url); return reply({ ...pad('Launch'), slug: 'launch', key: 'alice/launch', owner: 'alice', owned: true, is_owner: false }); };
  context.location.pathname = '/mashpad/pads/Alice/Launch/';
  for (const view of ['', '?view=settings']) {
    context.location.search = view;
    await api.route();
    const html = list + element('main').innerHTML;
    assert.doesNotMatch(html, /\?b=|\.php/);
  }
  assert.ok(requests.every(u => /\/api\/\?action=read&board=alice%2Flaunch/.test(u)), requests.join(' '));
});

test('a bare name that turns out to be a private pad settles on the pad\'s full address', async () => {
  const { api, context } = await app();
  const replaced = [];
  context.history = { replaceState: (a, b, url) => replaced.push(url), pushState() {} };
  context.fetch = async () => reply({ ...pad('Launch'), slug: 'launch', key: 'alice/launch', owner: 'alice', owned: true });
  context.location.search = '?b=launch';
  context.location.hash = '#notes';
  await api.route();
  assert.deepEqual(replaced, ['https://example.test/mashpad/pads/alice/launch#notes']);
  assert.equal(api.state.slug, 'alice/launch');
});

test('a name shared by two of your pads offers both addresses instead of guessing', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply({ error: 'ambiguous', message: 'More than one', choices: ['alice/launch', 'bob/launch'] }, 409);
  context.location.search = '?b=launch';
  await api.route();
  const html = element('main').innerHTML;
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/pads\/alice\/launch" data-nav="alice\/launch">alice\/launch</);
  assert.match(html, /href="https:\/\/example\.test\/mashpad\/pads\/bob\/launch" data-nav="bob\/launch">bob\/launch</);
});

test('an older open pad keeps its ?b= address until it expires', async () => {
  const { api } = await app();
  api.state.boards = [{ slug: 'old-open', name: 'Old open', total: 1, last_activity: 0, authors: [] }];
  assert.match(api.boardListHTML(), /class="board-link" href="https:\/\/example\.test\/mashpad\/pads\/\?b=old-open"/);
  assert.match(api.boardListHTML(), /pads\/\?b=old-open&amp;view=settings"/);
});

test('a pad nobody can see is described the same way whether or not it exists', async () => {
  const { api, context, element } = await app();
  context.fetch = async () => reply({ error: 'not_found' }, 404);
  const seen = [];
  for (const [pathname, search] of [['/mashpad/pads/alice/secret', ''], ['/mashpad/pads/alice/never-made', ''], ['/mashpad/pads/', '?b=secret']]) {
    context.location.pathname = pathname; context.location.search = search;
    await api.route();
    seen.push(element('main').innerHTML.replace(/<h1>.*?<\/h1>/, ''));
  }
  assert.equal(seen[0], seen[1]);
  assert.equal(seen[1], seen[2]);
  assert.doesNotMatch(seen[0], /as soon as an agent posts/);
});

test('a private pad shows About this pad above the conversation; an open pad has none', async () => {
  const { api, context, element } = await app();
  context.renderMessage = text => `<p>${text.replace(/</g, '&lt;')}</p>`;
  const about = { version: 3, body: 'For <launch> planning.', author: 'dylan', author_kind: 'human', ts: 1700000000 };
  context.fetch = async () => reply({ ...pad('Launch'), slug: 'launch', key: 'alice/launch', owner: 'alice', owned: true, brief_version: 3, brief: about, can_edit_about: false });
  context.location.pathname = '/mashpad/pads/alice/launch';
  await api.route();
  const main = element('main').innerHTML;
  assert.ok(main.indexOf('id="brief"') > 0 && main.indexOf('id="brief"') < main.indexOf('id="transcript"'));
  const box = element('brief').innerHTML;
  assert.match(box, /id="brief-h">About this pad</);
  assert.match(box, /Written by @dylan/);
  assert.match(box, /<p>For &lt;launch> planning\.<\/p>/);
  assert.doesNotMatch(box, /data-brief="edit"|data-brief="history"/, 'a member who did not create the pad gets no Edit');
  assert.doesNotMatch(box, /[Bb]rief<|decision|Objective/);
  context.fetch = async () => reply({ ...pad('Launch'), slug: 'launch', key: 'alice/launch', owner: 'alice', owned: true, brief_version: 3, brief: about, can_edit_about: true });
  await api.route();
  assert.match(element('brief').innerHTML, /data-brief="edit">Edit<\/button><button type="button" class="quiet" data-brief="history">Earlier versions</);
  context.fetch = async () => reply(pad());
  context.location.pathname = '/mashpad/pads/'; context.location.search = '?b=launch-review';
  await api.route();
  assert.doesNotMatch(element('main').innerHTML, /id="brief"/);
});

test('with nothing written, only the pad\'s creator is invited to write About this pad', async () => {
  const { api, context, element } = await app();
  const empty = can => reply({ ...pad('Launch'), slug: 'launch', key: 'alice/launch', owner: 'alice', owned: true, brief_version: 0, brief: null, can_edit_about: can });
  context.location.pathname = '/mashpad/pads/alice/launch';
  context.fetch = async () => empty(true);
  await api.route();
  assert.match(element('brief').innerHTML, /Say what this pad is for and who it’s for\./);
  assert.match(element('brief').innerHTML, /data-brief="edit">Write it</);
  assert.equal(element('brief').hidden, false);
  context.fetch = async () => empty(false);
  await api.route();
  assert.equal(element('brief').innerHTML, '');
  assert.equal(element('brief').hidden, true);
});

test('open pads show no private marker or members link', async () => {
  const { api, context, element } = await app();
  api.state.boards = [{ slug: 'open', name: 'Open', total: 1, last_activity: 0, authors: [] }];
  assert.doesNotMatch(api.boardListHTML(), /pad-private/);
  context.fetch = async () => reply(pad());
  context.location.search = '?b=launch-review';
  await api.route();
  assert.doesNotMatch(element('main').innerHTML, /manage\//);
});

test('signed-in sidebar offers New pad', async () => {
  const { api, context } = await app();
  context.fetch = async () => reply({ signed_in: true, handle: 'dylan', display_name: 'Dylan' });
  await api.loadMe();
  assert.match(api.accountHTML(), /href="https:\/\/example\.test\/mashpad\/create\/">New pad</);
});


test('pad info distinguishes member access and uses server expiry instead of a fixed plan', async () => {
  const { api, context, element } = await app();
  const expires = 1900000000;
  context.fetch = async () => reply({ ...pad(), owned: true, is_owner: false, expires });
  context.location.search = '?b=launch-review&view=settings';
  await api.route();
  const html = element('main').innerHTML;
  assert.match(html, />Manage access<\/a>/);
  assert.match(html, /invite your own agents/);
  assert.doesNotMatch(html, /Manage settings|View members|coming soon|30 days|preview/i);
  assert.ok(html.includes(new Date(expires * 1000).toLocaleString()));
  context.fetch = async () => reply(pad());
  await api.route();
  assert.doesNotMatch(element('main').innerHTML, /manage\//);
});

test('the MCP address Mashpad shows never carries a .php file name', () => {
  const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');
  assert.match(read('assets/boards.js'), /const MCP_URL = ROOT \+ 'mcp\/';/);
  assert.match(read('manage/index.php'), /\$mcpUrl\s*=\s*mb_site_url\(\) \. 'mcp\/';/);
  const mcp = read('mcp/index.php');
  assert.ok(!/parker-lambert-sandbox/.test(mcp), 'MCP instructions must not name a fixed host');
  assert.match(mcp, /\$pads = mb_site_url\(\) \. 'pads\/';/);
});

test('no page, script or MCP text builds a private pad link the old way', () => {
  const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');
  for (const f of ['assets/boards.js', 'manage/index.php', 'create/index.php', 'src/page.php', 'src/pads.php', 'mcp/index.php']) {
    const code = read(f).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*(\/\/|\*).*$/gm, '');
    assert.doesNotMatch(code, /manage\/\?b=|api\.php|mcp\/index\.php/, f);
    if (f !== 'assets/boards.js') assert.doesNotMatch(code, /pads\/\?b=/, f);
  }
  assert.match(read('assets/boards.js'), /const API = ROOT \+ 'api\/';/);
});

test('the site .htaccess serves pad and manage addresses and /mcp without a redirect', () => {
  const ht = fs.readFileSync(path.join(__dirname, '..', '.htaccess'), 'utf8');
  assert.match(ht, /RewriteRule \^mcp\$ %\{ENV:BASE\}mcp\/index\.php \[L\]/);
  assert.match(ht, /RewriteRule \^\(pads\|manage\)\//);
  assert.match(ht, /<IfModule mod_rewrite\.c>/);
  assert.doesNotMatch(ht, /^\s*RewriteBase/m);
});

test('the pad list never falls back to ?b= for a private pad', async () => {
  const { api, context } = await app();
  api.state.boards = [{ slug: 'launch', owner: 'alice', name: 'Launch', total: 1, last_activity: 0, authors: ['alice'], private: true }];
  const noKey = api.boardListHTML();
  assert.match(noKey, /class="board-link" href="https:\/\/example\.test\/mashpad\/pads\/alice\/launch"/);
  assert.doesNotMatch(noKey, /\?b=/);
  // A list entry that only knows the bare name is corrected as soon as the pad itself answers.
  api.state.boards = [{ slug: 'launch', name: 'Launch', total: 1, last_activity: 0, authors: [], private: true }];
  context.history = { replaceState() {}, pushState() {} };
  context.fetch = async url => /action=boards/.test(url)
    ? reply({ boards: api.state.boards, ttl: 1 })
    : reply({ ...pad('Launch'), slug: 'launch', key: 'alice/launch', owner: 'alice', owned: true });
  context.location.search = '?b=launch';
  await api.route();
  assert.doesNotMatch(api.boardListHTML(), /\?b=/);
  assert.match(api.boardListHTML(), /pads\/alice\/launch\?view=settings/);
});

test('a tab that predates a deploy stops intercepting pad links, so the next click loads the new script', async () => {
  const { api, listeners } = await app();
  const link = { getAttribute: () => 'https://example.test/mashpad/pads/alice/launch' };
  const click = () => { let prevented = false; listeners.click({ target: { closest: s => s === 'a[data-nav]' ? link : null }, button: 0, preventDefault() { prevented = true; } }); return prevented; };
  api.state.stale = true;
  assert.equal(click(), false);
});

test('the viewer reports and compares its build', () => {
  const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');
  assert.match(read('api/index.php'), /'build' => \$build/);
  assert.match(read('assets/boards.js'), /state\.stale = !!\(BUILD && d\.build && d\.build !== BUILD\);/);
});
