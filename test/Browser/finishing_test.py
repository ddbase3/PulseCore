"""Finishing checks: native Web Audio signal, real emulated touch, AJAX cleanup.

Uses the existing loopback PHP/SQLite test transport because this environment
blocks browser URL navigation. No host security settings are changed. Emulated
touch is not a physical iPhone/Android or OS audio interruption test.
"""
import asyncio
import base64
import json
import os
import socket
import subprocess
import tempfile
from pathlib import Path

from playwright.async_api import async_playwright
from browser_test import ROOT, TestTransport, offline_assets

ARTIFACTS = Path(os.environ.get('PULSECORE_FINISHING_ARTIFACTS', '/tmp/pulsecore-finishing-browser'))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
LEVELS = json.loads((ROOT / 'resources/levels/campaign.json').read_text())['levels']
SOLUTIONS = json.loads((ROOT / 'test/Engine/solutions.json').read_text())
GAME_URL = 'data:text/javascript;base64,' + base64.b64encode((ROOT / 'assets/js/game.js').read_bytes()).decode()
checks = []
errors = []

def check(value, message):
    assert value, message
    checks.append(message)
    print('PASS:', message, flush=True)

async def bind_game(page):
    # mount() returns the already mounted instance. No game rules are replaced.
    await page.evaluate('''async url => {
        window.testGame = (await import(url)).mount(document.querySelector('.pulsecore'));
    }''', GAME_URL)

async def settled(page):
    await page.wait_for_function("document.querySelector('.pulsecore')?.dataset.save === 'saved'")

async def image_assets(page):
    if not hasattr(page, '_pulsecore_art_urls'):
        page._pulsecore_art_urls = {}
        for file in (ROOT / 'assets').rglob('*.png'):
            url = await page.evaluate("""data => {
                const bytes = Uint8Array.from(atob(data), char => char.charCodeAt(0));
                return URL.createObjectURL(new Blob([bytes], {type: 'image/png'}));
            }""", base64.b64encode(file.read_bytes()).decode())
            page._pulsecore_art_urls[str(file.relative_to(ROOT / 'assets'))] = url
    return page._pulsecore_art_urls


async def create_page(browser, base, user, touch=False):
    context = await browser.new_context(viewport={'width': 390 if touch else 1440, 'height': 844 if touch else 1100},
                                        has_touch=touch, is_mobile=touch, device_scale_factor=2 if touch else 1)
    page = await context.new_page()
    page.set_default_timeout(10000)
    page.on('pageerror', lambda error: errors.append(str(error)))
    client = TestTransport(base)
    await page.expose_binding('__pulsecore_test_post', lambda source, payload: client.post(payload))
    await page.evaluate('''() => {
        window.fetch = async (url, options) => {
            const result = await window.__pulsecore_test_post({body: options.body});
            if (result.drop) throw new TypeError('Simulated lost response after commit.');
            return new Response(result.body, {status: result.status, headers: {'Content-Type': 'application/json'}});
        };
    }''')
    await page.set_content(offline_assets(client.html(f'/?testUser={user}'), base, await image_assets(page)))
    await page.wait_for_selector('.pulsecore[data-ready="true"]')
    await bind_game(page)
    return context, page, client

async def tap(page, selector):
    await page.locator(selector).first.tap()

async def prepare_touch(page, mission):
    await tap(page, f'[data-role="missions"] [data-level="{mission}"]')
    await page.wait_for_selector(f'.pulsecore[data-level="{mission}"][data-phase="planning"][data-save="saved"]')
    level = next(level for level in LEVELS if level['id'] == mission)
    solution = next(solution for solution in SOLUTIONS if solution['id'] == mission)
    for tile in level['tiles']:
        key = f'{tile["x"]},{tile["y"]}'
        if key in solution['rotations']:
            for _ in range((solution['rotations'][key] - tile['rotation']) % 4):
                await tap(page, f'[data-cell="{key}"]')
        if key in solution['delays']:
            for _ in range((solution['delays'][key] - tile['holdTicks']) % 3):
                await tap(page, f'[data-cell="{key}"]')
    await settled(page)
    return level, solution

async def run(browser, base):
    context, page, client = await create_page(browser, base, 701, touch=True)
    check(await page.evaluate('testGame.audio.context === null && !testGame.audio.enabled'), 'No autoplay or AudioContext before explicit activation')
    await tap(page, '[data-action="sound"]')
    await page.wait_for_function('testGame.audio.running && !testGame.audio.activating')
    check(await page.locator('[data-role="sound-label"]').inner_text() == 'Sound an', 'Touch activation confirms a running native AudioContext')
    await page.evaluate('''() => {
        window.testMeter = testGame.audio.context.createAnalyser(); testMeter.fftSize = 1024;
        testGame.audio.master.connect(testMeter);
    }''')
    await tap(page, '[data-action="help"]')
    await tap(page, '[data-action="sound-test"]')
    signal = await page.evaluate('''async () => {
        let peak = 0;
        for (let i = 0; i < 20; i++) {
            const wave = new Float32Array(testMeter.fftSize); testMeter.getFloatTimeDomainData(wave);
            peak = Math.max(peak, ...wave.map(Math.abs)); await new Promise(resolve => setTimeout(resolve, 20));
        }
        return {peak, state: testGame.audio.context.state};
    }''')
    check(signal['peak'] > .005 and signal['peak'] < 1, f'Native synthesized test signal is nonzero and below clipping (peak {signal["peak"]:.4f})')
    await page.locator('[data-role="volume"]').fill('35')
    check(await page.evaluate('testGame.audio.volume') == .35, 'Volume range controls the real audio master')
    check(await page.locator('[data-role="volume-label"]').inner_text() == '35%', 'Volume percentage is visible')
    await page.screenshot(path=str(ARTIFACTS / 'audio.png'), full_page=False)
    await tap(page, '[data-action="close-help"]')
    await page.evaluate('testGame.audio.context.suspend()')
    await page.wait_for_function('!testGame.audio.running')
    await page.wait_for_selector('[data-role="sound-notice"]:not([hidden])')
    check(await page.locator('[data-role="sound-notice"]').is_visible(), 'A suspended native context is visible rather than falsely reported as active')
    await tap(page, '[data-action="sound"]')
    await page.wait_for_function('testGame.audio.running')
    check(await page.locator('[data-role="sound-label"]').inner_text() == 'Sound an', 'Explicit touch resumes a suspended native context')
    await tap(page, '[data-action="sound"]')
    await page.wait_for_function('!testGame.audio.enabled && testGame.audio.voices.size === 0')
    await asyncio.sleep(.05)
    check(await page.evaluate('''() => { const samples = new Float32Array(testMeter.fftSize); testMeter.getFloatTimeDomainData(samples); return Math.max(...samples.map(Math.abs)) < .001; }'''), 'Mute stops scheduled effects and the measured output is silent')

    # The reverse button is beside the board, not at the bottom of the console.
    rotation = await page.evaluate('testGame.engine.tileAt(1,2).rotation')
    await tap(page, '[data-cell="1,2"]'); await settled(page)
    check(await page.evaluate('testGame.engine.turns') == 1, 'One touchscreen tap produces exactly one server-confirmed turn')
    await tap(page, '[data-action="ccw"]'); await settled(page)
    check(await page.evaluate('testGame.engine.tileAt(1,2).rotation') == rotation, 'Board-local reverse control restores orientation on touch')
    check(await page.evaluate('testGame.engine.turns') == 2, 'Reverse control does not create duplicate actions')
    dock = await page.locator('.pc-board-rotations').bounding_box()
    board = await page.locator('.pc-board-wrap').bounding_box()
    check(0 <= dock['y'] - board['y'] - board['height'] < 25, 'Rotation dock is immediately below the board')
    button = await page.locator('[data-action="ccw"]').bounding_box()
    check(button['width'] >= 48 and button['height'] >= 48, 'Primary touch rotation target is at least 48 by 48 CSS pixels')

    # Scroll with actual emulated touch events; native pointercancel must not rotate.
    await page.locator('[data-cell="1,2"]').scroll_into_view_if_needed()
    before = await page.evaluate('({turns:testGame.engine.turns, y:scrollY})')
    box = await page.locator('[data-cell="1,2"]').bounding_box()
    cdp = await context.new_cdp_session(page)
    x, y = box['x'] + box['width']/2, box['y'] + box['height']/2
    await cdp.send('Input.dispatchTouchEvent', {'type':'touchStart','touchPoints':[{'x':x,'y':y}]})
    for i in range(1,6):
        await cdp.send('Input.dispatchTouchEvent', {'type':'touchMove','touchPoints':[{'x':x,'y':y-i*18}]})
        await asyncio.sleep(.02)
    await cdp.send('Input.dispatchTouchEvent', {'type':'touchEnd','touchPoints':[]})
    await asyncio.sleep(.2)
    after = await page.evaluate('({turns:testGame.engine.turns, y:scrollY})')
    check(before['turns'] == after['turns'], 'Scrolling across a module does not submit a turn')
    check(abs(before['y']-after['y']) > 10, 'Native touch scrolling remains available on the board')

    # Long touch rotates counterclockwise once, without a second native click.
    await page.locator('[data-cell="1,2"]').scroll_into_view_if_needed()
    before = await page.evaluate('({turns:testGame.engine.turns, rotation:testGame.engine.tileAt(1,2).rotation})')
    box = await page.locator('[data-cell="1,2"]').bounding_box()
    point = {'x':box['x']+box['width']/2,'y':box['y']+box['height']/2}
    await cdp.send('Input.dispatchTouchEvent', {'type':'touchStart','touchPoints':[point]})
    await asyncio.sleep(.48)
    await cdp.send('Input.dispatchTouchEvent', {'type':'touchEnd','touchPoints':[]})
    await settled(page)
    after = await page.evaluate('({turns:testGame.engine.turns, rotation:testGame.engine.tileAt(1,2).rotation})')
    check(after['turns'] == before['turns']+1, 'Long touch produces one action, not a second release click')
    check(after['rotation'] == (before['rotation']-1)%4, 'Long touch turns backward without wasting actions on mobile')

    level, solution = await prepare_touch(page, 4)
    check(await page.locator('button[data-switch]').count() == 1, 'LIVE shortcut is built from the actual level module')
    check(await page.locator('button[data-switch]').is_disabled(), 'LIVE shortcut is disabled before launch')
    await tap(page, '[data-action="launch"]')
    # Pause as soon as the first pulse passes; user input still uses the actual controller/API.
    await page.wait_for_function('testGame.engine.tick >= 3')
    await tap(page, 'button[data-switch]')
    await page.wait_for_selector('.pulsecore[data-phase="won"][data-save="saved"]', timeout=20000)
    check(client.last_data['run']['result']['stars'] >= 1, 'Mission 4 completes on touch using the LIVE shortcut and PHP validation')
    check(await page.locator('button[data-switch]').is_disabled(), 'Finished attempts disable their LIVE shortcut')

    # Small viewports and two LIVE modules, without changing any level definitions.
    await prepare_touch(page, 6)
    for width in [320, 360, 390]:
        await page.set_viewport_size({'width':width,'height':844})
        await asyncio.sleep(.15)
        check(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'No page-level horizontal overflow at {width}px')
        check(await page.locator('[data-action="launch"]').is_visible(), f'Launch remains available at {width}px')
    await page.set_viewport_size({'width':390,'height':844})
    await page.locator('.pc-board-heading').scroll_into_view_if_needed()
    await page.screenshot(path=str(ARTIFACTS / 'mobile.png'), full_page=True)
    await page.set_viewport_size({'width':844,'height':390})
    await asyncio.sleep(.15)
    check(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'Landscape retains the responsive single display')
    await page.set_viewport_size({'width':390,'height':844})
    await page.emulate_media(reduced_motion='reduce')
    await page.wait_for_function('testGame.renderer.reducedMotion')
    await tap(page, '[data-cell="1,3"]')
    check(await page.evaluate('testGame.renderer.particles.length === 0 && testGame.renderer.rings.length === 0'), 'Reduced-motion preference suppresses spark and ring feedback')
    await page.emulate_media(reduced_motion='no-preference')

    # Hide/show and remove/reinsert match the existing AJAX display lifecycle.
    await tap(page, '[data-action="sound"]')
    await page.wait_for_function('testGame.audio.running')
    await tap(page, '[data-action="launch"]')
    await page.evaluate('document.querySelector(".pulsecore").style.display="none"')
    await page.wait_for_function('!testGame.visible && testGame.audio.context.state === "suspended"')
    tick = await page.evaluate('testGame.engine.tick')
    await asyncio.sleep(.3)
    check(await page.evaluate('testGame.engine.tick') == tick, 'Hidden AJAX display does not advance the simulation')
    check(await page.evaluate('testGame.audio.voices.size === 0 && testGame.frameId === null'), 'Hidden display stops audio voices and animation frames')
    await page.evaluate('document.querySelector(".pulsecore").style.display=""')
    await page.wait_for_function('testGame.visible')
    check(await page.evaluate('testGame.paused && !testGame.audio.running'), 'Returning to a hidden tab requires explicit game/audio resume')
    await tap(page, '[data-role="pause-overlay"] [data-action="pause"]')
    await page.wait_for_function('!testGame.paused && testGame.audio.running')
    check(True, 'One resume gesture restarts the game and native audio')
    await tap(page, '[data-role="pause"]'); await settled(page)
    await page.evaluate('window.removedGame=testGame; document.querySelector(".pulsecore").remove()')
    await page.wait_for_function('removedGame.destroyed && removedGame.audio.context.state === "closed"')
    check(await page.evaluate('removedGame.audio.voices.size === 0'), 'AJAX removal closes the native audio context and releases voices')
    html = offline_assets(client.html('/?fragment=1'),base, await image_assets(page))
    await page.evaluate('''html => {
        document.body.innerHTML = '<div id="ajax-tab"></div>';
        const tab = document.getElementById('ajax-tab'); tab.innerHTML = html;
        for (const old of tab.querySelectorAll('script')) {
            const script = document.createElement('script'); script.textContent = old.textContent; old.replaceWith(script);
        }
    }''',html)
    await page.wait_for_selector('.pulsecore[data-ready="true"]'); await bind_game(page)
    check(await page.evaluate('testGame.paused && !testGame.audio.enabled'), 'Recreated AJAX fragment restores confirmed progress and starts audio off')
    await context.close()

    # Desktop input, actual effects and narrow embedded container.
    context, page, client = await create_page(browser,base,702)
    await page.locator('[data-cell="1,2"]').click(); await settled(page)
    await page.locator('[data-cell="1,2"]').press('q'); await settled(page)
    check(await page.evaluate('testGame.engine.turns') == 2, 'Desktop click and Q still perform one action each')
    await page.evaluate('''() => {
        testGame.renderer.onEvents([{type:'mix', x:2,y:2,energy:3}]);
        testGame.renderer.draw(0,0,0,null,false);
    }''')
    check(await page.evaluate('testGame.renderer.rings.length > 0'), 'Fusion generates visible energy rings in the real Canvas renderer')
    await page.screenshot(path=str(ARTIFACTS / 'desktop.png'), full_page=True)
    await page.evaluate('document.querySelector(".pulsecore").style.maxWidth="400px"')
    await asyncio.sleep(.15)
    check(await page.evaluate('document.querySelector(".pulsecore").scrollWidth <= 401'), 'Container-based layout also fits a narrow embedded desktop tab')
    await context.close()
    check(not errors, 'No uncaught browser JavaScript errors')

async def main():
    with tempfile.TemporaryDirectory(prefix='pulsecore-finish-') as temp:
        env=os.environ.copy();env['PULSECORE_TEST_DB']=str(Path(temp)/'game.sqlite')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (ARTIFACTS/'server.log').open('w') as log:
            server=subprocess.Popen(['php','-d','ffi.enable=1','-S',f'127.0.0.1:{port}','test/serve.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                await asyncio.sleep(.4)
                async with async_playwright() as playwright:
                    browser=await playwright.chromium.launch(executable_path=os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
                    try:
                        await run(browser,base)
                    finally:
                        await browser.close()
            finally:
                server.terminate();server.wait(timeout=10)
    (ARTIFACTS/'checks.json').write_text(json.dumps(checks,indent=2))
    print(f'{len(checks)} finishing browser assertions passed.',flush=True)

if __name__ == '__main__':
    asyncio.run(main())
