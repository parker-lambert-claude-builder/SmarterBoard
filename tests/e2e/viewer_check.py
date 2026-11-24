import sys, subprocess, json, re
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); ' + code], capture_output=True, text=True).stdout.strip()
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
def mcp(name, args):
    r = subprocess.run(['curl', '-s', '-X', 'POST', B + '/mcp/', '-H', 'Content-Type: application/json', '-H', 'Authorization: Bearer ' + MUSE, '-d', json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args}})], capture_output=True, text=True).stdout
    return json.loads(r)['result']['content'][0]['text']
# a pad with two people and two agents: alice (owner), bob (added), muse (seeded), and a second agent
php('$a = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'alice\'")->fetchColumn(); $b = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'bob\'")->fetchColumn(); $pad = mb_pad_by_address($pdo, "alice", "launch");'
    ' mb_add_member($pdo, $pad, $b, "added"); [$q] = mb_create_agent($pdo, $a, ["handle" => "qa-bot"]); mb_add_member($pdo, $pad, $q, "added");')
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    ctx = br.new_context(viewport={'width': 1100, 'height': 800}); pg = signin(ctx, 'alice'); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#head-meta .who-kind')
    marks = pg.eval_on_selector_all('#head-meta .who-kind', 'els => els.map(e => [e.textContent, e.getAttribute("aria-label"), e.nextElementSibling && e.nextElementSibling.textContent])')
    by = {m[2]: m for m in marks}
    ck('every name in the member line has a mark in front of it', len(marks) >= 4 and all(m[2] for m in marks), marks)
    ck('people have the person mark and agents the robot head', by['@alice'][0] == '🧑' and by['@bob'][0] == '🧑' and by['@muse'][0] == '🤖' and by['@qa-bot'][0] == '🤖', marks)
    ck('each mark says what it is to a screen reader, and as a tooltip', by['@alice'][1] == 'Person' and by['@muse'][1] == 'Agent' and pg.get_attribute('#head-meta .who-kind', 'title') in ('Person', 'Agent'))
    ck('the mark is in front of the name, outside the link, so the link still opens that participant\'s page and is not underlined around the emoji', pg.locator('#head-meta .who-kind + a.author-link').count() == len(marks) and pg.locator('#head-meta a .who-kind').count() == 0)
    ck('the names are still the same names, in the same order, after the marks', [m[2] for m in marks] == pg.eval_on_selector_all('#head-meta a.author-link', 'els => els.map(e => e.textContent)'))
    # ---- it keeps its marks when the page updates by itself
    mcp('post_message', {'board': 'alice/launch', 'message': 'A post that makes the page refresh its header.'})
    pg.wait_for_function("() => document.querySelector('#transcript').innerText.includes('refresh its header')", timeout=20000)
    ck('and keeps them when a new post arrives and the header is redrawn', pg.locator('#head-meta .who-kind').count() >= 4 and pg.locator('#head-meta .who-kind').first.text_content() in ('🧑', '🤖'))
    # ---- the settings links again at the bottom
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('.pad-foot'); pg.wait_for_timeout(500)
    ck('the page opens at the latest message, and the settings links are on screen without scrolling', pg.evaluate('innerHeight + scrollY >= document.body.scrollHeight - 5') and pg.locator('.pad-foot').is_visible() and pg.evaluate("(() => { const r = document.querySelector('.pad-foot').getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; })()"))
    foot = pg.eval_on_selector_all('.pad-foot a', 'els => els.map(e => [e.textContent, e.getAttribute("href")])'); top = pg.eval_on_selector_all('.board-head .reading-nav a', 'els => els.map(e => [e.textContent, e.getAttribute("href")])')
    ck('they are the same links as at the top: Pad info and Manage access, with the same addresses', ['Pad info', 'Manage access'] == [f[0] for f in foot[1:]] and foot[1:] == top[1:], (foot, top))
    ck('and a way back to the top in place of Read from start', foot[0] == ['Back to top', '#'] and top[0][0] == 'Read from start')
    ck('they sit after the reply box, at the very end of the page', pg.evaluate("(() => { const f = document.querySelector('.pad-foot'), r = document.querySelector('#reply-bottom'); return !!(r.compareDocumentPosition(f) & Node.DOCUMENT_POSITION_FOLLOWING) && !f.nextElementSibling; })()"))
    pg.click('.pad-foot a:has-text("Back to top")'); pg.wait_for_timeout(600); ck('Back to top goes to the top', pg.evaluate('scrollY') < 5 and pg.locator('h1').first.is_visible())
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('.pad-foot'); pg.click('.pad-foot a:has-text("Pad info")'); pg.wait_for_selector('h1:has-text("Pad info")'); ck('Pad info from the bottom opens the pad\'s info page, as it does from the top', 'view=settings' in pg.url)
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('.pad-foot'); pg.click('.pad-foot a:has-text("Manage access")'); pg.wait_for_url('**/manage/alice/launch'); ck('Manage access from the bottom opens it', 'Manage access' in pg.inner_text('h1'), pg.inner_text('h1'))
    # ---- someone who is a member but doesn't own the pad
    bob = br.new_context(viewport={'width': 1100, 'height': 800}); bp = signin(bob, 'bob'); bp.goto(B + '/pads/alice/launch'); bp.wait_for_selector('#head-meta .who-kind')
    ck('another member sees the same marks and the same links at the foot', bp.locator('#head-meta .who-kind').count() >= 4 and bp.locator('.pad-foot a').count() == bp.locator('.board-head .reading-nav a').count())
    # ---- small screens, and a pad with no messages
    pg.set_viewport_size({'width': 320, 'height': 700}); pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('.pad-foot'); ck('a 320px screen has no sideways scroll, with the marks and the foot', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    php('$a = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'alice\'")->fetchColumn(); mb_create_pad($pdo, mb_principal($pdo, $a), "Empty Pad");')
    pg.goto(B + '/pads/alice/empty-pad'); pg.wait_for_selector('.pad-foot'); pg.wait_for_selector('#head-meta .who-kind'); ck('a pad with no messages still has the foot, and the mark in front of its owner', pg.locator('.pad-foot a').count() == 3 and pg.locator('#head-meta .who-kind').count() >= 1)
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
