import sys, subprocess, json, re, os, urllib.parse as up
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def curl(path, extra=()):
    r = subprocess.run(['curl', '-s', '-i', *extra, B + path], capture_output=True, text=True).stdout.replace('\r\n', '\n'); head, _, body = r.partition('\n\n'); return int(head.split()[1]), head, body
def mails(): return [json.loads(l)['body'] for l in open(FAKEBREVO_LOG)] if os.path.exists(FAKEBREVO_LOG) else []
def mcp(tool, args=None, method='tools/call'):
    p = {'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': {'name': tool, 'arguments': args or {}} if method == 'tools/call' else {}}
    r = json.loads(subprocess.run(['curl', '-s', '-H', 'Content-Type: application/json', B + '/mcp/open/', '-d', json.dumps(p)], capture_output=True, text=True).stdout)['result']
    return r['instructions'] if method == 'initialize' else (('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text'])
def signin(pg, who='alice'):
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
CHECK = subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; echo mb_check_guidance_joined(900);'], capture_output=True, text=True).stdout
with sync_playwright() as p:
    br = p.chromium.launch(); ctx = br.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page(); errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
    # --- signed out: the pads page sends you to sign in
    for path in ['/pads/', '/pads/alice/launch', '/pads/alice/launch?view=settings', '/pads/?b=old-open']:
        s, h, b = curl(path); loc = re.search(r'(?i)^location: (.*)$', h, re.M)
        ck(f'signed out, {path} redirects to sign-in carrying the page asked for', s == 302 and loc and loc.group(1).startswith('/signin/?next=') and up.unquote(loc.group(1).split('next=')[1]) == path, (s, loc.group(1) if loc else None))
    s, h, b = curl('/pads/'); ck('and shows no pads, no names, and not the old-pads message', 'band competitiveness' not in b and 'old-open' not in b and 'deleted after 30 days' not in b and '<h1>Your pads' not in b)
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_url('**/signin/**'); pg.fill('input[name=email]', 'alice@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/alice/launch')
    ck('signing in brings you back to the exact page', pg.url == B + '/pads/alice/launch')
    pg.goto(B + '/pads/'); pg.wait_for_selector('#board-nav a'); ck('signed in, the old open pad is still listed, so it can still be exported before it expires', 'old-open' in pg.inner_text('#board-nav').lower() or pg.locator('#board-nav a[href*="old-open"]').count() >= 1, pg.inner_text('#board-nav')[:200])
    pg.goto(B + '/pads/?b=old-open'); pg.wait_for_selector('main h1, main .inner', timeout=8000); ck('and it opens', pg.url.endswith('?b=old-open') and 'old-open' in (pg.inner_text('main') + pg.title()).lower())
    # --- Your pads
    pg.goto(B + '/agents/'); rail = pg.locator('aside.account-sidebar nav.site-pages').inner_text(); ck('the rail says "Your pads", not "All pads"', 'Your pads' in rail and 'All pads' not in rail, rail)
    s, h, b = curl('/pricing/'); ck('so does the Pricing header', '>Your pads</a>' in b and 'All pads' not in b)
    pg.goto(B + '/pads/alice/does-not-exist'); pg.wait_for_selector('main .empty', timeout=15000); ck('and the viewer\'s back link', pg.locator('main a:has-text("Your pads")').count() >= 1 and pg.locator('main a:has-text("All pads")').count() == 0)
    # --- home page and sign-in page
    anon = br.new_context().new_page(); anon.goto(B + '/'); ck('home page, signed out: the link says Sign in', anon.locator('.masthead a.sign-in-link').inner_text() == 'Sign in' and anon.locator('.masthead a.sign-in-link').get_attribute('href') == 'signin/')
    pg.goto(B + '/'); ck('home page, signed in: the link says Go to App and goes to the pads', pg.locator('.masthead a.sign-in-link').inner_text() == 'Go to App' and pg.locator('.masthead a.sign-in-link').get_attribute('href') == 'pads/')
    pg.click('.masthead a.sign-in-link'); pg.wait_for_url('**/pads/'); ck('and it works', True)
    anon.goto(B + '/signin/'); t = anon.inner_text('main'); ck('the sign-in page no longer says "Email reset is on its way", and keeps the working link', 'Email reset is on its way' not in t and 'site admin can reset' not in t and anon.locator('a:has-text("Forgot your password?")').count() == 1 and 'Create an account' in t)
    # --- reset: the email, the link, the page
    ck('the Brevo stand-in is running', len(mails()) == 0)
    anon.goto(B + '/reset/'); anon.fill('input[name=email]', 'bob@example.com'); anon.click('button:has-text("Send me a code")'); anon.wait_for_selector('#reset-code')
    m = mails()[-1]; code = re.search(r'(\d{6})$', m['subject']).group(1)
    ck('the email: the code, "Click here to enter your code and reset your password", the one link, and "This code expires in 15 minutes."', 'Your Mashpad password reset code is ' + code in m['textContent'] and 'Click here to enter your code and reset your password' in m['textContent'] and 'This code expires in 15 minutes.' in m['textContent']
       and re.findall(r'https?://\S+', m['textContent']) == [B + '/reset/?step=code'] and 'Type it on the page' not in m['textContent'] and 'works once and for' not in m['textContent'], m['textContent'])
    ck('its HTML version is a link on that whole sentence', f'<a href="{B}/reset/?step=code">Click here to enter your code and reset your password</a>' in m.get('htmlContent', ''), m.get('htmlContent'))
    ck('the code step reads short: no six-digit lecture, no autofill hint, a plain "Code" label', 'Six digits' not in anon.inner_text('main') and 'browser may offer' not in anon.inner_text('main') and anon.locator('label[for=reset-code]').inner_text() == 'Code' and 'It works once and for 15 minutes' not in anon.inner_text('main'), anon.inner_text('main'))
    link = re.findall(r'https?://\S+', m['textContent'])[0]
    ar = br.new_context(viewport={'width': 1280, 'height': 900}); a2 = ar.new_page(); a2.goto(link); a2.wait_for_selector('#reset-code')
    ck('clicking the link from the email opens the code step, with a box for the address and nothing secret in the address bar', a2.locator('input[name=email]').count() == 1 and a2.locator('input[name=email]').get_attribute('type') == 'email' and a2.url == B + '/reset/?step=code' and a2.evaluate("document.activeElement.name") == 'email')
    ck('and the code boxes are there', a2.locator('.code-boxes span').count() == 6 and 'Enter your email address and the code we emailed you' in a2.inner_text('main'))
    ar.grant_permissions(['clipboard-read', 'clipboard-write'], origin=B)
    a2.fill('input[name=email]', 'bob@example.com'); a2.focus('#reset-code'); a2.evaluate("(c) => navigator.clipboard.writeText(c.slice(0,3) + ' ' + c.slice(3))", code); a2.keyboard.press('Control+V')
    ck('a real clipboard paste of a code with a space in it fills all six boxes (the defect muse found)', ''.join(a2.locator('.code-boxes span').all_inner_texts()) == code and a2.input_value('#reset-code') == code, (a2.locator('.code-boxes span').all_inner_texts(), a2.input_value('#reset-code')))
    a2.fill('input[name=password]', 'bobs new long password'); a2.fill('input[name=password2]', 'bobs new long password'); a2.click('button:has-text("Save new password")'); a2.wait_for_url('**/signin/?reset=1')
    ck('typing the address and the code on that page completes the reset', 'Your password is changed' in a2.inner_text('main'))
    a2.fill('input[name=email]', 'bob@example.com'); a2.fill('input[name=password]', 'bobs new long password'); a2.click('button[type=submit]'); a2.wait_for_url('**/pads/'); ck('and the new password signs in', True)
    a3 = br.new_context().new_page(); a3.goto(B + '/reset/?step=code'); a3.fill('input[name=email]', 'bob@example.com'); a3.fill('#reset-code', '000000'); a3.fill('input[name=password]', 'another long password'); a3.fill('input[name=password2]', 'another long password'); a3.click('button:has-text("Save new password")'); a3.wait_for_selector('.error')
    ck('a wrong code from that page gets the same refusal, and keeps the address you typed', "isn't right, or it has expired" in a3.inner_text('.error') and 'bob@example.com' in a3.inner_text('main') and a3.locator('button:has-text("Send a new code")').count() == 1)
    es = br.new_context(); e = es.new_page(); signin(e, 'alice'); e.goto(B + '/email/'); t = e.inner_text('main')
    ck('Email settings says "code" now and says less', 'password-reset code' in t and 'password-reset link' not in t and 'These emails are on.' in t and 'through its open invitation' not in t, t)
    s, h, b = curl('/privacy/'); ck('the Privacy Policy says code too', 'password-reset code when you ask for one' in b and 'reset link' not in b)
    # --- #112: check regularly
    GUIDE = subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; echo mb_check_guidance(900);'], capture_output=True, text=True).stdout
    ins = mcp('x', method='initialize'); ck('the connect instructions say to check regularly, as a requirement, with the advice about agreeing an interval', 'Check your pads regularly.' in ins and 'nobody will notify you about new posts' in ins and GUIDE in ins and ' - ' not in ins.split('Check your pads regularly.')[1].split('To check for new messages')[0], ins[ins.index('Check your pads'):][:700])
    e.goto(B + '/manage/alice/solo-notes'); e.click('button:has-text("Show agent invitation")'); e.wait_for_selector('#agent-invitation'); lc = re.search(r'lobby code (\S+)', e.inner_text('#agent-invitation')).group(1)
    r = mcp('join_pad', {'pad': 'alice/solo-notes', 'lobby_code': lc, 'handle': 'codejoin'}); ck('a join by lobby code ends with the instruction', r.startswith('OK Joined') and r.rstrip().endswith(CHECK), r[-300:])
    e.click('button:has-text("Show the invitation link")'); e.wait_for_selector('#invite-url'); inv = e.inner_text('#invite-url').strip()
    r = mcp('join_pad', {'pad': inv, 'handle': 'waiter', 'acts_for': 'Sam'}); rq = re.search(r'\n([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})\n', r)
    ck('a request that is still waiting does not say "you are a member"', r.startswith('OK Your request to join') and CHECK not in r and rq, r[-300:])
    e.goto(B + '/pads/alice/solo-notes'); e.wait_for_selector('.pad-event .event-actions button'); e.locator('.pad-event', has_text='@waiter').locator('button:has-text("Approve")').click(); e.wait_for_function("[...document.querySelectorAll('.pad-event')].some(x => x.innerText.includes('@waiter (an agent acting for Sam) joined'))", timeout=10000)
    r = mcp('join_pad', {'request_code': rq.group(1)}); ck('collecting the token after approval ends with the instruction', r.startswith("OK You're in") and r.rstrip().endswith(CHECK), r[-300:])
    e.goto(B + '/manage/alice/solo-notes'); e.click('button:has-text("Stop asking me")'); e.wait_for_selector('.notice')
    r = mcp('join_pad', {'pad': inv, 'handle': 'instant', 'acts_for': 'Zed'}); ck('joining through the link with no approval step ends with it too', r.startswith('OK Joined') and r.rstrip().endswith(CHECK), r[-300:])
    # --- narrow screens
    pg.set_viewport_size({'width': 320, 'height': 700})
    for path in ['/', '/reset/?step=code', '/email/']:
        pg.goto(B + path); ck(f'{path} fits a 320px screen', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    pg.goto(B + '/reset/?step=code'); pg.screenshot(path='/tmp/reset_arrival.png')
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
