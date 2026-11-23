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
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
with sync_playwright() as p:
    br = p.chromium.launch(); al = br.new_context(viewport={'width': 1280, 'height': 900}).new_page(); errs = []; al.on('pageerror', lambda e: errs.append(str(e)))
    native = []; al.on('dialog', lambda d: (native.append(d.message), d.dismiss()))
    signin(al, 'alice'); al.goto(B + '/manage/alice/launch'); al.click('button.disclosure'); al.click('button:has-text("Remove @muse")'); al.wait_for_selector('.mp-modal')
    m = al.inner_text('.mp-modal'); ck('removing a member opens Mashpad\'s own dialog with a title, the consequence, and named buttons', 'Remove from this pad?' in m and 'Their earlier posts stay' in m and al.locator('.mp-modal button').all_inner_texts() == ['Cancel', 'Remove'], m)
    ck('it is a labelled modal dialog and Cancel has focus', al.get_attribute('.mp-modal', 'role') == 'alertdialog' and al.get_attribute('.mp-modal', 'aria-modal') == 'true' and al.evaluate("document.activeElement.className") == 'mp-modal-cancel')
    al.keyboard.press('Tab'); al.keyboard.press('Tab'); al.keyboard.press('Tab'); ck('Tab stays inside the dialog', al.evaluate("document.activeElement.closest('.mp-modal') !== null"))
    al.keyboard.press('Escape'); ck('Escape closes it, nothing happens, and focus returns to the button', al.locator('.mp-modal').count() == 0 and al.locator('button:has-text("Remove @muse")').count() == 1 and al.evaluate("document.activeElement.textContent") == 'Remove @muse')
    al.click('button:has-text("Remove @muse")'); al.mouse.click(5, 5); ck('a click outside the box cancels', al.locator('.mp-modal').count() == 0 and al.locator('button:has-text("Remove @muse")').count() == 1)
    al.click('button:has-text("Remove @muse")'); al.click('.mp-modal-ok'); al.wait_for_selector('.access-section:has(#members-h) .notice'); ck('agreeing carries out the action', 'Removed from this pad' in al.inner_text('.access-section:has(#members-h) .notice'))
    al.goto(B + '/agents/'); row = al.locator('.agent-list li', has_text='@muse'); ck('Your agents says Remove agent, not Delete agent', row.locator('button:has-text("Remove agent")').count() == 1 and 'Delete agent' not in al.inner_text('.manage'))
    row.locator('button:has-text("Disconnect")').click(); al.wait_for_selector('.mp-modal'); ck('revoking a token asks in the same dialog', "Disconnect @muse?" in al.inner_text('.mp-modal')); al.click('.mp-modal-cancel')
    row.locator('button:has-text("Remove agent")').click(); al.wait_for_selector('.mp-modal'); m = al.inner_text('.mp-modal')
    ck('Remove agent explains what happens', 'Remove @muse from your workspace?' in m and 'comes off every pad' in m and 'free to use again' in m and al.locator('.mp-modal-ok').inner_text() == 'Remove agent' and 'danger' in al.get_attribute('.mp-modal-ok', 'class'), m)
    al.click('.mp-modal-ok'); al.wait_for_selector('.notice'); ck('and removes it', '@muse is removed from your workspace' in al.inner_text('.notice') and '@muse' not in al.inner_text('.manage').replace(al.inner_text('.notice'), ''))
    ck('no browser popup was shown at any point', native == [], native)
    # in-pad approve / decline
    al.goto(B + '/manage/alice/solo-notes'); al.click('button:has-text("Show the invitation link")'); al.wait_for_selector('#invite-url'); link = al.inner_text('#invite-url').strip()
    r1 = mcp('join_pad', {'pad': link, 'handle': 'bodhi', 'acts_for': 'Dylan Rhodes'}); c1 = re.search(r'\n([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})\n', r1).group(1)
    mcp('join_pad', {'pad': link, 'handle': 'pushy', 'acts_for': 'Nobody'})
    bob = br.new_context().new_page(); signin(bob, 'bob'); al.goto(B + '/manage/alice/solo-notes'); al.fill('#add-person', '@bob'); al.click('.access-section:has(#add-person-h) button[type=submit]'); al.wait_for_selector('.notice')
    al.goto(B + '/pads/alice/solo-notes'); al.wait_for_selector('.pad-event .event-actions button')
    first = al.locator('.pad-event', has_text='@bodhi'); ck('the owner sees Approve and Decline on the request line itself', first.locator('button').all_inner_texts() == ['Approve', 'Decline'] and 'approve or decline here' in first.inner_text(), first.inner_text())
    bob.goto(B + '/pads/alice/solo-notes'); bob.wait_for_selector('.pad-event'); ck('another member sees the request but no buttons', bob.locator('.pad-event', has_text='@bodhi').count() == 1 and bob.locator('.event-actions button').count() == 0)
    code = bob.evaluate("fetch('%s/api/?action=request', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({board:'alice/solo-notes', request: 1, approve: true})}).then(async r => r.status + ' ' + (await r.text()))" % B)
    ck('a member approving directly is refused', code.startswith('403') and 'Only the pad' in code, code)
    first.locator('button:has-text("Approve")').click(); al.wait_for_function("[...document.querySelectorAll('.pad-event')].some(e => e.innerText.includes('@bodhi (an agent acting for Dylan Rhodes) joined through the invite link'))", timeout=10000)
    ck('Approve admits the agent without leaving the conversation, and the buttons go from that line', al.locator('.pad-event', has_text='@bodhi (an agent acting for Dylan Rhodes) asked').locator('button').count() == 0 and al.url == B + '/pads/alice/solo-notes')
    ck('the agent can now collect its token', mcp('join_pad', {'request_code': c1}).startswith("OK You're in"))
    second = al.locator('.pad-event', has_text='@pushy (an agent acting for Nobody) asked'); second.locator('button:has-text("Decline")').click(); al.wait_for_selector('.mp-modal')
    ck('Decline asks first, in the dialog', 'Decline this request?' in al.inner_text('.mp-modal')); al.click('.mp-modal-cancel'); ck('cancelling leaves the request waiting', second.locator('button').count() == 2)
    second.locator('button:has-text("Decline")').click(); al.click('.mp-modal-ok'); al.wait_for_function("[...document.querySelectorAll('.pad-event')].some(e => e.innerText.includes(\"request to join was declined by @alice\"))", timeout=10000)
    ck('declining records the outcome in the pad and clears the buttons', second.locator('button').count() == 0)
    bob.wait_for_function("[...document.querySelectorAll('.pad-event')].some(e => e.innerText.includes('declined by @alice'))", timeout=10000); ck('other members see the outcome too', True)
    al.goto(B + '/manage/alice/solo-notes'); ck('Manage access shows nothing left waiting', al.locator('#requests-h').count() == 0)
    al.set_viewport_size({'width': 320, 'height': 700}); al.goto(B + '/agents/'); ck('the dialog fits a 320px screen', True)
    ck('no browser popup at any point, and no script errors', native == [] and not errs, (native, errs)); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
