import sys, subprocess, json, re
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def mcp(tool, args=None, method='tools/call', headers=()):
    p = {'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': ({'name': tool, 'arguments': args or {}} if method == 'tools/call' else {})}
    cmd = ['curl', '-s', '-H', 'Content-Type: application/json', *[x for h in headers for x in ('-H', h)], B + '/mcp/open/', '-d', json.dumps(p)]
    r = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)['result']
    return r if method != 'tools/call' else (('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text'])
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
def api(ctx, path): r = ctx.request.get(B + '/api/' + path); return r.status, (r.json() if 'json' in r.headers.get('content-type', '') else r.text())
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    anon = br.new_context(); bob = br.new_context(); al = br.new_context()
    bp = signin(bob, 'bob'); ap = signin(al, 'alice')
    names = lambda d: [b.get('key') or b.get('name') for b in d['boards']]
    # signed out
    s, d = api(anon, '?action=boards'); ck('signed out: the pads list has no old open pads', s == 200 and 'old-open' not in names(d), names(d))
    ck('signed out: reading one is a 404', api(anon, '?action=read&board=old-open')[0] == 404)
    ck('signed out: exporting one is a 404', api(anon, '?action=export&board=old-open&format=md')[0] == 404)
    r = anon.request.post(B + '/api/?action=post', data=json.dumps({'board': 'old-open', 'author': 'visitor', 'message': 'hi'}), headers={'Content-Type': 'application/json'}); ck('signed out: posting to one is refused', r.status == 404, r.status)
    # signed in, not an administrator, no private pad of that name
    s, d = api(bob, '?action=boards'); ck('a signed-in person who is not an administrator does not see them in the list', s == 200 and 'old-open' not in names(d), names(d))
    ck('and gets a 404 on read and on export, exactly like a pad that does not exist', api(bob, '?action=read&board=old-open')[0] == 404 and api(bob, '?action=export&board=old-open')[0] == 404 and api(bob, '?action=read&board=nothing-here')[0] == 404)
    bp.goto(B + '/pads/?b=old-open'); bp.wait_for_selector('main .empty'); ck('the browser page says there is no pad here, and shows no messages', "There’s no pad here that you can see" in bp.inner_text('main') and bp.locator('#transcript .msg').count() == 0)
    # the same bare name as one of their own private pads
    subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); $b = mb_principal($pdo, (int) $pdo->query("SELECT id FROM principals WHERE handle=\'bob\'")->fetchColumn()); $pad = mb_create_pad($pdo, $b, "Old Open"); mb_pad_post($pdo, mb_pad_for($pdo, "bob/old-open", (int) $b["id"]), $b, "my own private pad");'], check=True)
    s, d = api(bob, '?action=read&board=old-open'); ck('a bare name that matches an old open pad now reaches the person\'s own private pad of that name', s == 200 and d.get('key') == 'bob/old-open' and any('my own private pad' in m['body'] for m in d['messages']), (s, d if s != 200 else d.get('key')))
    bp.goto(B + '/pads/?b=old-open'); bp.wait_for_url('**/pads/bob/old-open'); ck('and the old ?b= bookmark takes them to it', True)
    # administrators
    s, d = api(al, '?action=boards'); ck('an administrator still sees them in the list', 'old-open' in names(d), names(d))
    s, d = api(al, '?action=read&board=old-open'); ck('can read one', s == 200 and d.get('key') == 'old-open' and len(d['messages']) >= 1, (s,))
    s, t = api(al, '?action=export&board=old-open&format=md'); ck('and export it, which is what they are kept for', s == 200 and isinstance(t, str) and len(t) > 20, (s, str(t)[:100]))
    r = al.request.post(B + '/api/?action=post', data=json.dumps({'board': 'old-open', 'message': 'hello'}), headers={'Content-Type': 'application/json', 'Origin': B}); ck('but cannot post to one: they are read-only', r.status == 403 and 'read-only' in r.text(), (r.status, r.text()[:120]))
    ap.goto(B + '/pads/?b=old-open'); ap.wait_for_selector('#transcript .msg'); ck('in the viewer an administrator opens it', 'old-open' in ap.url)
    # over MCP, no token
    for tool, args in [('read_board', {'board': 'old-open'}), ('post_message', {'board': 'old-open', 'message': 'hi', 'author': 'visitor'}), ('list_boards', {})]:
        r = mcp(tool, args); ck(f'MCP without a token, {tool}: told the old open pads are closed', r.startswith('ERR The older open pads are closed') and 'invitation' in r and 'join_pad' in r, r)
    r = mcp('whoami'); ck('whoami without a token says there is nothing to reach yet and how to get in', 'older open pads are closed' in r and 'invitation' in r, r)
    init = mcp('x', method='initialize')['instructions']; ck('the connect instructions no longer say the old pads can be used', 'The older open pads (no owner, no token) are closed.' in init and 'can be read and posted to without a token' not in init)
    tools = {t['name']: t for t in mcp('x', method='tools/list')['tools']}
    ck('tool descriptions no longer offer open pads, and post_message has no "author" field', 'open pad' not in json.dumps(tools) and 'author' not in tools['post_message']['inputSchema']['properties'], [k for k in tools if 'open pad' in json.dumps(tools[k])])
    ck('with a token, reading and listing private pads still works', mcp('read_board', {'board': 'alice/launch'}, headers=[f'Authorization: Bearer {MUSE}']).startswith('OK') and mcp('list_boards', {}, headers=[f'Authorization: Bearer {MUSE}']).startswith('OK'))
    ck('and a join by a token-less agent is not affected', mcp('join_pad', {'pad': 'alice/launch', 'lobby_code': 'AAAA-BBBB'}).startswith('ERR') and 'closed' not in mcp('join_pad', {'pad': 'alice/launch', 'lobby_code': 'AAAA-BBBB'}).split('\n')[0].lower())
    br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
