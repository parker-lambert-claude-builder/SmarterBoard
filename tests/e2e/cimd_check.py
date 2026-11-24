import sys, subprocess, json, re, base64, hashlib, os, urllib.parse as up, shutil
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:700]))
def http(method, url, headers=None, data=None):
    cmd = ['curl', '-s', '-i', '-X', method, url]
    for k, v in (headers or {}).items(): cmd += ['-H', f'{k}: {v}']
    if data is not None: cmd += ['--data', data]
    raw = subprocess.run(cmd, capture_output=True, text=True).stdout.replace('\r\n', '\n'); head, _, body = raw.partition('\n\n')
    return int(head.split()[1]), head, body
b64 = lambda b: base64.urlsafe_b64encode(b).rstrip(b'=').decode()
def pkce(): v = b64(os.urandom(40)); return v, b64(hashlib.sha256(v.encode()).digest())
CB = 'http://localhost:8081/callback'
URL = 'https://claude.example/oauth/client.json'
shutil.rmtree(FIXTURES, ignore_errors=True); os.makedirs(FIXTURES); os.chmod(FIXTURES, 0o777)
def fixture(url, doc): open('' + FIXTURES + '/' + hashlib.sha256(url.encode()).hexdigest() + '.json', 'w').write(json.dumps(doc)); os.chmod('' + FIXTURES + '/' + hashlib.sha256(url.encode()).hexdigest() + '.json', 0o666)
good = {'client_id': URL, 'client_name': 'Claude <test>', 'redirect_uris': [CB], 'token_endpoint_auth_method': 'none', 'grant_types': ['authorization_code', 'refresh_token'], 'response_types': ['code'], 'client_uri': 'https://claude.example/'}
fixture(URL, good)
GPT = 'https://chatgpt.example/oauth/client.json'; fixture(GPT, {'client_id': GPT, 'client_name': 'ChatGPT', 'client_uri': 'https://chatgpt.example/', 'redirect_uris': [CB], 'token_endpoint_auth_method': 'private_key_jwt',
       'token_endpoint_auth_methods_supported': ['none', 'private_key_jwt'], 'token_endpoint_auth_signing_alg': 'RS256', 'jwks_uri': 'https://chatgpt.example/oauth/jwks.json', 'grant_types': ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer'], 'response_types': ['code']})
NOCODE = 'https://nocode.example/c.json'; fixture(NOCODE, {**good, 'client_id': NOCODE, 'grant_types': ['implicit']})
KEYONLY = 'https://keyonly.example/oauth/client.json'; fixture(KEYONLY, {'client_id': KEYONLY, 'client_name': 'Keys only', 'redirect_uris': [CB], 'token_endpoint_auth_method': 'private_key_jwt', 'token_endpoint_auth_methods_supported': ['private_key_jwt'], 'jwks_uri': 'https://keyonly.example/jwks.json'})
BAD_ID = 'https://mismatch.example/c.json'; fixture(BAD_ID, {**good, 'client_id': 'https://someone-else.example/c.json'})
SECRET = 'https://secret.example/c.json'; fixture(SECRET, {**good, 'client_id': SECRET, 'token_endpoint_auth_method': 'client_secret_basic'})
HTTPCB = 'https://httpcb.example/c.json'; fixture(HTTPCB, {**good, 'client_id': HTTPCB, 'redirect_uris': ['http://evil.example/cb']})
def authorize_url(cid, chal, redirect=CB): return B + '/oauth/authorize/?' + up.urlencode({'response_type': 'code', 'client_id': cid, 'redirect_uri': redirect, 'code_challenge': chal, 'code_challenge_method': 'S256', 'state': 'st4te'})
with sync_playwright() as p:
    br = p.chromium.launch(); ctx = br.new_context(viewport={'width': 1280, 'height': 950}); al = ctx.new_page(); errs = []; al.on('pageerror', lambda e: errs.append(str(e)))
    s, h, b = http('GET', B + '/.well-known/oauth-authorization-server'); am = json.loads(b)
    ck('the server says it supports metadata documents, and still offers registration', am['client_id_metadata_document_supported'] is True and am['registration_endpoint'].endswith('/oauth/register/'), am)
    # ---- an app that identifies itself by address
    v, c = pkce(); al.goto(authorize_url(URL, c)); t = al.inner_text('main')
    ck('signed out, the page names the app and says which address identified it', 'Claude <test>' in t and 'identifies itself with ' + URL in t and "Its name is its own claim; that address is what Mashpad checked." in t and 'localhost' in t, t[:500])
    ck('and the name is shown as text, not run', al.locator('main strong', has_text='Claude <test>').count() >= 1 and al.locator('main b').count() == 0)
    al.click('a:has-text("Sign in")'); al.fill('input[name=email]', 'alice@example.com'); al.fill('input[name=password]', 'correct horse battery'); al.click('button[type=submit]'); al.wait_for_url('**/oauth/authorize/**')
    ck('after signing in they are back, with the address in the request intact', 'Which agent is this?' in al.inner_text('main') and up.quote(URL, safe='') in al.url, al.url)
    al.check('input[name=agent][value=new]'); al.fill('input[name=handle]', 'claude-web'); al.click('button:has-text("Allow")'); al.wait_for_url('**/callback**')
    q = up.parse_qs(up.urlparse(al.url).query)
    ck('approving sends them back to the address the document listed, with a code, their state and the issuer', 'code' in q and q['state'] == ['st4te'] and q['iss'] == [B], al.url)
    s, h, b = http('POST', B + '/oauth/token/', {'Content-Type': 'application/x-www-form-urlencoded'}, up.urlencode({'grant_type': 'authorization_code', 'client_id': URL, 'code': q['code'][0], 'redirect_uri': CB, 'code_verifier': v}))
    tok = json.loads(b); ck('the code is exchanged using the address as client_id', s == 200 and tok['token_type'] == 'Bearer' and tok['access_token'].startswith('mp_o') and tok['refresh_token'].startswith('mp_r'), b)
    s, h, b = http('POST', B + '/mcp/', {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok['access_token']}, json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': 'whoami', 'arguments': {}}}))
    ck('and the access token works as that agent', 'claude-web' in json.loads(b)['result']['content'][0]['text'], b[:300])
    s, h, b = http('POST', B + '/oauth/token/', {'Content-Type': 'application/x-www-form-urlencoded'}, up.urlencode({'grant_type': 'refresh_token', 'client_id': URL, 'refresh_token': tok['refresh_token']}))
    ck('it refreshes the same way', s == 200 and json.loads(b)['access_token'].startswith('mp_o') and json.loads(b)['refresh_token'] != tok['refresh_token'], b)
    ck('only one client row exists for it, of the metadata kind, with its details kept', subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $r = mb_db()->query("SELECT COUNT(*), MAX(kind), MAX(name), MAX(client_uri) FROM oauth_clients WHERE client_id = \'' + URL + '\'")->fetch(PDO::FETCH_NUM); echo implode("|", $r);'], capture_output=True, text=True).stdout == '1|metadata|Claude <test>|https://claude.example/')
    # ---- an app shaped like ChatGPT: it prefers a signed key, and says it can use none too
    vg, cg = pkce(); al.goto(authorize_url(GPT, cg)); tg = al.inner_text('main')
    ck('ChatGPT-shaped document: the page names the app and the address that identified it, instead of refusing it', 'ChatGPT' in tg and 'identifies itself with ' + GPT in tg and "This connection can't continue" not in tg, tg[:400])
    al.check('input[name=agent][value=new]'); al.fill('input[name=handle]', 'chatgpt-app'); al.click('button:has-text("Allow")'); al.wait_for_url('**/callback**'); qg = up.parse_qs(up.urlparse(al.url).query)
    s, h, b = http('POST', B + '/oauth/token/', {'Content-Type': 'application/x-www-form-urlencoded'}, up.urlencode({'grant_type': 'authorization_code', 'client_id': GPT, 'code': qg['code'][0], 'redirect_uri': CB, 'code_verifier': vg}))
    tokg = json.loads(b); ck('and it signs in as a public client, with no client assertion: the code is exchanged', s == 200 and tokg['access_token'].startswith('mp_o') and tokg['refresh_token'].startswith('mp_r'), b)
    s, h, b = http('POST', B + '/oauth/token/', {'Content-Type': 'application/x-www-form-urlencoded'}, up.urlencode({'grant_type': 'refresh_token', 'client_id': GPT, 'refresh_token': tokg['refresh_token']})); ck('and refreshes', s == 200 and json.loads(b)['access_token'].startswith('mp_o'), b)
    vk, ck2 = pkce(); al.goto(authorize_url(KEYONLY, ck2)); tk = al.inner_text('main')
    ck('a document that can only authenticate with a key is still refused, and says why', "This connection can't continue" in tk and 'public apps only' in tk and 'doesn\'t say it can also use none' in tk, tk[:400])
    s, h, b = http('GET', B + '/.well-known/oauth-authorization-server'); ck('the server still offers only none, which is what makes ChatGPT use it', json.loads(b)['token_endpoint_auth_methods_supported'] == ['none'], b)
    # ---- an address it can't use
    for what, cid, needle in [('a document whose client_id is not its own address', BAD_ID, "its client_id isn't the address it was fetched from"), ('one that asks for a shared secret', SECRET, 'public apps only'), ('one that cannot use the code flow', NOCODE, 'grant_types must include authorization_code (Mashpad supports the code flow with a refresh token); the document lists ["implicit"]'),
                              ('one with a redirect address that is neither https nor localhost', HTTPCB, 'redirect address must be https'), ('an address with nothing there', 'https://nothing.example/c.json', 'HTTP 404'),
                              ('a bare IP address', 'https://127.0.0.1/c.json', 'an IP address, not a hostname'), ('an internal hostname', 'https://nas.internal/c.json', 'internal'), ('a plain http address', 'http://claude.example/client.json', "isn't registered")]:
        v2, c2 = pkce(); al.goto(authorize_url(cid, c2)); t = al.inner_text('main')
        ck(f'{what}: the person is stopped on this page, told why, and not sent anywhere', "This connection can't continue" in t and needle in t and '/callback' not in al.url and al.url.startswith(B), t[:300])
    v2, c2 = pkce(); al.goto(authorize_url(URL, c2, 'http://localhost:8081/other')); ck('a return address the document did not list is refused, even for a good document', "an address it didn't register" in al.inner_text('main') and al.url.startswith(B))
    s, h, b = http('POST', B + '/oauth/token/', {'Content-Type': 'application/x-www-form-urlencoded'}, up.urlencode({'grant_type': 'authorization_code', 'client_id': 'https://nothing.example/c.json', 'code': 'x', 'redirect_uri': CB, 'code_verifier': v}))
    ck('at the token endpoint an unusable address is a clean invalid_client, not an error page', s == 401 and json.loads(b)['error'] == 'invalid_client' and 'HTTP 404' in json.loads(b)['error_description'], (s, b))
    # ---- registration is untouched
    s, h, b = http('POST', am['registration_endpoint'], {'Content-Type': 'application/json'}, json.dumps({'client_name': 'Old way', 'redirect_uris': [CB]})); cid = json.loads(b).get('client_id', '')
    v3, c3 = pkce(); al.goto(authorize_url(cid, c3)); ck('an app that registers itself still works, and shows no "identifies itself" line', s == 201 and 'Old way' in al.inner_text('main') and 'identifies itself with' not in al.inner_text('main'), al.inner_text('main')[:200])
    al.set_viewport_size({'width': 320, 'height': 800}); v4, c4 = pkce(); al.goto(authorize_url(URL, c4)); ck('the page fits a 320px screen', al.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
