import sys, subprocess, json, re, os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); ' + code], capture_output=True, text=True).stdout.strip()
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
# alice gets eight more pads, each older than the last, so the order is certain: Pad 1 is the newest of them
php('$a = mb_principal($pdo, (int) $pdo->query("SELECT id FROM principals WHERE handle = \'alice\'")->fetchColumn()); for ($i = 1; $i <= 8; $i++) { $pad = mb_create_pad($pdo, $a, "Pad $i"); $pdo->prepare("UPDATE pads SET last_activity_at = UTC_TIMESTAMP() - INTERVAL ? DAY WHERE id = ?")->execute([$i, $pad["id"]]); }')
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    al = br.new_context(viewport={'width': 1280, 'height': 1000}); ap = signin(al, 'alice'); ap.on('pageerror', lambda e: errs.append(str(e)))
    ap.goto(B + '/pads/'); ap.wait_for_selector('#board-nav .pad-row')
    boards = ap.evaluate("fetch('/api/?action=boards').then(r => r.json()).then(d => d.boards.filter(b => !b.public).map(b => [b.name || b.slug, b.last_activity]))")
    byRecent = [n for n, _ in sorted(boards, key=lambda b: -b[1])]
    act = dict(boards)
    inOrder = lambda names: all(act[a] >= act[b] for a, b in zip(names, names[1:]))      # pads updated in the same second may swap
    rail = ap.eval_on_selector_all('#board-nav > .pad-row .name', 'els => els.map(e => e.textContent.trim())')
    ck('the left menu lists only the five most recently updated pads, newest first', len(rail) == 5 and set(rail) == {'Solo Notes', 'Launch', 'old-open', 'Pad 1', 'Pad 2'} and inOrder(rail) and 'Pad 8' not in rail, (rail, byRecent[:6]))
    see = ap.locator('#board-nav a.see-all')
    ck('followed by a See all link that says how many there are', see.count() == 1 and see.inner_text() == f'See all {len(boards)} pads', see.inner_text() if see.count() else 'none')
    ck('the See all link comes straight after the five, and before the public pads', ap.evaluate("(() => { const n = document.querySelector('#board-nav'); const kids = [...n.children]; const i = kids.findIndex(e => e.classList.contains('see-all')); return i === 5 && kids[i + 1].classList.contains('public-pads'); })()"))
    see.click(); ap.wait_for_selector('.mobile-boards .pad-row'); allrows = ap.eval_on_selector_all('.mobile-boards .pad-row .name', 'els => els.map(e => e.textContent.trim())')
    ck('it opens the home page, whose list has every pad, newest first', ap.url.rstrip('/').endswith('/pads') and 'All your pads' in ap.inner_text('main') and sorted(allrows) == sorted(byRecent) and inOrder(allrows) and 'Pad 8' in allrows, (ap.url, allrows))
    ap.goto(B + '/pads/alice/pad-8'); ap.wait_for_selector('#head-meta')
    rail2 = ap.eval_on_selector_all('#board-nav > .pad-row .name', 'els => els.map(e => e.textContent.trim())')
    ck('a pad you are reading stays in the menu even when it is older than the five, and is marked', len(rail2) == 6 and rail2[-1] == 'Pad 8' and ap.locator('#board-nav .pad-row.is-current a[aria-current="page"]').count() == 1, rail2)
    bc = br.new_context(viewport={'width': 1280, 'height': 1000}); bp = signin(bc, 'bob'); bp.goto(B + '/pads/'); bp.wait_for_selector('#board-nav .pad-row')
    bb = bp.evaluate("fetch('/api/?action=boards').then(r => r.json()).then(d => d.boards.filter(b => !b.public).length)")
    ck('a person with five pads or fewer sees them all, and a plain See all pads link', bp.locator('#board-nav > .pad-row').count() == bb and bb <= 5 and bp.locator('#board-nav a.see-all').inner_text() == 'See all pads', bb)
    php('$b = mb_principal($pdo, (int) $pdo->query("SELECT id FROM principals WHERE handle = \'bob\'")->fetchColumn()); for ($i = 1; $i <= 6; $i++) { mb_create_pad($pdo, $b, "Bobs $i"); }')
    bp.goto(B + '/pads/'); bp.wait_for_selector('#board-nav a.see-all'); ck('and once they have more, it says how many', bp.locator('#board-nav > .pad-row').count() == 5 and 'See all' in bp.locator('#board-nav a.see-all').inner_text() and re.search(r'See all \d+ pads', bp.locator('#board-nav a.see-all').inner_text()) is not None)
    ap.goto(B + '/pads/'); ap.wait_for_selector('#board-nav .public-pads'); ck('the public pads group is still its own, below', ap.locator('#board-nav .public-pads').count() == 1)
    ap.set_viewport_size({'width': 390, 'height': 800}); ap.goto(B + '/pads/'); ap.wait_for_selector('.mobile-boards .pad-row')
    ck('on a phone the home page lists every pad, and nothing scrolls sideways', ap.locator('.mobile-boards .pad-row').count() == len(boards) and ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
