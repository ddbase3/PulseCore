"""Browser integration against the real PHP API using a test transport binding.

The sandbox browser blocks URL navigation and native fullscreen by policy.
Templates/modules are therefore loaded offline, with original image bytes in
page-local Blob URLs to avoid repeatedly embedding large images in scripts.
A Python cookie-jar bridge
POSTs the unchanged request bodies to the actual local PHP HTTP API. Fullscreen
control/event tests simulate the DOM API, not native browser fullscreen.
The SQLite dialect adapter is only a test harness, never a production backend.
"""
import json
import mimetypes
import base64
import http.cookiejar
import urllib.error
import os
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ARTIFACTS = Path(os.environ.get('PULSECORE_TEST_ARTIFACTS', '/tmp/pulsecore-browser-tests'))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
LEVELS = json.loads((ROOT / 'resources/levels/campaign.json').read_text())['levels']
SOLUTIONS = json.loads((ROOT / 'test/Engine/solutions.json').read_text())
checks = []
clients = {}


class TestTransport:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.drop_next_checkpoint = False
        self.last_data = None

    def html(self, path):
        return self.opener.open(self.base + path, timeout=10).read().decode()

    def post(self, payload):
        request = urllib.request.Request(self.base + '/?name=pulsecoreapi&out=json', data=payload['body'].encode(), headers={'Content-Type': 'application/json'}, method='POST')
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        result = {'status': response.status, 'body': response.read().decode()}
        parsed = json.loads(result['body'])
        if parsed.get('ok'): self.last_data = parsed['data']
        if self.drop_next_checkpoint and json.loads(payload['body'])['operation'] == 'checkpoint':
            self.drop_next_checkpoint = False
            return {'drop': True}
        return result


def image_assets(page):
    if not hasattr(page, '_pulsecore_art_urls'):
        page._pulsecore_art_urls = {}
        for file in (ROOT / 'assets').rglob('*.png'):
            url = page.evaluate("""data => {
                const bytes = Uint8Array.from(atob(data), char => char.charCodeAt(0));
                return URL.createObjectURL(new Blob([bytes], {type: 'image/png'}));
            }""", base64.b64encode(file.read_bytes()).decode())
            page._pulsecore_art_urls[str(file.relative_to(ROOT / 'assets'))] = url
    return page._pulsecore_art_urls


def offline_assets(html, base, images=None):
    for file in (ROOT / 'assets').rglob('*'):
        if not file.is_file():
            continue
        mime = 'text/javascript' if file.suffix == '.js' else mimetypes.guess_type(file.name)[0]
        url = (images or {}).get(str(file.relative_to(ROOT / 'assets')))
        if url is None:
            url = 'data:' + mime + ';base64,' + base64.b64encode(file.read_bytes()).decode()
        address = '/assets/PulseCore/' + str(file.relative_to(ROOT / 'assets')) + '?pulsecore=0.3.1'
        html = html.replace(address, url).replace(address.replace('/', '\\/'), url)
    return html.replace('<head>', '<head><base href="' + base + '/">', 1)


def install_transport(page, client):
    clients[page] = client
    page.expose_binding('__pulsecore_test_post', lambda source, payload: client.post(payload))
    page.evaluate("""() => {
        window.fetch = async (url, options) => {
            const result = await window.__pulsecore_test_post({body: options.body});
            if (result.drop) throw new TypeError('Simulated lost response after commit.');
            return new Response(result.body, {status: result.status, headers: {'Content-Type': 'application/json'}});
        };
    }""")


def reload_display(page):
    client = clients[page]
    page.set_content(offline_assets(client.html('/'), client.base, image_assets(page)))


def simulate_fullscreen(page):
    page.evaluate("""() => {
        let fullscreenElement = null;
        Object.defineProperty(document, 'fullscreenEnabled', {configurable: true, get: () => true});
        Object.defineProperty(document, 'fullscreenElement', {configurable: true, get: () => fullscreenElement});
        const root = document.querySelector('.pulsecore');
        root.requestFullscreen = async () => { fullscreenElement = root; document.dispatchEvent(new Event('fullscreenchange')); };
        document.exitFullscreen = async () => { fullscreenElement = null; document.dispatchEvent(new Event('fullscreenchange')); };
        document.dispatchEvent(new Event('fullscreenchange'));
    }""")


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    checks.append(message)
    print('PASS:', message, flush=True)


def settled(page):
    # Run debounce timers, then wait for an actual server acknowledgement.
    page.clock.run_for(220)
    page.wait_for_selector('.pulsecore[data-save="saved"]', timeout=15000)


def click(root, selector, **event):
    root.locator(selector).first.dispatch_event('click', event)


def prepare(page, root, mission):
    level = next(item for item in LEVELS if item['id'] == mission)
    solution = next(item for item in SOLUTIONS if item['id'] == mission)
    # Navigate the real sector UI; do not bypass the server's unlock checks.
    sector = next(item['id'] for item in json.loads((ROOT / 'resources/levels/campaign.json').read_text())['sectors'] if mission in item['levelIds'])
    if root.get_attribute('data-screen') == 'game' and root.locator(f'[data-role="missions"] [data-level="{mission}"]').count():
        click(root, f'[data-role="missions"] [data-level="{mission}"]')
    else:
        if root.get_attribute('data-screen') != 'campaign':
            click(root, '[data-action="overview"]')
            page.wait_for_selector('.pulsecore[data-screen="campaign"][data-save="saved"]')
        click(root, f'[data-role="sector-cards"] [data-sector="{sector}"]')
        click(root, f'[data-role="campaign-levels"] [data-level="{mission}"]')
    page.wait_for_selector(f'.pulsecore[data-level="{mission}"][data-save="saved"][data-phase="planning"]')
    for tile in level['tiles']:
        key = f'{tile["x"]},{tile["y"]}'
        if key in solution['rotations']:
            rotation = tile['rotation']
            while rotation != solution['rotations'][key]:
                direction = -1 if (solution['rotations'][key] - rotation) % 4 == 3 else 1
                click(root, f'[data-cell="{key}"]', shiftKey=direction < 0)
                rotation = (rotation + direction) % 4
        if key in solution['delays']:
            hold = tile['holdTicks']
            while hold != solution['delays'][key]:
                click(root, f'[data-cell="{key}"]')
                hold = hold % 3 + 1
    settled(page)
    return level, solution


def finish(page, root, level, solution):
    applied = set()
    page.clock.run_for(32)
    for _ in range(level['maxTicks'] + 2):
        tick = int(root.locator('[data-role="tick"]').inner_text())
        for index, action in enumerate(solution['actions']):
            if index not in applied and tick >= action['tick']:
                click(root, f'[data-cell="{action["x"]},{action["y"]}"]', shiftKey=action['direction'] < 0)
                applied.add(index)
        if root.get_attribute('data-phase') != 'running':
            break
        page.clock.run_for(level['tickMs'])
    settled(page)
    check(root.get_attribute('data-phase') == 'won', f'Mission {level["id"]} completes through the browser controller and server')
    check(root.locator('[data-role="result-action"]').is_enabled(), f'Mission {level["id"]} waits for confirmed result before continuing')


def solve(page, root, mission, hint=False):
    level, solution = prepare(page, root, mission)
    if hint:
        click(root, '[data-action="hint"]')
    click(root, '[data-action="launch"]')
    finish(page, root, level, solution)
    stars = root.locator('[data-role="result-stars"] span:not(.is-empty)').count()
    check(stars == (2 if hint else 3), f'Mission {mission} shows the authoritative star rating')


def new_page(browser, base, user, mobile=False, ajax=False):
    context = browser.new_context(viewport={'width': 390, 'height': 844} if mobile else {'width': 1440, 'height': 1100})
    context.set_default_timeout(10000)
    page = context.new_page()
    page.clock.install()
    page.clock.pause_at(int(time.time() * 1000) + 1000)
    client = TestTransport(base)
    install_transport(page, client)
    page.set_content(offline_assets(client.html(f'/?testUser={user}' + ('&ajax=1' if ajax else '')), base, image_assets(page)))
    if not ajax:
        page.wait_for_selector('.pulsecore[data-ready="true"]')
    return context, page


def insert_fragment(page, base):
    html = offline_assets(clients[page].html('/?fragment=1'), base, image_assets(page))
    page.evaluate('''html => {
        const host = document.getElementById('tab');
        host.innerHTML = html;
        // Script execution is the host fragment-loader contract.
        for (const original of host.querySelectorAll('script')) {
            const script = document.createElement('script');
            script.textContent = original.textContent;
            original.replaceWith(script);
        }
    }''', html)
    page.wait_for_selector('#tab .pulsecore[data-ready="true"]')
    page.clock.run_for(48)


def run_suite(browser, base, ajax_only=False):
    errors = []
    if not ajax_only:
        context, page = new_page(browser, base, 101)
        page.on('pageerror', lambda error: errors.append(str(error)))
        root = page.locator('.pulsecore')
        check(root.count() == 1, 'One display contains the complete game')
        check(root.locator('[data-action="fullscreen"]').is_visible(), 'Fullscreen button is in the existing header')
        page.clock.run_for(48)
        page.screenshot(path=str(ARTIFACTS / 'desktop.png'), full_page=True)
        check(root.locator('[data-action="fullscreen"]').is_disabled() == (not page.evaluate('document.fullscreenEnabled')), 'Fullscreen availability matches browser policy')
        simulate_fullscreen(page)
        root.locator('[data-action="fullscreen"]').click()
        page.wait_for_function('document.fullscreenElement?.classList.contains("pulsecore")', polling=100)
        check(page.evaluate('document.fullscreenElement.tagName') == 'SECTION', 'Simulated fullscreen request targets the game section, not the host document')
        page.clock.run_for(64)
        root.locator('[data-action="fullscreen"]').click()
        page.wait_for_function('document.fullscreenElement === null', polling=100)
        check(root.locator('[data-action="fullscreen"]').get_attribute('aria-pressed') == 'false', 'Fullscreen toggle returns to the embedded game')
        click(root, '[data-action="overview"]')
        page.wait_for_selector('.pulsecore[data-screen="campaign"][data-save="saved"]')
        check(root.locator('[data-role="sector-cards"] button').count() == 6, 'Six artwork-backed sectors are available to preview')
        check(root.locator('[data-role="sector-cards"] [data-unlocked="false"]').count() == 5, 'Five later sectors are locked for a new player')
        page.wait_for_function("Array.from(document.querySelectorAll('.pc-sector-card img')).every(img => img.complete && img.naturalWidth > 0)")
        check(True, 'All six existing sector artworks decode as real images')
        click(root, '[data-role="sector-cards"] [data-sector="spectrum"]')
        check(root.locator('[data-role="sector-play"]').is_disabled(), 'Previewing locked artwork cannot start a locked mission')
        check(root.locator('[data-role="campaign-levels"] button:disabled').count() == 8, 'All eight locked-sector missions are visibly disabled')
        click(root, '[data-action="resume-run"]')
        page.wait_for_selector('.pulsecore[data-screen="game"][data-save="saved"]')
        check(root.get_attribute('data-level') == '1', 'Returning from the campaign retains the current attempt')
        click(root, '[data-cell="1,2"]')
        settled(page)
        check(root.locator('[data-role="turns"]').inner_text() == '01', 'Planning change saved')
        reload_display(page)
        page.wait_for_selector('.pulsecore[data-ready="true"]')
        check(root.locator('[data-role="turns"]').inner_text() == '01', 'Planning change survives a page reload')
        # Resume midway through a real solution.
        level, solution = prepare(page, root, 1)
        click(root, '[data-action="launch"]')
        page.clock.run_for(level['tickMs'] * 2 + 50)
        click(root, '[data-action="pause"]')
        settled(page)
        tick = root.locator('[data-role="tick"]').inner_text()
        reload_display(page)
        page.wait_for_selector('.pulsecore[data-ready="true"]')
        check(root.get_attribute('data-paused') == 'true', 'A loaded running attempt is paused, never advanced during absence')
        check(root.locator('[data-role="tick"]').inner_text() == tick, 'Resume restores the exact confirmed tick')
        click(root, '[data-role="pause-overlay"] [data-action="pause"]')
        finish(page, root, level, solution)
        for mission in range(2, 49):
            solve(page, root, mission)
            if mission == 6:
                click(root, '[data-role="result-action"]')
                page.wait_for_selector('.pulsecore[data-level="7"][data-phase="planning"][data-save="saved"]')
                check(True, 'The former final level 6 continues to the new level 7')
            if mission % 8 == 0 and mission < 48:
                check(root.locator('[data-role="result-action"]').inner_text() == 'Sektorabschluss ansehen', f'Sector {mission // 8} has its own completion action')
                click(root, '[data-role="result-action"]')
                page.wait_for_selector('.pulsecore[data-screen="campaign"][data-save="saved"]')
                check(root.locator('[data-role="sector-state"]').inner_text() == 'Sektor stabilisiert', f'Sector {mission // 8} completion screen is rendered')
                check(root.locator('[data-role="sector-next"]').is_visible(), f'Sector {mission // 8} links to the next sector')
        check(root.locator('[data-role="completed"]').inner_text() == '48', 'All 48 verified results are reflected in the campaign')
        click(root, '[data-role="result-action"]')
        page.wait_for_selector('.pulsecore[data-screen="campaign"][data-save="saved"]')
        check(not root.locator('[data-role="workspace"]').is_visible(), 'Last mission opens the overview instead of resetting level 48')
        check(root.locator('[data-role="campaign-title"]').inner_text() == 'Alle Systeme online.', 'Completion screen confirms the full campaign')
        check('144 / 144' in root.locator('[data-role="campaign-stats"]').inner_text(), 'Campaign overview shows accumulated best stars')
        check(root.get_attribute('data-xp') == '9900', 'All three-star wins plus sector bonuses produce exactly 9900 XP')
        check(root.get_attribute('data-rank') == '9', 'The highest rank is earned from verified results')
        check(root.locator('[data-role="achievements"] [data-earned="true"]').count() == 12, 'All twelve achievements are earned after a perfect campaign')
        page.screenshot(path=str(ARTIFACTS / 'campaign.png'), full_page=True)
        reload_display(page)
        page.wait_for_selector('.pulsecore[data-ready="true"][data-screen="campaign"]')
        check(root.locator('[data-role="completed"]').inner_text() == '48', 'Campaign completion and overview survive a later visit')
        click(root, '[data-role="sector-cards"] [data-sector="ignition"]')
        click(root, '[data-role="campaign-levels"] [data-level="1"]')
        page.wait_for_selector('.pulsecore[data-level="1"][data-phase="planning"][data-save="saved"]')
        check(root.locator('[data-role="completed"]').inner_text() == '48', 'Deliberate replay preserves campaign best results')
        solve(page, root, 1, hint=True)
        check(root.locator('[data-role="missions"] [data-level="1"] .is-earned').count() == 3, 'Worse replay does not replace a better saved score')
        check(root.get_attribute('data-xp') == '9900', 'Worse replays cannot farm or reduce XP')
        fresh_context, fresh_page = new_page(browser, base, 101)
        check(fresh_page.locator('[data-role="completed"]').inner_text() == '48', 'A new host session for the same account loads persistent results')
        fresh_context.close()
        # Same user, second tab: stale revision must not win silently.
        click(root, '[data-action="reset"]')
        page.wait_for_selector('.pulsecore[data-phase="planning"][data-save="saved"]')
        other = context.new_page()
        other.clock.install()
        other.clock.pause_at(int(time.time() * 1000) + 1000)
        install_transport(other, clients[page])
        other.set_content(offline_assets(clients[page].html('/'), base, image_assets(other)))
        other.wait_for_selector('.pulsecore[data-ready="true"]')
        click(root, '[data-cell="1,2"]')
        settled(page)
        other_root = other.locator('.pulsecore')
        click(other_root, '[data-cell="1,2"]')
        other.clock.run_for(220)
        other.wait_for_selector('.pulsecore[data-save="error"]')
        check(other_root.locator('[data-role="sync-error"]').is_visible(), 'Stale tab reports a conflict instead of overwriting progress')
        click(other_root, '[data-action="recover"]')
        other.wait_for_selector('.pulsecore[data-save="saved"]')
        check(other_root.locator('[data-role="turns"]').inner_text() == '01', 'Conflict recovery loads the canonical saved layout')
        other.close()
        # Drop the response after the server committed: load must recover, not duplicate.
        clients[page].drop_next_checkpoint = True
        click(root, '[data-cell="1,2"]')
        page.clock.run_for(220)
        page.wait_for_selector('.pulsecore[data-save="error"]')
        check(root.locator('[data-role="launch"]').is_disabled(), 'Unconfirmed network result blocks further gameplay')
        click(root, '[data-action="recover"]')
        page.wait_for_selector('.pulsecore[data-save="saved"]')
        check(root.locator('[data-role="turns"]').inner_text() == '02', 'Lost response recovers the committed move exactly once')
        # Logout/login uses the host identity; a new user has a distinct save.
        second_context, second_page = new_page(browser, base, 102)
        check(second_page.locator('[data-role="completed"]').inner_text() == '00', 'Different authenticated user starts with separate progress')
        second_context.close()
        mobile_context, mobile_page = new_page(browser, base, 103, mobile=True)
        mobile_page.clock.run_for(64)
        check(mobile_page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Mobile layout has no horizontal overflow')
        check(mobile_page.locator('[data-action="fullscreen"]').is_visible(), 'Fullscreen button remains available on the narrow header')
        mobile_page.screenshot(path=str(ARTIFACTS / 'mobile.png'), full_page=True)
        for width in [320, 280]:
            mobile_page.set_viewport_size({'width': width, 'height': 844})
            mobile_page.clock.run_for(64)
            check(mobile_page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'{width}px embedding does not overflow the header')
        mobile_page.set_viewport_size({'width': 390, 'height': 844})
        click(mobile_page.locator('.pulsecore'), '[data-action="overview"]')
        mobile_page.wait_for_selector('.pulsecore[data-screen="campaign"][data-save="saved"]')
        mobile_page.clock.run_for(64)
        check(mobile_page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Mobile sector overview has no horizontal overflow')
        mobile_page.screenshot(path=str(ARTIFACTS / 'mobile-campaign.png'), full_page=True)
        mobile_context.close()
        context.close()
    # AJAX insertion, hide/show, cleanup and re-entry all hit the real JSON endpoint.
    context, page = new_page(browser, base, 201, ajax=True)
    page.on('pageerror', lambda error: errors.append(str(error)))
    check(page.evaluate('document.readyState') == 'complete', 'AJAX insertion happens after document-ready')
    insert_fragment(page, base)
    root = page.locator('#tab .pulsecore')
    check(root.get_attribute('data-ready') == 'true', 'Game bootstraps from an AJAX fragment')
    click(root, '[data-cell="1,2"]')
    settled(page)
    # Remove and recreate the complete fragment; the database now owns continuity.
    page.evaluate('document.getElementById("tab").replaceChildren()')
    page.clock.run_for(48)
    insert_fragment(page, base)
    check(root.locator('[data-role="turns"]').inner_text() == '01', 'AJAX fragment replacement restores saved progress')
    level, solution = prepare(page, root, 4)
    click(root, '[data-action="launch"]')
    page.clock.run_for(220)
    page.wait_for_selector('.pulsecore[data-save="saved"]')
    page.evaluate('document.getElementById("tab").style.display = "none"')
    page.clock.run_for(64)
    before = root.locator('[data-role="tick"]').inner_text()
    page.clock.run_for(8000)
    check(root.locator('[data-role="tick"]').inner_text() == before, 'Hidden AJAX tab stops advancing the circuit')
    page.evaluate('document.getElementById("tab").style.display = "block"')
    page.clock.run_for(64)
    check(root.get_attribute('data-paused') == 'true', 'Returning to a hidden tab requires explicit resume')
    simulate_fullscreen(page)
    root.locator('[data-action="fullscreen"]').click()
    page.wait_for_function('document.fullscreenElement?.classList.contains("pulsecore")', polling=100)
    check(page.evaluate('document.fullscreenElement.id.startsWith("pulsecore_")'), 'Simulated fullscreen works for an AJAX-inserted display')
    page.evaluate('document.exitFullscreen()')
    page.wait_for_function('document.fullscreenElement === null', polling=100)
    page.clock.run_for(64)
    check(root.locator('[data-action="fullscreen"]').get_attribute('aria-pressed') == 'false', 'External fullscreen exit synchronizes the button')
    page.evaluate("() => { document.querySelector('.pulsecore').requestFullscreen = async () => { throw new Error('Test denial'); }; }")
    root.locator('[data-action="fullscreen"]').click()
    check('Browser' in root.locator('[data-role="status"]').inner_text(), 'Fullscreen denial is visible without changing the game')
    check(root.get_attribute('data-save') == 'saved', 'Fullscreen denial never affects database progress')
    # Re-execute the same bootstrap; mount must keep the same instance.
    page.evaluate('''() => {
        const original = document.querySelector('#tab script');
        const script = document.createElement('script'); script.textContent = original.textContent;
        document.getElementById('tab').append(script);
    }''')
    page.clock.run_for(100)
    check(root.get_attribute('data-paused') == 'true' and root.locator('[data-role="tick"]').inner_text() == before, 'Duplicate bootstrap does not replace the existing game')
    page.screenshot(path=str(ARTIFACTS / 'ajax.png'), full_page=True)
    page.evaluate('document.getElementById("tab").replaceChildren()')
    page.clock.run_for(64)
    check(page.locator('.pulsecore').count() == 0, 'AJAX removal removes the complete display')
    check(page.locator('#host-heading').inner_text() == 'Host application', 'Game lifecycle never replaces or modifies host content')
    insert_fragment(page, base)
    check(root.get_attribute('data-paused') == 'true', 'Recreated AJAX fragment resumes a stored running attempt in pause')
    context.close()
    check(not errors, 'No uncaught browser JavaScript errors: ' + str(errors))


def main(ajax_only=False):
    with tempfile.TemporaryDirectory(prefix='pulsecore-step3-') as temp:
        env = os.environ.copy()
        env['PULSECORE_TEST_DB'] = str(Path(temp) / 'game.sqlite')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        log = (ARTIFACTS / ('ajax-server.log' if ajax_only else 'browser-server.log')).open('w')
        server = subprocess.Popen(['php', '-d', 'ffi.enable=1', '-S', f'127.0.0.1:{port}', 'test/serve.php'], cwd=ROOT, env=env, stdout=log, stderr=log)
        try:
            for _ in range(50):
                try:
                    urllib.request.urlopen(base, timeout=1).close()
                    break
                except OSError:
                    time.sleep(.1)
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE', '/usr/bin/chromium'), args=['--no-sandbox'])
                try:
                    run_suite(browser, base, ajax_only)
                finally:
                    browser.close()
        finally:
            server.terminate()
            server.wait(timeout=10)
            log.close()
    (ARTIFACTS / ('ajax-checks.json' if ajax_only else 'browser-checks.json')).write_text(json.dumps(checks, indent=2))
    print(f'{len(checks)} browser assertions passed.', flush=True)


if __name__ == '__main__':
    main()
