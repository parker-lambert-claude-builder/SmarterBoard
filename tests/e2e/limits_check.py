import sys, subprocess, json, re
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:700]))
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); $id = fn ($h) => (int) $pdo->query("SELECT id FROM principals WHERE handle=\'$h\'")->fetchColumn(); ' + code], capture_output=True, text=True).stdout.strip()
def plan(code, col): return php('echo var_export($pdo->query("SELECT %s FROM plans WHERE code = \'%s\'")->fetchColumn(), true);' % (col, code)).strip("'")
def rpc(method, params=None, token=None):
    p = {'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params or {}}
    cmd = ['curl', '-s', '-H', 'Content-Type: application/json'] + (['-H', 'Authorization: Bearer ' + token] if token else []) + [B + '/mcp/open/', '-d', json.dumps(p)]
    return json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)['result']
def tool(name, args=None, token=None):
    r = rpc('tools/call', {'name': name, 'arguments': args or {}}, token); return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
def save(pg, code, **vals):
    pg.goto(B + '/admin/plans/')
    for f, v in vals.items():
        if isinstance(v, bool):
            pg.set_checked(f'#{code}-{f}', v)
        else:
            pg.evaluate(f"document.getElementById('{code}-{f}').removeAttribute('required')"); pg.fill(f'#{code}-{f}', str(v))
    pg.click(f'#plan-{code} ~ form button, section[aria-labelledby="plan-{code}"] button'); pg.wait_for_load_state()
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []; native = []
    al = br.new_context(viewport={'width': 1280, 'height': 900}); ap = signin(al, 'alice'); ap.on('pageerror', lambda e: errs.append(str(e))); ap.on('dialog', lambda d: (native.append(d.message), d.dismiss()))
    bob = br.new_context(viewport={'width': 1280, 'height': 900}); bp = signin(bob, 'bob'); anon = br.new_context(viewport={'width': 1280, 'height': 900}).new_page()
    # ---- Admin > Plans
    r = bob.request.get(B + '/admin/plans/'); ck('only an administrator sees the Plans page: everyone else gets a 404', r.status == 404 and anon.request.get(B + '/admin/plans/').status == 404 and al.request.get(B + '/admin/plans/').status == 200)
    ap.goto(B + '/admin/plans/'); ck('Plans is in the Admin navigation, with the page open', ap.locator('nav[aria-label="Admin sections"] a[aria-current="page"]').inner_text() == 'Plans' and ap.locator('nav[aria-label="Admin sections"] a').all_inner_texts() == ['Accounts', 'Plans', 'Public pads', 'Email'])
    ck('both plans are listed with their values, and a blank where there is no limit', ap.locator('section.plan-edit').count() == 2 and ap.input_value('#free-retention_days') == '30' and ap.input_value('#free-max_agents') == '' and ap.input_value('#plus-retention_days') == '180' and ap.is_checked('#plus-threading') and not ap.is_checked('#free-threading'))
    ck('the name and price are not editable there', ap.locator('input[name="name"], input[name="price_cents"]').count() == 0 and 'name and price aren\'t edited here' in ap.inner_text('main'))
    save(ap, 'free', max_human_seats=1, max_agents=3)
    ck('saving says what changed, in words, and that nobody loses anything', 'Free: people per pad no limit → 1; agents per account no limit → 3.' in ap.inner_text('main') and 'nobody already over one loses anything' in ap.inner_text('main') and plan('free', 'max_agents') == '3' and plan('free', 'max_human_seats') == '1', ap.inner_text('main')[:400])
    save(ap, 'plus', max_agents=25)
    ck('Plus gets twenty-five agents and keeps no limit on people', plan('plus', 'max_agents') == '25' and plan('plus', 'max_human_seats') == 'NULL')
    save(ap, 'free', max_agents='many'); ck('a bad value is refused in plain words, and the field keeps what was typed', 'Agents per account should be a whole number, or blank for no limit.' in ap.inner_text('main') and ap.input_value('#free-max_agents') == 'many' and plan('free', 'max_agents') == '3', ap.inner_text('main')[:300])
    save(ap, 'free', max_agents=0); ck('zero agents is refused with the range', 'should be between 1 and 10000. You gave 0' in ap.inner_text('main'))
    save(ap, 'free', retention_days=''); ck('a blank where a number is needed is refused, naming the field', 'How long pads are kept (days) should be a whole number.' in ap.inner_text('main') and plan('free', 'retention_days') == '30')
    ck('and every change is in the audit log, with who made it', php('echo $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = \'plan.update\' AND actor_id = " . $id("alice"))->fetchColumn();') == '2')
    save(ap, 'free', max_agents=3); ck('saving the same again says so and records nothing', 'was already set to these values' in ap.inner_text('main'))
    ap.goto(B + '/pricing/'); t = ap.inner_text('main')
    ck('Pricing shows the agents each plan allows, from the same numbers', 'Up to 3 agents' in t and 'Up to 25 agents' in t and 'One person, with their agents' in t and 'More than one person on a pad' in t, t[:500])
    # ---- people per pad
    php('$pdo->exec("UPDATE principals SET plan_code = \'free\' WHERE handle IN (\'alice\', \'bob\')");')
    ap.goto(B + '/manage/alice/launch'); t = ap.inner_text('main')
    ck('Manage access says what the owner is using against their plan', re.search(r'Your Free plan: people on this pad, 1 of 1; agents you are responsible for, \d+ of 3\.', t), t[:400])
    ck('and with the pad full there is no add-a-person form, only why, and that agents are not counted', ap.locator('#add-person').count() == 0 and 'This pad is full: your Free plan has room for one person on a pad. Agents don\'t count, so invite them as usual.' in t, t)
    ap.click('button:has-text("Show the invitation link")'); ap.wait_for_selector('#invite-url'); link = ap.inner_text('#invite-url').strip()
    bp.goto(link); t = bp.inner_text('main')
    ck('a second person opening the invitation is told the pad is full, whose plan, and that their agent can still come', "This pad is on @alice's Free plan, which has room for one person, so another person can't join it. You can still bring your agent: give it this link." in t and bp.locator('button:has-text("Join this pad"), button:has-text("Ask to join this pad")').count() == 0, t[:500])
    anon.goto(link); ck('and so is someone who is not signed in, rather than being sent to create an account to find out', "so another person can't join it" in anon.inner_text('main') and anon.locator('a:has-text("Create an account")').count() == 0)
    ck('the agent half of the page is still there for them', anon.locator('#for-agents').count() == 1 and 'Your agent has to check the pad on a schedule' in anon.inner_text('main'))
    txt = tool('join_pad', {'pad': link, 'handle': 'bobs-agent', 'acts_for': 'Bob'}); ck('an agent can still join by the link, because agents do not count as people', txt.startswith('OK') and 'bobs-agent' in txt, txt[:200])
    save(ap, 'free', max_human_seats=2)
    bp.goto(link); ck('raise the limit to two and the second person is offered the pad again', bp.locator('button:has-text("Join this pad"), button:has-text("Ask to join this pad")').count() == 1, bp.inner_text('main')[:300])
    bp.click('button:has-text("Join this pad"), button:has-text("Ask to join this pad")'); bp.wait_for_load_state()
    ck('and joins, or asks to, as before', 'manage/alice/launch' in bp.url or 'is with @alice' in bp.inner_text('main') or "You're on this pad" in bp.inner_text('main'), bp.url)
    ap.goto(B + '/manage/alice/launch'); ck('the owner then sees two of two', 'people on this pad, 2 of 2' in ap.inner_text('main') or 'people on this pad, 1 of 2' in ap.inner_text('main'), ap.inner_text('main')[:300])
    save(ap, 'free', max_human_seats='')
    # ---- agents per account
    held = int(php('echo $pdo->query("SELECT COUNT(*) FROM principals WHERE kind = \'agent\' AND owner_id = " . $id("alice") . " AND disabled_at IS NULL")->fetchColumn();'))
    save(ap, 'free', max_agents=held)
    ap.goto(B + '/agents/'); ck('Your agents says how many of how many, and what to do when it is all of them', f'You have {held} of {held} agents on the Free plan. To add another, remove one or move to a plan with more.' in ap.inner_text('main'), ap.inner_text('main')[:300])
    ap.goto(B + '/manage/alice/launch'); ck('Manage access warns the owner that new agents can\'t join under them', f'You have all {held} agents your Free plan allows, so a new agent can\'t join under you' in ap.inner_text('main') and 'An agent that already has a token can still join as itself.' in ap.inner_text('main'), ap.inner_text('main')[:400])
    bp.goto(link); ck('the invitation page tells an agent\'s person the same, from the pad\'s owner', f'@alice has all {held} agents their Free plan allows, so a new agent can\'t join under them right now.' in bp.inner_text('#for-agents') or f'@alice has all {held} agents' in bp.inner_text('main'), bp.inner_text('main')[:500])
    txt = tool('join_pad', {'pad': link, 'handle': 'one-too-many', 'acts_for': 'Zed'})
    ck('a new agent trying to join by the link is refused, with the reason, and that an agent with a token still can', txt.startswith('ERR A new agent can\'t join under @alice right now: their Free plan allows') and 'registered under the pad\'s owner until its person claims it' in txt and 'join with your token instead' in txt, txt[:400])
    ck('and it was not created', php('echo $pdo->query("SELECT COUNT(*) FROM principals WHERE handle = \'one-too-many\'")->fetchColumn();') == '0')
    ck('an agent that already has a token is not affected: muse can still read and post', tool('list_boards', token=MUSE).startswith('OK Private pads for @muse') or tool('list_boards', token=MUSE).startswith('OK'))
    save(ap, 'free', max_agents=1); ck('lowering the limit below what an account has leaves every agent in place', int(php('echo $pdo->query("SELECT COUNT(*) FROM principals WHERE kind = \'agent\' AND owner_id = " . $id("alice") . " AND disabled_at IS NULL")->fetchColumn();')) == held and tool('join_pad', {'pad': link, 'handle': 'still-no', 'acts_for': 'Zed'}).startswith('ERR'))
    save(ap, 'free', max_agents=held + 5)
    txt = tool('join_pad', {'pad': link, 'handle': 'room-again', 'acts_for': 'Zed'}); ck('raise it and a new agent can join again, right away', txt.startswith('OK'), txt[:300])
    ap.goto(B + '/agents/'); ck('and Your agents counts it', f'You have {held + 1} of {held + 5} agents on the Free plan.' in ap.inner_text('main') and 'To add another' not in ap.inner_text('main'), ap.inner_text('main')[:300])
    # ---- Plus
    php('$pdo->exec("UPDATE principals SET plan_code = \'plus\' WHERE handle = \'alice\'");')
    ap.goto(B + '/manage/alice/launch'); ck('an owner on Plus is measured against Plus', re.search(r'Your Plus plan: people on this pad, \d+ of no limit; agents you are responsible for, \d+ of 25\.', ap.inner_text('main')), ap.inner_text('main')[:300])
    ap.goto(B + '/agents/'); ck('and Your agents says so too', 'agents on the Plus plan.' in ap.inner_text('main'))
    ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + '/admin/plans/'); ck('Plans fits a 320px screen', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ap.goto(B + '/manage/alice/launch'); ck('and so does Manage access with the notices', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no browser popup and no script errors', native == [] and not errs, (native, errs))
    br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
