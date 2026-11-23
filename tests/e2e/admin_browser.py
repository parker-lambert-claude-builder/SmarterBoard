import sys
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:300]))
def signin(pg, who):
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
with sync_playwright() as p:
    br = p.chromium.launch()
    anon = br.new_context().new_page(); errs = []
    r = anon.goto(B + '/pricing/'); t = anon.inner_text('main')
    ck('pricing page serves signed out', r.status == 200 and 'Mashpad Plus' in t and '$5 a month' in t and 'Coming soon' in t and 'Free' in t, t)
    ck('pricing shows plan facts from the plans table', 'kept for 30 days' in t and 'kept for 6 months' in t and 'one post a minute' in t and 'Threaded replies' in t)
    ck('signed out, Free offers an account', anon.locator('a:has-text("Create a free account")').count() == 1)
    ck('pricing without the slash redirects', anon.goto(B + '/pricing').url == B + '/pricing/')
    anon.set_viewport_size({'width': 320, 'height': 700}); anon.goto(B + '/pricing/'); ck('pricing: no horizontal overflow at 320px', anon.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    r = anon.goto(B + '/admin/'); ck('admin is a 404 when signed out', r.status == 404 and 'Page not found' in anon.inner_text('main'))
    bob = br.new_context().new_page(); signin(bob, 'bob')
    r = bob.goto(B + '/admin/'); ck('admin is a 404 for a non-admin, same page', r.status == 404 and bob.inner_text('main') == anon.inner_text('main'))
    ck('a non-admin sees no Admin link', bob.goto(B + '/pads/') and bob.wait_for_selector('#account a') and bob.locator('#account a:has-text("Admin")').count() == 0)
    bob.goto(B + '/pricing/'); ck('signed in, pricing marks your plan', 'This is your plan.' in bob.inner_text('#plan-free >> xpath=..'))
    al = br.new_context().new_page(); al.on('pageerror', lambda e: errs.append(str(e))); signin(al, 'alice')
    al.wait_for_selector('#account a:has-text("Admin")'); al.click('#account a:has-text("Admin")'); al.wait_for_url('**/admin/')
    t = al.inner_text('main'); ck('admin lists both accounts with pads and agents', '@alice' in t and '@bob' in t and '2 accounts' in t and '1 agent' in t, t)
    row = al.locator('.admin-accounts li', has_text='@bob'); row.locator('select').select_option('plus'); row.locator('button').click(); al.wait_for_selector('.notice')
    ck('switching bob to Plus confirms and sticks', '@bob is now on Mashpad Plus' in al.inner_text('.notice') and al.locator('.admin-accounts li', has_text='@bob').locator('select').input_value() == 'plus')
    bob.goto(B + '/pricing/'); ck('bob\'s pricing page now marks Plus as his plan', 'This is your plan.' in bob.inner_text('#plan-plus >> xpath=..'))
    bob.goto(B + '/pads/bob/launch?view=settings'); bob.wait_for_selector('.settings-page')
    row = al.locator('.admin-accounts li', has_text='@bob'); row.locator('select').select_option('free'); row.locator('button').click(); al.wait_for_selector('.notice')
    ck('and back to Free', '@bob is now on Mashpad' in al.inner_text('.notice'))
    code = bob.evaluate("fetch('%s/admin/', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'account=1&plan=plus&csrf=x'}).then(r => r.status)" % B)
    ck('a non-admin POST to admin changes nothing and is a 404', code == 404)
    al.set_viewport_size({'width': 375, 'height': 700}); al.goto(B + '/admin/'); ck('admin: no horizontal overflow at 375px', al.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
