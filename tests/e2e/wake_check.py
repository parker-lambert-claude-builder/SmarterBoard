import sys, subprocess, json, re, hmac, hashlib, os, time, urllib.parse as up
from playwright.sync_api import sync_playwright
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')
CAPTURE = os.environ.get('MASHPAD_TEST_WAKE_CAPTURE', '/tmp/wake-capture.log'); REPLY = os.environ.get('MASHPAD_TEST_WAKE_REPLY', '/tmp/wake-reply')
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:700]))
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); ' + code], capture_output=True, text=True).stdout.strip()
def sent(): return [json.loads(l) for l in open(CAPTURE).read().splitlines() if l.strip()] if os.path.exists(CAPTURE) else []
def clear(): open(CAPTURE, 'w').write(''); os.chmod(CAPTURE, 0o666)
def reply(status):
    if status is None:
        if os.path.exists(REPLY): os.remove(REPLY)
    else:
        open(REPLY, 'w').write(str(status)); os.chmod(REPLY, 0o666)
def wait_sent(n, secs=8):
    t = time.time()
    while time.time() - t < secs:
        if len(sent()) >= n: return sent()
        time.sleep(0.3)
    return sent()
def http(method, url, headers=None, data=None):
    cmd = ['curl', '-s', '-i', '-X', method, url]
    for k, v in (headers or {}).items(): cmd += ['-H', f'{k}: {v}']
    if data is not None: cmd += ['--data', data]
    raw = subprocess.run(cmd, capture_output=True, text=True).stdout.replace('\r\n', '\n'); head, _, body = raw.partition('\n\n')
    return int(head.split()[1]), head, body
def tool(name, args=None, bearer=None):
    s, h, b = http('POST', B + '/mcp/', {'Content-Type': 'application/json', **({'Authorization': 'Bearer ' + bearer} if bearer else {})}, json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args or {}}}))
    r = json.loads(b)['result']; return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
def post(pg, text):
    pg.click('#reply-top-body'); pg.fill('#reply-top-body', text); pg.keyboard.press('Control+Enter'); pg.wait_for_function("t => document.querySelector('#transcript').innerText.includes(t)", arg=text[:20])
php('$pdo->exec("UPDATE plans SET post_interval_seconds = 0");')          # this check posts a lot
clear(); reply(None)
AID = php('echo $pdo->query("SELECT id FROM principals WHERE handle = \'muse\'")->fetchColumn();')
PAGE = f'/agents/wake/?agent={AID}'
ROUTINE = 'https://api.anthropic.com/v1/claude_code/routines/trig_01ABCdef/fire'; HOOK = 'https://hooks.example.com/wake/abc123'
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []; native = []
    al = br.new_context(viewport={'width': 1280, 'height': 1000}); ap = signin(al, 'alice'); ap.on('pageerror', lambda e: errs.append(str(e))); ap.on('dialog', lambda d: (native.append(d.message), d.dismiss()))
    bob = br.new_context(); bp = signin(bob, 'bob'); anon = br.new_context().new_page()
    # ---- who sees it
    anon.goto(B + PAGE); ck('someone not signed in is asked to sign in', 'Sign in' in anon.inner_text('main') and 'Wake-up for' not in anon.inner_text('main'))
    bp.goto(B + PAGE); ck('another person is told it isn\'t their agent, and sees nothing of it', "That isn't one of your agents" in bp.inner_text('main') and 'muse' not in bp.inner_text('main'))
    ap.goto(B + '/agents/'); t = ap.inner_text('.agent-list')
    ck('Your agents says it isn\'t set up, with a link to do it', 'Wake-up: not set up.' in t and ap.locator(f'a[href$="{PAGE}"]').count() == 1, t[:300])
    ap.goto(B + PAGE); t = ap.inner_text('main')
    ck('the page explains what a wake-up is and isn\'t, and shows the pad with its default', 'Mashpad can\'t reach into a chat app' in t and 'never what was said' in t and 'alice/launch' in t and ap.input_value('#mode-' + php('echo $pdo->query("SELECT id FROM pads WHERE slug = \'launch\' AND owner_id = (SELECT id FROM principals WHERE handle = \'alice\')")->fetchColumn();')) == 'people', t[:400])
    # ---- setting it up: what is refused
    def fill_save(kind, url, token=''):
        ap.goto(B + PAGE); ap.check(f'input[name=kind][value={kind}]'); ap.fill('#wake-url', url); ap.fill('#wake-token', token); ap.click('button:has-text("Save")'); ap.wait_for_load_state()
    fill_save('routine', 'https://hooks.example.com/v1/claude_code/routines/trig_01ABCdef/fire', 'sk-ant-oat01-abcdef123456')
    ck('a routine at anyone\'s address but Anthropic\'s is refused, saying the token only goes there, and the address stays in the box', "The token is only ever sent to api.anthropic.com" in ap.inner_text('main') and ap.input_value('#wake-url').startswith('https://hooks.example.com'), ap.inner_text('main')[:500])
    fill_save('routine', ROUTINE, ''); ck('a routine without its token is refused', 'A routine needs its token' in ap.inner_text('main'))
    fill_save('webhook', 'http://hooks.example.com/wake/abc123'); ck('an http address is refused', 'must be an https address' in ap.inner_text('main') or 'it must be an https address' in ap.inner_text('main'), ap.inner_text('main')[:400])
    fill_save('webhook', 'https://127.0.0.1/wake'); ck('an IP address is refused', 'an IP address, not a hostname' in ap.inner_text('main'))
    ck('nothing was saved by any of that', php('echo $pdo->query("SELECT COUNT(*) FROM agent_wake_targets")->fetchColumn();') == '0')
    # ---- a routine
    fill_save('routine', ROUTINE, 'sk-ant-oat01-abcdef123456'); t = ap.inner_text('main')
    ck('a routine is saved: the page says so and where it wakes the agent, and nothing has been sent yet', 'Saved. Mashpad will wake @muse there.' in t and 'A Claude Code routine at api.anthropic.com.' in t and 'Nothing sent yet.' in t and sent() == [], t[:400])
    ck('its token is not on the page, and not in the database in the clear', 'sk-ant-oat01' not in ap.content() and 'sk-ant' not in php('echo $pdo->query("SELECT token_sealed FROM agent_wake_targets")->fetchColumn();') and php('echo $pdo->query("SELECT token_sealed FROM agent_wake_targets")->fetchColumn();').startswith('v1.'))
    ck('the key that seals it is in the config file, which only the site can read', 'secret_key' in open(SITE + '/data/config.php').read() and oct(os.stat(SITE + '/data/config.php').st_mode)[-3:] == '600')
    ap.click('button:has-text("Send a test wake-up")'); ap.wait_for_load_state(); s = wait_sent(1); t = ap.inner_text('main')
    ck('the test is sent at once, and the page says it was delivered', 'Test sent. Delivered (HTTP 200).' in t and len(s) == 1, t[:300])
    r = s[0]; body = json.loads(r['body'])
    ck('it went to the routine\'s address with the token and the headers the endpoint needs, and says it is a test', r['url'] == ROUTINE and r['method'] == 'POST' and 'Authorization: Bearer sk-ant-oat01-abcdef123456' in r['headers'] and 'anthropic-beta: experimental-cc-routine-2026-04-01' in r['headers'] and 'anthropic-version: 2023-06-01' in r['headers']
       and list(body) == ['text'] and 'This is a test wake-up from Mashpad for @muse. Nothing needs doing.' == body['text'], r)
    ck('Lately lists it', 'test' in ap.inner_text('.wake-log') and 'Delivered' in ap.inner_text('.wake-log'), ap.inner_text('.wake-log'))
    reply(401); ap.click('button:has-text("Send a test wake-up")'); ap.wait_for_load_state(); t = ap.inner_text('main')
    ck('a test the address refuses says so, in plain words', 'The test did not get through.' in t and 'HTTP 401: the address refused it, so check the token or secret' in t, t[:400])
    ck('and it hasn\'t paused anything or counted against it', 'Paused' not in t and php('echo $pdo->query("SELECT failures FROM agent_wake_targets")->fetchColumn();') == '0'); reply(None)
    # ---- a webhook, and a real post that wakes it
    fill_save('webhook', HOOK); t = ap.inner_text('main'); secret = (re.search(r'whsec_[A-Za-z0-9_-]+', t) or [None])[0]
    ck('a webhook gets a signing secret, shown once, with how to check it', secret and 'Signing secret' in t and 'Mashpad-Signature: t=<time>,v1=<signature>' in t and 'HMAC-SHA256' in t, t[:300])
    ap.goto(B + PAGE); ck('and it is not shown again', 'whsec_' not in ap.content() and 'A signed webhook at hooks.example.com.' in ap.inner_text('main'))
    ck('the secret is sealed in the database', php('echo $pdo->query("SELECT secret_sealed FROM agent_wake_targets")->fetchColumn();').startswith('v1.') and secret not in php('echo $pdo->query("SELECT secret_sealed FROM agent_wake_targets")->fetchColumn();'))
    clear()
    pad = al.new_page(); pad.goto(B + '/pads/alice/launch'); pad.wait_for_selector('#reply-top-body'); post(pad, 'Secret plans: the launch is on Tuesday.')
    s = wait_sent(1)
    ck('a person\'s post wakes the agent: one request, to the webhook address', len(s) == 1 and s[0]['url'] == HOOK, s)
    if s:
        wb = json.loads(s[0]['body']); sig = [h for h in s[0]['headers'] if h.startswith('Mashpad-Signature: ')][0]; m = re.search(r't=(\d+),v1=([0-9a-f]{64})', sig)
        ck('it is signed over the time and the exact body with the secret the person was shown', m and hmac.compare_digest(hmac.new(secret.encode(), (m.group(1) + '.' + s[0]['body']).encode(), hashlib.sha256).hexdigest(), m.group(2)))
        ck('it says who, where and why, and carries nothing that was said', wb['type'] == 'wake' and wb['reason'] == 'person' and wb['agent'] == '@muse' and wb['pad'] == 'alice/launch' and wb['from'] == '@alice' and wb['pad_url'] == B + '/pads/alice/launch' and 'Tuesday' not in s[0]['body'] and 'Secret plans' not in s[0]['body'], wb)
    post(pad, 'Another one, straight after.'); time.sleep(2)
    ck('a second post doesn\'t wake it again while it hasn\'t read the pad', len(sent()) == 1)
    ck('the agent reading the pad over MCP is what releases it', tool('read_board', {'board': 'alice/launch'}, MUSE).startswith('OK'))
    post(pad, 'Hello @muse, are you there?'); s = wait_sent(2)
    ck('and then a mention wakes it, as a mention', len(s) == 2 and json.loads(s[1]['body'])['reason'] == 'mention' and 'mentioned you in alice/launch' in json.loads(s[1]['body'])['message'], s[-1:])
    ap.goto(B + PAGE); t = ap.inner_text('.wake-log')
    ck('Lately shows them, with the pad, who and why', 'alice/launch' in t and '@alice' in t and 'mentioned' in t and 'Delivered' in t, t)
    # ---- what wakes it, per pad
    padid = php('echo $pdo->query("SELECT id FROM pads WHERE slug = \'launch\' AND owner_id = (SELECT id FROM principals WHERE handle = \'alice\')")->fetchColumn();')
    ap.select_option('#mode-' + padid, 'off'); ap.click('form:has(#mode-' + padid + ') button'); ap.wait_for_load_state(); ck('what wakes it is saved per pad', 'Saved what wakes @muse on each pad.' in ap.inner_text('main') and php('echo $pdo->query("SELECT wake_mode FROM pad_members WHERE principal_id = ' + AID + ' AND pad_id = ' + padid + '")->fetchColumn();') == 'off')
    tool('read_board', {'board': 'alice/launch'}, MUSE); n = len(sent()); post(pad, 'Nobody should wake for this.'); time.sleep(2)
    ck('with "Never" a post wakes nobody', len(sent()) == n)
    ap.select_option('#mode-' + padid, 'people'); ap.click('form:has(#mode-' + padid + ') button'); ap.wait_for_load_state()
    ap.click('button:has-text("Pause")'); ap.wait_for_load_state(); ck('a paused wake-up says so', 'Paused' in ap.inner_text('main') and 'Paused by you.' in ap.inner_text('main'))
    tool('read_board', {'board': 'alice/launch'}, MUSE); n = len(sent()); post(pad, 'While paused.'); time.sleep(2); ck('and wakes nobody', len(sent()) == n)
    ap.click('button:has-text("Resume")'); ap.wait_for_load_state(); ck('resuming turns it back on', 'Resumed.' in ap.inner_text('main') and 'Paused' not in ap.inner_text('.agent-controls'))
    # ---- when it keeps failing
    php('$pdo->exec("UPDATE agent_wake_targets SET paused_at = UTC_TIMESTAMP(), failures = 5, pause_reason = \'5 wake-ups in a row could not be delivered. The last: HTTP 404: nothing is there any more\'");')
    ap.goto(B + '/pads/'); ap.wait_for_selector('.home-needs'); t = ap.inner_text('.home-needs')
    ck('a wake-up paused by failures is something that needs the person, with a link to it', "@muse's wake-up is paused after repeated failures" in t and ap.locator('.home-needs a[href$="' + PAGE + '"]').count() == 1, t)
    ap.goto(B + '/agents/'); ck('Your agents says it is paused', 'Wake-up: paused.' in ap.inner_text('.agent-list') and 'Manage it' in ap.inner_text('.agent-list'))
    ap.goto(B + PAGE); t = ap.inner_text('main'); ck('the page says why, and what to do', '5 wake-ups in a row could not be delivered. The last: HTTP 404' in t and 'Check the address and the token, send a test, then resume.' in t)
    ap.click('button:has-text("Resume")'); ap.wait_for_load_state(); ap.goto(B + '/pads/'); ap.wait_for_selector('.home-quiet, .home-block'); ck('resuming takes it off the list', 'wake-up is paused' not in ap.inner_text('main'))
    # ---- what the agent is told
    w = tool('whoami', None, MUSE)
    ck('whoami tells the agent its wake-up is on, how, and to keep checking', 'Wake-up: on, through a signed webhook at hooks.example.com' in w and 'Keep checking your pads with read_board as well.' in w, w[-500:])
    r = tool('update_profile', {'webhook_url': 'https://suggested.example.com/hook'}, MUSE)
    ck('an address the agent suggests is saved but not used, and it is told so', 'Your suggested wake-up address is saved. Mashpad calls nothing until your person sets up your wake-up' in r, r[-300:])
    ap.goto(B + '/agents/'); n0 = php('echo $pdo->query("SELECT COUNT(*) FROM agent_wake_targets")->fetchColumn();')
    ck('and the person can see what it suggested', n0 == '1')
    s2, h2, b2 = http('POST', B + '/mcp/', {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + MUSE}, json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'})); tools = json.loads(b2)['result']['tools']
    ck('the tool descriptions no longer say it is reserved or not called', 'Reserved for a later release' not in b2 and 'not called yet' not in b2 and 'Your person sets up your wake-up' in b2)
    # ---- removing it
    ap.goto(B + PAGE); ap.click('button:has-text("Remove")'); ap.wait_for_selector('.mp-modal'); dl = ap.inner_text('.mp-modal')
    ck('removing asks first, in Mashpad\'s own dialog, and says what happens', 'Remove the wake-up for @muse?' in dl and 'deletes the token or secret it kept' in dl and native == [], dl)
    ap.click('.mp-modal button:has-text("Remove")'); ap.wait_for_load_state()
    ck('and it is gone, and the page says it was removed', 'Removed.' in ap.inner_text('main') and 'Not set up.' in ap.inner_text('main') and php('echo $pdo->query("SELECT COUNT(*) FROM agent_wake_targets")->fetchColumn();') == '0')
    w = tool('whoami', None, MUSE); ck('and whoami says it is not set up, and who can', 'Wake-up: not set up. Your person can set one up on their Your agents page' in w)
    ap.goto(B + PAGE); ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + PAGE); ck('the page fits a 320px screen', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ap.set_viewport_size({'width': 1280, 'height': 1000})
    # ---- cron, plans, pricing, privacy
    fill_save('webhook', HOOK)
    php('$pdo->exec("INSERT INTO wake_log (agent_id, pad_id, post_seq, from_id, reason, status, next_try_at) SELECT " . ' + AID + ' . ", p.id, 1, (SELECT id FROM principals WHERE handle = \'alice\'), \'person\', \'pending\', UTC_TIMESTAMP() FROM pads p WHERE p.slug = \'launch\' AND p.owner_id = (SELECT id FROM principals WHERE handle = \'alice\')");')
    clear(); env = {**os.environ, 'MASHPAD_TEST_WAKE_CAPTURE': CAPTURE}
    out = subprocess.run(['php', SITE + '/bin/wake-cron.php'], capture_output=True, text=True, env=env).stdout.strip()
    ck('the cron script sends what is waiting, and reports it', json.loads(out)['sent'] == 1 and json.loads(out)['ok'] is True and len(sent()) == 1 and json.loads(out)['pruned'] == 0, out)
    s, h, b = http('GET', B + '/bin/wake-cron.php'); ck('opened in a browser it does nothing: not found', s == 404 and b.strip() == '', (s, b[:100]))
    ap.goto(B + '/admin/plans/'); ck('Admin > Plans edits the daily limit: 20 on Free, 200 on Plus', ap.input_value('#free-max_wakes_per_day') == '20' and ap.input_value('#plus-max_wakes_per_day') == '200' and 'Wake-ups per agent per day' in ap.inner_text('main'))
    ap.goto(B + '/pricing/'); t = ap.inner_text('main'); ck('Pricing says how many a day', 'Wake your agents by web request, up to 20 a day each' in t and 'up to 200 a day each' in t, t[:500])
    ap.goto(B + '/privacy/'); t = ap.inner_text('main'); ck('the privacy page says what is kept, and that a wake-up never carries what was said', 'sealed, never in the clear' in t and 'never anything that was said' in t)
    ck('no browser popup and no script errors', native == [] and not errs, (native, errs))
    br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
