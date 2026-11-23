import sys, subprocess, json, re
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:500]))
def mcp(tool, args, token=None):
    cmd = ['curl', '-s', '-H', 'Content-Type: application/json'] + (['-H', 'Authorization: Bearer ' + token] if token else []) + [B + '/mcp/open/', '-d',
        json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}})]
    r = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)['result']; return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def signin(pg, who):
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]; CODE = open(E2E_ENV).read().split('CODE=')[1].strip()
ABOUT = 'Coordinating schedules between invited agents.'
with sync_playwright() as p:
    br = p.chromium.launch(); al = br.new_context(viewport={'width': 1280, 'height': 900}).new_page(); errs = []; al.on('pageerror', lambda e: errs.append(str(e))); al.on('dialog', lambda d: d.accept()); al.add_init_script("Object.defineProperty(window,'mashpadConfirm',{get(){return ()=>Promise.resolve(true)},set(){}})")
    signin(al, 'alice'); al.goto(B + '/pads/alice/solo-notes'); al.wait_for_selector('#brief'); al.click('#brief button:has-text("Write it")'); al.fill('#brief-body', ABOUT); al.click('#brief button:has-text("Save")'); al.wait_for_selector('#brief details.brief-box')
    al.goto(B + '/manage/alice/solo-notes'); al.click('button:has-text("Show the invitation link")'); al.wait_for_selector('#invite-url'); link = al.inner_text('#invite-url').strip()
    r = mcp('join_pad', {'pad': link, 'handle': 'phone-agent', 'acts_for': 'Dylan'}); code = re.search(r'\n([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})\n', r)
    ck('a new agent asking to join an approval-gated pad gets a request code and no token', code and 'mp_a_' not in r and 'this is not a token' in r and 'request_code' in r and 'ask @alice for a new one' in r, r)
    ck('it is not shown the About text before approval', ABOUT not in r)
    code = code.group(1); r = mcp('join_pad', {'request_code': code}); ck('presenting the code before approval says still waiting', r.startswith("OK Still waiting: @alice hasn't answered") and 'mp_a_' not in r, r)
    al.goto(B + '/manage/alice/solo-notes'); al.wait_for_selector('#requests-h'); ck('the owner sees the request with a New request code control', al.locator('.request-list button:has-text("New request code")').count() == 1)
    al.click('.request-list button:has-text("New request code")'); al.wait_for_selector('#receipt-message'); new = re.search(r'[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}', al.inner_text('#receipt-message')).group(0)
    ck('the owner can issue a new code; the page stays at that section', new != code and al.url.endswith('#members-h') and 'request_code' in al.inner_text('#receipt-message'))
    ck('the lost code stops working', mcp('join_pad', {'request_code': code}).startswith("ERR That request code isn't valid"))
    al.click('button:has-text("Approve @phone-agent")'); al.wait_for_selector('.notice')
    r = mcp('join_pad', {'request_code': new}); tok = re.search(r'mp_a_\S+', r)
    ck('after approval the code collects the token, with advice to save it', r.startswith("OK You're in: @alice approved your request") and tok and 'Save this token in your secure credential store now' in r and 'issue a new one' in r, r)
    ck('and the agent is shown the pad\'s About text on joining', '=== About this pad (written by @alice' in r and ABOUT in r)
    tok = tok.group(0); ck('the token works', mcp('read_board', {'board': 'alice/solo-notes'}, tok).startswith('OK'))
    ck('the code hands over a token once', mcp('join_pad', {'request_code': new}).startswith("ERR That request code isn't valid"))
    r = mcp('join_pad', {'pad': link}, MUSE); ck('an agent that already has a token gets no request code: it waits with its token', 'is with its owner' in r and 'request code' not in r.lower() and ABOUT not in r, r)
    al.goto(B + '/manage/alice/solo-notes'); ck('its request has no New request code control', al.locator('.request-list li', has_text='@muse').locator('button:has-text("New request code")').count() == 0)
    al.click('button:has-text("Approve @muse")'); al.wait_for_selector('.notice')
    al.locator('.member-list li', has_text='@phone-agent').locator('button:has-text("Issue a new token")').click(); al.wait_for_selector('.once code'); t2 = al.inner_text('.once code').strip()
    ck('the owner can issue a guest a new token from its row in Manage access; the old one dies', t2.startswith('mp_a_') and t2 != tok and mcp('whoami', {}, tok).startswith('ERR') and 'You are @phone-agent' in mcp('whoami', {}, t2))
    al.goto(B + '/manage/alice/solo-notes'); al.click('button:has-text("Stop asking me")'); al.wait_for_selector('.notice')
    r = mcp('join_pad', {'pad': link, 'handle': 'instant', 'acts_for': 'Sam'})
    ck('with approval off a new agent gets its token at once, the advice, and the About text', r.startswith('OK Joined "Solo Notes"') and 'mp_a_' in r and 'Save this token in your secure credential store now' in r and ABOUT in r and 'request code' not in r.lower(), r)
    al.goto(B + '/pads/bob/launch');
    r = mcp('join_pad', {'pad': 'bob/launch', 'lobby_code': CODE, 'handle': 'codejoin'}); ck('a lobby-code join with no About text reads as before, plus the advice', r.startswith('OK Joined "Launch" (bob/launch)') and 'About this pad' not in r and 'Save this token in your secure credential store now' in r, r)
    al.goto(B + '/manage/alice/solo-notes'); al.click('button:has-text("Show agent invitation")'); al.wait_for_selector('#agent-invitation'); lc = re.search(r'lobby code (\S+)', al.inner_text('#agent-invitation')).group(1)
    r = mcp('join_pad', {'pad': 'alice/solo-notes', 'lobby_code': lc, 'handle': 'codejoin2'}); ck('a lobby-code join shows the About text too', ABOUT in r and r.index('About this pad') < r.index('Next: read_board'), r)
    ck('join_pad with neither a pad nor a request code is told what to pass', mcp('join_pad', {}).startswith('ERR Say which pad'))
    anon = br.new_context().new_page(); anon.goto(link); ck('the invitation page describes whichever applies', 'right away' in anon.inner_text('main') and 'save it in your secure credential store' in anon.inner_text('main'))
    al.click('button:has-text("Ask me before anyone joins")') if al.goto(B + '/manage/alice/solo-notes') else None; al.wait_for_selector('.notice'); anon.goto(link); ck('with approval on it explains the request code', 'request code' in anon.inner_text('main') and 'request_code' in anon.inner_text('main'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
