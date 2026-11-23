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
rect = lambda pg, sel: pg.eval_on_selector(sel, 'e => e.getBoundingClientRect().toJSON()')
with sync_playwright() as p:
    br = p.chromium.launch(); pg = br.new_context(viewport={'width': 1280, 'height': 900}).new_page()
    errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', 'alice@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
    pg.wait_for_selector('#board-nav .pad-row'); ck('the pad list has no Private mark', 'Private' not in pg.inner_text('#board-nav'))
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#transcript .msg')
    ck('two reply boxes are on the page without pressing anything', pg.locator('form.reply-box').count() == 2 and pg.locator('form.reply-box textarea:visible').count() == 2)
    ck('no Reply buttons and nothing pinned to the page bottom', pg.locator('.reply-button').count() == 0 and pg.locator('.composer').count() == 0)
    head, brief, top, msg, bottom = rect(pg, '.board-head'), rect(pg, '#brief'), rect(pg, '#reply-top-form'), rect(pg, '#transcript .group'), rect(pg, '#reply-bottom-form')
    ck('order: info header, brief, reply box, messages, reply box', head['bottom'] <= brief['top'] and brief['bottom'] <= top['top'] and top['bottom'] <= msg['top'] and msg['bottom'] <= bottom['top'], (head, brief, top, msg, bottom))
    ck('both boxes are exactly as wide as the posts', all(abs(b['width'] - msg['width']) < 1 and abs(b['left'] - msg['left']) < 1 for b in (top, bottom)), (top['width'], bottom['width'], msg['width']))
    ck('at rest a box is one line with its prompt, and shows no buttons', top['height'] < 70 and pg.get_attribute('#reply-top-body', 'placeholder') == 'Join the conversation' and pg.locator('#reply-top-form button:visible').count() == 0, top['height'])
    pg.click('#reply-top-body'); pg.wait_for_selector('#reply-top-form.is-open')
    ck('clicking in opens it: taller, with Cancel and Post', rect(pg, '#reply-top-form')['height'] > 150 and pg.locator('#reply-top-form button:visible').all_inner_texts() == ['Cancel', 'Post'], pg.locator('#reply-top-form button:visible').all_inner_texts())
    ck('signed in, there is no name field', pg.locator('.reply-who:visible').count() == 0)
    ck('the other box stays at rest', pg.locator('#reply-bottom-form.is-open').count() == 0)
    pg.fill('#reply-top-body', 'discard me'); pg.click('#reply-top-form button:has-text("Cancel")')
    ck('Cancel empties and closes it', pg.input_value('#reply-top-body') == '' and pg.locator('#reply-top-form.is-open').count() == 0 and rect(pg, '#reply-top-form')['height'] < 70)
    pg.click('#reply-top-body'); pg.fill('#reply-top-body', 'From the top box, hello @muse and @nobody.'); pg.click('#reply-top-form button:has-text("Post")'); pg.wait_for_selector('#m2')
    ck('Post from the top box adds the message and the box closes', 'From the top box' in pg.inner_text('#m2') and pg.locator('#reply-top-form.is-open').count() == 0 and pg.input_value('#reply-top-body') == '')
    h = pg.inner_html('#m2'); ck('@muse links to their info page; @nobody does not', 'class="mention"' in h and 'agent=muse' in h and 'agent=nobody' not in h, h)
    pg.click('#reply-bottom-body'); pg.fill('#reply-bottom-body', 'Too soon.'); pg.keyboard.press('Control+Enter'); pg.wait_for_selector('#reply-bottom-form .form-error')
    ck('a refused post (one a minute) explains itself in the box and keeps the text', 'one post per minute' in pg.inner_text('#reply-bottom-form .form-error') and pg.input_value('#reply-bottom-body') == 'Too soon.' and pg.locator('#reply-bottom-form.is-open').count() == 1)
    pg.click('#m2 a.mention'); pg.wait_for_selector('.agent-page'); ck('a mention opens the participant\'s info page in the app', pg.url == B + '/pads/alice/launch?view=agent&agent=muse', pg.url)
    pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#transcript .msg'); pg.focus('#reply-bottom-body')
    ck('keyboard: tabbing into a box opens it, and Tab reaches Cancel then Post', pg.locator('#reply-bottom-form.is-open').count() == 1 and [pg.keyboard.press('Tab') or pg.evaluate("document.activeElement.textContent") for _ in range(2)] == ['Cancel', 'Post'])
    pg.focus('#reply-bottom-body'); pg.keyboard.press('Escape'); ck('Escape closes an empty box', pg.locator('#reply-bottom-form.is-open').count() == 0)
    for w in (375, 320):
        pg.set_viewport_size({'width': w, 'height': 700}); pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#transcript .msg'); pg.click('#reply-bottom-body')
        ck(f'no horizontal overflow at {w}px with a box open', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    anon = br.new_context().new_page(); anon.goto(B + '/pads/?b=old-open'); anon.wait_for_url('**/signin/**'); ck('signed out, an old open pad sends you to sign in rather than offering a name box', '/signin/?next=' in anon.url)
    pg.set_viewport_size({'width': 1280, 'height': 1000}); pg.goto(B + '/pads/alice/launch'); pg.wait_for_selector('#transcript .msg'); pg.evaluate('window.scrollTo(0,0)'); pg.screenshot(path='/tmp/reply_rest.png', full_page=True)
    pg.click('#reply-bottom-body'); pg.fill('#reply-bottom-body', 'Typing a reply here.'); pg.screenshot(path='/tmp/reply_open.png', full_page=True)
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
