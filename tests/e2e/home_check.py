import sys, subprocess, json, re, time
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:700]))
MUSE = open(E2E_ENV).read().split('TOK=')[1].split('\n')[0]
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); $id = fn ($h) => (int) $pdo->query("SELECT id FROM principals WHERE handle=\'$h\'")->fetchColumn(); ' + code], capture_output=True, text=True).stdout
def post(pad, who, body):
    php('$pad = mb_pad_by_address($pdo, "alice", "%s"); $pdo->beginTransaction(); $seq = (int) $pdo->query("SELECT next_seq FROM pads WHERE id = " . (int) $pad["id"] . " FOR UPDATE")->fetchColumn(); $pdo->prepare("INSERT INTO messages (pad_id, seq, author_id, body, kind) VALUES (?, ?, ?, ?, \'post\')")->execute([$pad["id"], $seq, $id("%s"), %s]); $pdo->prepare("UPDATE pads SET next_seq = next_seq + 1 WHERE id = ?")->execute([$pad["id"]]); $pdo->commit();' % (pad, who, json.dumps(body)))
def mcp(tool, args=None, token=MUSE):
    p = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args or {}}}
    r = json.loads(subprocess.run(['curl', '-s', '-H', 'Content-Type: application/json', *(['-H', 'Authorization: Bearer ' + token] if token else []), B + '/mcp/open/', '-d', json.dumps(p)], capture_output=True, text=True).stdout)['result']
    return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
def blocks(pg): pg.wait_for_selector('#home-blocks .home-stats'); return pg.inner_text('#home-blocks')
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    al = br.new_context(viewport={'width': 1280, 'height': 900}); ap = signin(al, 'alice'); ap.on('pageerror', lambda e: errs.append(str(e)))
    r = al.request.get(B + '/api/?action=home'); ck('the summary is served to a signed-in person', r.status == 200 and set(r.json()) >= {'needs', 'activity', 'more', 'stats'})
    anon = br.new_context(); ck('and refused to anyone else', anon.request.get(B + '/api/?action=home').status == 401)
    # open each pad so everything is seen, then the home page is quiet
    for pad in ['alice/launch', 'alice/solo-notes']:
        ap.goto(B + '/pads/' + pad); ap.wait_for_selector('#transcript')
    ap.goto(B + '/pads/'); t = blocks(ap)
    ck('after opening every pad the main column has no cards and no Needs you, and says so quietly', 'All caught up.' in t and 'Recent activity' not in t and 'Needs you' not in t, t)
    ck('the old "Pick a pad…" line is gone, and the numbers are there', 'Pick a pad' not in t and re.search(r'\d+ pads?\s+\d+ agents?\s+\d+ posts? this week', t), t)
    st = al.request.get(B + '/api/?action=home').json()['stats']
    ck('the strip matches the server', f"{st['pads']} pad" in t and f"{st['posts_week']} post" in t)
    # new posts from others make cards, newest first
    post('launch', 'muse', "## Review of the launch page\nThe sign-up form needs a shorter explanation."); post('launch', 'muse', 'Second, shorter note.'); time.sleep(1.1); post('solo-notes', 'bob', 'Hello from Bob, with *emphasis* and more words after it.')
    ap.reload(); t = blocks(ap)
    cards = ap.locator('.activity-card').all_inner_texts()
    ck('each pad with news is one card: its name, the count, who posted last, and that post\'s opening line', len(cards) == 2 and 'Solo Notes' in cards[0] and '1 new post' in cards[0] and '@bob: Hello from Bob, with emphasis and more words after it.' in cards[0]
       and 'Launch' in cards[1] and '2 new posts' in cards[1] and '@muse: Second, shorter note.' in cards[1], cards)
    ck('newest first', cards[0].startswith('Solo Notes') and cards[1].startswith('Launch'))
    ck('Recent activity comes after Needs you would, and before the numbers', ap.evaluate("(() => { const h = document.querySelector('#recent-h').getBoundingClientRect().top, s = document.querySelector('.home-stats').getBoundingClientRect().top; return h < s })()"))
    # an agent's read does not clear it
    mcp('read_board', {'board': 'alice/launch'}); ap.reload(); ck('an agent reading the pad does not clear a person\'s card', ap.locator('.activity-card', has_text='Launch').count() == 1 and blocks(ap) != '')
    ap.goto(B + '/pads/alice/launch?view=settings'); ap.wait_for_selector('#brief'); ap.goto(B + '/pads/'); blocks(ap); ck('and neither does opening Pad info', ap.locator('.activity-card', has_text='Launch').count() == 1)
    # opening the pad does
    ap.click('.activity-card a:has-text("Launch")'); ap.wait_for_selector('#transcript .msg'); time.sleep(1); ap.click('aside .brand'); blocks(ap)
    ck('opening the pad clears its card and leaves the other', ap.locator('.activity-card', has_text='Launch').count() == 0 and ap.locator('.activity-card', has_text='Solo Notes').count() == 1)
    # live refresh while open
    post('launch', 'muse', 'Fresh while you watch.'); ap.wait_for_function("document.querySelector('.activity-list') && document.querySelector('.activity-list').innerText.includes('Fresh while you watch.')", timeout=20000)
    ck('while the page is open a new post appears as a card without a reload', ap.locator('.activity-card', has_text='Launch').count() == 1)
    # needs you
    ap.goto(B + '/manage/alice/launch'); ap.click('button:has-text("Show the invitation link")'); ap.wait_for_selector('#invite-url'); link = ap.inner_text('#invite-url').strip()
    r = mcp('join_pad', {'pad': link, 'handle': 'visitor', 'acts_for': 'Zed'}, token=None)
    ap.goto(B + '/pads/'); t = blocks(ap)
    ck('a request to join one of her pads is in Needs you, with a link to where it is handled', 'Needs you' in t and '1 request to join Launch' in t and ap.locator('#home-blocks a:has-text("1 request to join Launch")').get_attribute('href').endswith('/manage/alice/launch'), t)
    ck('and a guest agent not yet claimed is the second line', '1 guest agent not claimed yet' in t and ap.locator('#home-blocks a:has-text("guest agent")').get_attribute('href').endswith('/agents/'), t)
    ck('Needs you comes first', ap.evaluate("document.querySelector('#needs-h').getBoundingClientRect().top < document.querySelector('#recent-h').getBoundingClientRect().top"))
    ap.click('#home-blocks a:has-text("1 request to join Launch")'); ap.wait_for_url('**/manage/alice/launch'); ap.click('button:has-text("Approve @visitor")'); ap.wait_for_selector('.notice'); ap.goto(B + '/pads/'); t = blocks(ap)
    ck('once approved the request is gone, and the guest line stays until its person claims it', 'request to join' not in t and '1 guest agent not claimed yet' in t, t)
    # another person
    bob = br.new_context(viewport={'width': 1280, 'height': 900}); bp = signin(bob, 'bob'); bt = blocks(bp)
    ck('another person sees none of Alice\'s needs', 'Needs you' not in bt and 'guest agent' not in bt, bt)
    # the rest of the page
    ap.goto(B + '/pads/'); ap.wait_for_selector('details.agent-setup'); ck('the Connect block and the retention note are still there, after the blocks', ap.locator('.home-note').count() == 1 and ap.evaluate("document.querySelector('.home-stats').getBoundingClientRect().top < document.querySelector('details.agent-setup').getBoundingClientRect().top"))
    ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + '/pads/'); blocks(ap); ap.wait_for_selector('.mobile-boards .pad-row'); ck('at 320px it fits, and the pad list for phones is still there', ap.evaluate('document.documentElement.scrollWidth <= innerWidth') and ap.locator('.mobile-boards .pad-row').count() >= 2)
    ap.set_viewport_size({'width': 1000, 'height': 1000}); ap.goto(B + '/pads/'); blocks(ap); ap.screenshot(path='/tmp/home.png')
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
