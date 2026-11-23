import sys, subprocess, json, re, html as H
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def get(path, extra=()): return subprocess.run(['curl', '-s', *extra, B + path], capture_output=True, text=True).stdout
norm = lambda t: ' '.join(H.unescape(re.sub(r'<[^>]+>', ' ', t)).split())
NOTE = lambda addr: f"If your agent's app lets you add a custom connector (MCP server), use {addr}. Some apps only allow that on their web version or on some plans. Claude and Codex are tested and work; in ChatGPT it is web only, and write access is for Business, Enterprise and Edu plans, so the phone app can't add one."
# ---- the sign-in documents
rm = json.loads(get('/.well-known/oauth-protected-resource')); am = json.loads(get('/.well-known/oauth-authorization-server'))
ck('the protected-resource document advertises both scopes', rm['scopes_supported'] == ['pads', 'offline_access'], rm)
ck('and the authorization-server document does, alongside refresh_token as a grant', am['scopes_supported'] == ['pads', 'offline_access'] and 'refresh_token' in am['grant_types_supported'], am)
reg = json.loads(get('/oauth/register/', ['-H', 'Content-Type: application/json', '-d', json.dumps({'client_name': 'ChatGPT test', 'redirect_uris': ['https://chatgpt.example/callback'], 'token_endpoint_auth_method': 'none', 'grant_types': ['authorization_code', 'refresh_token'], 'response_types': ['code']})]))
ck('a newly registered app is told it has both scopes', reg.get('scope') == 'pads offline_access' and reg['client_id'].startswith('mpc_'), reg)
# ---- the pages
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    al = br.new_context(viewport={'width': 1280, 'height': 900}); ap = al.new_page(); ap.on('pageerror', lambda e: errs.append(str(e)))
    ap.goto(B + '/signin/'); ap.fill('input[name=email]', 'alice@example.com'); ap.fill('input[name=password]', 'correct horse battery'); ap.click('button[type=submit]'); ap.wait_for_url('**/pads/')
    ap.goto(B + '/manage/alice/launch'); ap.click('button:has-text("Show the invitation link")'); ap.wait_for_selector('#invite-url'); link = ap.inner_text('#invite-url').strip()
    page = norm(get(link.replace(B, '')))
    ck('the invitation page tells the person which apps can connect, in the people section', norm(NOTE("this page's link")) in page and page.index("this page's link") < page.index('For agents'), page[:200])
    ck('and it is a separate line from the checking advice, after it', page.index('Your agent has to check the pad on a schedule') < page.index('If your agent\'s app lets you add a custom connector'))
    ap.goto(B + '/agents/'); t = norm(ap.inner_html('main'))
    ck('Your agents says it too, right after the connector address', norm(NOTE('that address')) in t and t.index('add this address as a connector') < t.index('If your agent\'s app lets you add a custom connector'), t[:400])
    ck('it reads as a quiet note, not a warning', ap.locator('main p.muted', has_text="custom connector (MCP server)").count() == 1)
    ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + '/agents/'); ck('and Your agents fits 320px with it', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ap.goto(link); ck('as does the invitation page', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
