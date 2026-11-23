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
def get(path):
    r = subprocess.run(['curl', '-s', '-w', '\n%{http_code}', B + path], capture_output=True, text=True).stdout; body, _, code = r.rpartition('\n'); return int(code), body
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
c, f = get('/features/'); text = re.sub(r'<[^>]+>', ' ', f).replace('&#039;', "'").replace('&amp;', '&')
heads = re.findall(r'<h3 id="uc-[a-z]+">(.*?)</h3>', f)
ck('ten use cases, every tool named in its heading', heads == ['Build software together (GitHub)', 'Arrange the meeting, and the lunch after it (Google Calendar, OpenTable)', 'Plan the meeting (Google Calendar, Zoom)',
   'Plan the dinner party (Instacart, Google Calendar, Gmail)', 'Build the road-trip playlist (Spotify)', 'File the weekly report (Google Drive, Gmail, Google Calendar)',
   'Watch project time (Timey Wimey, Toggl Track, Clockify)', 'Look after brand assets (InspireStudio, Bynder, Frontify, Cloudinary)', 'Bring in work from other tools (Canva)', 'Send agreements out for signature (DocuSign)'], heads)
pl = f.split('id="uc-playlist"')[1].split('</section>')[0]
ck('the playlist use case has its intro and an eight-message thread of bold @handles', 'Three friends are driving to a cabin' in pl and 'Nobody curates a playlist by hand.' in pl and len(re.findall(r'<p><strong>@[a-z-]+</strong><br>', pl)) == 8 and f.count('class="thread"') == 10)
ck('the playlist thread keeps the agreed words, with its one dash recast', 'Two duplicates between Maya and Priya: keep both, they are the point of the trip.' in pl and ' - ' not in re.sub(r'<[^>]+>', ' ', pl) and 'Bad Moon Rising' in pl and '63 tracks, 3 hours 58 minutes' in pl)
ck('the author-names-and-timestamps sentence is cut and the rest of that paragraph stands', 'Clear author names' not in f and 'keep detailed findings organized.</p>' in f)
ck('the style rules still hold across the page: no "Nothing" subject, no "actually", no dash as punctuation', not re.search(r'(^|[.!?]\s+)Nothing\b', text) and not re.search(r'\bactually\b', text, re.I) and ' - ' not in text and '—' not in text)
c, p = get('/pricing/'); ck('Pricing says "Not on sale yet. We\'ll say here when it is." and no "Nothing to do"', "Not on sale yet. We&#039;ll say here when it is." in p or "Not on sale yet. We'll say here when it is." in p, re.findall(r'Not on sale[^<]*', p))
ck('and "Nothing to do" is gone from the site', 'Nothing to do' not in p)
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(viewport={'width': 1280, 'height': 900}); pg = signin(ctx, 'alice'); errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/agents/'); t = pg.inner_text('.manage')
    ck('Your agents: the intro, then the connect route as its own paragraph in the approved wording, without "secure"', "Removing an agent from one pad is done on that pad's Manage access page; Remove agent here removes it from your workspace altogether." in t
       and f"In your agent's app, add this address as a connector: {B}/mcp/ The app brings you to Mashpad to approve it. You choose the agent and the pads it joins. If the app can't sign in to services, invite the agent from a pad's Manage access page instead." in t.replace('\n', ' ') and 'secure' not in t.lower(), t[:700])
    ck('the two are separate paragraphs', pg.locator('.manage > p').count() >= 2 and 'To add an agent' not in t)
    pg.goto(B + '/manage/alice/launch'); t = pg.inner_text('.manage')
    ck('Manage access: "connect it instead", and no "you don\'t need a code"', "connect it instead: add " + B + "/mcp/, approve it on the page that opens, and tick this pad there." in t and "you don't need a code" not in t, [x for x in t.split('\n') if 'sign in to services' in x])
    ck('the Secure agent invitation heading stays, as Dylan wrote it', pg.locator('#lobby-h').inner_text() == 'Secure agent invitation')
    ck('the open-invitation caution loses the schedule-coordination clause and keeps its warning', 'schedule coordination' not in t and 'Caution: anybody who has this invitation link can ask to join this pad until you revoke it. It\'s best for sharing with a close group of people and their agents.' in t.replace('\n', ' '))
    pg.goto(B + '/pads/alice/launch?view=settings'); pg.wait_for_selector('#brief .brief-box'); note = pg.locator('.settings-note').first.inner_text()
    ck('Pad info: the note starts at "Manage access to invite agents and people, or change who is on it."', note.startswith('Manage access to invite agents and people, or change who is on it.') and 'You own this pad' not in pg.inner_text('main'), note)
    # the rail: collisions, counts, long names
    subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); $id = fn ($h) => (int) $pdo->query("SELECT id FROM principals WHERE handle=\'$h\'")->fetchColumn(); $a = mb_principal($pdo, $id("alice")); mb_add_member($pdo, mb_pad_by_address($pdo, "bob", "launch"), $id("alice"), "added"); mb_create_pad($pdo, $a, "A very long pad name with unbrokenwordsunbrokenwordsunbrokenwords");'], check=True)
    pg.goto(B + '/pads/'); pg.wait_for_selector('#board-nav .pad-row'); rows = pg.locator('#board-nav .pad-row .board-link')
    names = [rows.nth(i).locator('.name').inner_text() for i in range(rows.count())]
    ck('two pads named Launch, owned by different people, each carry a short owner label', sorted(n for n in names if n.lower().startswith('launch')) == ['Launch @alice', 'Launch @bob'], names)
    ck('a name that is not shared carries no label, and no entry shows an address', all('@' not in n for n in names if not n.lower().startswith('launch')) and not re.search(r'alice/|bob/', pg.inner_text('#board-nav')), names)
    pg.screenshot(path='/tmp/rail2.png', clip={'x': 0, 'y': 0, 'width': 340, 'height': 760})
    ok = pg.evaluate("[...document.querySelectorAll('#board-nav .board-link')].every(a => a.scrollWidth <= a.clientWidth + 1)")
    ck('a long name wraps inside the rail instead of being clipped', ok)
    pg.set_viewport_size({'width': 320, 'height': 700}); pg.goto(B + '/pads/'); pg.wait_for_selector('.mobile-boards .pad-row'); ck('and at 320px nothing overflows', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    pg.goto(B + '/features/'); ck('the Features page fits 320px with its longer headings', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
