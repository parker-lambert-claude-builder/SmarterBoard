import sys, subprocess, json, re
import os
SITE = os.environ.get('MASHPAD_SITE', '/srv/site')                      # where the site is deployed (see README)
E2E_ENV = os.environ.get('MASHPAD_E2E_ENV', '/tmp/e2e.env')       # written by seed.php: an agent token and a lobby code
FIXTURES = os.environ.get('MASHPAD_TEST_CIMD_DIR', '/tmp/cimd')   # the directory the server reads app documents from
FAKEBREVO_LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
from playwright.sync_api import sync_playwright
B = sys.argv[1]; res = []
def ck(name, cond, detail=''):
    res.append(bool(cond)); print(('ok   ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(detail)[:400]))
def mcp(tool, args, token=None):
    cmd = ['curl', '-s', '-H', 'Content-Type: application/json'] + (['-H', 'Authorization: Bearer ' + token] if token else []) + [B + '/mcp/open/', '-d',
        json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}})]
    r = json.loads(subprocess.run(cmd, capture_output=True, text=True).stdout)['result']; return ('ERR ' if r.get('isError') else 'OK ') + r['content'][0]['text']
def plan(code): subprocess.run(['php', '-r', 'require "' + SITE + '/src/pads.php"; mb_db()->exec("UPDATE principals SET plan_code = \'%s\' WHERE handle = \'bob\'"); mb_db()->exec("UPDATE messages SET created_at = created_at - INTERVAL 2 MINUTE");' % code], check=True)
CODE = open(E2E_ENV).read().split('CODE=')[1].strip()
with sync_playwright() as p:
    br = p.chromium.launch(); pg = br.new_context(viewport={'width': 1280, 'height': 900}).new_page(); errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/signin/'); pg.fill('input[name=email]', 'bob@example.com'); pg.fill('input[name=password]', 'correct horse battery'); pg.click('button[type=submit]'); pg.wait_for_url('**/pads/')
    tok = re.search(r'mp_a_\S+', mcp('join_pad', {'pad': 'bob/launch', 'lobby_code': CODE, 'handle': 'tbot'})).group(0)
    pg.goto(B + '/pads/bob/launch'); pg.wait_for_selector('#transcript .msg')
    ck('on Free there are no per-message Reply links', pg.locator('[data-reply-to]').count() == 0 and pg.locator('.tmsg').count() == 0)
    r = mcp('post_message', {'board': 'bob/launch', 'message': 'Agent reply on Free.', 'reply_to': 1}, tok); ck('on Free an agent\'s reply_to is accepted and lands at the end, and it is told so', r.startswith('OK Posted message #2') and "doesn't have threaded replies" in r, r)
    plan('plus'); pg.reload(); pg.wait_for_selector('.tmsg')
    ck('on Plus every message has its own Reply link', pg.locator('.tmsg').count() == 2 and pg.locator('.tmsg [data-reply-to]').count() == 2)
    ck('the top and bottom boxes are still there', pg.locator('#reply-top-form').count() == 1 and pg.locator('#reply-bottom-form').count() == 1)
    pg.click('#m1 > .tmsg-actions [data-reply-to]'); pg.wait_for_selector('#reply-m1-form')
    ck('Reply opens a box under that message, already open, addressed to its author, focused', pg.get_attribute('#reply-m1-body', 'placeholder') == 'Reply to @bob' and pg.locator('#reply-m1-form.is-open').count() == 1
       and pg.evaluate("document.activeElement.id") == 'reply-m1-body' and pg.locator('#reply-m1-form button:visible').all_inner_texts() == ['Cancel', 'Post'])
    box = pg.eval_on_selector('#reply-m1-form', 'e => e.getBoundingClientRect().top'); ck('it sits between the message and the next one', pg.eval_on_selector('#m1 > .body', 'e => e.getBoundingClientRect().bottom') <= box < pg.eval_on_selector('#m2', 'e => e.getBoundingClientRect().top'))
    pg.click('#reply-m1-form button:has-text("Cancel")'); ck('Cancel removes the box and returns focus to that Reply link', pg.locator('#reply-m1-form').count() == 0 and pg.evaluate("document.activeElement.dataset.replyTo") == '1')
    pg.keyboard.press('Enter'); pg.wait_for_selector('#reply-m1-form'); ck('keyboard: Enter on Reply opens it again', pg.evaluate("document.activeElement.id") == 'reply-m1-body')
    pg.fill('#reply-m1-body', 'Replying under the first message.'); pg.keyboard.press('Control+Enter'); pg.wait_for_selector('#replies-m1 > #m3')
    ck('the reply appears indented under the message it answers, and the box is gone', 'Replying under the first' in pg.inner_text('#replies-m1 > #m3') and pg.locator('#reply-m1-form').count() == 0)
    ck('it is indented', pg.eval_on_selector('#m3', 'e => e.getBoundingClientRect().left') > pg.eval_on_selector('#m1', 'e => e.getBoundingClientRect().left') + 15)
    r = mcp('post_message', {'board': 'bob/launch', 'message': 'Agent answering the reply.', 'reply_to': 3}, tok); ck('an agent replies to a reply with reply_to', r.startswith('OK Posted message #4') and 'in reply to #3' in r, r)
    pg.wait_for_selector('#replies-m3 > #m4', timeout=10000); ck('it appears live, nested a level deeper', 'Agent answering' in pg.inner_text('#replies-m3 > #m4'))
    r = mcp('read_board', {'board': 'bob/launch'}, tok); ck('a read marks which message each reply answers', '--- #4 from @tbot at ' in r and ', in reply to #3 ---' in r and re.search(r'--- #3 from @bob at [^\n]*, in reply to #1 ---', r) is not None, r[-400:])
    ck('replying to a message that is not there is refused', mcp('post_message', {'board': 'bob/launch', 'message': 'x', 'reply_to': 77}, tok).startswith('ERR Not posted: there is no message #77'))
    pg.click('#reply-top-body'); pg.fill('#reply-top-body', 'A new top-level point.'); plan('plus'); pg.click('#reply-top-form button:has-text("Post")'); pg.wait_for_selector('#transcript > #m5')
    ck('the top box still posts to the pad as a whole, at the end', pg.locator('.replies #m5').count() == 0)
    pg.click('#reply-bottom-body'); pg.fill('#reply-bottom-body', 'x'); pg.click('#reply-bottom-form button:has-text("Cancel")')
    ck('Cancel on a standing box closes it and leaves the keyboard in the box', pg.locator('#reply-bottom-form.is-open').count() == 0 and pg.evaluate("document.activeElement.id") == 'reply-bottom-body' and pg.input_value('#reply-bottom-body') == '')
    pg.keyboard.type('h'); ck('typing there opens it again', pg.locator('#reply-bottom-form.is-open').count() == 1); pg.fill('#reply-bottom-body', '')
    for w in (375, 320):
        pg.set_viewport_size({'width': w, 'height': 700}); pg.goto(B + '/pads/bob/launch'); pg.wait_for_selector('.tmsg'); pg.click('#m4 > .tmsg-actions [data-reply-to]')
        ck(f'no horizontal overflow at {w}px with nested replies and a box open', pg.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    pg.set_viewport_size({'width': 1280, 'height': 1100}); pg.goto(B + '/pads/bob/launch'); pg.wait_for_selector('.tmsg'); pg.click('#m3 > .tmsg-actions [data-reply-to]'); pg.screenshot(path='/tmp/threads.png', full_page=True)
    with pg.expect_download() as d:
        pg.goto(B + '/pads/bob/launch?view=settings'); pg.wait_for_selector('.settings-page'); pg.click('a:has-text("Download Markdown")')
    ck('the export says what each reply answers', '(reply to #3)' in open(d.value.path()).read())
    plan('free'); pg.goto(B + '/pads/bob/launch'); pg.wait_for_selector('#transcript .msg')
    ck('back on Free the pad is flat, with no Reply links, and replies say what they answered', pg.locator('[data-reply-to]').count() == 0 and pg.locator('.tmsg').count() == 0 and 'in reply to #1' in pg.inner_text('#m3'))
    ck('no script errors', not errs, errs); br.close()
print(f"{sum(res)} passed, {len(res)-sum(res)} failed")
