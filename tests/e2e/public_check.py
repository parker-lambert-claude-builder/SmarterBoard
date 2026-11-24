import sys, subprocess, json, re
import os
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:600]))
def signin(ctx, who):
    pg = ctx.new_page(); pg.goto(B + '/signin/'); pg.fill('input[name=email]', who + '@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/'); return pg
PUBLIC = [('home', '/', 'home'), ('How it works', '/features/', 'features'), ('Pricing', '/pricing/', 'pricing'), ('Sign in', '/signin/', 'signin'), ('Create an account', '/register/', 'register'), ('Terms', '/terms/', ''), ('Privacy', '/privacy/', ''), ('reset', '/reset/', ''), ('404', '/no-such-page/', '')]
OUT = ['How it works', 'Pricing', 'Sign in', 'Create an account']; IN = ['How it works', 'Pricing', 'Your pads']
nav = lambda pg: pg.eval_on_selector_all('header.site-header .site-nav a', 'els => els.map(e => e.textContent.trim())')
with sync_playwright() as p:
    br = p.chromium.launch(); errs = []
    anon = br.new_context(viewport={'width': 1280, 'height': 900}); ap = anon.new_page(); ap.on('pageerror', lambda e: errs.append(str(e)))
    user = br.new_context(viewport={'width': 1280, 'height': 900}); up = signin(user, 'alice')
    # ---- the same header and footer on every public page
    for label, path, key in PUBLIC:
        ap.goto(B + path)
        ck(f'{label}: signed out, the header has How it works, Pricing, Sign in and Create an account, in that order', nav(ap) == OUT, nav(ap))
        ck(f'{label}: the logo goes home, and there is one header', ap.locator('header.site-header a.brand').get_attribute('href') == '/' and ap.locator('header.site-header').count() == 1 and ap.locator('.account-sidebar').count() == 0)
        ck(f'{label}: the footer is Terms, Privacy, Mashpad home, with nothing that only looks like a link', ap.eval_on_selector_all('footer a', 'els => els.map(e => e.textContent.trim())') == ['Terms', 'Privacy', 'Mashpad home'] and 'mashpad.ai' not in ap.inner_text('footer').lower())
        up.goto(B + path)
        ck(f'{label}: signed in, the header has How it works, Pricing and Your pads', nav(up) == IN, nav(up))
        cur = ap.eval_on_selector_all('header.site-header [aria-current="page"]', 'els => els.map(e => e.textContent.trim())')
        want = {'home': [], 'How it works': ['How it works'], 'Pricing': ['Pricing'], 'Sign in': ['Sign in'], 'Create an account': ['Create an account'], 'reset': ['Sign in']}.get(label, [])
        ck(f'{label}: ' + (f'{want[0]} is marked as the page you are on' if want else 'no link is marked as current'), cur == want, cur)
    # ---- Sign in and Create an account: the form's button is the strongest thing
    ap.goto(B + '/signin/'); ck('Sign in page: Create an account is a quiet link, and the form button is the only dark button', ap.locator('.nav-cta').count() == 0 and ap.locator('header.site-header a:has-text("Create an account")').count() == 1 and ap.locator('main button[type=submit]').count() == 1)
    ap.goto(B + '/register/'); ck('Create an account page: the same, with Sign in as the quiet link', ap.locator('.nav-cta').count() == 0 and ap.locator('header.site-header a:has-text("Sign in")').count() == 1)
    ap.goto(B + '/pricing/'); ck('everywhere else Create an account is the one dark button in the header', ap.locator('header.site-header a.nav-cta').inner_text() == 'Create an account')
    # ---- the home page
    ap.goto(B + '/'); ck('home, signed out: the main button says Create a free account, with Sign in and How it works under it', ap.locator('a.continue').inner_text().strip().lower() == 'create a free account' and ap.locator('a.continue').get_attribute('href') == 'register/' and ap.locator('#entry-note a').all_inner_texts() == ['Sign in', 'How it works'], ap.locator('#entry-note').inner_text())
    up.goto(B + '/'); ck('home, signed in: the main button says Your pads', up.locator('a.continue').inner_text().strip().lower() == 'your pads' and up.locator('a.continue').get_attribute('href') == 'pads/')
    # ---- Pricing
    ap.goto(B + '/pricing/')
    ck('Pricing: the Free plan has a real button to create an account', ap.locator('.plan a.plan-action').first.inner_text() == 'Create a free account' and ap.locator('.plan a.plan-action').first.evaluate('e => getComputedStyle(e).backgroundColor') != 'rgba(0, 0, 0, 0)')
    soon = ap.locator('.plan-status', has_text='Coming soon')
    ck('Pricing: "Coming soon" is plain text, not a pill or a button', soon.count() == 1 and soon.evaluate('e => getComputedStyle(e).borderTopWidth') == '0px' and soon.evaluate('e => e.tagName') == 'SPAN' and ap.locator('.plan-soon').count() == 0)
    up.goto(B + '/pricing/'); ck('Pricing, signed in: no sign-up button, and the plan they are on says so', 'Create a free account' not in up.inner_text('main') and 'This is your plan.' in up.inner_text('main'))
    # ---- How it works
    ap.goto(B + '/features/'); ck('How it works: the page ends with a Create a free account button, and Your pads when signed in', ap.locator('.feature-action a').inner_text() == 'Create a free account' and ap.locator('.feature-action a').get_attribute('href').endswith('register/'))
    up.goto(B + '/features/'); ck('and signed in', up.locator('.feature-action a').inner_text() == 'Your pads')
    ck('no page is called Features or Open your pads any more', 'Open your pads' not in ap.inner_text('body') and ap.locator('header.site-header a:has-text("Features")').count() == 0)
    # ---- layout: a centered container, with narrower columns for what is read or filled in
    box = lambda pg, sel: pg.locator(sel).first.bounding_box()
    ap.goto(B + '/signin/'); f = box(ap, 'main'); s = box(ap, '.public-shell')
    ck('the Sign in form sits in a column of about 480px, centered in the page', 440 <= f['width'] <= 500 and abs((f['x'] - s['x']) - ((s['x'] + s['width']) - (f['x'] + f['width']))) < 40, (f, s))
    ap.goto(B + '/terms/'); f = box(ap, 'main'); ck('Terms are a reading column no wider than about 70 characters, centered', 600 <= f['width'] <= 760 and abs(f['x'] - (1280 - f['x'] - f['width'])) < 40, f)
    ap.goto(B + '/pricing/'); f = box(ap, 'main'); ck('Pricing uses the whole container, which is no wider than 1120px plus its margins', 900 <= f['width'] <= 1060 and box(ap, '.public-shell')['width'] <= 1120, f)
    # ---- the app keeps its sidebar
    up.goto(B + '/agents/'); ck('signed-in app pages keep their sidebar, and have no public header', up.locator('.account-sidebar').count() == 1 and up.locator('header.site-header').count() == 0)
    up.goto(B + '/pads/'); ck('the pads page keeps the app rail', up.locator('#board-nav, .rail, nav[aria-label*="Pads"], aside').count() >= 1 and up.locator('header.site-header').count() == 0)
    # ---- the 404
    s2 = anon.request.get(B + '/no-such-page/'); body = s2.text(); ck('an address that is not there is a Mashpad page and still a 404', s2.status == 404 and 'Page not found' in body and 'site-header' in body and 'No such file' not in body)
    ck('and it points to Sign in or Create an account when signed out, and to the pads when signed in, and says nothing about any pad', 'Sign in' in body and 'create an account' in body and 'alice' not in body.lower().replace('mashpad', '') and 'Go to your pads' in user.request.get(B + '/no-such-page/').text())
    ck('even an address that looks like a private pad gives the same page', anon.request.get(B + '/pads/alice/no-such-pad').status in (200, 404) and 'launch' not in anon.request.get(B + '/no-such-folder/alice/launch').text())
    # ---- keyboard and phones
    ap.goto(B + '/pricing/'); ap.keyboard.press('Tab'); ck('the first Tab goes to the skip link, then through the header in order', ap.evaluate('document.activeElement.className') == 'skip-link')
    order = []
    for _ in range(5): ap.keyboard.press('Tab'); order.append(ap.evaluate('document.activeElement.textContent.trim()'))
    ck('then the logo and the links, in the order they read', order[:5] == ['Mashpad', 'How it works', 'Pricing', 'Sign in', 'Create an account'], order)
    ap.set_viewport_size({'width': 390, 'height': 800}); ap.goto(B + '/pricing/')
    ck('on a phone the links fold into a button called Menu, closed to begin with', ap.locator('.menu-button').is_visible() and ap.get_attribute('.menu-button', 'aria-expanded') == 'false' and not ap.locator('#site-nav').is_visible())
    ap.click('.menu-button'); ck('pressing it opens them, and says so', ap.get_attribute('.menu-button', 'aria-expanded') == 'true' and ap.locator('#site-nav').is_visible() and nav(ap) == OUT)
    ap.keyboard.press('Escape'); ck('Escape closes it and returns to the button', ap.get_attribute('.menu-button', 'aria-expanded') == 'false' and ap.evaluate('document.activeElement.className') == 'menu-button')
    ap.set_viewport_size({'width': 1280, 'height': 800}); ap.wait_for_timeout(200); ck('on a wide screen there is no Menu button and the links show', not ap.locator('.menu-button').is_visible() and ap.locator('#site-nav').is_visible())
    for path in ['/', '/features/', '/pricing/', '/signin/', '/register/', '/terms/', '/privacy/', '/no-such-page/']:
        ap.set_viewport_size({'width': 320, 'height': 700}); ap.goto(B + path); ck(f'{path}: no sideways scroll at 320px', ap.evaluate('document.documentElement.scrollWidth <= innerWidth'), ap.evaluate('document.documentElement.scrollWidth'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
