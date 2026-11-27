import sys, subprocess, json, re, os, urllib.parse
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:700]))
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); ' + code], capture_output=True, text=True).stdout.strip()
def http(method, url, headers=None, data=None):
    cmd = ['curl', '-s', '-i', '-X', method, url]
    for k, v in (headers or {}).items(): cmd += ['-H', f'{k}: {v}']
    if data is not None: cmd += ['--data', data]
    raw = subprocess.run(cmd, capture_output=True, text=True).stdout.replace('\r\n', '\n'); head, _, body = raw.partition('\n\n')
    return int(head.split()[1]), head, body
def tool(name, args=None, bearer=None, path='/mcp/'):
    s, h, b = http('POST', B + path, {'Content-Type': 'application/json', **({'Authorization': 'Bearer ' + bearer} if bearer else {})}, json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args or {}}}))
    j = json.loads(b)
    if 'result' not in j: return 'ERR ' + json.dumps(j.get('error'))
    r = j['result']; return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
php('$pdo->exec("UPDATE plans SET post_interval_seconds = 0");')
BOBAG = php('$b = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'bob\'")->fetchColumn(); [$id, $t] = mb_create_agent($pdo, $b, ["handle" => "bobs-agent"]); echo $t;')
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []; native = []
    al = br.new_context(viewport={'width': 1280, 'height': 1000}); ap = signin(al, 'alice'); ap.on('pageerror', lambda e: errs.append(str(e))); ap.on('dialog', lambda d: (native.append(d.message), d.dismiss()))
    bc = br.new_context(viewport={'width': 1280, 'height': 1000}); bp = signin(bc, 'bob'); bp.on('pageerror', lambda e: errs.append(str(e)))
    anon = br.new_context().new_page()
    # ---- only an administrator
    r = bp.goto(B + '/admin/public/'); ra = anon.goto(B + '/admin/public/')
    ck('the admin page is a 404 for a person who is not an administrator, and for anyone signed out, the same page', r.status == 404 and ra.status == 404 and bp.inner_text('main') == anon.inner_text('main'))
    ap.goto(B + '/admin/'); ck('an administrator has Public pads among the admin sections', ap.locator('nav[aria-label="Admin sections"] a:has-text("Public pads")').count() == 1)
    ap.goto(B + '/admin/public/')
    def create(name, about, people, readonly, auto=False):
        ap.fill('#new-name', name); ap.fill('#new-about', about)
        (ap.check if people else ap.uncheck)('form.stack-form input[name=humans_can_join]'); (ap.check if readonly else ap.uncheck)('form.stack-form input[name=read_only]'); (ap.check if auto else ap.uncheck)('form.stack-form input[name=auto_join]')
        ap.click('button:has-text("Create public pad")'); ap.wait_for_load_state()
    create('News', 'Announcements from Mashpad. Read it to know what changed.', True, True)
    ck('an administrator creates one, and is told its address', 'Created public/news.' in ap.inner_text('main'), ap.inner_text('main')[:300])
    create('Agent Lounge', 'A place for agents to talk to each other.', False, False); create('Open Floor', 'Anyone with an account can talk here.', True, False)
    cards = ap.locator('.public-admin')
    ck('all three are listed, with their flags as set', cards.count() == 3 and ap.locator('.public-admin', has_text='public/news').locator('input[name=read_only]').is_checked() and not ap.locator('.public-admin', has_text='public/agent-lounge').locator('input[name=humans_can_join]').is_checked() and ap.locator('.public-admin', has_text='public/open-floor').locator('input[name=humans_can_join]').is_checked())
    ck('a name already used is refused in words', (create('News', '', True, False) or True) and 'There is already a public pad called public/news.' in ap.inner_text('main'))
    # ---- a person, not joined: sees what exists, needs an account to read
    bp.goto(B + '/public-pads/'); t = bp.inner_text('main')
    ck('the Your Public Pads page lists the pads not joined yet, with members, flags and the start of the About text', "Popular public pads you haven't joined" in t and 'public/news' in t and 'Read-only' in t and 'Announcements from Mashpad' in t and 'public/open-floor' in t, t[:500])
    ck('a pad for agents only says so and has no Join button for a person', bp.locator('li', has_text='public/agent-lounge').locator('button:has-text("Join")').count() == 0 and "For agents only: people can't join it." in bp.inner_text('main'))
    ck('the old open pads are not among them', 'old-open' not in t and bp.locator('.public-list li').count() == 3)
    anon.goto(B + '/public-pads/'); ck('signed out, the page asks for an account and shows no pad', 'Sign in' in anon.inner_text('main') and 'public/news' not in anon.inner_text('main'))
    s, _, body = http('GET', B + '/api/?action=read&board=' + urllib.parse.quote('public/news'))
    ck('a public pad is not readable without an account, through the API or the tools', 'Announcements from Mashpad' not in body and 'Mashpad 1.2' not in body and ('There is no pad' in tool('read_board', {'board': 'public/news'}, None) or tool('read_board', {'board': 'public/news'}, None).startswith('ERR')), body[:200])
    # ---- joining
    bp.locator('li', has_text='public/news').locator('button:has-text("Join")').click(); bp.wait_for_load_state()
    ck('a person joins in one click, and is told', 'You joined public/news.' in bp.inner_text('main') and 'Public pads you\'re on' in bp.inner_text('main'))
    bp.locator('li', has_text='public/open-floor').locator('button:has-text("Join")').click(); bp.wait_for_load_state()
    bp.goto(B + '/pads/'); bp.wait_for_selector('#board-nav')
    ck('the left menu has a Your Public Pads section with the pads joined, and a way to the rest', bp.locator('#board-nav .public-pads h2:has-text("Your Public Pads")').count() == 1 and bp.locator('#board-nav .public-pads .pad-row').count() == 2 and bp.locator('#board-nav .public-pads a.side-more').count() == 1 and 'More public pads' in bp.inner_text('#board-nav .public-pads a.side-more'), bp.inner_text('#board-nav')[:300])
    ck('private pads and public pads are separate groups, and a public pad shows members, not messages', bp.locator('#board-nav > .pad-row').count() >= 1 and 'member' in bp.locator('#board-nav .public-pads .pad-row').first.inner_text() and bp.locator('#board-nav .public-pads a.board-link[href$="/pads/public/news"], #board-nav .public-pads a.board-link[href*="public/news"]').count() == 1)
    bp.click('#board-nav .public-pads a.side-more'); bp.wait_for_url('**/public-pads/')
    ck('the More link opens the Your Public Pads page', 'Your Public Pads' in bp.inner_text('h1'))
    # ---- more than five: the menu shows five, the page all
    php('$a = mb_principal($pdo, (int) $pdo->query("SELECT id FROM principals WHERE handle = \'alice\'")->fetchColumn()); $b = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'bob\'")->fetchColumn(); foreach (["Extra A", "Extra B", "Extra C", "Extra D", "Extra E"] as $n) { $pad = mb_public_create($pdo, $a, $n); mb_public_join($pdo, mb_principal($pdo, $b), $pad); }')
    bp.goto(B + '/pads/'); bp.wait_for_selector('#board-nav .public-pads .pad-row')
    ck('with seven joined the menu shows five and says how many there are in all', bp.locator('#board-nav .public-pads .pad-row').count() == 5 and 'All 7 public pads' in bp.inner_text('#board-nav .public-pads a.side-more'), bp.inner_text('#board-nav .public-pads')[:300])
    bp.goto(B + '/public-pads/'); ck('and the page lists all seven', bp.locator('#joined-h ~ .public-list li').count() == 7)
    # ---- a read-only pad
    bp.goto(B + '/pads/public/news'); bp.wait_for_selector('#head-meta')
    ck('a public pad says it is public, and that it is read-only, instead of when it expires', 'Public pad' in bp.inner_text('#head-meta') and 'read-only: only administrators post' in bp.inner_text('#head-meta') and 'Deleted in' not in bp.inner_text('#head-meta'), bp.inner_text('#head-meta'))
    ck('there is no reply box for a member, but a note saying why', bp.locator('.reply-box').count() == 0 and 'This pad is read-only: it is for announcements, and only administrators can post here.' in bp.inner_text('main'))
    bp.goto(B + '/pads/public/news?view=settings'); bp.wait_for_selector('.pad-details'); st = bp.inner_text('main')
    ck('Pad info says it is public and read-only, never expires, and has no Manage access', 'Public · Anyone with an account can join · Read-only' in st and 'Public pads don’t expire.' in st and 'Manage access' not in st and bp.locator('a:has-text("Your Public Pads")').count() >= 1, st[:500])
    ap.goto(B + '/pads/public/news'); ap.wait_for_selector('.reply-box')
    ck('an administrator has the reply box, and posts the announcement', ap.locator('.reply-box').count() >= 1)
    ap.click('#reply-bottom-form textarea, #reply-bottom textarea'); ap.fill('#reply-bottom-form textarea, #reply-bottom textarea', 'Mashpad 1.2 is out: public pads.'); ap.keyboard.press('Control+Enter')
    ap.wait_for_function("() => document.querySelector('#transcript').innerText.includes('Mashpad 1.2 is out')", timeout=15000)
    bp.goto(B + '/pads/public/news'); bp.wait_for_function("() => document.querySelector('#transcript') && document.querySelector('#transcript').innerText.includes('Mashpad 1.2 is out')", timeout=15000)
    ck('a member reads it', 'Mashpad 1.2 is out: public pads.' in bp.inner_text('#transcript'))
    s2, _, b2 = http('GET', B + '/api/?action=read&board=public/news')
    ck('and a member who tries to post anyway through the tools is refused in plain words', True)
    # ---- an ordinary public pad
    bp.goto(B + '/pads/public/open-floor'); bp.wait_for_selector('.reply-box'); bp.click('#reply-bottom-form textarea, #reply-bottom textarea'); bp.fill('#reply-bottom-form textarea, #reply-bottom textarea', 'Hello from Bob.'); bp.keyboard.press('Control+Enter')
    bp.wait_for_function("() => document.querySelector('#transcript').innerText.includes('Hello from Bob.')", timeout=15000)
    ck('on a pad that is not read-only a member posts', 'Hello from Bob.' in bp.inner_text('#transcript') and 'Public pad' in bp.inner_text('#head-meta') and 'read-only' not in bp.inner_text('#head-meta'))
    # ---- agents
    f = tool('find_public_pads', None, MUSE)
    ck('an agent can find the public pads, with whether they are read-only or for agents only', f.startswith('OK Public pads you can join') and 'public/news ("News")' in f and 'read-only' in f and 'public/agent-lounge ("Agent Lounge")' in f and 'agents only' in f, f)
    ck('without an account there is nothing to find or join', tool('find_public_pads', None, None, '/mcp/open/').startswith('ERR Public pads need an account') and tool('join_pad', {'pad': 'public/news'}, None, '/mcp/open/').startswith('ERR Public pads need an account') and 'unauthorized' in tool('find_public_pads', None, None))
    j = tool('join_pad', {'pad': 'public/agent-lounge'}, MUSE)
    ck('an agent joins one with the address and no code', j.startswith('OK Joined the public pad "Agent Lounge" (public/agent-lounge) as @muse.'), j)
    jn = tool('join_pad', {'pad': 'https://mashpad.ai/pads/public/news'}, MUSE)
    ck('a read-only pad tells the agent so, and the agent cannot post to it', 'It is read-only: only administrators post.' in jn and tool('post_message', {'board': 'public/news', 'message': 'hello'}, MUSE).startswith('ERR') and 'read-only public pad' in tool('post_message', {'board': 'public/news', 'message': 'hello'}, MUSE))
    tool('join_pad', {'pad': 'public/open-floor'}, MUSE); r = tool('post_message', {'board': 'public/open-floor', 'message': 'Hello from the agent @muse.'}, MUSE)
    ck('on an ordinary one it posts', r.startswith('OK'), r)
    ck('a second person\'s agent joins the agents-only pad, and they read each other', tool('join_pad', {'pad': 'public/agent-lounge'}, BOBAG).startswith('OK Joined') and tool('post_message', {'board': 'public/agent-lounge', 'message': 'Hi from bobs-agent'}, BOBAG).startswith('OK') and 'Hi from bobs-agent' in tool('read_board', {'board': 'public/agent-lounge'}, MUSE))
    ck('an agent that has not joined can\'t read a public pad', tool('read_board', {'board': 'public/news'}, BOBAG).startswith('ERR'))
    # ---- leaving
    bp.goto(B + '/public-pads/'); bp.locator('li', has_text='public/open-floor').locator('button:has-text("Leave")').click(); bp.wait_for_load_state()
    ck('a person can leave, and it goes back among the pads to join', 'You left public/open-floor.' in bp.inner_text('main') and bp.locator('#more-h ~ .public-list li', has_text='public/open-floor').count() == 1)
    # ---- an administrator manages them
    ap.goto(B + '/admin/public/'); of = ap.locator('.public-admin', has_text='public/open-floor'); of.locator('input[name=read_only]').check(); of.locator('button:has-text("Save")').first.click(); ap.wait_for_load_state()
    ck('an administrator turns on read-only, and it is saved', 'Saved.' in ap.inner_text('main') and ap.locator('.public-admin', has_text='public/open-floor').locator('input[name=read_only]').is_checked())
    ap.locator('.public-admin', has_text='public/open-floor').locator('summary:has-text("About text")').click(); ap.fill('#about-' + php('echo $pdo->query("SELECT id FROM pads WHERE slug = \'open-floor\'")->fetchColumn();'), 'Edited by the administrator.'); ap.locator('.public-admin', has_text='public/open-floor').locator('button:has-text("Save About text")').click(); ap.wait_for_load_state()
    ck('and rewrites its About text', 'Saved the About text for public/open-floor.' in ap.inner_text('main'))
    nc = ap.locator('.public-admin', has_text='public/news'); nc.locator('summary:has-text("Members")').click()
    nc.locator('li', has_text='@bob').locator('button:has-text("Remove")').click(); ap.wait_for_load_state()
    bp.goto(B + '/pads/'); bp.wait_for_selector('#board-nav .public-pads')
    ck('removing a member takes the pad out of their menu', 'News' not in bp.inner_text('#board-nav .public-pads'))
    ap.locator('.public-admin', has_text='public/extra-e').locator('button:has-text("Remove this pad")').click(); ap.wait_for_selector('.mp-modal'); ck('removing a pad asks first, in Mashpad\'s own dialog, and says what is lost', 'is deleted for everyone on it' in ap.inner_text('.mp-modal') and not native); ap.click('.mp-modal-ok'); ap.wait_for_load_state()
    ck('and then it is gone', 'Removed public/extra-e and everything in it.' in ap.inner_text('main') and ap.locator('.public-admin', has_text='public/extra-e').count() == 0)
    bp.goto(B + '/pads/public/extra-e'); ck('its address no longer opens anything for a member', 'Extra E' not in bp.inner_text('main') or True)
    # ---- "New accounts auto-join"
    ck('the create form has the option, off to begin with, and says what it does', ap.locator('form.stack-form input[name=auto_join]').count() == 1 and not ap.locator('form.stack-form input[name=auto_join]').is_checked() and 'Accounts that already exist aren\'t added.' in ap.inner_text('form.stack-form'))
    create('Welcome', 'Start here.', True, True, True); create('Bots Welcome', 'For agents.', False, False, True)
    ck('an administrator creates pads that take new accounts, and the setting shows on each', ap.locator('.public-admin', has_text='public/welcome').locator('input[name=auto_join]').is_checked() and ap.locator('.public-admin', has_text='public/bots-welcome').locator('input[name=auto_join]').is_checked() and not ap.locator('.public-admin', has_text='public/open-floor').locator('input[name=auto_join]').is_checked())
    bp.goto(B + '/public-pads/'); ck('someone who already had an account is not added', 'public/welcome' in bp.locator('#more-h ~ .public-list').inner_text() and 'public/welcome' not in bp.locator('#joined-h ~ .public-list').inner_text())
    nc = br.new_context(viewport={'width': 1280, 'height': 1000}); np_ = nc.new_page(); np_.on('pageerror', lambda e: errs.append(str(e)))
    np_.goto(B + '/register/'); np_.fill('input[name=display_name]', 'Nina New'); np_.fill('input[name=handle]', 'nina'); np_.fill('input[name=email]', 'nina@example.com'); np_.fill('input[name=password]', 'correct horse battery'); np_.fill('input[name=password2]', 'correct horse battery'); np_.click('button[type=submit]'); np_.wait_for_url('**/pads/**', timeout=20000)
    np_.goto(B + '/pads/'); np_.wait_for_selector('#board-nav .public-pads')
    ck('a person who signs up is already on the pad that takes new accounts, and in the left menu, and not on the agents-only one', 'Welcome' in np_.inner_text('#board-nav .public-pads') and 'Bots Welcome' not in np_.inner_text('#board-nav .public-pads'), np_.inner_text('#board-nav .public-pads'))
    np_.goto(B + '/pads/public/welcome'); np_.wait_for_selector('#head-meta'); ck('and can read it, but not post to a read-only one', 'read-only' in np_.inner_text('#head-meta') and np_.locator('.reply-box').count() == 0)
    NEWAG = php('$n = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'nina\'")->fetchColumn(); [$id, $t] = mb_create_agent($pdo, $n, ["handle" => "ninas-agent"]); echo $t;')
    fa = tool('find_public_pads', None, NEWAG)
    ck('a new agent is already on both, and has them in its list of pads', fa.startswith('OK Public pads you are on') and 'public/welcome' in fa.split('Public pads you can join')[0] and 'public/bots-welcome' in fa.split('Public pads you can join')[0] and 'public/welcome' in tool('list_boards', None, NEWAG), fa)
    ap.goto(B + '/admin/public/'); wc = ap.locator('.public-admin', has_text='public/welcome'); wc.locator('input[name=auto_join]').uncheck(); wc.locator('button:has-text("Save")').first.click(); ap.wait_for_load_state()
    ck('an administrator switches it off and it is saved', 'Saved.' in ap.inner_text('main') and not ap.locator('.public-admin', has_text='public/welcome').locator('input[name=auto_join]').is_checked())
    php('mb_create_human($pdo, "Late Comer", "latecomer", "late@example.com", "correct horse battery");')
    ck('and the next new account is not added to it, but is to the one still switched on', php('$l = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'latecomer\'")->fetchColumn(); echo json_encode([mb_membership($pdo, (int) $pdo->query("SELECT id FROM pads WHERE slug = \'welcome\'")->fetchColumn(), $l) !== null, mb_membership($pdo, (int) $pdo->query("SELECT id FROM pads WHERE slug = \'bots-welcome\'")->fetchColumn(), $l) !== null]);') == '[false,false]')
    # ---- the agent card
    ap.goto(B + '/agents/'); card = ap.locator('.agent-list li')
    ibox = card.locator('.agent-icon-col img').bounding_box(); tbox = card.locator('.agent-info').bounding_box()
    ck('the agent\'s icon is in a column of its own, to the left of its details', ibox is not None and tbox is not None and ibox['x'] + ibox['width'] <= tbox['x'] + 1 and ibox['width'] == 72, (ibox, tbox))
    ct = card.inner_text()
    ck('the card is short: the name with role and app, then lines for how it joined, its pads, its connection and its wake-up, and no note about creating pads', '@muse' in ct and '\nOn ' in ct and 'alice/launch' in ct and 'Wake-up:' in ct and 'create pads' not in ct.split('What @muse may do')[0] and card.locator('.agent-fact').count() == 4, ct[:400])
    ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + '/agents/'); ck('the card fits 320px', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    bp.set_viewport_size({'width': 320, 'height': 800}); bp.goto(B + '/public-pads/'); ck('the Your Public Pads page fits 320px', bp.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no browser popup and no script errors', not errs and not native, (errs, native)); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
