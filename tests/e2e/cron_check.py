import sys, subprocess, json, os, time
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def http(method, url, headers=None, data=None):
    cmd = ['curl', '-s', '-i', '-o', '-', '-w', '\nTIME:%{time_total}', '-X', method, url]
    if method == 'HEAD': cmd = ['curl', '-s', '-I', '-w', '\nTIME:%{time_total}', url]
    for k, v in (headers or {}).items(): cmd += ['-H', f'{k}: {v}']
    if data is not None: cmd += ['--data', data]
    raw = subprocess.run(cmd, capture_output=True, text=True).stdout.replace('\r\n', '\n')
    raw, _, t = raw.rpartition('\nTIME:'); head, _, body = raw.partition('\n\n')
    hd = {l.split(':', 1)[0].lower(): l.split(':', 1)[1].strip() for l in head.split('\n')[1:] if ':' in l}
    return int(head.split()[1]), hd, body, float(t)
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    ap = signin(br.new_context(viewport={'width': 1280, 'height': 1000}), 'alice'); ap.on('pageerror', lambda e: errs.append(str(e)))
    bp = signin(br.new_context(), 'bob'); anon = br.new_context().new_page()
    r = bp.goto(B + '/admin/cron/'); ra = anon.goto(B + '/admin/cron/')
    ck('the Cron page is a 404 for anyone who is not an administrator, the same page as for someone signed out', r.status == 404 and ra.status == 404 and bp.inner_text('main') == anon.inner_text('main'))
    ap.goto(B + '/admin/'); ck('an administrator has Cron among the admin sections', ap.locator('nav[aria-label="Admin sections"] a:has-text("Cron")').count() == 1)
    ap.goto(B + '/admin/cron/'); t = ap.inner_text('main')
    ck('it explains the job, says there is no key yet and that it has never run', 'one scheduled job' in t and 'php /path/to/site/bin/wake-cron.php' in t and 'There is no key yet.' in t and 'It has never run.' in t, t[:400])
    s0, h0, b0, _ = http('GET', B + '/cron/')
    ck('before a key exists the address is a 404, whatever is sent', s0 == 404 and 'Not found' in b0 and http('GET', B + '/cron/', {'Authorization': 'Bearer mpc_anything'})[0] == 404)
    ap.click('button:has-text("Make a key")'); ap.wait_for_selector('#cron-key'); key = ap.inner_text('#cron-key').strip(); t = ap.inner_text('main')
    ck('a key is made and shown once, with the address, the header and the address with the key in it', key.startswith('mpc_') and len(key) == 52 and 'It is shown only this once' in t and ap.inner_text('#cron-url').strip().endswith('/cron/') and f'Authorization: Bearer {key}' in t and ap.inner_text('#cron-url-key').strip().endswith('/cron/?key=' + key), t[:300])
    ck('the setup notes say what to ask the service for', 'every minute' in t and 'Authorization' in t and '404, on purpose' in t and 'about 30 seconds' in t and 'only works over https' in t)
    ap.goto(B + '/admin/cron/'); t = ap.inner_text('main')
    ck('opening the page again does not show the key', key not in ap.content() and 'A key exists.' in t and 'can\'t be shown again' in t and 'Make a new key' in t)
    # ---- calling it
    wrong = http('GET', B + '/cron/', {'Authorization': 'Bearer mpc_' + '0' * 48})
    ck('a wrong key, no key, garbage and a wrong method are all the same 404, and a wrong guess takes a moment', wrong[0] == 404 and http('GET', B + '/cron/')[0] == 404 and http('GET', B + '/cron/?key=nope')[0] == 404 and http('DELETE', B + '/cron/', {'Authorization': 'Bearer ' + key})[0] == 404 and wrong[3] >= 0.25 and wrong[2] == http('GET', B + '/cron/')[2], wrong)
    s, h, b, _ = http('GET', B + '/cron/', {'Authorization': f'Bearer {key}'}); j = json.loads(b)
    ck('the right key in a Bearer header runs the job and gets a small JSON summary', s == 200 and j['ok'] is True and all(k in j for k in ('sent', 'retry', 'failed', 'pruned', 'messages_pruned', 'ran_at', 'ms')) and h['content-type'].startswith('application/json'), (s, b))
    ck('the answer is never cached or indexed, and never contains the key', h.get('cache-control') == 'no-store' and 'noindex' in h.get('x-robots-tag', '') and key not in b)
    ck('?key=, an X-Cron-Key header and POST do the same', http('GET', B + '/cron/?key=' + key)[0] == 200 and http('GET', B + '/cron/', {'X-Cron-Key': key})[0] == 200 and http('POST', B + '/cron/', {'Authorization': 'Bearer ' + key}, 'x=1')[0] == 200)
    sh, hh, bh, _ = http('HEAD', B + '/cron/?key=' + key)
    ck('a HEAD request is accepted and has no body', sh == 200 and bh.strip() == '', (sh, bh))
    ap.goto(B + '/admin/cron/'); t = ap.inner_text('main')
    ck('the admin page says when it last ran, how, and what it did', 'Ran just now' in t and 'called over the web' in t and 'wake-ups' in t and 'old private message' in t and 'It hasn\'t run for more than ten minutes' not in t, t[:500])
    # ---- a new key, and running it from the page
    ap.click('button:has-text("Make a new key")'); ap.wait_for_selector('#cron-key'); key2 = ap.inner_text('#cron-key').strip()
    ck('a new key replaces the old one: the old stops working at once, the new works', key2 != key and http('GET', B + '/cron/', {'Authorization': 'Bearer ' + key})[0] == 404 and http('GET', B + '/cron/', {'Authorization': 'Bearer ' + key2})[0] == 200)
    ap.goto(B + '/admin/cron/'); ap.click('button:has-text("Run it now")'); ap.wait_for_load_state(); t = ap.inner_text('main')
    ck('Run it now runs it and says so, and the status says it was run from the page', 'Ran it now:' in t and 'run from this page' in t, t[:400])
    ck('the key never shows up in a page', key2 not in ap.content())
    ap.set_viewport_size({'width': 320, 'height': 800}); ap.goto(B + '/admin/cron/'); ap.click('button:has-text("Make a new key")'); ap.wait_for_selector('#cron-key')
    ck('with a key on screen the page still fits 320px', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
