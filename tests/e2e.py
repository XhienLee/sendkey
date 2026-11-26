# Run with: python3 tests/e2e.py   (needs php and Playwright's Chromium, see tests/requirements.txt)
import json
import os
import pathlib
import random
import shutil
import subprocess
import sys
import tempfile
import time

os.environ['NO_PROXY'] = '*'
from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
WORK = pathlib.Path(tempfile.mkdtemp())
PORT = 18900 + random.randint(0, 90)
BASE = f'http://127.0.0.1:{PORT}/'
results = [0, 0]


def check(name, ok, info=''):
    results[0 if ok else 1] += 1
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else f'  {info}'), flush=True)


def write(name, data):
    path = WORK / name
    path.write_bytes(data if isinstance(data, bytes) else data.encode())
    return path


env = dict(os.environ, FAMILY_DATA_DIR=str(WORK / 'data'), FAMILY_MIN_FREE_BYTES='0', SENDKEY_PER_IP_HOUR='100')
server = subprocess.Popen(['php', '-d', 'post_max_size=40M', '-S', f'127.0.0.1:{PORT}', '-t', str(ROOT), str(ROOT / 'router.php')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)


class Tab:
    def __init__(self, context, path):
        self.page = context.new_page()
        self.errors = []
        self.requests = []
        self.page.on('pageerror', lambda e: self.errors.append('PAGEERROR ' + str(e)))
        self.page.on('console', lambda m: self.errors.append(m.text) if m.type == 'error' and 'status of 4' not in m.text else None)
        self.page.on('request', lambda r: self.requests.append(r))
        self.page.goto(BASE + path)


def words_of(page, selector):
    return page.locator(f'{selector} .key-word').all_inner_texts()


def body_has(tab, needle):
    return any(r.method == 'POST' and r.post_data_buffer and needle in r.post_data_buffer for r in tab.requests)


try:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(args=['--no-proxy-server'])
        context = browser.new_context(accept_downloads=True, viewport={'width': 1100, 'height': 900})

        send = Tab(context, '')
        p = send.page
        secret = write('plan.txt', 'the secret plan, 12345')
        p.set_input_files('#file', str(secret))
        p.check('input[name="mode"][value="apart"]')
        p.click('#send')
        p.wait_for_selector('#sent', state='visible', timeout=90000)
        link = p.input_value('#link')
        key = '-'.join(words_of(p, '#words'))
        check('sendkey: the link has no key and the 6 words are shown apart', '#' not in link and '?s=' in link and len(key.split('-')) == 6, link)
        check('sendkey: the upload does not contain the file text', not body_has(send, b'secret plan') and any(r.method == 'POST' for r in send.requests))
        get = Tab(context, link.replace(BASE, ''))
        g = get.page
        g.fill('#key', '-'.join(words_of(p, '#words')[::-1]))
        g.click('#check')
        g.wait_for_selector('#get-alert:not([hidden])', timeout=60000)
        check('sendkey: a wrong key is refused without using up the link', "doesn't open" in g.inner_text('#get-alert'), g.inner_text('#get-alert'))
        g.fill('#key', key)
        g.click('#check')
        g.wait_for_selector('#found', state='visible', timeout=60000)
        check('sendkey: the right key shows the file name', g.inner_text('#found-name') == 'plan.txt')
        with g.expect_download(timeout=90000) as download:
            g.click('#download')
        saved = WORK / 'plan-out.txt'
        download.value.save_as(saved)
        check('sendkey: the file downloads and opens unchanged', saved.read_text() == 'the secret plan, 12345' and download.value.suggested_filename == 'plan.txt')
        again = Tab(context, link.replace(BASE, ''))
        again.page.fill('#key', key)
        again.page.click('#check')
        again.page.wait_for_selector('#get-alert:not([hidden])', timeout=30000)
        check('sendkey: the link works only once', 'used or has expired' in again.page.inner_text('#get-alert'), again.page.inner_text('#get-alert'))
        p.reload()
        p.set_input_files('#file', str(secret))
        p.click('#send')
        p.wait_for_selector('#sent', state='visible', timeout=90000)
        full = p.input_value('#link')
        check('sendkey: with the key inside the link, the key sits after the #', '#k=' in full and p.is_hidden('#apart'))
        hashed = Tab(context, full.replace(BASE, ''))
        check('sendkey: such a link needs no typed key', hashed.page.is_hidden('#need-key'))
        hashed.page.click('#check')
        hashed.page.wait_for_selector('#found', state='visible', timeout=60000)
        check('sendkey: the key in the link opens the file', hashed.page.inner_text('#found-name') == 'plan.txt')
        check('sendkey: the key in the link never reaches the server', not any('k=' in r.url.split('#')[0] and 'api.php' in r.url for r in hashed.requests))
        check('sendkey: no console errors', not send.errors and not get.errors, (send.errors + get.errors)[:3])
        browser.close()
finally:
    server.terminate()
    server.wait()
    shutil.rmtree(WORK, ignore_errors=True)

print(f'{results[0]} passed, {results[1]} failed')
sys.exit(1 if results[1] else 0)
