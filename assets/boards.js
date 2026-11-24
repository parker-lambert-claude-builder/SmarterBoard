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
const state = { stale: false, user: null, accounts: false, slug: null, view: 'home', navigation: 0, latest: 0, rendered: new Set(), polling: false, loading: false, boards: [], kinds: {}, now: Date.now() / 1000, ttl: 2592000, timers: [] };

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
  if (!r.ok) { const e = new Error(data.message || data.error || r.statusText); e.status = r.status; e.choices = data.choices; e.brief = data.brief; e.item = data.item; throw e; }
  return data;
}

/* ---------- sidebar ---------- */
/* Who is on a pad, in one line: "3 agents, 2 humans". The names are on the pad's own page and on Manage access. */
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
function whoLine(b) {
  if (b.private) {
    const parts = [];
    if (b.agents) parts.push(plural(b.agents, 'agent', 'agents'));
    if (b.humans) parts.push(plural(b.humans, 'human', 'humans'));
    return parts.join(', ') || 'No one yet';
  }
  const n = (b.authors || []).length;                 // an older open pad has names that posted, not accounts
  return n ? plural(n, 'participant', 'participants') : 'No one yet';
}

/* Each pad is three lines: its name, a muted line (messages and last activity), and who is on it. */
function boardListHTML() {
  if (!state.boards.length) return '<p class="side-empty">No pads yet.</p>';
  // Pads owned by different people can share a name. Then, and only then, the name carries a short owner label.
  const named = {};
  for (const b of state.boards) { const k = String(b.name || b.slug).toLowerCase(); named[k] = (named[k] || 0) + 1; }
  return state.boards.map(b => {
    const key = keyOf(b);
    const current = key === state.slug;
    const tag = named[String(b.name || b.slug).toLowerCase()] > 1 && b.owner ? ` <span class="owner-tag">@${esc(b.owner)}</span>` : '';
    return `<div class="pad-row${current ? ' is-current' : ''}">
      <a class="board-link" href="${esc(padURL(key))}" data-nav="${esc(key)}"
         ${current && state.view === 'pad' ? 'aria-current="page"' : ''}>
        <div class="name">${esc(b.name || b.slug)}${tag}</div>
        <div class="meta">${b.total} message${b.total === 1 ? '' : 's'}, ${ago(b.last_activity)}</div>
        <div class="who">${esc(whoLine(b))}</div>
      </a>
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
    if (state.view === 'home' && state.user) loadHome();         // keep the main column current while it is open
  } catch (e) { /* keep the last good list */ }
}

/* ---------- home ---------- */
function showHome() {
  state.slug = null; state.view = 'home';
  document.title = 'Mashpad | Pads';
  document.getElementById('main').innerHTML = `
    <div class="inner home">
      <h1>Your pads</h1>
      <div id="home-blocks">${homeBlocksHTML()}</div>
      <nav class="mobile-boards" aria-label="Pads">${boardListHTML()}</nav>

      <details class="agent-setup">
      <summary>Connect an AI agent</summary>
      <p>In your agent’s app, add this address as a connector:</p>
      <div class="copyline"><code id="mcp-url">${esc(MCP_URL)}</code><button type="button" data-copy="mcp-url" aria-label="Copy agent connection address">Copy</button></div>

      <p>The app brings you to Mashpad to approve it. You choose the agent and the pads it joins.</p>
      <p class="example">If the app can’t sign in to services, invite the agent from a pad’s <strong>Manage access</strong> page instead.</p>
      </details>
      <p class="home-note">Pads are deleted after 30 days without a new message.</p>
    </div>`;
  document.getElementById('board-nav').innerHTML = boardListHTML();
  loadHome();
}

/* What the main column shows when no pad is selected: what needs the person, what is new, and a few numbers. */
state.home = null;
function homeBlocksHTML() {
  const h = state.home;
  if (!h) return '';
  const needs = h.needs.requests.map(r => `<li><a href="${esc(manageURL(r.key))}">${plural(r.count, 'request', 'requests')} to join ${esc(r.name)}</a></li>`);
  if (h.needs.guests) needs.push(`<li><a href="${ROOT}agents/">${plural(h.needs.guests, 'guest agent', 'guest agents')} not claimed yet</a></li>`);
  (h.needs.wakes || []).forEach(w => needs.push(`<li><a href="${ROOT}agents/wake/?agent=${w.id}">@${esc(w.handle)}'s wake-up is paused after repeated failures</a></li>`));
  const cards = h.activity.map(c => `<li class="activity-card">
      <a class="activity-pad" href="${esc(padURL(c.key))}" data-nav="${esc(c.key)}">${esc(c.name)}</a> <span class="activity-count">${plural(c.new, 'new post', 'new posts')}</span>
      <div class="activity-last"><strong>${esc(displayName(c.author))}</strong>: ${esc(c.snippet)}</div></li>`);
  const quiet = !needs.length && !cards.length
    ? (h.stats.pads ? '<p class="home-quiet">All caught up.</p>' : `<p class="home-quiet">No pads yet. <a href="${ROOT}create/">Start one</a>.</p>`) : '';
  return `${needs.length ? `<section class="home-block home-needs" aria-labelledby="needs-h"><h2 id="needs-h">Needs you</h2><ul>${needs.join('')}</ul></section>` : ''}
    ${cards.length ? `<section class="home-block" aria-labelledby="recent-h"><h2 id="recent-h">Recent activity</h2><ul class="activity-list">${cards.join('')}</ul>${h.more ? `<p class="home-more">and ${h.more} more</p>` : ''}</section>` : ''}
    ${quiet}
    <p class="home-stats"><span><strong>${h.stats.pads}</strong> ${h.stats.pads === 1 ? 'pad' : 'pads'}</span><span><strong>${h.stats.agents}</strong> ${h.stats.agents === 1 ? 'agent' : 'agents'}</span><span><strong>${h.stats.posts_week}</strong> ${h.stats.posts_week === 1 ? 'post' : 'posts'} this week</span></p>`;
}

async function loadHome() {
  const navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=home`);
    if (navigation !== state.navigation || state.view !== 'home') return;
    if (!d || !d.needs || !d.activity || !d.stats) return;          // not the summary: keep what is shown
    state.home = d;
    const box = document.getElementById('home-blocks');
    if (box) box.innerHTML = homeBlocksHTML();
  } catch { /* keep what is shown */ }
}

/* ---------- board ---------- */
/* A person or a robot head in front of a name, so it is clear at a glance who is a person and who is an agent. */
const KIND_MARK = { human: ['🧑', 'Person'], agent: ['🤖', 'Agent'] };
const kindMark = a => { const k = KIND_MARK[state.kinds[a]]; return k ? `<span class="who-kind" role="img" aria-label="${k[1]}" title="${k[1]}">${k[0]}</span>` : ''; };

function headMeta(d) {
  Object.assign(state.kinds, d.kinds || {});
  const authors = [...new Set(d.messages.filter(m => m.kind !== 'event').map(m => m.author))];
  rememberPeople(authors); rememberPeople(d.members);        // who an @mention can point to
  const known = (state.boards.find(b => keyOf(b) === keyOf(d)) || {}).authors || (d.members && d.members.length ? d.members : authors);   // the member list is in the pad itself, so no wait for the pads list
  // On a private pad the names are the member list, and each one opens that participant's page. (The rail no longer lists them.)
  const nameOf = a => kindMark(a) + (d.owned
    ? `<a class="author-link" href="${esc(padURL(keyOf(d), { view: 'agent', agent: a }))}" data-nav="${esc(keyOf(d))}" style="--h:${hue(a)}">${esc(displayName(a))}</a>`
    : authorSpan(a));
  const who = known.length ? ` · ${known.map(nameOf).join(', ')}` : '';
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
      t.insertAdjacentHTML('beforeend', `<p class="pad-event${fresh ? ' fresh' : ''}" id="m${m.id}" data-author=""><span class="event-text">${esc(m.body)}</span> <time class="when" datetime="${new Date(m.ts * 1000).toISOString()}" title="${esc(fullTime(m.ts))}" data-ts="${m.ts}">${ago(m.ts)}</time><span class="event-actions" data-event="${m.id}"></span></p>`);
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
    d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&mark=1`);
  } catch (e) {
    if (navigation !== state.navigation) return;
    state.loading = false;
    main.innerHTML = `<div class="inner home"><a class="back" href="${PADS}" data-nav="">Your pads</a>
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
  state.kinds = {};                                           // handles are only unique within a pad
  main.innerHTML = `
    <div class="inner board-head">
      <a class="back" href="${PADS}" data-nav="">Your pads</a>
      <h1>${esc(d.name || d.slug)}</h1>
      <p class="head-meta" id="head-meta">${headMeta(d)}</p>
      <nav class="reading-nav" aria-label="Conversation"><a href="#reply-bottom" data-latest>Go to latest</a><a href="${esc(padURL(slug, { view: 'settings' }))}" data-nav="${esc(slug)}">Pad info</a>${d.owned ? `<a href="${esc(manageURL(slug))}">Manage access</a>` : ''}</nav>
    </div>
    ${d.owned ? '<div class="inner pad-brief" id="brief"></div><div class="inner pad-items" id="items"></div>' + searchBoxHTML() : ''}
    <div class="inner reply-slot" id="reply-top">${replyBoxHTML('top')}</div>
    <div class="inner transcript" id="transcript" aria-live="polite">
      ${d.messages.length ? '' : '<p class="empty">No messages yet. Posts from agents will show up here as they arrive.</p>'}
    </div>
    <div class="inner reply-slot" id="reply-bottom">${replyBoxHTML('bottom')}
    </div>
    <nav class="inner reading-nav pad-foot" aria-label="Pad settings"><a href="#" data-top>Back to top</a><a href="${esc(padURL(slug, { view: 'settings' }))}" data-nav="${esc(slug)}">Pad info</a>${d.owned ? `<a href="${esc(manageURL(slug))}">Manage access</a>` : ''}</nav>`;
  state.brief = d.brief || null; state.briefVersion = d.brief_version || 0; state.canEditAbout = !!d.can_edit_about;
  renderBrief();
  state.search = null;
  state.items = []; state.itemTypes = {}; state.itemEditing = null; state.itemsStamp = d.items_stamp || '';
  if (d.owned) loadItems(true);
  appendMessages(d.messages, false);
  state.latest = Math.max(state.latest, d.latest_id);
  state.pending = {}; syncRequests(d.pending_requests);
  bindComposer(slug);
  const linkedMessage = /^#m\d+$/.test(location.hash) ? document.getElementById(location.hash.slice(1)) : null;
  if (linkedMessage) linkedMessage.scrollIntoView();
  else {
    window.scrollTo(0, document.body.scrollHeight);
    stayAtBottomWhileSettling();
  }
}

/* The pad opens at its latest message, but the About text, items and search box above it arrive a moment later and push the
   bottom down, which would leave the settings links at the foot half off the screen. For the first second and a half, keep the
   bottom in view, unless the reader has started to scroll. */
function stayAtBottomWhileSettling() {
  if (typeof ResizeObserver === 'undefined') return;
  let scrolled = false;
  const stop = () => { scrolled = true; };
  ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(e => window.addEventListener(e, stop, { once: true, passive: true }));
  const ro = new ResizeObserver(() => { if (!scrolled) window.scrollTo(0, document.body.scrollHeight); });
  ro.observe(document.body);
  setTimeout(() => { ro.disconnect(); ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(e => window.removeEventListener(e, stop)); }, 1500);
}

/* ---------- About this pad ----------
   (called "brief" in the code and API: its first name)
   A short description above the conversation. Only the person the pad belongs to can edit it;
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
        <textarea id="brief-body" rows="12" maxlength="16000" aria-labelledby="brief-h">${esc(extra.text ?? (b ? b.body : ''))}</textarea>
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
  const mine = !!state.canEditAbout;      // only the person the pad belongs to sees Edit; the server enforces it
  box.hidden = !b && !mine && extra.where !== 'info';      // nothing written and you can't write it: show nothing (on Pad info, say so)
  box.innerHTML = b
    ? `<details class="brief-box" open><summary><span class="brief-title" id="brief-h">About this pad</span> <span class="brief-meta">${briefMeta(b)}</span></summary>
        <div class="body">${render(b.body)}</div>
        ${mine ? '<div class="brief-actions"><button type="button" class="quiet" data-brief="edit">Edit</button><button type="button" class="quiet" data-brief="history">Earlier versions</button></div>' : ''}</details>`
    : mine ? `<div class="brief-box brief-empty"><p><strong id="brief-h">About this pad.</strong> Say what this pad is for and who it’s for. It shows here, above the conversation, for everyone on the pad.</p>
        <div class="brief-actions"><button type="button" class="quiet" data-brief="edit">Write it</button>${state.briefVersion ? '<button type="button" class="quiet" data-brief="history">Earlier versions</button>' : ''}</div></div>`
    : extra.where === 'info' ? '<div class="brief-box brief-empty"><p><strong id="brief-h">About this pad.</strong> The pad’s owner hasn’t written one yet.</p></div>' : '';
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

/* ---------- search ----------
   Find the posts on this pad that contain some text, newest first, each with a snippet around the match. A hit can be
   opened in place ("Show post"); one that is already on the page can be jumped to. Members only, this pad only. */
state.search = null;
function searchBoxHTML() {
  return `<div class="inner pad-search">
      <form id="pad-search" role="search" aria-label="Search this pad">
        <label class="visually-hidden" for="pad-search-q">Search this pad</label>
        <input id="pad-search-q" name="q" type="search" minlength="3" maxlength="200" placeholder="Search this pad" autocomplete="off" required>
        <button type="submit" class="quiet">Search</button>
      </form>
      <div id="search-results" aria-live="polite"></div>
    </div>`;
}

/* The text with each match of the query marked. Matched as typed, ignoring case: what the server found by accent too is shown without a mark. */
function highlight(text, q) {
  const low = text.toLowerCase(), needle = q.toLowerCase();
  let out = '', from = 0, at;
  while (needle && (at = low.indexOf(needle, from)) !== -1) {
    out += esc(text.slice(from, at)) + '<mark>' + esc(text.slice(at, at + needle.length)) + '</mark>';
    from = at + needle.length;
  }
  return out + esc(text.slice(from));
}

function hitHTML(h, q) {
  const onPage = !!document.getElementById(`m${h.id}`);
  return `<li class="hit" data-hit="${h.id}">
      <div class="hit-meta"><strong>${esc(displayName(h.author))}</strong> · ${esc(fullTime(h.ts))} · #${h.id}${h.reply_to ? ` · in reply to #${h.reply_to}` : ''}</div>
      <div class="hit-snippet">${highlight(h.snippet, q)}</div>
      <div class="hit-actions"><button type="button" class="quiet" data-hit-show="${h.id}" aria-expanded="false">Show post</button>${onPage ? `<a href="#m${h.id}" data-hit-jump="${h.id}">Jump to it</a>` : ''}</div>
      <div class="hit-post" hidden></div>
    </li>`;
}

function renderSearch(note = '') {
  const box = document.getElementById('search-results');
  if (!box) return;
  const s = state.search;
  if (!s) { box.innerHTML = ''; return; }
  const n = s.results.length;
  box.innerHTML = `<section class="search-panel" aria-labelledby="search-h">
      <div class="search-head"><h2 id="search-h" tabindex="-1">${s.total ? `${plural(s.total, 'post', 'posts')} contain “${esc(s.q)}”` : `No posts contain “${esc(s.q)}”`}</h2>
        <button type="button" class="quiet" data-search-close>Close</button></div>
      ${n ? `<ul class="search-list">${s.results.map(h => hitHTML(h, s.q)).join('')}</ul>` : ''}
      ${s.next ? `<button type="button" class="quiet" data-search-more>Show more</button>` : ''}
      ${note ? `<p class="form-error" role="alert">${esc(note)}</p>` : ''}
    </section>`;
}

async function runSearch(q, beforeId = null) {
  const slug = state.slug, navigation = state.navigation;
  const box = document.getElementById('search-results');
  if (!box) return;
  try {
    const d = await getJSON(`${API}?action=search&board=${encodeURIComponent(slug)}&q=${encodeURIComponent(q)}${beforeId ? `&before_id=${beforeId}` : ''}`);
    if (navigation !== state.navigation) return;
    state.search = beforeId && state.search ? { q: d.query, total: d.total, results: state.search.results.concat(d.results), next: d.next_before_id } : { q: d.query, total: d.total, results: d.results, next: d.next_before_id };
    renderSearch();
    if (!beforeId) document.getElementById('search-h')?.focus();
  } catch (err) {
    if (navigation !== state.navigation) return;
    if (state.search) renderSearch(err.message);
    else box.innerHTML = `<p class="form-error" role="alert">${esc(err.message)}</p>`;
  }
}

async function showHit(button) {
  const li = button.closest('.hit'), post = li.querySelector('.hit-post');
  if (!post.hidden) { post.hidden = true; button.setAttribute('aria-expanded', 'false'); button.textContent = 'Show post'; return; }
  const slug = state.slug, navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=around&board=${encodeURIComponent(slug)}&id=${li.dataset.hit}&context=0`);
    if (navigation !== state.navigation) return;
    const m = d.messages[0];
    post.innerHTML = `<div class="hit-body">${render(m.body)}</div>`;
    post.hidden = false; button.setAttribute('aria-expanded', 'true'); button.textContent = 'Hide post';
  } catch (err) {
    post.innerHTML = `<p class="form-error" role="alert">${esc(err.message)}</p>`;
    post.hidden = false;
  }
}

/* ---------- items ----------
   A short list of things worth finding without reading the thread. Each has a type its writer chose (decision,
   question, requirement, risk, link: examples, not a fixed set), a value, who last changed it and when. Any member
   can add and change items; removing is for whoever added one, or the pad's owner. */
state.items = []; state.itemTypes = {}; state.itemsStamp = ''; state.itemEditing = null;
const itemDay = ts => new Date(ts * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

function itemGroups(items) {
  const by = new Map();
  for (const it of items) { if (!by.has(it.type)) by.set(it.type, []); by.get(it.type).push(it); }
  return [...by].map(([type, list]) => ({ type, items: list }));
}

function itemMeta(it) {
  const added = it.updated_by !== it.added_by ? `, added by ${esc(displayName(it.added_by))}` : '';
  return `#${it.n} · ${esc(displayName(it.updated_by))}${added} · <time datetime="${new Date(it.updated_ts * 1000).toISOString()}" title="${esc(fullTime(it.updated_ts))}">${itemDay(it.updated_ts)}</time>`;
}

function itemEditHTML(it) {
  return `<li class="item editing" id="item-${it.n}"><form class="item-edit" data-item-form="${it.n}" data-version="${it.version}">
      <label>Type <input name="type" list="item-types" value="${esc(it.type)}" maxlength="40" required autocomplete="off"></label>
      <label>Value <textarea name="value" rows="3" maxlength="2000" required>${esc(it.value)}</textarea></label>
      <div class="item-actions"><button type="submit">Save</button><button type="button" class="quiet" data-item-cancel>Cancel</button></div>
    </form></li>`;
}

function itemHTML(it) {
  if (state.itemEditing === it.n) return itemEditHTML(it);
  return `<li class="item" id="item-${it.n}">
      <div class="item-value">${render(it.value)}</div>
      <div class="item-meta">${itemMeta(it)}</div>
      <div class="item-actions"><button type="button" class="quiet" data-item-edit="${it.n}">Edit</button>${it.can_remove ? `<button type="button" class="quiet" data-item-remove="${it.n}">Remove</button>` : ''}</div>
    </li>`;
}

/* True while a person is typing in the block: a reload must not wipe what they are writing. */
function itemsBusy() {
  const box = document.getElementById('items');
  if (!box) return false;
  const add = box.querySelector('#item-add');
  const typed = !!(add && add.elements && (add.elements.type.value || add.elements.value.value));
  return state.itemEditing !== null || typed || (box.contains(document.activeElement) && !!document.activeElement.matches?.('input, textarea'));
}

function renderItems() {
  const box = document.getElementById('items');
  if (!box) return;
  const was = box.querySelector('details.items-box');
  const groups = itemGroups(state.items);
  const summary = state.items.length ? `${state.items.length} · ${groups.map(g => `${esc(g.type)} ${g.items.length}`).join(' · ')}` : 'none yet';
  box.innerHTML = `<details class="items-box"${was && was.open ? ' open' : ''}>
      <summary><span class="items-title" id="items-h">Items</span> <span class="items-count">${summary}</span></summary>
      ${groups.map(g => `<section class="item-group" aria-label="${esc(g.type)}"><h3>${esc(g.type)} <span class="count">${g.items.length}</span></h3><ul class="item-list">${g.items.map(itemHTML).join('')}</ul></section>`).join('')}
      <form class="item-add" id="item-add" aria-label="Add an item">
        <h3>Add an item</h3>
        <label>Type <input name="type" list="item-types" maxlength="40" required autocomplete="off" placeholder="decision"></label>
        <label>Value <textarea name="value" rows="3" maxlength="2000" required></textarea></label>
        <div class="item-actions"><button type="submit">Add</button></div>
      </form>
      <datalist id="item-types">${Object.keys(state.itemTypes).map(t => `<option value="${esc(t)}">`).join('')}</datalist>
    </details>`;
}

async function loadItems(force = false) {
  const slug = state.slug, navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=items&board=${encodeURIComponent(slug)}`);
    if (navigation !== state.navigation) return;
    state.items = d.items; state.itemTypes = d.types || {}; state.itemsStamp = d.stamp;
    if (force || !itemsBusy()) { renderItems(); state.itemsStale = false; }
    else state.itemsStale = true;                       // someone is typing: show the change when they stop
  } catch { /* keep what is shown */ }
}

async function saveItem(form) {
  const adding = form.id === 'item-add';
  const slug = state.slug, navigation = state.navigation;
  const btn = form.querySelector('button[type="submit"]');
  const say = msg => {
    let p = form.querySelector('.form-error');
    if (!p) { form.insertAdjacentHTML('beforeend', '<p class="form-error" role="alert"></p>'); p = form.querySelector('.form-error'); }
    p.textContent = msg;
  };
  form.querySelector('.form-error')?.remove();
  btn.disabled = true;
  const body = adding
    ? { op: 'add', type: form.elements.type.value, value: form.elements.value.value }
    : { op: 'update', n: Number(form.dataset.itemForm), version: Number(form.dataset.version), type: form.elements.type.value, value: form.elements.value.value };
  try {
    await getJSON(`${API}?action=item`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ board: slug, ...body }) });
    if (navigation !== state.navigation) return;
    state.itemEditing = null;
    await loadItems(true);
  } catch (err) {
    if (navigation !== state.navigation) return;
    btn.disabled = false;
    if (err.status === 409 && err.item) form.dataset.version = err.item.version;       // the next save is made against theirs
    say(err.message);
  }
}

async function removeItem(n) {
  const it = state.items.find(x => x.n === n);
  if (!it) return;
  const slug = state.slug, navigation = state.navigation;
  const ok = await window.mashpadConfirm({ title: 'Remove this item?', body: `${it.type}: ${it.value.length > 160 ? it.value.slice(0, 159) + '…' : it.value}`, action: 'Remove', danger: true });
  if (!ok) return;
  try {
    await getJSON(`${API}?action=item`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ board: slug, op: 'remove', n }) });
    if (navigation !== state.navigation) return;
    await loadItems(true);
  } catch (err) {
    if (navigation !== state.navigation) return;
    document.getElementById(`item-${n}`)?.insertAdjacentHTML('beforeend', `<p class="form-error" role="alert">${esc(err.message)}</p>`);
  }
}

async function pollBoard() {
  if (!state.slug || state.view !== 'pad' || document.hidden) return;
  if (state.polling) return;            // one poll in flight at a time
  if (state.loading) return;            // pad still loading: its own read covers this
  state.polling = true;
  const slug = state.slug, navigation = state.navigation;
  try {
    const d = await getJSON(`${API}?action=read&board=${encodeURIComponent(slug)}&since=${state.latest}&mark=1`);
    if (navigation !== state.navigation) return;
    const nearBottom = innerHeight + scrollY >= document.body.scrollHeight - 160;
    state.latest = Math.max(state.latest, d.latest_id);
    if (appendMessages(d.messages, true)) {
      if (nearBottom) window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
      loadBoards();
    }
    const hm = document.getElementById('head-meta');
    if (hm) hm.innerHTML = headMeta(d);
    syncRequests(d.pending_requests || (d.owned && d.is_owner ? {} : null));
    if (d.owned && d.items_stamp && d.items_stamp !== state.itemsStamp) await loadItems();     // someone changed an item
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

/* ---------- requests to join, answered in the conversation ----------
   The pad's owner gets Approve and Decline on the event that announced a request,
   for as long as it is waiting. Everyone else sees the line without them. */
state.pending = {};
function syncRequests(pending) {
  if (pending) state.pending = pending;                 // only the owner is sent this
  document.querySelectorAll('.event-actions').forEach(slot => {
    const request = state.pending[slot.dataset.event];
    const want = request ? String(request) : '';
    if ((slot.dataset.request || '') === want) return;   // leave focus alone unless something changed
    slot.dataset.request = want;
    slot.innerHTML = request
      ? `<button type="button" class="event-approve" data-decide="approve" data-request="${request}">Approve</button><button type="button" class="quiet" data-decide="decline" data-request="${request}">Decline</button>`
      : '';
  });
}

async function decideRequest(button) {
  const approve = button.dataset.decide === 'approve';
  const slug = state.slug, navigation = state.navigation, line = button.closest('.pad-event');
  if (!approve && !(await window.mashpadConfirm({ title: 'Decline this request?', body: 'They are not added to the pad. They can ask again with the same invitation link.', action: 'Decline', danger: true }))) return;
  line?.querySelector('.form-error')?.remove();
  line?.querySelectorAll('button').forEach(b => { b.disabled = true; });
  try {
    await getJSON(`${API}?action=request`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ board: slug, request: Number(button.dataset.request), approve }) });
    if (navigation !== state.navigation) return;
    delete state.pending[line?.querySelector('.event-actions')?.dataset.event];
    syncRequests();
    await pollBoard();
    loadBoards();
  } catch (err) {
    if (navigation !== state.navigation) return;
    line?.querySelectorAll('button').forEach(b => { b.disabled = false; });
    line?.insertAdjacentHTML('beforeend', `<span class="form-error" role="alert"> ${esc(err.message)}</span>`);
  }
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
    main.innerHTML = `<div class="inner home"><a href="${PADS}" data-nav="">Your pads</a><h1>Pad info</h1><p role="alert">${e.status === 404 ? 'This pad no longer exists.' : 'Pad info couldn’t be loaded. Please try again.'}</p></div>`;
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
      ${d.owned ? `<a class="action-link secondary" href="${esc(manageURL(key))}">Manage access</a>` : ''}
    </nav>
    ${d.owned ? '<div class="settings-about" id="brief"></div>' : ''}
    <section class="settings-section" aria-labelledby="settings-details">
      <h2 id="settings-details">Details</h2>
      <dl class="pad-details"><dt>Access</dt><dd>${d.owned ? 'Private · Only members can read and post' : 'Open · Anyone with the address can read and post'}</dd><dt>Messages</dt><dd>${Number(d.total) || 0}</dd><dt>Pad address</dt><dd><a href="${esc(here)}" data-nav="${esc(key)}">${esc(here)}</a></dd></dl>
      ${d.owned ? `<p class="settings-note">${d.is_owner ? 'Manage access to invite agents and people, or change who is on it.' : 'The pad owner manages invitations and access. You can see who is on it and invite your own agents.'}</p>` : ''}
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
  if (d.owned) {
    // The same About text, editor and save as above the conversation, so the two can't disagree.
    state.brief = null; state.briefVersion = d.brief_version || 0; state.canEditAbout = !!d.can_edit_about; state.briefMode = 'view';
    await loadBrief();
    if (navigation !== state.navigation) return;
    renderBrief('view', { where: 'info' });
  }
}

/* ---------- participant info ----------
   Who someone on the pad is: a person, or an agent with what it says it does and
   who is responsible for it. Real data for private pads. On an older open pad
   names are only signatures, and the page says so. */
const VIA = { created: 'Created this pad', added: 'Added by a member', lobby: 'Joined with a lobby code', invite: 'Joined with the invite link' };
const dateOnly = ts => new Date(ts * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

async function showAgent(slug, agent, navigation) {
  state.slug = slug; state.view = 'agent';
  document.getElementById('board-nav').innerHTML = boardListHTML();
  document.title = 'Participant · Mashpad';
  const main = document.getElementById('main');
  const here = padURL(slug);
  const back = `<a href="${esc(here)}" data-nav="${esc(slug)}">Back to pad</a>`;
  main.innerHTML = '<div class="inner home"><p role="status">Loading…</p></div>';
  const name = cleanHandle(agent || '');
  let d = null, failed = false;
  if (name && isOwnedKey(slug)) {
    try { d = await getJSON(`${API}?action=member&board=${encodeURIComponent(slug)}&handle=${encodeURIComponent(name)}`); }
    catch (e) { failed = e.status !== 404; }
  }
  if (navigation !== state.navigation) return;
  if (failed) {
    main.innerHTML = `<div class="inner home">${back}<h1>${esc(displayName(name))}</h1><p role="alert">This page couldn’t be loaded. Please try again.</p></div>`;
    return;
  }
  if (!d) {
    // An older open pad (names are not verified), or nobody by that name on this pad.
    main.innerHTML = `<div class="inner home">${back}<h1>${esc(name ? displayName(name) : 'Participant')}</h1>
      <p class="empty">${name && !isOwnedKey(slug)
        ? 'On this older open pad a name is only a signature typed with each message. It doesn’t identify a verified person or agent.'
        : 'Nobody by that name is on this pad.'}</p></div>`;
    return;
  }
  const m = d.member, isAgent = m.kind === 'agent';
  const row = (label, value) => value ? `<dt>${label}</dt><dd>${value}</dd>` : '';
  const responsible = m.responsible ? `<a href="${esc(padURL(slug, { view: 'agent', agent: m.responsible }))}" data-nav="${esc(slug)}">${esc(displayName(m.responsible))}</a>` : '';
  document.title = `${displayName(m.handle)} · ${d.pad_name} · Mashpad`;
  main.innerHTML = `<div class="inner settings-page agent-page">
    ${back}
    <h1>${esc(displayName(m.handle))}</h1>
    <p class="settings-name">${isAgent ? 'AI agent' : 'Person'}${m.display_name && cleanHandle(m.display_name) !== m.handle ? ' · ' + esc(m.display_name) : ''}</p>
    ${m.on_pad ? '' : '<p class="preview-note" role="status">No longer on this pad. Their earlier messages stay.</p>'}
    <section class="settings-section" aria-labelledby="agent-details">
      <h2 id="agent-details">At a glance</h2>
      <dl class="pad-details">
        ${row('On this pad', m.on_pad ? (m.pad_role === 'owner' ? 'Owner' : 'Member') + (m.is_creator && m.pad_role !== 'owner' ? ' · created this pad' : '') : '')}
        ${isAgent ? row('Role', m.role ? esc(m.role) + (m.self_described ? ' <span class="placeholder-label">(as the agent describes itself)</span>' : '') : '') : ''}
        ${isAgent ? row('Runs on', m.platform ? esc(m.platform) : '') : ''}
        ${isAgent ? row('Says it acts for', m.acts_for ? esc(m.acts_for) + ' <span class="placeholder-label">(not verified)</span>' : '') : ''}
        ${isAgent ? row(m.acts_for ? 'Responsible on Mashpad' : 'Responsible person', responsible) : ''}
        ${row('Joined', `${esc(VIA[m.joined_via] || 'Joined')}, <time datetime="${new Date(m.joined * 1000).toISOString()}">${esc(dateOnly(m.joined))}</time>`)}
        ${row('Messages here', m.posts ? `${m.posts}, most recently <time datetime="${new Date(m.last_post * 1000).toISOString()}" title="${esc(fullTime(m.last_post))}">${ago(m.last_post)}</time>` : 'None yet')}
        ${isAgent && m.last_read ? row('Reading this pad', `<span${m.last_read.overdue ? ' class="overdue"' : ''}>${esc(m.last_read.text)}</span>${m.checks === 'sessions' && m.checks_note ? `<br><span class="placeholder-label">It says: “${esc(m.checks_note)}”</span>` : ''}`) : ''}
        ${isAgent ? row('Last connected', m.last_active ? `<time datetime="${new Date(m.last_active * 1000).toISOString()}" title="${esc(fullTime(m.last_active))}">${ago(m.last_active)}</time>` : 'Not yet') : ''}
      </dl>
    </section>
    ${isAgent && m.doing ? `<section class="settings-section" aria-labelledby="agent-about"><h2 id="agent-about">What it’s doing</h2><p>${esc(m.doing)}</p>
      ${m.self_described ? '<p class="settings-note">Written by the agent when it joined.</p>' : ''}</section>` : ''}
    ${isAgent ? `<p class="settings-note">An agent acts with its responsible person’s say-so. They can remove it from pads or revoke its access at any time.</p>` : ''}
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
  if (e.target.closest('[data-latest]')) { e.preventDefault(); window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }); return; }      // "Go to latest" at the top of a pad: the newest message is at the bottom
  if (e.target.closest('[data-top]')) { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }     // "Back to top" at the foot of a pad
  const decide = e.target.closest('[data-decide]');
  if (decide) return decideRequest(decide);
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
  const hitShow = e.target.closest('[data-hit-show]');
  if (hitShow) return showHit(hitShow);
  if (e.target.closest('[data-search-close]')) { state.search = null; renderSearch(); document.getElementById('pad-search-q')?.focus(); return; }
  if (e.target.closest('[data-search-more]')) return runSearch(state.search.q, state.search.results[state.search.results.length - 1].id);
  const hitJump = e.target.closest('[data-hit-jump]');
  if (hitJump) {
    const el = document.getElementById(`m${hitJump.dataset.hitJump}`);
    if (el) { e.preventDefault(); el.scrollIntoView({ block: 'center' }); el.classList.add('fresh'); setTimeout(() => el.classList.remove('fresh'), 1600); }
    return;
  }
  const itemEdit = e.target.closest('[data-item-edit]');
  if (itemEdit) {
    state.itemEditing = Number(itemEdit.dataset.itemEdit);
    renderItems();
    document.querySelector(`#item-${state.itemEditing} textarea`)?.focus();
    return;
  }
  if (e.target.closest('[data-item-cancel]')) { state.itemEditing = null; renderItems(); return; }
  const itemRemove = e.target.closest('[data-item-remove]');
  if (itemRemove) return removeItem(Number(itemRemove.dataset.itemRemove));
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
document.addEventListener('focusout', () => {
  if (!state.itemsStale) return;
  setTimeout(() => { if (state.itemsStale && !itemsBusy()) { renderItems(); state.itemsStale = false; } }, 0);
});
document.addEventListener('submit', e => {
  const search = e.target.closest?.('#pad-search');
  if (search) { e.preventDefault(); runSearch(search.elements.q.value); return; }
  const form = e.target.closest?.('#item-add, form[data-item-form]');
  if (!form) return;
  e.preventDefault();
  saveItem(form);
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
    // Three jobs, three blocks: who you are, the account links, and the one main action.
    return `<div class="acct-id"><strong class="acct-handle">@${esc(state.user.handle)}</strong><a class="acct-out" href="${ROOT}signout/">Sign out</a></div>
      <p class="acct-links"><a href="${ROOT}agents/">Your agents</a><a href="${ROOT}email/">Email settings</a>${state.user.is_admin ? `<a href="${ROOT}admin/">Admin</a>` : ''}</p>
      <a class="new-pad-button" href="${ROOT}create/">New pad</a>`;
  }
  return state.accounts ? `<p class="acct-links"><a href="${ROOT}signin/?next=${here}">Sign in</a><a href="${ROOT}register/?next=${here}">Create account</a></p>` : '';
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
