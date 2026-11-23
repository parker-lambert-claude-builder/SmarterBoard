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
def mcp(tool, args=None, method='tools/call', token=MUSE):
    p = {'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': ({'name': tool, 'arguments': args or {}} if method == 'tools/call' else {})}
    cmd = ['curl', '-s', '-H', 'Content-Type: application/json'] + (['-H', 'Authorization: Bearer ' + token] if token else []) + [B + '/mcp/open/', '-d', json.dumps(p)]
    r = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)['result']
    return r if method != 'tools/call' else (('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text'])
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
def event(pg, text, timeout=10000):
    try:
        pg.wait_for_function("(t) => [...document.querySelectorAll('.pad-event')].some(e => e.innerText.includes(t))", arg=text, timeout=timeout); return True
    except Exception:
        return False
def sql(q): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; echo mb_db()->query(' + json.dumps(q) + ')->fetchColumn();'], capture_output=True, text=True).stdout
L = 'alice/launch'
# ---- agents over MCP
init = mcp('x', method='initialize')['instructions']
ck('the connect instructions explain items, with examples and the habit of reusing a type', 'A pad also keeps a short list of items' in init and 'decision, question, requirement, risk and link' in init and 'call list_items first' in init and 'update_item, naming the version you read' in init)
tools = {t['name']: t for t in mcp('x', method='tools/list')['tools']}
ck('four tools are listed, and update needs the version', all(k in tools for k in ['list_items', 'add_item', 'update_item', 'remove_item']) and 'version' in tools['update_item']['inputSchema']['required'] and tools['remove_item']['annotations']['destructiveHint'] is True)
ck('list_items on a pad with none says how to start', 'has no items yet' in mcp('list_items', {'board': L}) and 'decision, question, requirement, risk or link' in mcp('list_items', {'board': L}))
r = mcp('add_item', {'board': L, 'type': '  Decision ', 'value': 'Free plan first, Plus later.'}); ck('add_item tidies the type and gives the number and version', r.startswith('OK Added item #1') and 'as a decision' in r and 'version is 1' in r and 'n=1 and version=1' in r, r)
mcp('add_item', {'board': L, 'type': 'question', 'value': 'Which date for the launch?'})
r = mcp('list_items', {'board': L}); ck('list_items groups by type with numbers, versions and who changed it, and names the types in use', 'decision (1)' in r and '#1 (version 1) Free plan first, Plus later.  [updated by @muse' in r and 'Types in use: decision (1), question (1).' in r, r)
r = mcp('read_board', {'board': L}); ck('a catch-up read shows the items before the messages', '=== Items (decision 1, question 1) ===' in r and r.index('=== Items') < r.index('--- #') and '=== end of Items ===' in r, r[:300])
ck('a read since an id does not repeat them', '=== Items' not in mcp('read_board', {'board': L, 'since_id': 1}))
r = mcp('update_item', {'board': L, 'n': 2, 'value': 'Launch date: Tuesday 9 December.', 'version': 1}); ck('update_item changes the value and bumps the version', r.startswith('OK Item #2') and 'version is 2' in r, r)
r = mcp('update_item', {'board': L, 'n': 2, 'value': 'Mine', 'version': 1}); ck('a stale update is refused, says what the item is now, and carries its current text to merge into', r.startswith('ERR Not saved: item 2 changed since you read it. You had version 1; it is now version 2') and 'The item now:' in r and '#2 (version 2) Launch date: Tuesday 9 December.' in r, r)
ck('an update without a version is refused with the reason', mcp('update_item', {'board': L, 'n': 2, 'value': 'x'}).startswith('ERR update_item needs n'))
r = mcp('update_item', {'board': L, 'n': 2, 'type': 'decision', 'value': 'Launch date: Tuesday 9 December.', 'version': 2}); ck('a question answered becomes a decision by passing a type', 'is now a decision' in r, r)
ck('a bad type and an over-long value are refused plainly, the type message naming underscores', 'hyphens and underscores' in mcp('add_item', {'board': L, 'type': 'a/b', 'value': 'x'}) and mcp('add_item', {'board': L, 'type': 'a/b', 'value': 'x'}).startswith('ERR A type is up to 40') and mcp('add_item', {'board': L, 'type': 'risk', 'value': 'x' * 2001}).startswith('ERR An item\'s value is up to 2000'))
ck('without a token an agent can do none of it', mcp('add_item', {'board': L, 'type': 'x', 'value': 'y'}, token=None).startswith('ERR This needs your agent token'))
ck('and reading items of a pad you are not on looks like no pad', mcp('list_items', {'board': 'bob/launch'}).startswith('ERR There is no pad'))
with sync_playwright() as p:
    br = p.chromium.launch(); native = []; errs = []
    al = br.new_context(viewport={'width': 1280, 'height': 900}); ap = signin(al, 'alice'); ap.on('dialog', lambda d: (native.append(d.message), d.dismiss())); ap.on('pageerror', lambda e: errs.append(str(e)))
    subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); $b = (int) $pdo->query("SELECT id FROM principals WHERE handle=\'bob\'")->fetchColumn(); mb_add_member($pdo, mb_pad_by_address($pdo, "alice", "launch"), $b, "added");'], check=True)
    # ---- the viewer
    ap.goto(B + '/pads/alice/launch'); ap.wait_for_selector('#items .items-box')
    ck('the pad page has an Items block under About, closed, with counts', ap.locator('#items .items-count').inner_text() == '2 · decision 2' and not ap.locator('#items details').get_attribute('open') is not None and ap.locator('#brief').bounding_box()['y'] < ap.locator('#items').bounding_box()['y'] < ap.locator('#reply-top').bounding_box()['y'], ap.locator('#items .items-count').inner_text())
    ap.click('#items summary'); t = ap.inner_text('#items')
    ck('opened, items are grouped by type with the value and who and when', 'decision 2' in t and 'Free plan first, Plus later.' in t and '#1 · @muse' in t and 'Launch date: Tuesday 9 December.' in t)
    ap.fill('#item-add input[name=type]', 'Requirement'); ap.fill('#item-add textarea', 'Every email carries a one-press unsubscribe.'); ap.click('#item-add button:has-text("Add")'); ap.wait_for_selector('#items .item-group[aria-label="requirement"]')
    ck('adding an item from the viewer puts it under its own type, tidied, and keeps the block open', 'Every email carries a one-press unsubscribe.' in ap.inner_text('#items') and ap.locator('#items details').get_attribute('open') is not None and ap.locator('#items .items-count').inner_text().startswith('3 ·'))
    ck('the add form is empty again, and offers the types in use', ap.input_value('#item-add textarea') == '' and sorted(ap.eval_on_selector_all('#item-types option', 'els => els.map(e => e.value)')) == ['decision', 'requirement'])
    ck('the new item shows who added it and the pad records it as an event', '#3 · @alice' in ap.inner_text('#items') and event(ap, '@alice added a requirement (#3): Every email carries a one-press unsubscribe.'))
    # edit, and a conflict
    ap.click('#item-1 [data-item-edit]'); ap.wait_for_selector('#item-1 form'); ck('Edit opens the item in a form with its type and value and focuses the value', ap.input_value('#item-1 input[name=type]') == 'decision' and ap.evaluate("document.activeElement.name") == 'value')
    mcp('update_item', {'board': L, 'n': 1, 'value': 'Free plan first; Plus when billing exists.', 'version': 1})
    ap.fill('#item-1 textarea', 'Free plan first, then Plus (Alice).'); ap.click('#item-1 button:has-text("Save")'); ap.wait_for_selector('#item-1 .form-error')
    ck('if an agent changed it meanwhile, the save is refused, says who and what, and keeps the writer\'s text', 'changed since you read it' in ap.inner_text('#item-1 .form-error') and '@muse' in ap.inner_text('#item-1 .form-error') and ap.input_value('#item-1 textarea') == 'Free plan first, then Plus (Alice).' and ap.get_attribute('#item-1 form', 'data-version') == '2')
    ap.click('#item-1 button:has-text("Save")'); ap.wait_for_selector('#item-1 [data-item-edit]')
    ck('saving again, against the newer version, goes through and shows who changed it and who added it', 'Free plan first, then Plus (Alice).' in ap.inner_text('#item-1') and '#1 · @alice, added by @muse' in ap.inner_text('#item-1'), ap.inner_text('#item-1'))
    ck('the update is recorded in the conversation with the old text', event(ap, '@alice updated a decision (#1): Free plan first, then Plus (Alice). (was: Free plan first; Plus when billing exists.)'))
    ap.click('#item-1 [data-item-edit]'); ap.click('#item-1 [data-item-cancel]'); ck('Cancel closes the form unchanged', ap.locator('#item-1 form').count() == 0)
    # remove
    ap.click('#item-3 [data-item-remove]'); ap.wait_for_selector('.mp-modal'); ck('Remove asks in Mashpad\'s own dialog, naming the item', 'Remove this item?' in ap.inner_text('.mp-modal') and 'requirement: Every email carries' in ap.inner_text('.mp-modal') and 'danger' in ap.get_attribute('.mp-modal-ok', 'class'))
    ap.click('.mp-modal-cancel'); ck('cancelling keeps it', ap.locator('#item-3').count() == 1)
    ap.click('#item-3 [data-item-remove]'); ap.click('.mp-modal-ok'); ap.wait_for_selector('#item-3', state='detached')
    ck('confirming removes it, and the block\'s counts follow', ap.locator('#items .items-count').inner_text() == '2 · decision 2' and event(ap, '@alice removed a requirement (#3)'))
    # an owner can remove an agent's; a member cannot remove somebody else's
    ck('the owner is offered Remove on an item an agent added', ap.locator('#item-1 [data-item-remove]').count() == 1 and ap.locator('#item-2 [data-item-remove]').count() == 1)
    bob = br.new_context(viewport={'width': 1280, 'height': 900}); bp = signin(bob, 'bob'); bp.goto(B + '/pads/alice/launch'); bp.wait_for_selector('#items .items-box'); bp.click('#items summary')
    ck('a member who did not add them can edit but is not offered Remove', bp.locator('#items [data-item-edit]').count() == 2 and bp.locator('#items [data-item-remove]').count() == 0)
    r = bp.request.post(B + '/api/?action=item', data=json.dumps({'board': L, 'op': 'remove', 'n': 2}), headers={'Content-Type': 'application/json', 'Origin': B})
    ck('and removing one directly is refused by the server, naming who may', r.status == 400 and 'Only @muse, who added item 2, or the pad\'s owner can remove it' in r.text(), (r.status, r.text()[:200]))
    r = bp.request.post(B + '/api/?action=item', data='board=alice/launch&op=add&type=decision&value=x', headers={'Content-Type': 'application/x-www-form-urlencoded'})
    ck('an item change sent as a form, the way a cross-site page would, is refused', r.status == 403 and len(json.loads(subprocess.run(['curl', '-s', B + '/api/?action=items&board=alice/launch'], capture_output=True, text=True).stdout or '{"items":[]}').get('items', [])) == 0, r.status)
    outsider = br.new_context(); op = signin(outsider, 'bob'); r = op.request.get(B + '/api/?action=items&board=alice/solo-notes'); ck('a person who is not on a pad gets a 404 for its items, like a pad that does not exist', r.status == 404, r.status)
    # live updates, and not wiping what someone is typing
    mcp('add_item', {'board': L, 'type': 'risk', 'value': 'The mail provider may rewrite links.'})
    ap.wait_for_function("document.querySelector('#items').innerText.includes('The mail provider may rewrite links.')", timeout=12000)
    ck('an item an agent adds shows up for the people reading, without a reload', True)
    ap.fill('#item-add textarea', 'half-written thought'); ap.fill('#item-add input[name=type]', 'quest')
    mcp('add_item', {'board': L, 'type': 'link', 'value': 'https://example.org/spec'}); time.sleep(5)
    ck('while someone is typing in the add form, a change by someone else does not wipe it', ap.input_value('#item-add textarea') == 'half-written thought' and ap.input_value('#item-add input[name=type]') == 'quest' and 'https://example.org/spec' not in ap.inner_text('#items'))
    ap.fill('#item-add textarea', ''); ap.fill('#item-add input[name=type]', ''); ap.click('#items summary h2, #items .items-title'); ap.click('#items .items-title')
    ap.mouse.click(5, 300); ap.wait_for_function("document.querySelector('#items').innerText.includes('https://example.org/spec')", timeout=8000)
    ck('and it appears once they stop', True)
    # retention, exports, other pad types
    ap.goto(B + '/pads/alice/launch'); la = sql("SELECT last_activity_at FROM pads WHERE slug='launch' AND owner_id=(SELECT id FROM principals WHERE handle='alice')")
    mcp('add_item', {'board': L, 'type': 'decision', 'value': 'Retention is unchanged by items.'}); ck('changing items does not extend a pad\'s retention', sql("SELECT last_activity_at FROM pads WHERE slug='launch' AND owner_id=(SELECT id FROM principals WHERE handle='alice')") == la, la)
    s = al.request.get(B + '/api/?action=export&board=alice/launch&format=md').text()
    ck('the Markdown export has an Items section grouped by type', '## Items' in s and '### decision (' in s and re.search(r'- #1 Free plan first, then Plus \(Alice\)\. \(updated by @alice, ', s) and '### risk (1)' in s, s[s.find('## Items'):][:500])
    j = al.request.get(B + '/api/?action=export&board=alice/launch&format=json').json(); ck('the JSON export has the items, without anyone\'s id', len(j['items']) >= 4 and all('creator_id' not in i for i in j['items']) and j['items'][0]['n'] == 1)
    ap.goto(B + '/pads/?b=old-open'); ap.wait_for_selector('#transcript .msg'); ck('an older open pad has no Items block', ap.locator('#items').count() == 0)
    ap.set_viewport_size({'width': 320, 'height': 700}); ap.goto(B + '/pads/alice/launch'); ap.wait_for_selector('#items .items-box'); ap.click('#items summary'); ck('with the block open the pad page fits a 320px screen', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ap.set_viewport_size({'width': 900, 'height': 1100}); ap.goto(B + '/pads/alice/launch'); ap.wait_for_selector('#items .items-box'); ap.click('#items summary'); ap.screenshot(path='/tmp/items.png')
    ck('no browser popup and no script errors', native == [] and not errs, (native, errs))
    br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
