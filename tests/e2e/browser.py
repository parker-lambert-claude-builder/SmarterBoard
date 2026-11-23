import re, sys
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]
res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:300]))
with sync_playwright() as p:
    br = p.chromium.launch(); ctx = br.new_context(viewport={'width': 1280, 'height': 800}); pg = ctx.new_page()
    errs = []; pg.on('pageerror', lambda e: errs.append(str(e))); bad = []
    pg.on('response', lambda r: bad.append((r.status, r.url)) if r.status >= 400 and '/api/' not in r.url else None)
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', 'alice@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
    pg.wait_for_selector('#board-nav .pad-row')
    nav = pg.inner_html('#board-nav')
    ck('list shows private pads by address', 'alice/launch' in nav and 'alice/solo-notes' in nav and 'bob/launch' not in nav, nav)
    pg.click('#board-nav a.board-link[data-nav="alice/launch"]'); pg.wait_for_selector('#transcript .msg')
    ck('clicking a pad lands on /pads/alice/launch', pg.url == B + '/pads/alice/launch', pg.url)
    ck('conversation renders', "Hello from Alice" in pg.inner_text('#transcript'))
    pg.click('#reply-top-body'); pg.fill('#reply-top-body', 'Posted from the browser.'); pg.keyboard.press('Control+Enter'); pg.wait_for_selector('#m2')
    ck('posting works at the new address', 'Posted from the browser.' in pg.inner_text('#transcript'))
    pg.click('nav.reading-nav a:has-text("Pad info")'); pg.wait_for_selector('.settings-page')
    ck('pad info address', pg.url == B + '/pads/alice/launch?view=settings', pg.url)
    info = pg.inner_html('#main')
    ck('pad info shows the full link and no ?b= or .php', (B + '/pads/alice/launch') in info and '?b=' not in info and '.php' not in info, info[:400])
    with pg.expect_download() as d: pg.click('a:has-text("Download Markdown")')
    ck('export downloads, named for owner and pad', re.match(r'alice-launch-\d{4}-\d\d-\d\d\.md$', d.value.suggested_filename), d.value.suggested_filename)
    pg.go_back(); pg.wait_for_selector('#transcript .msg'); ck('Back returns to the conversation', pg.url == B + '/pads/alice/launch', pg.url)
    pg.reload(); pg.wait_for_selector('#transcript .msg'); ck('reload on the deep address works', pg.locator('#transcript .msg').count() == 2)
    pg.goto(B + '/pads/alice/launch#m2'); pg.wait_for_selector('#m2'); ck('message deep link', pg.url.endswith('/pads/alice/launch#m2'))
    pg.goto(B + '/pads/?b=launch&view=settings#m1'); pg.wait_for_selector('.settings-page')
    ck('A5 old link redirects; view and fragment survive', pg.url == B + '/pads/alice/launch?view=settings#m1', pg.url)
    pg.goto(B + '/pads/bob/launch'); pg.wait_for_selector('#main p.empty'); hidden = pg.inner_text('#main p.empty')
    pg.goto(B + '/pads/bob/never-made'); pg.wait_for_selector('#main p.empty')
    ck('A10 hidden and nonexistent pads read the same', hidden == pg.inner_text('#main p.empty') and 'no pad here that you can see' in hidden, hidden)
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#transcript .msg'); pg.click('nav.reading-nav a:has-text("Manage access")'); pg.wait_for_selector('.manage')
    ck('Manage access goes to /manage/alice/launch', pg.url == B + '/manage/alice/launch', pg.url)
    ck('manage page is styled (stylesheets loaded)', pg.evaluate("getComputedStyle(document.querySelector('.manage-sidebar')).display") != 'inline')
    pg.click('button:has-text("Show agent invitation")'); pg.wait_for_selector('#agent-invitation')
    inv = pg.inner_text('#agent-invitation'); ck('invitation text', (B + '/pads/alice/launch with lobby code') in inv and (B + '/mcp/open/.') in inv and pg.url.startswith(B + '/manage/alice/launch'), inv)
    pg.click('.manage-sidebar a:has-text("Conversation")'); pg.wait_for_selector('#transcript .msg'); ck('sidebar Conversation link', pg.url == B + '/pads/alice/launch', pg.url)
    pg.goto(B + '/pads/?b=old-open'); pg.wait_for_selector('#transcript .msg'); ck('older open pad still opens at ?b=', pg.url == B + '/pads/?b=old-open' and 'legacy' in pg.inner_text('#transcript'), pg.url)
    pg.set_viewport_size({'width': 375, 'height': 700}); pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#transcript .msg')
    ck('no horizontal overflow at 375px', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    pg.goto(B + '/manage/alice/launch'); pg.wait_for_selector('.manage'); ck('manage: no horizontal overflow at 375px', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); ck('no failed page or asset requests', not bad, bad)
    br.close()
print(f"{B}: {sum(res)} passed, {len(res)-sum(res)} failed")
