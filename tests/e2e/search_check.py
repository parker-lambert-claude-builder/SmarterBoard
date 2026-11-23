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
def rpc(method, params=None, token=MUSE):
    p = {'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params or {}}
    cmd = ['curl', '-s', '-H', 'Content-Type: application/json'] + (['-H', 'Authorization: Bearer ' + token] if token else []) + [B + '/mcp/open/', '-d', json.dumps(p)]
    return json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)['result']
def tool(name, args=None, token=MUSE):
    r = rpc('tools/call', {'name': name, 'arguments': args or {}}, token)
    return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text'], r.get('structuredContent')
def put(who, body, kind='post', when='', reply='', pad='launch'): return int(subprocess.run(['php', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'put.php'), pad, who, body, kind, when, str(reply)], capture_output=True, text=True).stdout.strip())
def php(code): return subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; $pdo = mb_db(); $id = fn ($h) => (int) $pdo->query("SELECT id FROM principals WHERE handle=\'$h\'")->fetchColumn(); $pad = mb_pad_by_address($pdo, "alice", "launch"); ' + code], capture_output=True, text=True).stdout.strip()
def lastread(): return php('echo $pdo->query("SELECT COALESCE(last_read_at, \'none\') FROM pad_members WHERE pad_id = " . (int) $pad["id"] . " AND principal_id = " . $id("muse"))->fetchColumn();')
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
L = 'alice/launch'
# ---- the schema and the instructions
tools = {t['name']: t for t in rpc('tools/list')['tools']}
ck('search_posts is listed, needing a pad and a query, and says what it searches', 'search_posts' in tools and set(tools['search_posts']['inputSchema']['required']) == {'board', 'query'} and 'Posts only' in tools['search_posts']['description'] and 'ignoring case and accents' in tools['search_posts']['description'])
ck('read_board offers around_id and context, saying it is not a check', 'around_id' in tools['read_board']['inputSchema']['properties'] and 'context' in tools['read_board']['inputSchema']['properties'] and "Doesn't count as checking the pad" in tools['read_board']['inputSchema']['properties']['around_id']['description'])
ck('list_items offers contains', 'contains' in tools['list_items']['inputSchema']['properties'])
ins = rpc('initialize')['instructions']
ck('the instructions say how to find something said earlier, and to read it in context', 'To find something said earlier, call search_posts with a word or phrase' in ins and 'read_board with around_id set to a result\'s number' in ins and 'list_items has a contains filter' in ins)
# ---- searching
a = put('alice', 'We should pick the launch date soon.'); b = put('muse', "Launch date: **Tuesday 9 December**.\nThe second line carries more detail.", reply=a); put('muse', '@muse updated item #2: launch date moved.', 'event'); put('alice', 'Unrelated chatter about lunch.'); d = put('alice', 'Is the LAUNCH DATE still Tuesday?')
txt, sc = tool('search_posts', {'board': L, 'query': 'launch date'})
ck('search_posts finds the posts that contain it, newest first, saying how many and giving each a snippet', txt.startswith('OK Found 3 posts containing “launch date” on "alice/launch":') and txt.index(f'#{d} @alice') < txt.index(f'#{b} @muse') < txt.index(f'#{a} @alice') and 'Is the LAUNCH DATE still Tuesday?' in txt, txt)
ck('a reply says which post it answers, and a snippet collapses lines', f'#{b} @muse' in txt and f', in reply to #{a}' in txt and 'Launch date: **Tuesday 9 December**. The second line carries more detail.' in txt)
ck('events are not found, and it ends by saying how to read one in context', 'updated item #2' not in txt and txt.rstrip().endswith('To read one in context, call read_board with around_id set to its number.'))
ck('as data: the query, the total, each result, and no next batch', sc['total'] == 3 and sc['query'] == 'launch date' and [r['id'] for r in sc['results']] == [d, b, a] and sc['next_before_id'] is None and sc['pad'] == L and sc['results'][1]['reply_to'] == a)
ck('nothing found is not an error', tool('search_posts', {'board': L, 'query': 'zebra'})[0] == 'OK No posts on "alice/launch" contain “zebra”.')
ck('too short, too long, and a bad date are refused plainly', tool('search_posts', {'board': L, 'query': 'ab'})[0].startswith('ERR Search for at least 3 characters.') and tool('search_posts', {'board': L, 'query': 'x' * 201})[0].startswith('ERR A search is up to 200 characters. Yours is 201.') and tool('search_posts', {'board': L, 'query': 'launch', 'from': 'soon'})[0].startswith('ERR "from" should be a date'))
ck('it needs a token, and a pad you are not on looks like no pad', tool('search_posts', {'board': L, 'query': 'launch'}, token=None)[0].startswith('ERR This needs your agent token') and tool('search_posts', {'board': 'bob/launch', 'query': 'launch'})[0].startswith('ERR There is no pad'))
for i in range(25): put('alice', f'Item {i} mentions the quokka.')
txt, sc = tool('search_posts', {'board': L, 'query': 'quokka', 'limit': 10})
ck('ten of twenty-five, with the total and how to ask for the next ten', 'Found 25 posts containing “quokka” on "alice/launch" (showing 10, newest first):' in txt and f'call search_posts again with before_id={sc["next_before_id"]}.' in txt and sc['total'] == 25 and len(sc['results']) == 10)
txt2, sc2 = tool('search_posts', {'board': L, 'query': 'quokka', 'limit': 10, 'before_id': sc['next_before_id']}); txt3, sc3 = tool('search_posts', {'board': L, 'query': 'quokka', 'limit': 10, 'before_id': sc2['next_before_id']})
ck('the next batches continue without a repeat, and the last has no next', len({r['id'] for r in sc['results'] + sc2['results'] + sc3['results']}) == 25 and sc3['next_before_id'] is None and len(sc3['results']) == 5 and 'before_id=' not in txt3)
ck('by author, and by date', all(r['author'] == 'muse' for r in tool('search_posts', {'board': L, 'query': 'launch date', 'author': '@muse'})[1]['results']) and tool('search_posts', {'board': L, 'query': 'launch date', 'author': 'muse'})[1]['total'] == 1 and tool('search_posts', {'board': L, 'query': 'launch date', 'to': '2000-01-01'})[1]['total'] == 0 and tool('search_posts', {'board': L, 'query': 'launch date', 'from': '2000-01-01'})[1]['total'] == 3)
# ---- a post in context
txt, sc = tool('read_board', {'board': L, 'around_id': b})
ck('around_id gives the post with a few either side, and marks the one asked for', txt.startswith(f'OK Posts around #{b} on "alice/launch" (a few before and after; #{b} is the one you asked for):') and re.search(rf'--- #{b} from @muse at [^\n]+, in reply to #{a} --- \(the one you asked for\)', txt) and f'--- #{a} from @alice' in txt, txt[:500])
ck('it ends by saying where to keep reading from, and carries the messages as data', txt.rstrip().endswith(f'To keep reading from here, call read_board with since_id={sc["messages"][-1]["id"]}.') and sc['around_id'] == b and any(m['id'] == b for m in sc['messages']))
ck('context 0 is the post alone, and a number that is not there is refused', [m['id'] for m in tool('read_board', {'board': L, 'around_id': b, 'context': 0})[1]['messages']] == [b] and tool('read_board', {'board': L, 'around_id': 9999})[0].startswith('ERR There is no post #9999 on this pad.'))
php('$pdo->exec("UPDATE pad_members SET last_read_at = NULL");'); tool('search_posts', {'board': L, 'query': 'launch'}); tool('read_board', {'board': L, 'around_id': b})
ck('neither a search nor a read in context counts as checking the pad', lastread() == 'none')
tool('read_board', {'board': L, 'since_id': 1}); ck('an ordinary read still does', lastread() != 'none')
# ---- items
tool('add_item', {'board': L, 'type': 'decision', 'value': 'Launch on a Tuesday.'}); tool('add_item', {'board': L, 'type': 'question', 'value': 'Which launch venue?'}); tool('add_item', {'board': L, 'type': 'decision', 'value': 'Budget is 100% approved.'})
txt, sc = tool('list_items', {'board': L, 'contains': 'LAUNCH'}); ck('list_items with contains shows the items whose value contains it, and says so', txt.startswith('OK Items on "alice/launch" containing “LAUNCH” (2):') and [i['n'] for i in sc['items']] == [1, 2], txt[:200])
ck('narrowed by type; and a miss says what it looked for', [i['n'] for i in tool('list_items', {'board': L, 'contains': 'launch', 'type': 'question'})[1]['items']] == [2] and tool('list_items', {'board': L, 'contains': 'zebra'})[0].startswith('OK No items on "alice/launch" contain “zebra”.'))
ck('% is a plain character', [i['n'] for i in tool('list_items', {'board': L, 'contains': '100%'})[1]['items']] == [3])
# ---- the viewer
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []; native = []
    al = br.new_context(viewport={'width': 1280, 'height': 900}); ap = signin(al, 'alice'); ap.on('pageerror', lambda e: errs.append(str(e))); ap.on('dialog', lambda dlg: (native.append(dlg.message), dlg.dismiss()))
    ap.goto(B + '/pads/alice/launch'); ap.wait_for_selector('#pad-search')
    ck('the pad page has a search box under About and Items, above the reply box', ap.locator('#items').bounding_box()['y'] < ap.locator('#pad-search').bounding_box()['y'] < ap.locator('#reply-top').bounding_box()['y'] and ap.get_attribute('#pad-search-q', 'minlength') == '3' and ap.get_attribute('#pad-search', 'role') == 'search')
    ap.fill('#pad-search-q', 'launch date'); ap.click('#pad-search button'); ap.wait_for_selector('#search-results .hit')
    ck('searching shows how many posts contain it, newest first, each with who, when, its number and the post it replies to', ap.inner_text('#search-h') == '3 posts contain “launch date”' and ap.locator('.hit').count() == 3 and ap.locator('.hit').first.inner_text().startswith('@alice'), ap.inner_text('#search-results')[:300])
    hits = ap.locator('.hit').all_inner_texts()
    ck('the second hit says it replies to the first', f'#{b}' in hits[1] and f'in reply to #{a}' in hits[1], hits[1])
    ck('the match is marked in each snippet, as typed, ignoring case', ap.locator('.hit-snippet mark').count() == 3 and ap.locator('.hit-snippet mark').all_inner_texts() == ['LAUNCH DATE', 'Launch date', 'launch date'], ap.locator('.hit-snippet mark').all_inner_texts())
    ck('the results heading takes focus, for a screen reader', ap.evaluate("document.activeElement.id") == 'search-h')
    ap.click(f'.hit[data-hit="{b}"] [data-hit-show]'); ap.wait_for_selector(f'.hit[data-hit="{b}"] .hit-body')
    ck('Show post opens the whole post in place, as Markdown', ap.locator(f'.hit[data-hit="{b}"] .hit-body strong').inner_text() == 'Tuesday 9 December' and 'The second line carries more detail.' in ap.inner_text(f'.hit[data-hit="{b}"] .hit-post') and ap.get_attribute(f'.hit[data-hit="{b}"] [data-hit-show]', 'aria-expanded') == 'true')
    ap.click(f'.hit[data-hit="{b}"] [data-hit-show]'); ck('and Hide post closes it', ap.locator(f'.hit[data-hit="{b}"] .hit-post').is_hidden() and ap.inner_text(f'.hit[data-hit="{b}"] [data-hit-show]') == 'Show post')
    ck('a hit that is on the page offers a jump to it', ap.locator(f'.hit[data-hit="{b}"] [data-hit-jump]').count() == 1, ap.inner_text(f'.hit[data-hit="{b}"]'))
    ap.click(f'.hit[data-hit="{b}"] [data-hit-jump]'); ap.wait_for_timeout(300)
    ck('and jumping brings that message into view', ap.evaluate(f"(() => {{ const r = document.getElementById('m{b}').getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight }})()"))
    ap.click('[data-search-close]'); ck('Close clears the results and puts the cursor back in the box', ap.locator('#search-results .hit').count() == 0 and ap.evaluate("document.activeElement.id") == 'pad-search-q')
    ap.fill('#pad-search-q', 'zebra'); ap.click('#pad-search button'); ap.wait_for_selector('#search-h'); ck('a search that finds nothing says so', ap.inner_text('#search-h') == 'No posts contain “zebra”' and ap.locator('.hit').count() == 0)
    ap.fill('#pad-search-q', 'quokka'); ap.click('#pad-search button'); ap.wait_for_selector('.hit')
    ck('twenty of twenty-five show, with Show more', ap.inner_text('#search-h') == '25 posts contain “quokka”' and ap.locator('.hit').count() == 20 and ap.locator('[data-search-more]').count() == 1)
    ap.click('[data-search-more]'); ap.wait_for_function("document.querySelectorAll('.hit').length === 25")
    ck('Show more adds the rest and the button goes', ap.locator('[data-search-more]').count() == 0 and len({x for x in ap.locator('.hit').evaluate_all('els => els.map(e => e.dataset.hit)')}) == 25)
    ap.evaluate("document.getElementById('pad-search-q').removeAttribute('minlength')"); ap.fill('#pad-search-q', 'ab'); ap.click('#pad-search button'); ap.wait_for_selector('.form-error')
    ck('a search that is too short, got past the browser, is refused by the server in plain words', 'Search for at least 3 characters.' in ap.inner_text('#search-results'))
    outsider = br.new_context(); op = signin(outsider, 'bob'); r = op.request.get(B + '/api/?action=search&board=alice/launch&q=launch'); anon = br.new_context()
    ck('a person who is not on the pad, or not signed in, gets a 404 like a pad that does not exist', r.status == 404 and anon.request.get(B + '/api/?action=search&board=alice/launch&q=launch').status == 404 and op.request.get(B + '/api/?action=around&board=alice/launch&id=1').status == 404)
    r = al.request.get(B + '/api/?action=around&board=alice/launch&id=1&context=0'); ck('the in-place read is a post by its number, with its body', r.status == 200 and len(r.json()['messages']) == 1 and r.json()['messages'][0]['id'] == 1 and 'body' in r.json()['messages'][0])
    r = al.request.get(B + '/api/?action=around&board=alice/launch&id=99999'); ck('and a number that is not there is a 400 with the reason', r.status == 400 and 'There is no post #99999' in r.json()['message'])
    ap.goto(B + '/pads/?b=old-open'); ap.wait_for_selector('#transcript .msg'); ck('an older open pad has no search box', ap.locator('#pad-search').count() == 0)
    ap.goto(B + '/pads/alice/launch?view=settings'); ap.wait_for_selector('#brief'); ck('and neither has Pad info', ap.locator('#pad-search').count() == 0)
    ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + '/pads/alice/launch'); ap.wait_for_selector('#pad-search'); ap.fill('#pad-search-q', 'quokka'); ap.click('#pad-search button'); ap.wait_for_selector('.hit')
    ck('with results open it fits a 320px screen', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ap.set_viewport_size({'width': 1000, 'height': 900}); ap.goto(B + '/pads/alice/launch'); ap.wait_for_selector('#pad-search'); ap.fill('#pad-search-q', 'launch date'); ap.click('#pad-search button'); ap.wait_for_selector('.hit'); ap.click(f'.hit[data-hit="{b}"] [data-hit-show]'); ap.wait_for_selector('.hit-body'); ap.screenshot(path='/tmp/search.png', full_page=False)
    ck('no browser popup and no script errors', native == [] and not errs, (native, errs))
    br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
