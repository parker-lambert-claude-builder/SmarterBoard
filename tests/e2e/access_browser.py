import sys, subprocess, json, re
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def show(pg, handle):
    # Your agents shows one agent at a time: choose it from the pull-down, if there is one
    if not pg.locator('#agent-picker').count(): return
    for v, t in pg.eval_on_selector_all('#agent-picker option', 'os => os.map(o => [o.value, o.textContent.trim()])'):
        if t == handle or t.startswith(handle + ' '):
            if pg.locator('#agent-picker').input_value() != v:
                pg.select_option('#agent-picker', value=v); pg.wait_for_load_state(); pg.wait_for_selector('.agent-list')
            return
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:400]))
def mcp(tool, args, token=None):
    cmd = ['curl', '-s', '-H', 'Content-Type: application/json'] + (['-H', 'Authorization: Bearer ' + token] if token else []) + [B + '/mcp/open/', '-d',
        json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}})]
    r = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)['result']; return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def signin(pg, who):
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
with sync_playwright() as p:
    br = p.chromium.launch(); al = br.new_context(viewport={'width': 1280, 'height': 900}).new_page(); errs = []; al.on('pageerror', lambda e: errs.append(str(e)))
    al.on('dialog', lambda d: d.accept()); al.add_init_script("Object.defineProperty(window,'mashpadConfirm',{get(){return ()=>Promise.resolve(true)},set(){}})")
    signin(al, 'alice'); al.goto(B + '/pads/alice/launch'); al.wait_for_selector('#transcript .msg'); al.click('nav.reading-nav a:has-text("Manage access")'); al.wait_for_selector('.manage h1')
    ck('the page is called Manage access', al.inner_text('.manage h1') == 'Manage access' and al.title().startswith('Manage access'))
    ck('four sections in the requested order', al.locator('.access-section h2').all_inner_texts() == ["Who's on this pad", 'Secure agent invitation', 'Invite people', 'Open invitation'], al.locator('.access-section h2').all_inner_texts())
    ck('the summary counts humans and agents in words', al.inner_text('.access-summary') == 'One human, one agent', al.inner_text('.access-summary'))
    ck('the member list is collapsed by default, behind a control that looks like a button', not al.locator('.member-list').is_visible() and al.locator('button.disclosure').get_attribute('aria-expanded') == 'false'
       and al.eval_on_selector('button.disclosure', 'e => getComputedStyle(e).borderTopWidth') != '0px' and al.eval_on_selector('button.disclosure', 'e => getComputedStyle(e).fontWeight') in ('400', 'normal'))
    al.click('button.disclosure'); ck('pressing it opens the list and the button now offers to hide it', al.locator('.member-list').is_visible() and al.inner_text('button.disclosure').strip() == 'Hide the list' and al.locator('button.disclosure').get_attribute('aria-expanded') == 'true')
    al.click('button.disclosure'); ck('pressing it again hides the list', not al.locator('.member-list').is_visible() and 'Show everyone' in al.inner_text('button.disclosure'))
    al.click('button.disclosure'); ck('opened, it lists members with their own controls', al.locator('.member-list li').count() == 2 and al.locator('button:has-text("Remove @muse")').is_visible() and al.locator('button:has-text("Remove @alice")').count() == 0)
    t = al.inner_text('.manage'); ck('"set up a new agent yourself" is retired, and agent token settings are not on this page', 'Set up a new agent' not in t and 'Replace token' not in t and 'Agent settings' not in t)
    ck('section copy matches the brief', "Invite your own agents with a code that's good for ten minutes." in t and 'Add people to your project.' in t and 'Caution: anybody who has this invitation link' in t)
    al.set_viewport_size({'width': 1280, 'height': 520}); al.locator('button:has-text("Show agent invitation")').scroll_into_view_if_needed()
    al.click('button:has-text("Show agent invitation")'); al.wait_for_selector('#agent-invitation'); inv = al.inner_text('#agent-invitation'); code = re.search(r'lobby code (\S+)', inv).group(1)
    ck('showing the agent invitation keeps you at that section, not the top of the page', al.evaluate('scrollY') > 100 and al.url.endswith('#lobby-h') and al.locator('#agent-invitation').is_visible() and al.evaluate("(() => { const r = document.querySelector('#lobby-h').getBoundingClientRect(); return r.top >= 0 && r.top < innerHeight })()"), (al.evaluate('scrollY'), al.url))
    al.click('button[data-copy="agent-invitation"]'); al.wait_for_function("document.querySelector('#lobby-h').closest('section').querySelector('.copy-status').textContent.length > 0")
    al.set_viewport_size({'width': 1280, 'height': 900})
    ck('Show agent invitation reveals the paste-ready message', (B + '/pads/alice/launch') in inv and 'valid 10 minutes' in inv and (B + '/mcp/') in inv)
    r = mcp('join_pad', {'pad': 'alice/launch', 'lobby_code': code, 'handle': 'helper'}); htok = re.search(r'mp_a_\S+', r).group(0)
    ck('an agent joins with it and belongs to the owner who made the code', 'Responsible person: @alice.' in mcp('whoami', {}, htok))
    al.fill('#add-person', 'sam@example.org'); al.click('.access-section:has(#add-person-h) button[type=submit]'); al.wait_for_selector('.error')
    ck('an email address gets a plain "not yet", shown in that section, and stays in the box', "Inviting by email isn't available yet" in al.inner_text('.access-section:has(#add-person-h) .error') and al.input_value('#add-person') == 'sam@example.org' and al.url.endswith('#add-person-h'))
    al.fill('#add-person', '@bob'); al.click('.access-section:has(#add-person-h) button[type=submit]'); al.wait_for_selector('.notice')
    ck('a @handle adds the person; the confirmation is in that section and the summary updates', '@bob added to this pad' in al.inner_text('.access-section:has(#add-person-h) .notice') and al.url.endswith('#add-person-h') and al.inner_text('.access-summary') == 'Two humans, two agents', al.inner_text('.access-summary'))
    ck('the link is not shown until asked for', al.locator('#invite-url').count() == 0)
    al.set_viewport_size({'width': 1280, 'height': 520}); al.locator('button:has-text("Show the invitation link")').scroll_into_view_if_needed()
    al.click('button:has-text("Show the invitation link")'); al.wait_for_selector('#invite-url'); link = al.inner_text('#invite-url').strip()
    ck('showing the link keeps you at the open invitation section', al.evaluate('scrollY') > 100 and al.url.endswith('#invite-h') and al.locator('#invite-url').is_visible())
    al.context.grant_permissions(['clipboard-read', 'clipboard-write']); al.click('button[data-copy="invite-url"]'); al.wait_for_function("document.querySelector('.invite-link .copy-status').textContent.length > 0")
    st = al.inner_text('.invite-link .copy-status'); ck('copying the link says so in words that fit people and agents', st == 'Link copied. Send it to the people you’re inviting, or to their agents.' and al.evaluate('navigator.clipboard.readText()') == link, st)
    al.set_viewport_size({'width': 1280, 'height': 900})
    ck('Show the invitation link reveals it, with Revoke and Regenerate', re.fullmatch(re.escape(B) + r'/join/[A-Za-z0-9]{32}', link) and al.locator('.invite-actions button:has-text("Revoke")').count() == 1 and al.locator('.invite-actions button:has-text("Regenerate")').count() == 1)
    ck('the caution says people ask to join while approval is on', 'can ask to join this pad' in al.inner_text('.invite-link') and 'You approve each request' in al.inner_text('.invite-link'))
    anon = br.new_context().new_page(); ck('the link works', anon.goto(link).status == 200)
    al.click('.invite-actions button:has-text("Regenerate")'); al.wait_for_selector('.notice'); al.click('button:has-text("Show the invitation link")'); al.wait_for_selector('#invite-url'); link2 = al.inner_text('#invite-url').strip()
    ck('Regenerate replaces the link and kills the old one', link2 != link and anon.goto(link).status == 404 and anon.goto(link2).status == 200)
    al.click('.invite-actions button:has-text("Revoke")'); al.wait_for_selector('.notice'); ck('Revoke switches it off', anon.goto(link2).status == 404 and 'open invitation is revoked' in al.inner_text('.invite-link'))
    al.click('button:has-text("Regenerate (it stays off)")'); al.wait_for_selector('.notice'); ck('regenerating a revoked invitation leaves it off', 'switched off until you switch it on' in al.inner_text('.manage') and al.locator('button:has-text("Switch it back on")').count() == 1)
    al.click('button:has-text("Switch it back on")'); al.wait_for_selector('.notice'); al.click('button:has-text("Stop asking me")'); al.wait_for_selector('.notice'); ck('with approval off the caution says they get in right away', 'They get in right away' in al.inner_text('.invite-link'))
    # a member who is not the owner
    bob = br.new_context().new_page(); bob.on('dialog', lambda d: d.accept()); bob.add_init_script("Object.defineProperty(window,'mashpadConfirm',{get(){return ()=>Promise.resolve(true)},set(){}})"); signin(bob, 'bob'); bob.goto(B + '/manage/alice/launch'); bob.wait_for_selector('.manage h1')
    ck('a member sees who is on the pad and the agent invitation, not the owner\'s sections', bob.locator('.access-section h2').all_inner_texts() == ["Who's on this pad", 'Secure agent invitation'] and '@alice owns this pad' in bob.inner_text('.manage'))
    bob.click('button:has-text("Show agent invitation")'); bob.wait_for_selector('#agent-invitation'); bcode = re.search(r'lobby code (\S+)', bob.inner_text('#agent-invitation')).group(1)
    r = mcp('join_pad', {'pad': 'alice/launch', 'lobby_code': bcode, 'handle': 'bobs-bot'}); btok = re.search(r'mp_a_\S+', r).group(0)
    ck('an agent that joins with a member\'s code belongs to that member', 'Responsible person: @bob.' in mcp('whoami', {}, btok) and mcp('read_board', {'board': 'alice/launch'}, btok).startswith('OK'))
    bob.goto(B + '/manage/alice/launch'); bob.click('button.disclosure')
    ck('a member can manage their own agent (a new token, removing it) and nobody else', bob.locator('.member-list button').all_inner_texts() == ['Issue a new token', 'Remove @bobs-bot'], bob.locator('.member-list button').all_inner_texts())
    refused = bob.evaluate("(async () => { const csrf = document.querySelector('input[name=csrf]').value; const out = []; for (const act of ['person_add','invite_show','invite_reset','invite_off','request_approve']) { const r = await fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({csrf, b:'alice/launch', act, handle:'alice'})}); out.push((await r.text()).includes('Only the pad')); } return out })()")
    ck('owner-only actions posted directly by a member are refused', refused == [True] * 5, refused)
    ck('the owner sees and can cancel a member\'s live code; a member sees only their own', 'made by another member' in (al.goto(B + '/manage/alice/launch') and al.inner_text('.manage')) and bob.locator('.lobby-active').count() == 1)
    # Your agents
    al.goto(B + '/pads/'); al.wait_for_selector('#account a:has-text("Your agents")'); al.click('#account a:has-text("Your agents")'); al.wait_for_selector('.agent-list')
    opts = al.eval_on_selector_all('#agent-picker option', 'os => os.map(o => o.textContent.trim())'); show(al, '@muse'); t = al.inner_text('.agent-list')
    ck('Your agents offers the owner\'s agents in a pull-down and shows the pads the chosen one is on, not the member\'s', any(o.startswith('@muse') for o in opts) and any(o.startswith('@helper') for o in opts) and not any('@bobs-bot' in o for o in opts) and 'alice/launch' in t and '@bobs-bot' not in t, (opts, t))
    show(al, '@helper'); al.locator('.agent-list li', has_text='@helper').locator('button:has-text("Replace token")').click(); al.wait_for_selector('.once code'); newtok = al.inner_text('.once code').strip()
    ck('Replace token shows a new token once and kills the old one', newtok.startswith('mp_a_') and newtok != htok and mcp('whoami', {}, htok).startswith('ERR') and 'You are @helper' in mcp('whoami', {}, newtok))
    helper = al.locator('.agent-list li', has_text='@helper'); helper.locator('input[name=can_create_pads]').check(); helper.locator('button:has-text("Save permissions")').click(); al.wait_for_selector('.notice')
    ck('pad creation is switched on from here', 'Can create pads: yes' in mcp('whoami', {}, newtok) and al.locator('.agent-list li', has_text='@helper').locator('input[name=can_create_pads]').is_checked())
    code = bob.evaluate("fetch('%s/agents/').then(r => r.text()).then(t => [t.includes('@bobs-bot'), t.includes('@helper')])" % B); ck('each person sees only their own agents there', code == [True, False], code)
    al.goto(B + '/manage/alice/launch'); al.click('button.disclosure'); al.click('button:has-text("Remove @helper")'); al.wait_for_selector('.access-section:has(#members-h) .notice')
    ck('after removing someone the page comes back at the list, still open, with the confirmation there', al.locator('.member-list').is_visible() and 'Removed from this pad' in al.inner_text('.access-section:has(#members-h) .notice') and al.url.endswith('#members-h'))
    al.goto(B + '/create/'); ck('the new-pad form has no example sentence', 'For example' not in al.inner_text('main') and 'checkout-qa' not in al.inner_text('main'))
    al.goto(B + '/pads/alice/solo-notes'); al.wait_for_selector('#brief'); al.click('#brief button:has-text("Write it")')
    ck('the About editor has one heading and no second label', al.locator('#brief-form label').count() == 0 and al.inner_text('#brief-form h2') == 'About this pad' and al.get_attribute('#brief-body', 'aria-labelledby') == 'brief-h')
    anon.goto(al.evaluate("''") or link2 if False else B + '/pads/');
    for pg_, url in ((al, '/manage/alice/launch'), (al, '/agents/'), (bob, '/manage/alice/launch')):
        pg_.set_viewport_size({'width': 320, 'height': 700}); pg_.goto(B + url); pg_.wait_for_selector('.manage h1')
        ck(f'no horizontal overflow at 320px on {url}', pg_.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    al.set_viewport_size({'width': 1100, 'height': 1000}); al.goto(B + '/manage/alice/launch'); al.screenshot(path='/tmp/access.png', full_page=True)
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
