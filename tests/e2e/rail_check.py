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
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    ctx = br.new_context(viewport={'width': 1280, 'height': 900}); pg = signin(ctx, 'alice'); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.wait_for_selector('#board-nav .pad-row'); rail = pg.locator('aside')
    acct = pg.locator('#account')
    ck('the account block is the handle with a small Sign out beside it, no "Signed in as"', acct.locator('.acct-handle').inner_text() == '@alice' and acct.locator('.acct-out').inner_text() == 'Sign out' and 'Signed in as' not in acct.inner_text())
    ck('Your agents, Email settings and Admin are one quiet row, in that order', acct.locator('.acct-links a').all_inner_texts() == ['Your agents', 'Email settings', 'Admin'] and pg.evaluate("(() => { const a = [...document.querySelectorAll('.acct-links a')]; return new Set(a.map(x => Math.round(x.getBoundingClientRect().top))).size })()") == 1)
    btn = acct.locator('a.new-pad-button'); bg = btn.evaluate("e => getComputedStyle(e).backgroundColor")
    ck('New pad is a button with weight: dark fill, full width, comfortable height', btn.inner_text() == 'New pad' and bg == 'rgb(32, 39, 38)' and btn.bounding_box()['height'] >= 44 and btn.bounding_box()['width'] > 200, (bg, btn.bounding_box()))
    y = lambda sel: pg.locator(sel).first.bounding_box()['y']
    ck('the blocks are in order with space between: account, links, New pad, then the pads', y('.acct-id') < y('.acct-links') < y('a.new-pad-button') < y('aside .side-title') < y('#board-nav .pad-row') and y('aside .side-title') - (y('a.new-pad-button') + 46) >= 20, [y('.acct-id'), y('.acct-links'), y('a.new-pad-button'), y('aside .side-title'), y('#board-nav .pad-row')])
    rows = pg.locator('#board-nav .pad-row .board-link'); n = rows.count()
    shapes = [r.evaluate("e => [...e.children].map(c => c.className)") for r in [rows.nth(i) for i in range(n)]]
    ck('every pad entry is the same three lines: name, meta, who', n >= 2 and all(sh == ['name', 'meta', 'who'] for sh in shapes), shapes)
    txt = [rows.nth(i).inner_text().split('\n') for i in range(n)]
    ck('the meta line is messages and activity only; the who line is "N agents, N humans"', all(re.fullmatch(r'\d+ messages?, .+', t[1]) and (re.fullmatch(r'(\d+ agents?)?(, )?(\d+ humans?)?', t[2]) or t[2] in ('No one yet',) or re.fullmatch(r'\d+ participants?', t[2])) for t in txt), txt)
    ck('no address, no handles and no "created by" anywhere in the rail', not re.search(r'@\w|created by|alice/', ' '.join(' '.join(t) for t in txt)), txt)
    ck('the launch pad says how many agents and humans', any(t[0] == 'Launch' and re.fullmatch(r'\d+ agents?, \d+ humans?|\d+ agents?|\d+ humans?', t[2]) for t in txt), txt)
    ht = [pg.locator('#board-nav .pad-row').nth(i).bounding_box()['height'] for i in range(n)]
    ck('the entries are an even height, so the list is calm', max(ht) - min(ht) < 30, ht)
    pg.screenshot(path='/tmp/rail.png', clip={'x': 0, 'y': 0, 'width': 340, 'height': 640})
    # the Connect an AI agent block
    pg.goto(B + '/pads/'); pg.wait_for_selector('details.agent-setup'); pg.click('details.agent-setup summary'); t = pg.inner_text('details.agent-setup')
    ck('the Connect block says exactly the approved words', 'In your agent’s app, add this address as a connector:' in t and 'The app brings you to Mashpad to approve it. You choose the agent and the pads it joins.' in t and 'If the app can’t sign in to services, invite the agent from a pad’s Manage access page instead.' in t, t)
    ck('and no longer mentions tokens', 'token' not in t.lower() and 'remote MCP server' not in t, t)
    ck('the address shown is the sign-in connector address', pg.inner_text('#mcp-url') == B + '/mcp/')
    # Pad info
    pg.goto(B + '/pads/alice/launch?view=settings'); pg.wait_for_selector('#brief .brief-box')
    ck('Pad info: About this pad comes first, above a section now called Details', pg.locator('#brief').bounding_box()['y'] < pg.locator('#settings-details').bounding_box()['y'] and pg.inner_text('#settings-details') == 'Details')
    pg.click('#brief button[data-brief="write"], #brief button[data-brief="edit"]'); pg.wait_for_selector('#brief-form'); pg.fill('#brief-body', 'Coordinating the launch. Agents post findings here.'); pg.click('#brief button:has-text("Save")'); pg.wait_for_selector('#brief .brief-note')
    ck('the owner writes it there, and it saves', 'Saved.' in pg.inner_text('#brief') and 'Coordinating the launch.' in pg.inner_text('#brief'))
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#brief .brief-box'); ck('and it is the same text in the pad header, so the two agree', 'Coordinating the launch.' in pg.inner_text('#brief'))
    pg.click('#brief button[data-brief="edit"]'); pg.fill('#brief-body', 'Edited from the header.'); pg.click('#brief button:has-text("Save")'); pg.wait_for_selector('#brief .brief-note')
    pg.goto(B + '/pads/alice/launch?view=settings'); pg.wait_for_selector('#brief .brief-box'); ck('an edit made in the header shows on Pad info', 'Edited from the header.' in pg.inner_text('#brief'))
    pg.click('#brief button[data-brief="edit"]'); pg.fill('#brief-body', 'Edited on Pad info.'); pg.click('#brief button:has-text("Save")'); pg.wait_for_selector('#brief .brief-note')
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#brief .brief-box'); ck('and an edit made on Pad info shows in the header', 'Edited on Pad info.' in pg.inner_text('#brief'))
    pg.goto(B + '/pads/alice/launch?view=settings'); pg.wait_for_selector('#brief .brief-box'); pg.click('#brief button[data-brief="history"]'); pg.wait_for_selector('.brief-history'); ck('earlier versions are kept and listed from Pad info too', pg.locator('.brief-history li').count() >= 3)
    # a member who is not the owner
    subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); $b = (int) $pdo->query("SELECT id FROM principals WHERE handle=\'bob\'")->fetchColumn(); foreach (["launch", "solo-notes"] as $n) { mb_add_member($pdo, mb_pad_by_address($pdo, "alice", $n), $b, "added"); }'], check=True)
    bob = br.new_context(viewport={'width': 1280, 'height': 900}); bp = signin(bob, 'bob'); bp.goto(B + '/pads/alice/launch?view=settings'); bp.wait_for_selector('#brief .brief-box')
    ck('a member reads it on Pad info with no Edit button', 'Edited on Pad info.' in bp.inner_text('#brief') and bp.locator('#brief button[data-brief]').count() == 0)
    bp.goto(B + '/pads/alice/solo-notes?view=settings'); bp.wait_for_selector('#brief')
    ck('and where nothing is written, a member is told the owner has not written one', 'hasn’t written one yet' in bp.inner_text('#brief') and bp.locator('#brief button').count() == 0, bp.inner_text('#brief'))
    # narrow screens
    pg.set_viewport_size({'width': 320, 'height': 700}); pg.goto(B + '/pads/'); pg.wait_for_selector('.mobile-boards .pad-row'); ck('the pads page fits a 320px screen', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    pg.goto(B + '/pads/alice/launch?view=settings'); pg.wait_for_selector('#brief .brief-box'); ck('so does Pad info', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
