import sys, subprocess, json, re
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
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
    br = p.chromium.launch(); al = br.new_context(viewport={'width': 1280, 'height': 900}).new_page(); errs = []; al.on('pageerror', lambda e: errs.append(str(e))); al.on('dialog', lambda d: d.accept()); al.add_init_script("Object.defineProperty(window,'mashpadConfirm',{get(){return ()=>Promise.resolve(true)},set(){}})")
    signin(al, 'alice'); al.goto(B + '/manage/alice/solo-notes'); al.click('button:has-text("Show the invitation link")'); al.wait_for_selector('#invite-url'); link = al.inner_text('#invite-url').strip()
    al.click('button:has-text("Stop asking me")'); al.wait_for_selector('.notice')
    r = mcp('join_pad', {'pad': link, 'handle': 'bobs-assistant', 'acts_for': 'Bob'}); gtok = re.search(r'mp_a_\S+', r).group(0)
    w = mcp('whoami', {}, gtok); ck('a guest agent is told it is a guest and how its person can claim it', 'Responsible person: @alice.' in w and 'You are a guest' in w and 'accept_owner' in w, w)
    al.goto(B + '/agents/'); ck('the pad owner sees it as a guest that its person can claim', 'Guest on your pad' in al.inner_text('.agent-list') and 'says it acts for Bob' in al.inner_text('.agent-list') and 'Its person can claim it' in al.inner_text('.agent-list'))
    bob = br.new_context().new_page(); signin(bob, 'bob'); bob.goto(B + '/agents/'); bob.wait_for_selector('#claim-h')
    ck('a person with no agents still gets Claim an agent', "don't have any agents yet" in bob.inner_text('.manage') and bob.locator('button:has-text("Get a claim code")').count() == 1)
    bob.click('button:has-text("Get a claim code")'); bob.wait_for_selector('#claim-message'); msg = bob.inner_text('#claim-message'); code = re.search(r'code (\S+)', msg).group(1)
    ck('Get a claim code shows a code and a paste-ready message', re.fullmatch(r'[A-Z2-9]{4}-[A-Z2-9]{4}', code) and 'accept_owner' in msg and (B + '/mcp/') in msg and 'valid 10 minutes' in msg, msg)
    r = mcp('accept_owner', {'code': code}, MUSE); ck('an agent its owner set up cannot be claimed with it', r.startswith('ERR Not accepted: you already have a responsible person on Mashpad (@alice)') and 'Responsible person: @alice.' in mcp('whoami', {}, MUSE), r)
    ck('accepting needs a token', mcp('accept_owner', {'code': code}).startswith('ERR Accepting a claim needs your own Mashpad token'))
    r = mcp('accept_owner', {'code': code}, gtok); ck('the guest accepts the code', r.startswith('OK Done. @bob is now the person responsible for you (@bobs-assistant)') and 'alice/solo-notes' in r, r)
    w = mcp('whoami', {}, gtok); ck('it now belongs to the claimant, with the same token and pad, and is no longer a guest', 'Responsible person: @bob.' in w and 'alice/solo-notes' in w and 'You are a guest' not in w and 'since claimed' in w, w)
    ck('it can still read the pad', mcp('read_board', {'board': 'alice/solo-notes'}, gtok).startswith('OK'))
    bob.reload(); ck('it appears under the claimant\'s agents with token controls', '@bobs-assistant' in bob.inner_text('.agent-list') and 'Claimed by you' in bob.inner_text('.agent-list') and bob.locator('.agent-list button:has-text("Replace token")').count() == 1)
    al.reload(); ck('and has left the pad owner\'s agents', '@bobs-assistant' not in al.inner_text('.manage'))
    al.goto(B + '/pads/alice/solo-notes'); al.wait_for_selector('.pad-event'); ck('the pad records the change as an event', '@bobs-assistant is now managed by @bob, who claimed it' in al.inner_text('#transcript'))
    al.goto(B + '/manage/alice/solo-notes'); al.click('button.disclosure'); ck('the pad owner can still remove it from the pad', al.locator('button:has-text("Remove @bobs-assistant")').count() == 1)
    ck('the used code does nothing more', mcp('accept_owner', {'code': code}, gtok).startswith('ERR Not accepted: you already have a responsible person on Mashpad (@bob)'))
    tools = json.loads(subprocess.run(['curl', '-s', '-H', 'Content-Type: application/json', B + '/mcp/open/', '-d', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'], capture_output=True, text=True).stdout)['result']['tools']
    ck('accept_owner is listed for agents', 'accept_owner' in [t['name'] for t in tools])
    anon = br.new_context().new_page(); anon.goto(link); ck('the invitation page tells agents their person can claim them later', 'accept_owner' in anon.inner_text('main') and 'claim you' in anon.inner_text('main'))
    bob.set_viewport_size({'width': 320, 'height': 700}); bob.goto(B + '/agents/'); bob.click('button:has-text("Get a claim code")'); bob.wait_for_selector('#claim-message'); ck('no horizontal overflow at 320px with a claim code showing', bob.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
