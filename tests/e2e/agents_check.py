import sys, subprocess, json, re, os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; require_once "' + SITE + '/src/invites.php"; $pdo = mb_db(); ' + code], capture_output=True, text=True).stdout.strip()
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
# alice has muse (seeded); add five more agents, one with a long role, and a guest that joined on its own
php('$a = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'alice\'")->fetchColumn(); foreach (["qa-bot", "builder-one", "helper-two", "scout-three", "writer-four"] as $h) { mb_create_agent($pdo, $a, ["handle" => $h, "role" => $h === "writer-four" ? "Writes the release notes and keeps the changelog current" : "Role of " . $h]); }'
    ' $pad = mb_pad_by_address($pdo, "alice", "launch"); mb_invite_make($pdo, $pad, $a); mb_invite_guest_agent($pdo, $pad, ["handle" => "stranger", "acts_for" => "Sam"], false);')
php('$b = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'bob\'")->fetchColumn(); mb_create_agent($pdo, $b, ["handle" => "bobs-agent"]);')
ids = json.loads(php('echo json_encode($pdo->query("SELECT p.handle, p.id, a.icon FROM principals p JOIN agent_profiles a ON a.principal_id = p.id WHERE p.owner_id = (SELECT id FROM principals WHERE handle = \'alice\') AND p.kind = \'agent\' ORDER BY p.id")->fetchAll(PDO::FETCH_NUM));'))
ID = {h: i for h, i, _ in ids}; ICON = {h: ic for h, _, ic in ids}
BOB_AGENT = php('echo $pdo->query("SELECT id FROM principals WHERE handle = \'bobs-agent\'")->fetchColumn();')
def title(pg):
    # the agent's name is the start of its card's heading, before the role and app
    return pg.locator('.agent-list li .agent-title').first.inner_text().split(' ·')[0].strip()
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []; native = []
    al = br.new_context(viewport={'width': 1280, 'height': 1000}); ap = signin(al, 'alice'); ap.on('pageerror', lambda e: errs.append(str(e))); ap.on('dialog', lambda d: (native.append(d.message), d.dismiss()))
    # ---- the pull-down
    ap.goto(B + '/agents/')
    opts = ap.eval_on_selector_all('#agent-picker option', 'os => os.map(o => o.textContent.trim())')
    ck('with several agents there is a pull-down labelled Agent, and a count', ap.get_by_label('Agent', exact=True).count() == 1 and ap.locator('#agent-picker').evaluate('e => e.tagName') == 'SELECT' and '7 agents' in ap.inner_text('.agent-picker'), opts)
    ck('it lists every agent as @handle, with its role, and says which one is a guest', len(opts) == 7 and any(o.startswith('@muse') for o in opts) and 'Role of qa-bot' in ' '.join(opts) and any(o.startswith('@stranger') and o.endswith('(guest)') for o in opts), opts)
    ck('a long role is cut short in the list', any(o.startswith('@writer-four') and '…' in o and len(o) < 75 for o in opts), opts)
    ck('only one agent\'s settings are on the page at a time, and it is the first by default', ap.locator('.agent-list li').count() == 1 and title(ap) == '@' + opts[0].split(' ')[0].lstrip('@'), ap.locator('.agent-list li').count())
    ck('its permissions, token controls and wake-up are there, and nobody else\'s name', 'Save permissions' in ap.inner_text('.agent-list') and 'Wake-up:' in ap.inner_text('.agent-list') and not any(o.split(' ')[0] in ap.inner_text('.agent-list') for o in opts[1:]), opts)
    show = lambda pg, h: (pg.select_option('#agent-picker', value=str(ID[h])), pg.wait_for_load_state(), pg.wait_for_selector('.agent-list'))
    show(ap, 'scout-three')
    ck('choosing another agent shows its settings and no one else\'s, and the address says which', title(ap) == '@scout-three' and f'agent={ID["scout-three"]}' in ap.url and ap.locator('#agent-picker').input_value() == str(ID['scout-three']) and '@muse' not in ap.inner_text('.agent-list'), ap.url)
    ck('there is no Show button when scripts work: choosing is enough', not ap.locator('.picker-go').is_visible())
    show(ap, 'stranger'); gt = ap.inner_text('.agent-list')
    ck('an agent that joined on its own and is not claimed shows the line to claim it first, and no Permissions form', 'Claim it to choose what it may do.' in gt and ap.locator('.agent-list li input[type=checkbox]').count() == 0 and ap.locator('.agent-list li button:has-text("Save permissions")').count() == 0, gt[:300])
    show(ap, 'scout-three')
    # ---- icons
    ck('the first four agents have four different icons, taken in the library\'s order', len({ICON[h] for h in ['muse', 'qa-bot', 'builder-one', 'helper-two']}) == 4 and [ICON[h] for h in ['muse', 'qa-bot', 'builder-one', 'helper-two']] == sorted(ICON[h] for h in ['muse', 'qa-bot', 'builder-one', 'helper-two']), ICON)
    from collections import Counter
    use = Counter(ICON.values()); ck('with more agents than icons they are shared as evenly as can be', len(use) == 4 and max(use.values()) - min(use.values()) <= 1, use)
    big = ap.locator('.agent-icon-large'); small = ap.locator('.picker-icon')
    ck('the chosen agent\'s icon is shown large beside its name and small beside the pull-down, and they are the same icon', big.count() == 1 and small.count() == 1 and big.get_attribute('src').split('?')[0] == small.get_attribute('src').split('?')[0] and ICON['scout-three'] in big.get_attribute('src') and big.get_attribute('width') == '72' and small.get_attribute('width') == '40')
    ck('the image really loads, as a 100px webp', ap.evaluate("new Promise(r => { const i = document.querySelector('.agent-icon-large'); if (i.complete) r([i.naturalWidth, i.naturalHeight]); else i.onload = () => r([i.naturalWidth, i.naturalHeight]); })") == [100, 100] and ap.request.get(B + big.get_attribute('src')).headers.get('content-type') == 'image/webp')
    ck('the icon is decorative: the name is beside it', big.get_attribute('alt') == '')
    # ---- doing things keeps the same agent
    card = ap.locator('.agent-list li'); card.locator('input[name=can_message_agents]').check(); card.locator('button:has-text("Save permissions")').click(); ap.wait_for_load_state()
    ck('saving permissions comes back to the same agent, with the notice', title(ap) == '@scout-three' and '@scout-three can now send private messages' in ap.inner_text('main') and ap.locator('#agent-picker').input_value() == str(ID['scout-three']), ap.url)
    ap.locator('.agent-list li button:has-text("Issue token"), .agent-list li button:has-text("Replace token")').first.click(); ap.wait_for_load_state()
    if ap.locator('.mp-modal').count(): ap.click('.mp-modal button:has-text("Issue"), .mp-modal-ok'); ap.wait_for_load_state()
    ck('issuing a token shows it for that agent and keeps it chosen', 'New token for @scout-three' in ap.inner_text('main') and ap.locator('#agent-picker').input_value() == str(ID['scout-three']))
    show(ap, 'writer-four'); ap.click('.agent-list li button:has-text("Remove agent")'); ap.wait_for_selector('.mp-modal'); ap.click('.mp-modal-ok'); ap.wait_for_load_state()
    opts2 = ap.eval_on_selector_all('#agent-picker option', 'os => os.map(o => o.textContent.trim())')
    ck('removing an agent goes back to a list without it, showing another agent, and says so', len(opts2) == 6 and not any(o.startswith('@writer-four') for o in opts2) and '@writer-four is removed from your workspace' in ap.inner_text('main') and ap.locator('.agent-list li').count() == 1, opts2)
    lib = ['orange-sprout', 'orange-visor', 'pink-stars', 'teal-cheeks']
    active = php('echo json_encode($pdo->query("SELECT a.icon FROM principals p JOIN agent_profiles a ON a.principal_id = p.id WHERE p.owner_id = (SELECT id FROM principals WHERE handle = \'alice\') AND p.kind = \'agent\' AND p.disabled_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));')
    counts = Counter(json.loads(active)); expected = min(lib, key=lambda k: (counts.get(k, 0), lib.index(k)))
    freed = php('$a = (int) $pdo->query("SELECT id FROM principals WHERE handle = \'alice\'")->fetchColumn(); [$id] = mb_create_agent($pdo, $a, ["handle" => "newcomer"]); echo $pdo->query("SELECT icon FROM agent_profiles WHERE principal_id = $id")->fetchColumn();')
    ck('and the removed agent\'s icon no longer counts: the next agent gets whichever the remaining agents use least', freed == expected and counts.get(ICON['writer-four'], 0) < sum(1 for h in ICON if ICON[h] == ICON['writer-four']), (freed, expected, counts))
    # ---- someone else's agent, and other people
    ap.goto(B + f'/agents/?agent={BOB_AGENT}'); ck('asking for another person\'s agent by number shows one of your own and nothing of theirs', 'bobs-agent' not in ap.inner_text('main') and ap.locator('.agent-list li').count() == 1)
    bp = signin(br.new_context(viewport={'width': 1280, 'height': 900}), 'bob'); bp.goto(B + '/agents/')
    ck('a person with one agent sees no pull-down, just the agent and its icon', bp.locator('#agent-picker').count() == 0 and title(bp) == '@bobs-agent' and bp.locator('.agent-icon-large').count() == 1)
    ck('and that account starts from the first icon again, whatever alice\'s agents use', ICON['muse'] in bp.locator('.agent-icon-large').get_attribute('src'))
    cp = signin(br.new_context(viewport={'width': 1280, 'height': 900}), 'bob')
    # ---- without scripts the Show button does it
    nj = br.new_context(viewport={'width': 1280, 'height': 900}, java_script_enabled=False); njp = nj.new_page(); njp.goto(B + '/signin/'); njp.fill('input[name=email]', 'alice@example.com'); njp.fill('input[name=password]', 'correct horse battery'); njp.click('button[type=submit]'); njp.wait_for_url('**/pads/'); njp.goto(B + '/agents/')
    ck('without scripts a Show button is there', njp.locator('.picker-go').is_visible())
    njp.select_option('#agent-picker', value=str(ID['helper-two'])); njp.click('.picker-go'); njp.wait_for_load_state()
    ck('and pressing it shows the chosen agent', title(njp) == '@helper-two' and f'agent={ID["helper-two"]}' in njp.url)
    # ---- the pad page
    ap.goto(B + '/pads/alice/launch'); ap.wait_for_selector('#head-meta .who-icon')
    imgs = ap.eval_on_selector_all('#head-meta .who-icon', 'els => els.map(e => [e.getAttribute("src"), e.naturalWidth, e.width, e.nextElementSibling.textContent])')
    ck('on a pad each agent shows its own icon, 20px, before its name', len(imgs) >= 1 and all(i[1] == 100 and i[2] == 20 for i in imgs) and any(i[3] == '@muse' and ICON['muse'] in i[0] for i in imgs), imgs)
    ck('and people keep the person mark', ap.locator('#head-meta .who-kind', has_text='🧑').count() >= 1)
    # ---- narrow screen
    ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + '/agents/'); ck('Your agents fits 320px with the pull-down and the icon', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no browser popup and no script errors', not errs, errs)
    ck('the library is the four files, renamed', sorted(os.listdir(SITE + '/assets/agent-icons')) == ['orange-sprout.webp', 'orange-visor.webp', 'pink-stars.webp', 'teal-cheeks.webp'])
    br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
