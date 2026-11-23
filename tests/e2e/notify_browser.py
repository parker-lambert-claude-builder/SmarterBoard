import sys, subprocess, json, re, os
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:500]))
def mails(): return [json.loads(l)['body'] for l in open(FAKEBREVO_LOG)] if os.path.exists(FAKEBREVO_LOG) else []
def mcp(tool, args):
    r = json.loads(subprocess.run(['curl', '-s', '-H', 'Content-Type: application/json', B + '/mcp/open/', '-d', json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}})], capture_output=True, text=True).stdout)['result']
    return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
with sync_playwright() as p:
    br = p.chromium.launch(); pg = br.new_context(viewport={'width': 1280, 'height': 900}).new_page(); errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/signin/'); ck('sign-in offers "Forgot your password?"', pg.locator('a:has-text("Forgot your password?")').count() == 1)
    pg.click('a:has-text("Forgot your password?")'); pg.fill('input[name=email]', 'nobody@example.com'); pg.click('button:has-text("Send me a code")'); pg.wait_for_selector('h1:has-text("Enter your code")')
    unknown = pg.inner_text('main'); ck('an unknown address gets the same code page and no email', 'If nobody@example.com has a Mashpad account' in unknown and len(mails()) == 0)
    pg.goto(B + '/reset/'); pg.fill('input[name=email]', 'alice@example.com'); pg.click('button:has-text("Send me a code")'); pg.wait_for_selector('h1:has-text("Enter your code")')
    m = mails(); sub = re.fullmatch(r'Your Mashpad password reset code is (\d{6})', m[-1]['subject']) if m else None
    ck('the email\'s subject is "Your Mashpad password reset code is" and six digits', len(m) == 1 and m[0]['to'][0]['email'] == 'alice@example.com' and sub, m[-1]['subject'] if m else None)
    code = sub.group(1); ck('the email has one link, to the plain reset page, and the page reads the same as for an unknown address', re.findall(r'https?://\S+', m[-1]['textContent']) == [B + '/reset/?step=code'] and pg.inner_text('main').replace('alice@example.com', 'X') == unknown.replace('nobody@example.com', 'X'))
    ck('the code field is one real field a browser can autofill', pg.get_attribute('#reset-code', 'autocomplete') == 'one-time-code' and pg.get_attribute('#reset-code', 'inputmode') == 'numeric' and pg.get_attribute('#reset-code', 'maxlength') is None and pg.evaluate("document.activeElement.id") == 'reset-code')
    ck('it is drawn as six separate boxes, the first one marked as where typing goes', pg.locator('.code-boxes span').count() == 6 and pg.locator('.code-entry.enhanced').count() == 1 and pg.locator('.code-boxes span.active').count() == 1
       and pg.evaluate("[...document.querySelectorAll('.code-boxes span')].map(b => Math.round(b.getBoundingClientRect().width)).every(w => w >= 36)") and pg.evaluate("getComputedStyle(document.querySelector('#reset-code')).color") in ('rgba(0, 0, 0, 0)', 'transparent'))
    ck('every box is really visible: the field underneath paints nothing over them', pg.evaluate("""(() => { const i = getComputedStyle(document.querySelector('#reset-code')); if (i.backgroundColor !== 'rgba(0, 0, 0, 0)' || i.borderTopWidth !== '0px') return 'field paints: ' + i.backgroundColor + ' ' + i.borderTopWidth;
        return [...document.querySelectorAll('.code-boxes span')].every(b => { const r = b.getBoundingClientRect(); const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return top && top.id === 'reset-code' || top === b; }) })()""") is True)
    pg.keyboard.type('12a3'); ck('digits appear in the boxes as you type; letters are ignored', pg.locator('.code-boxes span').all_inner_texts() == ['1', '2', '3', '', '', ''] and pg.input_value('#reset-code') == '123' and pg.locator('.code-boxes span.filled').count() == 3
       and pg.evaluate("[...document.querySelectorAll('.code-boxes span')].findIndex(b => b.classList.contains('active'))") == 3)
    pg.keyboard.press('Backspace'); ck('Backspace clears the last box', pg.locator('.code-boxes span').all_inner_texts() == ['1', '2', '', '', '', ''])
    pg.fill('#reset-code', ''); pg.evaluate("(c) => { const i = document.querySelector('#reset-code'); i.focus(); const dt = new DataTransfer(); dt.setData('text/plain', c.slice(0,3) + ' ' + c.slice(3)); i.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true})); i.value = c.slice(0,3) + ' ' + c.slice(3); i.dispatchEvent(new Event('input', {bubbles: true})); }", code)
    ck('a pasted or auto-filled code, even with a space in it, fills all six boxes', ''.join(pg.locator('.code-boxes span').all_inner_texts()) == code and pg.input_value('#reset-code') == code)
    pg.screenshot(path='/tmp/code_entry.png')
    wrong = '000000' if code != '000000' else '111111'; pg.fill('#reset-code', wrong); pg.fill('input[name=password]', 'a new and longer password'); pg.fill('input[name=password2]', 'a new and longer password'); pg.click('button:has-text("Save new password")'); pg.wait_for_selector('.error')
    ck('a wrong code is refused and you stay on the code step', "isn't right, or it has expired" in pg.inner_text('.error') and pg.locator('.code-boxes span').count() == 6)
    pg.fill('#reset-code', code); pg.fill('input[name=password]', 'a new and longer password'); pg.fill('input[name=password2]', 'a different one entirely'); pg.click('button:has-text("Save new password")'); pg.wait_for_selector('.error')
    ck('mismatched passwords are caught without using up the code, which stays in its boxes', "don't match" in pg.inner_text('.error') and ''.join(pg.locator('.code-boxes span').all_inner_texts()) == code)
    pg.fill('input[name=password]', 'a new and longer password'); pg.fill('input[name=password2]', 'a new and longer password'); pg.click('button:has-text("Save new password")'); pg.wait_for_url('**/signin/?reset=1')
    ck('the right code saves the password and sends you to sign in', 'Your password is changed' in pg.inner_text('main'))
    pg.fill('input[name=email]', 'alice@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_selector('.error'); ck('the old password is refused', True)
    pg.fill('input[name=email]', 'alice@example.com'); pg.fill('input[name=password]', 'a new and longer password'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); ck('the new password signs in', True)
    nojs = br.new_context(java_script_enabled=False).new_page(); nojs.goto(B + '/reset/'); nojs.fill('input[name=email]', 'bob@example.com'); nojs.click('button:has-text("Send me a code")'); nojs.wait_for_selector('#reset-code')
    ck('without scripts the code is a plain visible field', nojs.locator('.code-entry.enhanced').count() == 0 and nojs.locator('#reset-code').is_visible() and not nojs.locator('.code-boxes').is_visible())
    nojs.click('button:has-text("Send a new code")'); nojs.wait_for_selector('.notice'); ck('Send a new code replaces the code and says so', 'a new code is on its way' in nojs.inner_text('.notice') and sum(1 for x in mails() if x['to'][0]['email'] == 'bob@example.com') == 2)
    # notification + unsubscribe
    pg.goto(B + '/pads/'); pg.wait_for_selector('#account a:has-text("Email settings")'); ck('the pads page links to Email settings', True)
    pg.goto(B + '/manage/alice/solo-notes'); pg.click('button:has-text("Show the invitation link")'); pg.wait_for_selector('#invite-url'); inv = pg.inner_text('#invite-url').strip()
    n = len(mails()); j = mcp('join_pad', {'pad': inv, 'handle': 'bodhi', 'acts_for': 'Dylan'}); m = mails()
    ck('a request to join emails the pad\'s owner', j.startswith('OK Your request to join') and len(m) == n + 1 and m[-1]['to'][0]['email'] == 'alice@example.com' and m[-1]['subject'] == '@bodhi asked to join Solo Notes' and B + '/pads/alice/solo-notes' in m[-1]['textContent'], m[-1] if len(m) > n else j)
    un = m[-1]['headers']['List-Unsubscribe'].strip('<>')
    ck('the email carries the one-click unsubscribe headers, and its body has no token link', re.fullmatch(r'http://127\.0\.0\.1:8081/email/\?t=[A-Za-z0-9_-]+', un) and m[-1]['headers']['List-Unsubscribe-Post'] == 'List-Unsubscribe=One-Click'
       and '?t=' not in m[-1]['textContent'] and B + '/email/' in m[-1]['textContent'] and 'Unsubscribe button' in m[-1]['textContent'], m[-1])
    anon = br.new_context().new_page(); anon.goto(un); t = anon.inner_text('main')
    ck('opening the header\'s address signed out shows the choice and does nothing yet', 'For @alice' in t and anon.locator('button:has-text("Stop these emails")').count() == 1 and 'These emails are on.' in t)
    n = len(mails()); mcp('join_pad', {'pad': inv, 'handle': 'second', 'acts_for': 'Ann'}); ck('so a mail scanner opening the link has not unsubscribed anyone', len(mails()) == n + 1)
    anon.click('button:has-text("Stop these emails")'); anon.wait_for_selector('.notice'); ck('one press stops them, with no sign-in', "won't email you about requests to join" in anon.inner_text('.notice') and anon.locator('button:has-text("Turn these emails back on")').count() == 1)
    n = len(mails()); j = mcp('join_pad', {'pad': inv, 'handle': 'third', 'acts_for': 'Zed'}); ck('the next request is still filed and sends no email', j.startswith('OK Your request to join') and len(mails()) == n)
    pg.goto(B + '/email/'); ck('signed in, Email settings shows them off', 'These emails are off' in pg.inner_text('main')); pg.click('button:has-text("Turn these emails back on")'); pg.wait_for_selector('.notice')
    out = subprocess.run(['curl', '-s', '-X', 'POST', '-d', 'List-Unsubscribe=One-Click', un], capture_output=True, text=True).stdout
    pg.goto(B + '/email/'); ck('a mail app\'s own Unsubscribe button (one-click POST) switches them off', out.startswith('Unsubscribed.') and 'These emails are off' in pg.inner_text('main'), out)
    r = anon.goto(B + '/email/?t=' + 'z' * 43); ck('a made-up link shows nobody\'s settings', "That link isn't valid" in anon.inner_text('main') and anon.locator('form').count() == 0)
    pg.set_viewport_size({'width': 390, 'height': 800})
    for path in ['/reset/', '/email/']:
        pg.goto(B + path); ck(f'{path} fits a 390px screen', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
