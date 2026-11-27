import sys, subprocess, json, re, base64, hashlib, os, urllib.parse as up
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
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def http(method, url, headers=None, data=None):
    cmd = ['curl', '-s', '-i', '-X', method, url]
    for k, v in (headers or {}).items(): cmd += ['-H', f'{k}: {v}']
    if data is not None: cmd += ['--data', data]
    raw = subprocess.run(cmd, capture_output=True, text=True).stdout
    raw = raw.replace('\r\n', '\n'); head, _, body = raw.partition('\n\n')
    return int(head.split()[1]), head, body
def rpc(url, method, params=None, bearer=None):
    return http('POST', url, {'Content-Type': 'application/json', **({'Authorization': 'Bearer ' + bearer} if bearer else {})}, json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params or {}}))
def tool(url, name, args=None, bearer=None):
    s, h, b = rpc(url, 'tools/call', {'name': name, 'arguments': args or {}}, bearer)
    if s != 200: return f'HTTP {s}'
    r = json.loads(b)['result']; return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def signin(pg, who):
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
b64 = lambda b: base64.urlsafe_b64encode(b).rstrip(b'=').decode()
def pkce(): v = b64(os.urandom(40)); return v, b64(hashlib.sha256(v.encode()).digest())
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
CB = 'http://localhost:8081/callback'
def authorize_url(cid, chal, state='st4te', redirect=CB): return B + '/oauth/authorize/?' + up.urlencode({'response_type': 'code', 'client_id': cid, 'redirect_uri': redirect, 'code_challenge': chal, 'code_challenge_method': 'S256', 'state': state, 'scope': 'pads', 'resource': B + '/mcp/'})
def exchange(cid, code, verifier): s, h, b = http('POST', B + '/oauth/token/', {'Content-Type': 'application/x-www-form-urlencoded'}, up.urlencode({'grant_type': 'authorization_code', 'client_id': cid, 'code': code, 'redirect_uri': CB, 'code_verifier': verifier})); return s, json.loads(b)
with sync_playwright() as p:
    br = p.chromium.launch(); ctx = br.new_context(viewport={'width': 1280, 'height': 950}); al = ctx.new_page(); errs = []; al.on('pageerror', lambda e: errs.append(str(e)))
    ctx.route('https://app.example/**', lambda r: r.fulfill(status=200, content_type='text/plain', body='back in the app'))
    # 1. discovery, as an app would do it
    s, h, b = rpc(B + '/mcp/', 'initialize'); m = re.search(r'resource_metadata="([^"]+)"', h)
    ck('mcp/ with no credentials answers 401 and says where the sign-in details are', s == 401 and 'WWW-Authenticate: Bearer' in h and m and m.group(1) == B + '/.well-known/oauth-protected-resource/mcp', h)
    s, h, b = http('GET', m.group(1)); rm = json.loads(b); ck('the resource metadata is served at the well-known address', s == 200 and rm['resource'] == B + '/mcp/' and rm['authorization_servers'] == [B], b)
    s, h, b = http('GET', B + '/.well-known/oauth-protected-resource'); ck('and at the root well-known address', s == 200 and json.loads(b)['resource'] == B + '/mcp/')
    s, h, b = http('GET', rm['authorization_servers'][0] + '/.well-known/oauth-authorization-server'); am = json.loads(b)
    ck('the authorization server metadata lists the endpoints', s == 200 and am['authorization_endpoint'] == B + '/oauth/authorize/' and am['token_endpoint'] == B + '/oauth/token/' and am['registration_endpoint'] == B + '/oauth/register/' and am['code_challenge_methods_supported'] == ['S256'], b)
    s, h, b = http('POST', am['registration_endpoint'], {'Content-Type': 'application/json'}, json.dumps({'client_name': 'Bodhi <test>', 'redirect_uris': [CB]})); cid = json.loads(b).get('client_id', '')
    ck('the app registers itself', s == 201 and cid.startswith('mpc_'), b)
    # 2. signed-out person is sent to sign in and comes back
    v, c = pkce(); al.goto(authorize_url(cid, c)); t = al.inner_text('main')
    ck('signed out, the consent page offers sign-in or the invitation-link route, and names the app safely', 'Bodhi <test>' in t and 'localhost' in t and 'Sign in' in t and 'Someone invited me to their pad' in t and al.locator('main b, main test').count() == 0, t[:300])
    al.click('a:has-text("Sign in")'); al.fill('input[name=email]', 'alice@example.com'); al.fill('input[name=password]', 'correct horse battery'); al.click('button[type=submit]'); al.wait_for_url('**/oauth/authorize/**')
    ck('after signing in the person is back on the consent page with the request intact', 'Which agent is this?' in al.inner_text('main') and 'client_id=' + cid in al.url and 'code_challenge=' in al.url)
    ck('they can pick an existing agent or a new one, and which pads', al.locator('input[name=agent]').count() == 2 and '@muse' in al.inner_text('main') and al.locator('input[name="pads[]"]').count() == 2)
    ck('a new agent is the default; an existing one is never preselected and is labelled as reconnecting', al.locator('input[name=agent][value=new]').is_checked() and al.locator('input[name=agent]:checked').count() == 1
       and 'Or reconnect an existing agent' in al.inner_text('main') and 'only if this app is that agent' in al.inner_text('main') and 'Reconnect @muse' in al.inner_text('main'))
    al.check('input[name=agent][value=new]'); al.fill('input[name=handle]', 'bodhi'); al.fill('input[name=role]', 'Scheduling assistant'); al.locator('label.choice', has_text='Launch').locator('input').check()
    al.click('button:has-text("Allow")')
    try: al.wait_for_url('http://localhost:8081/callback**', timeout=8000)
    except Exception: print('DEBUG', al.url, '|', al.inner_text('body')[:500]); raise
    q = dict(up.parse_qsl(up.urlparse(al.url).query))
    ck('Allow sends the person back to the app with a code, the state and the issuer', 'code' in q and q.get('state') == 'st4te' and q.get('iss') == B, al.url)
    s, tok = exchange(cid, q['code'], v); at = tok.get('access_token', '')
    ck('the app exchanges the code for tokens itself', s == 200 and at.startswith('mp_o_') and tok.get('refresh_token', '').startswith('mp_r_') and tok.get('token_type') == 'Bearer', tok)
    s, h, b = rpc(B + '/mcp/', 'initialize', bearer=at); ck('with that token the MCP server answers', s == 200 and 'serverInfo' in b)
    w = tool(B + '/mcp/', 'whoami', bearer=at); ck('the connection is the new agent, responsible person the one who approved, on the pad they ticked', 'You are @bodhi (Scheduling assistant)' in w and 'Responsible person: @alice.' in w and 'alice/launch' in w, w)
    ck('it can read and post with no token ever shown to anyone', tool(B + '/mcp/', 'read_board', {'board': 'alice/launch'}, at).startswith('OK') and tool(B + '/mcp/', 'post_message', {'board': 'alice/launch', 'message': 'Connected by signing in.'}, at).startswith('OK Posted'))
    s, h, b = http('POST', B + '/oauth/token/', {'Content-Type': 'application/x-www-form-urlencoded'}, up.urlencode({'grant_type': 'refresh_token', 'client_id': cid, 'refresh_token': tok['refresh_token']})); t2 = json.loads(b)
    ck('the app renews its access on its own; the old token stops', s == 200 and t2['access_token'] != at and rpc(B + '/mcp/', 'initialize', bearer=at)[0] == 401 and 'You are @bodhi' in tool(B + '/mcp/', 'whoami', bearer=t2['access_token']))
    at = t2['access_token']
    # 3. choosing an existing agent; deny; bad requests
    v2, c2 = pkce(); al.goto(authorize_url(cid, c2, 'two')); al.locator('label.choice', has_text='@muse').locator('input').check(); al.click('button:has-text("Allow")'); al.wait_for_url('http://localhost:8081/callback**')
    mcode = dict(up.parse_qsl(up.urlparse(al.url).query))['code']
    s, tm = exchange(cid, mcode, v2); ck('an existing agent can be connected the same way and keeps its personal token', 'You are @muse' in tool(B + '/mcp/', 'whoami', bearer=tm['access_token']) and 'You are @muse' in tool(B + '/mcp/', 'whoami', bearer=MUSE))
    s, tk = exchange(cid, mcode, v2); ck('a code used a second time is refused, and what it produced is withdrawn', s == 400 and tk.get('error') == 'invalid_grant' and rpc(B + '/mcp/', 'initialize', bearer=tm['access_token'])[0] == 401, tk)
    ck('the agent\'s own personal token is untouched by that', 'You are @muse' in tool(B + '/mcp/', 'whoami', bearer=MUSE))
    v3, c3 = pkce(); al.goto(authorize_url(cid, c3, 'three')); al.click('button:has-text("Don\'t allow")'); al.wait_for_url('http://localhost:8081/callback**'); q3 = dict(up.parse_qsl(up.urlparse(al.url).query))
    ck('Don\'t allow tells the app it was refused, with no code', q3.get('error') == 'access_denied' and 'code' not in q3 and q3.get('state') == 'three', al.url)
    r = al.goto(authorize_url(cid, c3, redirect='https://evil.example/steal')); ck('a redirect address the app never registered is stopped on Mashpad, not followed', r.status == 400 and "didn't register" in al.inner_text('main') and al.url.startswith(B + '/oauth/authorize/'))
    r = al.goto(authorize_url('mpc_unknown', c3)); ck('an unknown app is stopped too', r.status == 400 and "isn't registered" in al.inner_text('main'))
    al.goto(B + '/oauth/authorize/?' + up.urlencode({'response_type': 'code', 'client_id': cid, 'redirect_uri': CB, 'state': 'nopkce'})); al.wait_for_url('http://localhost:8081/callback**'); ck('a request without PKCE is sent back to the app as an error', 'error=invalid_request' in al.url)
    # 4. the fallbacks still work
    ck('a personal token still works on mcp/ as a Bearer header and as ?key=', 'You are @muse' in tool(B + '/mcp/', 'whoami', bearer=MUSE) and 'You are @muse' in tool(B + '/mcp/?key=' + MUSE, 'whoami'))
    s, h, b = rpc(B + '/mcp/open/', 'initialize'); ck('mcp/open/ answers with no credentials', s == 200 and 'serverInfo' in b)
    ck('there a token passed as a tool argument works, as before', 'You are @muse' in tool(B + '/mcp/open/', 'whoami', {'token': MUSE}) and tool(B + '/mcp/open', 'whoami').startswith('OK No token was sent'))
    s, h, b = rpc(B + '/mcp/open/', 'initialize', bearer='mp_o_garbage'); ck('a bad OAuth token is a 401 that tells the app to renew, on either address', s == 401 and 'error="invalid_token"' in h)
    ck('the status page says how to connect', 'ask you to sign in to Mashpad' in http('GET', B + '/mcp/')[2] and '/mcp/open/' in http('GET', B + '/mcp/')[2])
    # 5. no account: connect with an invitation link
    al.goto(B + '/manage/alice/solo-notes'); al.click('button:has-text("Show the invitation link")'); al.wait_for_selector('#invite-url'); link = al.inner_text('#invite-url').strip()
    gctx = br.new_context(); gctx.route('https://app.example/**', lambda r: r.fulfill(status=200, content_type='text/plain', body='back in the app')); g = gctx.new_page()
    v4, c4 = pkce(); g.goto(authorize_url(cid, c4, 'guest')); g.fill('input[name=invite]', 'not a link'); g.fill('section:has(#have-invite) input[name=handle]', 'lee-assistant'); g.fill('input[name=acts_for]', 'Lee Park'); g.click('button:has-text("Connect my agent")')
    ck('a bad invitation link is explained and nothing is created', "That invitation link isn't valid" in g.inner_text('.error') and g.input_value('input[name=acts_for]') == 'Lee Park')
    g.fill('input[name=invite]', link); g.click('button:has-text("Connect my agent")'); g.wait_for_url('http://localhost:8081/callback**')
    s, tg = exchange(cid, dict(up.parse_qsl(up.urlparse(g.url).query))['code'], v4); gt = tg.get('access_token', '')
    w = tool(B + '/mcp/', 'whoami', bearer=gt); ck('with only an invitation link, the agent is connected as a guest, with no account and no token shown', gt.startswith('mp_o_') and 'You are @lee-assistant' in w and 'as a guest acting for Lee Park' in w and 'Responsible person: @alice.' in w, w)
    j = tool(B + '/mcp/', 'join_pad', {'pad': link}, gt)
    ck('a signed-in agent that calls join_pad while waiting is given no code and no token', 'is with its owner' in j and 'connected by sign-in' in j and 'no token or code to collect' in j and 'mp_a_' not in j and not re.search(r'[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}', j), j)
    j2 = tool(B + '/mcp/', 'join_pad', {'request_code': 'ABCD-EFGH-JKLM'}, gt); ck('and presenting any request code issues nothing', j2.startswith("OK You don't need a request code or a token") and 'mp_a_' not in j2, j2)
    ck('whoami tells it how it is connected', 'Connected: by sign-in through your app' in tool(B + '/mcp/', 'whoami', bearer=gt) and 'Connected: with a personal agent token' in tool(B + '/mcp/', 'whoami', bearer=MUSE))
    rw = tool(B + '/mcp/', 'read_board', {'board': 'alice/solo-notes'}, gt); ck('reading the pad it is waiting on says it is waiting, not that the pad is missing', rw.startswith('ERR You\'ve asked to join "alice/solo-notes"') and "hasn't approved yet" in rw and 'There is no pad' not in rw, rw)
    ck('it waits for the owner like any guest', 'Waiting for the owner to approve your request to join: alice/solo-notes' in tool(B + '/mcp/', 'list_boards', bearer=gt) and tool(B + '/mcp/', 'read_board', {'board': 'alice/launch'}, gt).startswith('ERR There is no pad'))
    al.goto(B + '/pads/alice/solo-notes'); al.wait_for_selector('.pad-event .event-actions button'); al.locator('.pad-event', has_text='@lee-assistant').locator('button:has-text("Approve")').click()
    al.wait_for_function("[...document.querySelectorAll('.pad-event')].some(e => e.innerText.includes('@lee-assistant (an agent acting for Lee Park) joined'))", timeout=10000)
    ck('once the owner approves in the pad, the same connection can read: no request code, nothing to paste', tool(B + '/mcp/', 'read_board', {'board': 'alice/solo-notes'}, gt).startswith('OK'))
    al.goto(B + '/agents/'); show(al, '@lee-assistant'); ck('no personal token was ever created for it', 'Connected through Bodhi <test>' in al.locator('.agent-list li', has_text='@lee-assistant').inner_text() and 'personal token' not in al.locator('.agent-list li', has_text='@lee-assistant').inner_text(), al.locator('.agent-list li', has_text='@lee-assistant').inner_text())
    # 5b. connecting through an invitation address: the page already knows the pad
    tokn = link.rsplit('/', 1)[1]; IA = link
    ck('the invitation link is still a web page for a person', http('GET', link)[0] == 200 and 'For people' in http('GET', link)[2])
    s, h, b = rpc(B + '/mcp/i/' + tokn, 'initialize'); ck('the mcp/i/ form of the address answers the same way', s == 401 and '/.well-known/oauth-protected-resource/mcp/i/' + tokn in h)
    s, h, b = rpc(IA, 'initialize'); m2 = re.search(r'resource_metadata="([^"]+)"', h)
    ck('the invitation address challenges with its own metadata address', s == 401 and m2 and m2.group(1) == B + '/.well-known/oauth-protected-resource/join/' + tokn, h)
    s, h, b = http('GET', m2.group(1)); ck('whose resource is the invitation address itself', s == 200 and json.loads(b)['resource'] == IA and json.loads(b)['authorization_servers'] == [B], b)
    i1 = br.new_context().new_page(); v6, c6 = pkce(); i1.goto(authorize_url(cid, c6, 'inv').replace(up.quote(B + '/mcp/', safe=''), up.quote(IA, safe=''))); t6 = i1.inner_text('main')
    ck('an invited person is told what this is, which pad, and who invited them, with no link to find', 'is asking to connect to Mashpad' in t6 and 'Join “Solo Notes” as a guest' in t6 and "invitation to @alice's pad" in t6 and 'This page is how your agent joins it' in t6 and 'What happens next' in t6 and "nothing else for you to do" in t6 and i1.locator('input[name=invite]').count() == 0 and "don't need a Mashpad account" in t6, t6[:500])
    ck('the guest option comes first, the account option second, and the agent\'s name is suggested', i1.locator('main h2').all_inner_texts() == ['Join “Solo Notes” as a guest', 'Or use a Mashpad account'] and i1.input_value('input[name=handle]') == 'bodhi-test', (i1.locator('main h2').all_inner_texts(), i1.input_value('input[name=handle]')))
    i1.fill('input[name=handle]', 'sam-assistant'); i1.fill('input[name=acts_for]', 'Sam Rivera'); i1.click('button:has-text("Connect my agent to Solo Notes")'); i1.wait_for_url('http://localhost:8081/callback**')
    s, ti = exchange(cid, dict(up.parse_qsl(up.urlparse(i1.url).query))['code'], v6); w = tool(IA, 'whoami', bearer=ti.get('access_token', ''))
    ck('two fields and one button later the agent is connected as a guest on that pad', 'You are @sam-assistant' in w and 'acting for Sam Rivera' in w and 'alice/solo-notes' in tool(B + '/mcp/', 'list_boards', bearer=ti['access_token']), w)
    v7, c7 = pkce(); al.goto(authorize_url(cid, c7, 'inv2').replace(up.quote(B + '/mcp/', safe=''), up.quote(IA, safe=''))); t7 = al.inner_text('main')
    ck('a signed-in person coming through an invitation sees that pad named and ticked', 'The pad you were invited to' in t7 and al.locator('input[name=join_invited]').is_checked() and 'Solo Notes' in t7)
    i2 = br.new_context().new_page(); r = i2.goto(authorize_url(cid, c7, 'stale').replace(up.quote(B + '/mcp/', safe=''), up.quote(B + '/join/' + 'x' * 32, safe='')))
    ck('an invitation that no longer exists is said plainly, and the ordinary choices are offered', 'no longer valid' in i2.inner_text('main') and 'Someone invited me to their pad' in i2.inner_text('main') and i2.locator('input[name=invite]').count() == 1)
    i1.set_viewport_size({'width': 390, 'height': 844}); i1.goto(authorize_url(cid, c6, 'shot').replace(up.quote(B + '/mcp/', safe=''), up.quote(IA, safe=''))); i1.screenshot(path='/tmp/consent_invite.png', full_page=True)
    # 6. disconnecting
    al.goto(B + '/agents/'); show(al, '@bodhi'); row = al.locator('.agent-list li', has_text='@bodhi'); ck('Your agents shows which app each agent is connected through', 'Connected through Bodhi <test> (since' in row.inner_text(), row.inner_text())
    row.locator('button:has-text("Disconnect")').click(); al.click('.mp-modal-ok'); al.wait_for_selector('.notice')
    s, h, b = rpc(B + '/mcp/', 'initialize', bearer=at); ck('Disconnect signs the app out at once', '@bodhi is disconnected' in al.inner_text('.notice') and s == 401 and 'invalid_token' in h)
    al.set_viewport_size({'width': 320, 'height': 700}); v5, c5 = pkce(); al.goto(authorize_url(cid, c5)); ck('the consent page fits a 320px screen', al.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    al.set_viewport_size({'width': 900, 'height': 1000}); al.screenshot(path='/tmp/consent.png', full_page=True)
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
