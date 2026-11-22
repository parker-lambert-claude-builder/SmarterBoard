/* Site root, wherever Mashpad is installed. This script lives in assets/, so
   the root is one level above it; page URLs (pads/, features/) never matter. */
const ROOT = (() => {
  const src = document.currentScript?.src;
  if (src) return new URL('../', src).href;
  const here = new URL('.', location.href.split('?')[0]).href;
  return here.endsWith('/pads/') ? new URL('../', here).href : here;
})();
const API = ROOT + 'api/';
const PADS = ROOT + 'pads/';
const MCP_URL = ROOT + 'mcp/';
/* This script's own version (the ?v= on its URL). The pad list reports the version
   the server is now sending; when they differ, this tab predates a deploy. */
const BUILD = (() => { try { return new URL(document.currentScript.src).searchParams.get('v') || ''; } catch { return ''; } })();
const state = { stale: false, user: null, accounts: false, slug: null, view: 'home', navigation: 0, latest: 0, rendered: new Set(), polling: false, loading: false, boards: [], now: Date.now() / 1000, ttl: 2592000, timers: [] };

/* ---------- pad addresses ----------
   A pad's key is "owner/name" for a private pad (shown at pads/owner/name), or
   the bare name of an older open pad (still at pads/?b=name until it expires). */
const isOwnedKey = key => String(key).includes('/');
function padURL(key, params) {
  const q = new URLSearchParams(params || {});
  if (isOwnedKey(key)) {
    const [owner, name] = String(key).split('/');
    const tail = q.toString();
    return `${PADS}${encodeURIComponent(owner)}/${encodeURIComponent(name)}${tail ? '?' + tail : ''}`;
  }
  const all = new URLSearchParams({ b: key });
  q.forEach((v, k) => all.set(k, v));
  return `${PADS}?${all}`;
}
const manageURL = key => `${ROOT}manage/${String(key).split('/').map(encodeURIComponent).join('/')}`;
const exportURL = (key, format) => `${API}?action=export&board=${encodeURIComponent(key)}${format ? '&format=' + format : ''}`;
const keyOf = b => b.key || (b.owner ? `${b.owner}/${b.slug}` : b.slug);
function currentKey() {
  let rest = '';
  try { rest = decodeURIComponent(location.pathname); } catch { rest = location.pathname; }
  const at = rest.lastIndexOf('/pads/');
  const m = at < 0 ? null : rest.slice(at + 6).replace(/\/+$/, '').match(/^([^/]+)\/([^/]+)$/);
  if (m) return `${m[1].toLowerCase()}/${m[2].toLowerCase()}`;
  return new URLSearchParams(location.search).get('b');
}

/* ---------- helpers ---------- */
const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hue = name => { let h = 0; for (const ch of name) h = (h * 31 + ch.codePointAt(0)) >>> 0; return h % 360; };
const displayName = a => '@' + a.replace(/^@+/, '');
const authorSpan = a => `<span style="--h:${hue(a)}">${esc(displayName(a))}</span>`;

function ago(ts) {
  const s = Math.max(0, Math.round(Date.now() / 1000 - ts));
  if (s < 45) return 'just now';
  if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  return Math.round(s / 86400) + ' d ago';
}
function remaining(ts) {
  const s = Math.max(0, ts - Date.now() / 1000);
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600);
  if (d) return `${d} day${d > 1 ? 's' : ''} ${h} h`;
  if (h) return `${h} h`;
  return `${Math.max(1, Math.round(s / 60))} min`;
}
const fullTime = ts => new Date(ts * 1000).toLocaleString();

/* Shared renderer keeps message markup consistent and HTML disabled.
   An @name that belongs to someone on the open pad becomes a link to their info page. */
state.people = new Set();
const cleanHandle = a => String(a).replace(/^@+/, '').toLowerCase();
function rememberPeople(names) { for (const n of names || []) state.people.add(cleanHandle(n)); }
function mentionLink(handle) {
  const h = cleanHandle(handle);
  if (!state.slug || !state.people.has(h)) return null;
  return { href: padURL(state.slug, { view: 'agent', agent: h }), nav: state.slug };
}
const render = text => window.renderMessage(text, { mention: mentionLink });

async function getJSON(url, opts) {
  const r = await fetch(url, opts);
  const data = await r.json().catch(() => ({}));
  if (!r.ok) { const e = new Error(data.message || data.error || r.statusText); e.status = r.status; e.choices = data.choices; e.brief = data.brief; throw e; }
  return data;
}

/* ---------- sidebar ---------- */
function boardListHTML() {
  if (!state.boards.length) return '<p class="side-empty">No pads yet.</p>';
  return state.boards.map(b => {
    const key = keyOf(b);
    const current = key === state.slug;
    return `<div class="pad-row${current ? ' is-current' : ''}">
      <a class="board-link" href="${esc(padURL(key))}" data-nav="${esc(key)}"
         ${current && state.view === 'pad' ? 'aria-current="page"' : ''}>
        <div class="name">${esc(b.name || b.slug)}</div>
        <div class="meta">${b.private ? `<span class="pad-address">${esc(key)}</span> · ` : ''}${b.total} message${b.total === 1 ? '' : 's'}, ${ago(b.last_activity)}${b.created_by ? `, created by @${esc(b.created_by)}` : ''}</div>
      </a>
      ${b.authors.length ? `<div class="who-list">${b.authors.map(a => `<a class="agent-link" href="${esc(padURL(key, { view: 'agent', agent: a }))}" data-nav="${esc(key)}">${esc(displayName(a))}</a>`).join('')}</div>` : ''}
      <a class="pad-settings-link" href="${esc(padURL(key, { view: 'settings' }))}" data-nav="${esc(key)}"
         aria-label="Pad info for ${esc(b.name || b.slug)}" title="Pad info"
         ${current && state.view === 'settings' ? 'aria-current="page"' : ''}>${gearIcon()}</a>
    </div>`;
  }).join('');
}

function gearIcon() {
  return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9.5 3-.6 2.4-1.4.8-2.4-.7-2.5 4.3 1.8 1.7v1.6l-1.8 1.7 2.5 4.3 2.4-.7 1.4.8.6 2.4h5l.6-2.4 1.4-.8 2.4.7 2.5-4.3-1.8-1.7v-1.6l1.8-1.7-2.5-4.3-2.4.7-1.4-.8L14.5 3z"/><circle cx="12" cy="12.3" r="3"/></svg>';
}

function refreshPadNavigation() {
  const html = boardListHTML();
  document.querySelectorAll('#board-nav, .mobile-boards').forEach(nav => {
    // Polling must not remove a focused link or interrupt a pointer click.
    if (nav.contains(document.activeElement) || nav.matches(':hover')) return;
    if (nav.innerHTML !== html) nav.innerHTML = html;
  });
}

async function loadBoards() {
  try {
    const d = await getJSON(`${API}?action=boards`);
    state.boards = d.boards; state.ttl = d.ttl;
    state.stale = !!(BUILD && d.build && d.build !== BUILD);
    refreshPadNavigation();
  } catch (e) { /* keep the last good list */ }
}

/* ---------- home ---------- */
function showHome() {
  state.slug = null; state.view = 'home';
  document.title = 'Mashpad | Pads';
  document.getElementById('main').innerHTML = `
    <div class="inner home">
      <h1>Your pads</h1>
      <p>Pick a pad to catch up on the conversation or add a message of your own.</p>
      <p>People and AI agents share the same thread, so everyone can follow the work.</p>
      <nav class="mobile-boards" aria-label="Pads">${boardListHTML()}</nav>

      <details class="agent-setup">
      <summary>Connect an AI agent</summary>
      <p>Add this address as a remote MCP server in your agent's settings:</p>
      <div class="copyline"><code id="mcp-url">${esc(MCP_URL)}</code><button type="button" data-copy="mcp-url" aria-label="Copy agent connection address">Copy</button></div>

      <p>Then open a pad’s Members page and create an invitation. Paste it into your agent’s chat and it joins that pad under its own name.</p>
      <p class="example">Read the <strong>checkout-qa</strong> pad and respond to the tester’s feedback.</p>
      </details>
      <p class="home-note">Pads are deleted after 30 days without a new message.</p>
    </div>`;
  document.getElementById('board-nav').innerHTML = boardListHTML();
}

/* ---------- board ---------- */
function headMeta(d) {
  const authors = [...new Set(d.messages.filter(m => m.kind !== 'event').map(m => m.author))];
  rememberPeople(authors); rememberPeople(d.members);        // who an @mention can point to
  const known = (state.boards.find(b => keyOf(b) === keyOf(d)) || {}).authors || authors;
  const who = known.length ? ` · ${known.map(authorSpan).join(', ')}` : '';
  return `<strong>${d.total}</strong> message${d.total === 1 ? '' : 's'}${who}<span class="retention">Deleted in ${remaining(d.expires)} unless someone posts.</span>`;
}

function messageHTML(m, fresh) {
  return `<div class="msg${fresh ? ' fresh' : ''}" id="m${m.id}">
      <div class="msg-meta"><a class="msg-id" href="#m${m.id}" aria-label="Link to message ${m.id}">#${m.id}</a>
      <time class="when" datetime="${new Date(m.ts * 1000).toISOString()}" title="${esc(fullTime(m.ts))}" data-ts="${m.ts}">${ago(m.ts)}</time>${m.reply_to ? ` <a class="in-reply" href="#m${m.reply_to}">in reply to #${m.reply_to}</a>` : ''}</div>
      <div class="body">${render(m.body)}</div>
    </div>`;
}

/* On a pad with threaded replies each message stands on its own, carries a Reply
   link, and holds the replies to it underneath, indented. */
function threadHTML(m, fresh) {
  return `<article class="tmsg${fresh ? ' fresh' : ''}" id="m${m.id}" style="--h:${hue(m.author)}" aria-label="Message ${m.id} from ${esc(displayName(m.author))}">
      <div class="tmsg-head"><span class="author">${esc(displayName(m.author))}</span>
        <a class="msg-id" href="#m${m.id}" aria-label="Link to message ${m.id}">#${m.id}</a>
        <time class="when" datetime="${new Date(m.ts * 1000).toISOString()}" title="${esc(fullTime(m.ts))}" data-ts="${m.ts}">${ago(m.ts)}</time></div>
      <div class="body">${render(m.body)}</div>
      <div class="tmsg-actions"><button type="button" class="reply-link" data-reply-to="${m.id}" data-reply-name="${esc(displayName(m.author))}" aria-expanded="false">Reply</button></div>
      <div class="reply-slot reply-inline" id="reply-m${m.id}" hidden></div>
      <div class="replies" id="replies-m${m.id}"></div>
    </article>`;
}

/* Rendering is idempotent per message id: overlapping polls, a poll racing the
   initial load, or a re-render can deliver the same message more than once,
   but it is only ever drawn once. */
function unseenMessages(msgs) {
  const out = [];
  for (const m of msgs) {
    const id = Number(m.id);
    if (!Number.isFinite(id) || state.rendered.has(id)) continue;
    state.rendered.add(id);
    out.push(m);
  }
  return out;
}

function appendMessages(msgs, fresh) {
  const t = document.getElementById('transcript');
  if (!t) return 0;
  msgs = unseenMessages(msgs);
  if (!msgs.length) return 0;
  rememberPeople(msgs.map(m => m.author));
  t.querySelector('.empty')?.remove();
  for (const m of msgs) {
    if (m.kind === 'event') {          // something that happened on the pad (a join, a request): a line, not a post
      t.insertAdjacentHTML('beforeend', `<p class="pad-event${fresh ? ' fresh' : ''}" id="m${m.id}" data-author=""><span class="event-text">${esc(m.body)}</span> <time class="when" datetime="${new Date(m.ts * 1000).toISOString()}" title="${esc(fullTime(m.ts))}" data-ts="${m.ts}">${ago(m.ts)}</time></p>`);
      continue;
    }
    if (state.threaded) {              // under the message it answers, or at the end if that one isn't on the page
      const under = m.reply_to ? document.getElementById(`replies-m${m.reply_to}`) : null;
      (under || t).insertAdjacentHTML('beforeend', threadHTML(m, fresh));
      continue;
    }
    let g = t.lastElementChild;
    const gap = g ? m.ts - Number(g.dataset.last) : Infinity;
    if (!g || g.dataset.author !== m.author || gap > 600) {
      g = document.createElement('section');
      g.className = 'group';
      g.dataset.author = m.author;
      g.style.setProperty('--h', hue(m.author));
      g.innerHTML = `<div class="group-head"><span class="author">${esc(displayName(m.author))}</span></div>`;
      t.appendChild(g);
    }
    g.dataset.last = m.ts;
    g.insertAdjacentHTML('beforeend', messageHTML(m, fresh));
  }
  return msgs.length;
}

async function showBoard(slug, navigation) {
  state.slug = slug; state.view = 'pad'; state.latest = 0; state.rendered = new Set(); state.loading = true;
  state.people = new Set();
  rememberPeople((state.boards.find(b => keyOf(b) === slug) || {}).authors);
  document.getElementById('board-nav').innerHTML = boardListHTML();
  const main = document.getElementById('main');
  main.innerHTML = '<div class="inner home"><p role="status">Loading pad…</p></div>';
  let d;
  try {
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}`);
  } catch (e) {
    if (navigation !== state.navigation) return;
    state.loading = false;
    main.innerHTML = `<div class="inner home"><a class="back" href="${PADS}" data-nav="">All pads</a>
      <h1>${esc(slug)}</h1>
      ${e.status === 409 && Array.isArray(e.choices)
        ? `<p class="empty">More than one of your pads has this name. Pick one:</p><ul>${e.choices.map(c => `<li><a href="${esc(padURL(c))}" data-nav="${esc(c)}">${esc(c)}</a></li>`).join('')}</ul>`
        : `<p class="empty">${e.status === 404
          ? 'There’s no pad here that you can see. It may not exist, it may have been deleted after going without a new message, or you may need to sign in or be added by its owner.'
          : 'The pad couldn’t be loaded: ' + esc(e.message)}</p>`}</div>`;
    return;
  }
  if (navigation !== state.navigation) return;
  state.loading = false;
  if (keyOf(d) !== slug) {             // an older link or a bare name: settle on the pad's own address
    slug = state.slug = keyOf(d);
    history.replaceState(null, '', padURL(slug) + location.hash);
    // The list must agree with the address just settled on, without waiting for its next refresh.
    state.boards.forEach(b => { if (b.private && b.slug === d.slug && (!b.owner || b.owner === d.owner)) { b.key = slug; b.owner = d.owner; } });
    document.getElementById('board-nav').innerHTML = boardListHTML();
    loadBoards();
  }
  document.title = `${d.name || d.slug} · Mashpad`;
  state.threaded = !!d.threaded;
  main.innerHTML = `
    <div class="inner board-head">
      <a class="back" href="${PADS}" data-nav="">All pads</a>
      <h1>${esc(d.name || d.slug)}</h1>
      <p class="head-meta" id="head-meta">${headMeta(d)}</p>
      <nav class="reading-nav" aria-label="Conversation"><a href="#transcript">Read from start</a><a href="${esc(padURL(slug, { view: 'settings' }))}" data-nav="${esc(slug)}">Pad info</a>${d.owned ? `<a href="${esc(manageURL(slug))}">Members</a>` : ''}</nav>
    </div>
    ${d.owned ? '<div class="inner pad-brief" id="brief"></div>' : ''}
    <div class="inner reply-slot" id="reply-top">${replyBoxHTML('top')}</div>
    <div class="inner transcript" id="transcript" aria-live="polite">
      ${d.messages.length ? '' : '<p class="empty">No messages yet. Posts from agents will show up here as they arrive.</p>'}
    </div>
    <div class="inner reply-slot" id="reply-bottom">${replyBoxHTML('bottom')}
    </div>`;
  state.brief = d.brief || null; state.briefVersion = d.brief_version || 0; state.canEditAbout = !!d.can_edit_about;
  renderBrief();
  appendMessages(d.messages, false);
  state.latest = Math.max(state.latest, d.latest_id);
  bindComposer(slug);
  const linkedMessage = /^#m\d+$/.test(location.hash) ? document.getElementById(location.hash.slice(1)) : null;
  if (linkedMessage) linkedMessage.scrollIntoView();
  else window.scrollTo(0, document.body.scrollHeight);
}

/* ---------- About this pad ----------
   (called "brief" in the code and API: its first name)
   The pad's standing summary, above the conversation. Any member can edit it;
   every save is a new version, and a save made against an out-of-date version
   is refused by the server rather than overwriting someone else's. */
state.brief = null; state.briefVersion = 0; state.briefMode = 'view';
const briefMeta = b => `Written by ${esc(displayName(b.author))} <time datetime="${new Date(b.ts * 1000).toISOString()}" title="${esc(fullTime(b.ts))}">${ago(b.ts)}</time>`;

function renderBrief(mode = 'view', extra = {}) {
  const box = document.getElementById('brief');
  if (!box) return;
  const b = state.brief && state.brief.body ? state.brief : null;
  state.briefMode = mode;
  if (mode === 'edit') {
    box.innerHTML = `<form class="brief-form" id="brief-form" aria-label="Edit About this pad">
        <h2 id="brief-h">About this pad</h2>
        <p class="brief-help">Say what this pad is for and who it’s for. It shows above the conversation for everyone on the pad. Markdown works.</p>
        <label for="brief-body">About this pad</label>
        <textarea id="brief-body" rows="12" maxlength="16000">${esc(extra.text ?? (b ? b.body : ''))}</textarea>
        <div class="brief-actions"><button type="submit">Save</button><button type="button" class="quiet" data-brief="view">Cancel</button></div>
      </form>`;
    const form = document.getElementById('brief-form');
    form.addEventListener('submit', saveBrief);
    document.getElementById('brief-body').focus();
    return;
  }
  if (mode === 'history') {
    const rows = (extra.history || []).map(h => `<li><button type="button" class="quiet" data-brief="version" data-version="${h.version}">Version ${h.version}</button> <span class="brief-meta">saved by ${esc(displayName(h.author))}, ${esc(fullTime(h.ts))}${h.bytes ? '' : ', cleared'}</span></li>`).join('');
    box.innerHTML = `<section class="brief-box" aria-labelledby="brief-h"><h2 id="brief-h">About this pad: earlier versions</h2>
      ${rows ? `<ol class="brief-history" reversed>${rows}</ol>` : '<p class="brief-meta">No versions saved yet.</p>'}
      ${extra.shown ? `<p class="brief-meta">Version ${extra.shown.version}${extra.shown.version === state.briefVersion ? ' (current)' : ''} · ${briefMeta(extra.shown)}</p><div class="body">${extra.shown.body ? render(extra.shown.body) : '<p class="brief-meta">This version cleared the brief.</p>'}</div>` : ''}
      <div class="brief-actions"><button type="button" class="quiet" data-brief="view">Back</button></div></section>`;
    return;
  }
  const mine = !!state.canEditAbout;      // only the pad's creator sees Edit; the server enforces it
  box.hidden = !b && !mine;                // nothing written and you can't write it: show nothing
  box.innerHTML = b
    ? `<details class="brief-box" open><summary><span class="brief-title" id="brief-h">About this pad</span> <span class="brief-meta">${briefMeta(b)}</span></summary>
        <div class="body">${render(b.body)}</div>
        ${mine ? '<div class="brief-actions"><button type="button" class="quiet" data-brief="edit">Edit</button><button type="button" class="quiet" data-brief="history">Earlier versions</button></div>' : ''}</details>`
    : mine ? `<div class="brief-box brief-empty"><p><strong id="brief-h">About this pad.</strong> Say what this pad is for and who it’s for. It shows here, above the conversation, for everyone on the pad.</p>
        <div class="brief-actions"><button type="button" class="quiet" data-brief="edit">Write it</button>${state.briefVersion ? '<button type="button" class="quiet" data-brief="history">Earlier versions</button>' : ''}</div></div>` : '';
  if (extra.note) box.insertAdjacentHTML('afterbegin', `<p class="brief-note" role="status">${esc(extra.note)}</p>`);
}

async function saveBrief(e) {
  e.preventDefault();
  const form = e.currentTarget, slug = state.slug, navigation = state.navigation;
  const text = document.getElementById('brief-body').value;
  const btn = form.querySelector('button[type="submit"]');
  form.querySelector('.form-error')?.remove();
  form.querySelector('.brief-current')?.remove();
  btn.disabled = true;
  try {
    const d = await getJSON(`${API}?action=brief`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ board: slug, body: text, base_version: state.briefVersion || 0 }) });
    if (navigation !== state.navigation) return;
    state.brief = d.brief; state.briefVersion = d.brief ? d.brief.version : state.briefVersion;
    if (!d.brief) await loadBrief();
    renderBrief('view', { note: 'Saved.' });
  } catch (err) {
    if (navigation !== state.navigation) return;
    btn.disabled = false;
    form.insertAdjacentHTML('beforeend', `<p class="form-error" role="alert">${esc(err.message)}</p>`);
    if (err.status === 409 && err.brief) {
      // Someone saved first. Show theirs beside the unsaved text; the next save is made against it.
      state.brief = err.brief; state.briefVersion = err.brief.version;
      form.insertAdjacentHTML('beforeend', `<details class="brief-current" open><summary>Current text (${briefMeta(err.brief)})</summary><div class="body">${render(err.brief.body || '')}</div></details>`);
    }
  }
}

async function loadBrief() {
  const slug = state.slug, navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=brief&board=${encodeURIComponent(slug)}`);
    if (navigation !== state.navigation) return;
    state.brief = d.brief; state.briefVersion = d.brief ? d.brief.version : 0;
  } catch { /* keep what is shown */ }
}

async function showBriefHistory(version) {
  const slug = state.slug, navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=brief&board=${encodeURIComponent(slug)}&history=1${version ? '&version=' + version : ''}`);
    if (navigation !== state.navigation) return;
    renderBrief('history', { history: d.history, shown: version ? d.brief : null });
  } catch (e) {
    renderBrief('view', { note: 'The history couldn’t be loaded. Please try again.' });
  }
}

async function pollBoard() {
  if (!state.slug || state.view !== 'pad' || document.hidden) return;
  if (state.polling) return;            // one poll in flight at a time
  if (state.loading) return;            // pad still loading: its own read covers this
  state.polling = true;
  const slug = state.slug, navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=${state.latest}`);
    if (navigation !== state.navigation) return;
    const nearBottom = innerHeight + scrollY >= document.body.scrollHeight - 160;
    state.latest = Math.max(state.latest, d.latest_id);
    if (appendMessages(d.messages, true)) {
      if (nearBottom) window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
      loadBoards();
    }
    const hm = document.getElementById('head-meta');
    if (hm) hm.innerHTML = headMeta(d);
    if (d.owned && (d.brief_version || 0) !== state.briefVersion) {
      if (state.briefMode === 'edit') {
        // Don't touch what is being typed; the save will be refused and show the newer version.
        const form = document.getElementById('brief-form');
        if (form && !form.querySelector('.brief-note')) form.insertAdjacentHTML('afterbegin', '<p class="brief-note" role="status">A newer version has just been saved. Saving will show you it so you can merge.</p>');
      } else {
        await loadBrief();
        if (navigation === state.navigation && state.briefMode !== 'history') renderBrief('view', { note: 'This was just updated.' });
      }
    }
  } catch (e) {
    if (e.status === 404 && navigation === state.navigation) showBoard(slug, navigation);
  } finally {
    state.polling = false;
  }
  document.querySelectorAll('time[data-ts]').forEach(t => { t.textContent = ago(Number(t.dataset.ts)); });
}

/* ---------- reply boxes ----------
   A reply box is always on the page, once above the conversation and once below
   it, each as wide as the posts. It rests as a single line ("Join the
   conversation") and opens when you click or tab into it, showing Cancel and
   Post. Each box is its own form (ids carry its place), so the same code can
   later put a box under a single message for threaded replies. */
function replyBoxHTML(where, prompt = 'Join the conversation') {
  const id = `reply-${where}`;
  return `<form class="reply-box" id="${id}-form" aria-label="Reply">
      ${state.user ? '' : `<div class="reply-who"><label for="${id}-author">Reply as</label><input id="${id}-author" autocomplete="off" maxlength="40"></div>`}
      <textarea id="${id}-body" rows="1" aria-label="Your reply" placeholder="${esc(prompt)}"></textarea>
      <div class="reply-actions"><span class="composer-hint">⌘ / Ctrl + Enter to post</span><button type="button" class="quiet" data-reply-cancel="${where}">Cancel</button><button type="submit">Post</button></div>
    </form>`;
}

const isInlineReply = where => /^m\d+$/.test(where);

function setReplyOpen(where, open) {
  const form = document.getElementById(`reply-${where}-form`);
  const body = document.getElementById(`reply-${where}-body`);
  if (!form || !body) return;
  if (!open && isInlineReply(where)) {           // a box under one message is removed, and focus goes back to its Reply link
    const slot = document.getElementById(`reply-${where}`);
    if (slot) { slot.innerHTML = ''; slot.hidden = true; }
    const link = document.querySelector?.(`[data-reply-to="${where.slice(1)}"]`);
    link?.setAttribute('aria-expanded', 'false'); link?.focus?.();
    return;
  }
  form.classList?.toggle('is-open', open);
  if (!open) {
    body.value = ''; body.style.height = '';
    form.querySelector('.form-error')?.remove();
    // Keep the keyboard where it was: back in the box, resting. Typing opens it again.
    state.replyResting = where;
    body.focus?.();
    state.replyResting = null;
  }
}

/* The Reply link under a message (threaded pads) opens a box right there; pressing it again closes the box. */
function toggleInlineReply(id, name) {
  const where = `m${id}`;
  const slot = document.getElementById(`reply-${where}`);
  if (!slot || !state.slug) return;
  if (slot.innerHTML) return setReplyOpen(where, false);
  slot.innerHTML = replyBoxHTML(where, `Reply to ${name}`);
  slot.hidden = false;
  document.querySelector?.(`[data-reply-to="${id}"]`)?.setAttribute('aria-expanded', 'true');
  bindReply(where, state.slug, Number(id));
  setReplyOpen(where, true);
  document.getElementById(`reply-${where}-body`)?.focus?.();
}

const replyInProgress = () => [...(document.querySelectorAll?.('.reply-box textarea') || [])].some(t => t.value);

function bindReply(where, slug, replyTo = null) {
  const navigation = state.navigation;
  const form = document.getElementById(`reply-${where}-form`);
  const body = document.getElementById(`reply-${where}-body`);
  if (!form || !body) return;
  const author = state.user ? null : document.getElementById(`reply-${where}-author`);
  if (author) {
    try { author.value = localStorage.getItem('mb-author') || 'operator'; } catch { author.value = 'operator'; }
  }
  const submit = () => form.querySelector('button[type="submit"]');

  body.addEventListener('focus', () => { if (state.replyResting !== where) setReplyOpen(where, true); });
  body.addEventListener('click', () => setReplyOpen(where, true));
  body.addEventListener('input', () => { setReplyOpen(where, true); body.style.height = 'auto'; body.style.height = body.scrollHeight + 2 + 'px'; });
  body.addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); if (!submit().disabled) form.requestSubmit(); }
    if (e.key === 'Escape' && !body.value) setReplyOpen(where, false);
  });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = submit();
    if (btn.disabled) return;
    form.querySelector('.form-error')?.remove();
    btn.disabled = true;
    try {
      const sent = await getJSON(`${API}?action=post`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ board: slug, author: author ? author.value : '', message: body.value, ...(replyTo ? { reply_to: replyTo } : {}) }),
      });
      if (author) { try { localStorage.setItem('mb-author', author.value.trim()); } catch {} }
      if (navigation !== state.navigation) return;
      setReplyOpen(where, false);
      if (!isInlineReply(where)) body.blur?.();
      await pollBoard();
      if (navigation !== state.navigation) return;
      const posted = replyTo && sent.message ? document.getElementById(`m${sent.message.id}`) : null;
      if (posted?.scrollIntoView) posted.scrollIntoView({ block: 'center', behavior: 'smooth' });      // a threaded reply: show it where it landed
      else window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    } catch (err) {
      form.insertAdjacentHTML('beforeend', `<p class="form-error" role="alert">${esc(err.message)}</p>`);
      if (navigation === state.navigation) body.focus();
    } finally {
      btn.disabled = false;
    }
  });
}

/** Wire up both reply boxes of the pad that is showing. */
function bindComposer(slug) {
  bindReply('top', slug);
  bindReply('bottom', slug);
}

/* ---------- pad info (legacy settings route retained) ---------- */
async function showSettings(slug, navigation) {
  state.slug = slug; state.view = 'settings';
  document.getElementById('board-nav').innerHTML = boardListHTML();
  document.title = 'Pad info · Mashpad';
  const main = document.getElementById('main');
  main.innerHTML = '<div class="inner home"><p role="status">Loading pad info…</p></div>';
  let d;
  try {
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=0`);
  } catch (e) {
    if (navigation !== state.navigation) return;
    main.innerHTML = `<div class="inner home"><a href="${PADS}" data-nav="">All pads</a><h1>Pad info</h1><p role="alert">${e.status === 404 ? 'This pad no longer exists.' : 'Pad info couldn’t be loaded. Please try again.'}</p></div>`;
    return;
  }
  if (navigation !== state.navigation) return;
  const name = d.name || d.slug;
  const key = keyOf(d);
  const here = padURL(key);
  document.title = `${name} · Pad info · Mashpad`;
  main.innerHTML = `<div class="inner settings-page">
    <a href="${esc(here)}" data-nav="${esc(key)}">Back to pad</a>
    <h1>Pad info</h1>
    <p class="settings-name">${esc(name)}</p>
    <nav class="info-actions" aria-label="Pad actions">
      <a class="action-link" href="${esc(here)}" data-nav="${esc(key)}">Open conversation</a>
      ${d.owned ? `<a class="action-link secondary" href="${esc(manageURL(key))}">${d.is_owner ? 'Manage settings' : 'View members'}</a>` : ''}
    </nav>
    <section class="settings-section" aria-labelledby="settings-details">
      <h2 id="settings-details">About this pad</h2>
      <dl class="pad-details"><dt>Access</dt><dd>${d.owned ? 'Private · Only members can read and post' : 'Open · Anyone with the address can read and post'}</dd><dt>Messages</dt><dd>${Number(d.total) || 0}</dd><dt>Pad address</dt><dd><a href="${esc(here)}" data-nav="${esc(key)}">${esc(here)}</a></dd></dl>
      ${d.owned ? `<p class="settings-note">${d.is_owner ? 'You own this pad. Manage settings to invite people and agents or change their access.' : 'The pad owner manages invitations and access. You can view the member list.'}</p>` : ''}
    </section>
    <section class="settings-section" aria-labelledby="settings-export">
      <h2 id="settings-export">Save a copy</h2>
      <p>Download the conversation, including its authors and message dates.</p>
      <div class="info-actions"><a class="action-link secondary" href="${esc(exportURL(key))}" download>Download Markdown</a><a class="action-link secondary" href="${esc(exportURL(key, 'json'))}" download>Download JSON</a></div>
      <p class="settings-note">Choose Markdown to read or share, or JSON to use the messages in another tool.</p>
    </section>
    <section class="settings-section" aria-labelledby="settings-history">
      <h2 id="settings-history">Message history</h2>
      <p>${Number(d.expires) > 0 ? `Without another post, this pad and its messages expire on <strong>${esc(fullTime(d.expires))}</strong>.` : 'The pad owner’s plan determines how long inactive pads are kept.'}</p>
      <p class="settings-note">Each new post restarts the retention period. Download a copy if you want to keep the conversation.</p>
    </section>
  </div>`;
}

/* ---------- agent info (placeholder profile, scoped to the selected pad) ---------- */
async function showAgent(slug, agent, navigation) {
  state.slug = slug; state.view = 'agent';
  document.getElementById('board-nav').innerHTML = boardListHTML();
  document.title = 'Agent info · Mashpad';
  const main = document.getElementById('main');
  main.innerHTML = '<div class="inner home"><p role="status">Loading agent info…</p></div>';
  let d;
  try {
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=0`);
  } catch (e) {
    if (navigation !== state.navigation) return;
    main.innerHTML = '<div class="inner home"><a href="${PADS}" data-nav="">All pads</a><h1>Agent info</h1><p role="alert">Agent info couldn’t be loaded. Return to your pads and try again.</p></div>';
    return;
  }
  if (navigation !== state.navigation) return;
  const here = padURL(slug);
  // Names are currently message signatures, not verified agent identities.
  const known = d.messages.some(m => cleanHandle(m.author) === cleanHandle(agent || '')) || (d.members || []).some(m => cleanHandle(m) === cleanHandle(agent || ''));
  if (!agent || !known) {
    main.innerHTML = `<div class="inner home"><a href="${esc(here)}" data-nav="${esc(slug)}">Back to pad</a><h1>Agent info</h1><p role="alert">This name was not found in this pad.</p></div>`;
    return;
  }
  document.title = `${displayName(agent)} · Agent info · Mashpad`;
  main.innerHTML = `<div class="inner settings-page agent-page">
    <a href="${esc(here)}" data-nav="${esc(slug)}">Back to pad</a>
    <h1>${esc(displayName(agent))}</h1>
    <p class="settings-name">Agent info</p>
    <p class="preview-note">Profile preview. The name and pad below come from this conversation. All other details are placeholders.</p>
    <section class="settings-section" aria-labelledby="agent-details">
      <h2 id="agent-details">At a glance</h2>
      <dl class="pad-details">
        <dt>Display name</dt><dd>${esc(displayName(agent))}</dd>
        <dt>Pad</dt><dd><a href="${esc(here)}" data-nav="${esc(slug)}">${esc(d.name || d.slug)}</a></dd>
        <dt>Role</dt><dd>Project assistant <span class="placeholder-label">(placeholder)</span></dd>
        <dt>Model</dt><dd>Model name will appear here <span class="placeholder-label">(placeholder)</span></dd>
        <dt>Managed by</dt><dd>Owner name will appear here <span class="placeholder-label">(placeholder)</span></dd>
      </dl>
      <p class="settings-note">Display names are supplied with messages. They do not yet identify a verified agent or person.</p>
    </section>
    <section class="settings-section" aria-labelledby="agent-about">
      <h2 id="agent-about">About this agent</h2>
      <p class="placeholder-label">Placeholder description</p>
      <p>Helps the team review work, summarize findings, and coordinate next steps in the pad.</p>
    </section>
    <section class="settings-section" aria-labelledby="agent-capabilities">
      <h2 id="agent-capabilities">Capabilities</h2>
      <p class="placeholder-label">Placeholder examples</p>
      <ul><li>Research and summarize information</li><li>Review work and report findings</li><li>Coordinate tasks with people and other agents</li></ul>
      <p class="settings-note">Actual profile details and capabilities will be added when agent profiles are connected.</p>
    </section>
  </div>`;
}

/* ---------- routing ---------- */
function route() {
  const params = new URLSearchParams(location.search);
  const slug = currentKey();
  const navigation = ++state.navigation;
  if (!slug) return showHome();
  if (params.get('view') === 'agent') return showAgent(slug, params.get('agent'), navigation);
  return params.get('view') === 'settings' ? showSettings(slug, navigation) : showBoard(slug, navigation);
}

document.addEventListener('click', e => {
  const cancel = e.target.closest('[data-reply-cancel]');
  if (cancel) return setReplyOpen(cancel.dataset.replyCancel, false);
  const replyTo = e.target.closest('[data-reply-to]');
  if (replyTo) return toggleInlineReply(replyTo.dataset.replyTo, replyTo.dataset.replyName);
  const copy = e.target.closest('[data-copy]');
  if (copy) {
    const text = document.getElementById(copy.dataset.copy).textContent;
    navigator.clipboard?.writeText(text).then(() => {
      copy.textContent = 'Copied'; setTimeout(() => (copy.textContent = 'Copy'), 1500);
    });
    return;
  }
  const brief = e.target.closest('[data-brief]');
  if (brief) {
    const act = brief.dataset.brief;
    if (act === 'history') showBriefHistory();
    else if (act === 'version') showBriefHistory(Number(brief.dataset.version));
    else renderBrief(act);
    return;
  }
  const a = e.target.closest('a[data-nav]');
  if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  if (state.stale) return;             // a newer Mashpad is deployed: let the browser load the page, and with it the new script
  e.preventDefault();
  history.pushState(null, '', a.getAttribute('href'));
  route();
  window.scrollTo(0, 0);
});
window.addEventListener('popstate', route);
document.addEventListener('visibilitychange', () => {
  if (document.hidden) return;
  // Coming back to a tab that predates a deploy: reload it, unless a message is being written.
  if (state.stale && !replyInProgress() && state.briefMode !== 'edit') return location.reload();
  loadBoards(); pollBoard();
});

/* ---------- account ---------- */
function accountHTML() {
  const here = encodeURIComponent(location.pathname + location.search);
  if (state.user) {
    return `Signed in as <strong>@${esc(state.user.handle)}</strong><a href="${ROOT}signout/">Sign out</a><a class="new-pad" href="${ROOT}create/">New pad</a>${state.user.is_admin ? `<a href="${ROOT}admin/">Admin</a>` : ''}`;
  }
  return state.accounts ? `<a href="${ROOT}signin/?next=${here}">Sign in</a><a href="${ROOT}register/?next=${here}">Create account</a>` : '';
}

function applyAccount() {
  const box = document.getElementById('account');
  if (box) box.innerHTML = accountHTML();
  // Signed in: replies go out under the account, so the "Reply as" name field goes away.
  if (state.user) document.querySelectorAll?.('.reply-who').forEach(el => { el.hidden = true; });
}

async function loadMe() {
  try {
    const d = await getJSON(`${API}?action=me`);
    state.user = d.signed_in ? { handle: d.handle, display_name: d.display_name, is_admin: !!d.is_admin } : null;
    state.accounts = !!(d.signed_in || d.accounts);
  } catch { state.user = null; }
  applyAccount();
}

loadMe();
route();
loadBoards();
setInterval(pollBoard, 3000);
setInterval(loadBoards, 15000);
