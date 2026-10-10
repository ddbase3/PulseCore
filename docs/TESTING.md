# Tests

Run commands from the `PulseCore` module directory. Production dependencies
are injected BASE3 services. Test support is confined to `test/` and is not a
runtime fallback, installer or alternative storage architecture.

## PHP contracts, services and persistence

Point `BASE3_API_DIR` at a matching framework source directory, including the
API contracts from the supplied archive:

```sh
export BASE3_API_DIR=/path/to/Base3Framework/src
php -d ffi.enable=1 test/run.php
php -d ffi.enable=1 test/atomic_upgrade.php
php -d ffi.enable=1 test/campaign.php
find src tpl test -name '*.php' -exec php -l {} \;
```

The persistence test fixture uses **PHP FFI and libsqlite3**. Enable FFI only
in the test CLI process as required by your environment. FFI and SQLite are
not production dependencies. `TestDatabase` implements the actual `IDatabase`
contract and translates the limited MySQL syntax used by the repository into
SQLite syntax. The test adapter deliberately rejects all transaction methods.
Tests exercise atomic revision-checked writes, deliberately failed writes and
upgrades from the old three-table format. It does **not** establish
MySQL/MariaDB syntax, locking, collation or deployment compatibility.

The suite covers interface compatibility, plugin composition, migrations,
identity/CSRF, owner separation, revision conflicts, snapshots, all 48 wins,
duplicate delivery, best results, post-commit events, rule-version changes,
strict API validation and real PHP template rendering.

## Deterministic rules and PHP/JavaScript parity

With Node.js 18 or newer, PHP and `BASE3_API_DIR` still configured:

```sh
npm test
```

No npm installation is needed. The original engine suite checks all 48
reference solutions and invalid game paths. The parity suite additionally
compares PHP and JavaScript after every command, including server snapshot
restoration between commands, hints, resets, retries and seeded mixed actions.
`test/Engine/replay.php` is a CLI-only test driver, not a web endpoint.

## Browser gameplay, persistence and AJAX lifecycle

Install development-only dependencies:

```sh
python -m pip install -r test/Browser/requirements.txt
python -m playwright install chromium
export PLAYWRIGHT_CHROMIUM_EXECUTABLE=/path/to/chromium
python test/Browser/browser_test.py
python test/Browser/ajax_test.py
```

By default, the scripts use `/usr/bin/chromium`. Set
`PULSECORE_TEST_ARTIFACTS` to choose the screenshot/report directory. PHP FFI,
libsqlite3 and `BASE3_API_DIR` are required for the disposable test server.

The harness launches a loopback PHP server with a temporary SQLite database.
It renders the real display/templates and loads the shipped CSS/ES modules
as data URLs. A Python cookie-jar transport bridges browser requests to the
actual PHP JSON endpoint. The unchanged request payloads, authentication,
CSRF, service, repository and atomic persistence paths are executed. The browser's
native network transport, target host CSP and live ILIAS routing are **not**
covered by this bridge.

The AJAX fixture inserts a late trusted fragment and executes its bootstrap,
matching the documented host responsibility. Tests cover removal/recreation,
hide/show without hidden simulation, double mounting, resumed pause, existing
host DOM, stored campaign results and stale tabs. A lost response after a real
commit is simulated to verify safe recovery without duplicate commands.

Fullscreen availability is checked against browser policy. Request/exit,
`fullscreenchange` and denial handling are tested with a **simulated DOM API**.
The build browser disallowed native fullscreen. These tests do not claim that
native fullscreen entry, escape handling or `:fullscreen` layout was exercised.
No browser security policy is modified or bypassed.

The separate AJAX runner is a focused subset of the full browser suite; its
assertion count must not be presented as additional independent coverage.

## Optional local manual test

Use a development machine with the above test dependencies:

```sh
export PULSECORE_TEST_DB=/tmp/pulsecore-manual.sqlite
php -d ffi.enable=1 -S 127.0.0.1:8765 test/serve.php
```

The fixture is restricted to the built-in PHP server and loopback requests.
It has a test-only identity and is not suitable for deployment. Production
always enters through the existing BASE3 runtime, not `test/serve.php`.

## Required target-installation checks

Before relying on the release in a live project, verify the real
MySQL/MariaDB migration and existing `IDatabase` implementation. Then check
`pulsecoredisplay` in the actual AJAX TabDisplay, the generated `pulsecoreapi`
URL, session cookies, JSON responses, CSP and resolved module MIME types.
Confirm save/reopen, a second user and a stale second tab.

Use a real user click to check native fullscreen entry/exit on the target
browser, including Escape and the host's permissions policy. Verify mobile
fullscreen support on the intended devices. These host-specific checks are
not replaced by the isolated tests above.

## Step 3 coverage

`test/campaign.php` checks sector membership, preservation of the six original
level hashes, 0.2.2 snapshot loading, server-side sector gates, return from the
campaign without resetting a run, XP differences, bonus idempotence and failures
that must not award progress. Its legacy snapshot fixture contains synthetic
data in the actual old format, not a copy of a production user's data.

The browser suite plays all 48 reference solutions through the real display
controller and PHP API. It crosses every sector boundary, tests the former
six-level endpoint, verifies all artwork files decode, and checks the full
campaign's 9,900 XP, highest rank and twelve earned achievements. Mobile and
AJAX checks continue to use the same display, without a separate implementation.

## Finishing tests (0.3.1)

`npm test` now includes `test/Frontend/*.test.mjs` for audio lifecycle and
bounded visual effects. The activation-timeout test uses a real three-second
deadline, so no newer Node mock-timer API is required.

```sh
python test/Browser/finishing_test.py
```

This additional async Playwright suite exercises actual emulated touch events,
native Web Audio and an AnalyserNode, volume, pause/resume, mobile layouts,
long-press deduplication, touch scrolling, LIVE controls and AJAX disposal.
PNG bytes are loaded once per page as Blob URLs; images are not resized or
replaced. This keeps large inline artwork strings out of repeated bootstrap
scripts without changing production loading. Requests still use the documented
test-only PHP transport bridge. Physical speaker output and real mobile
operating systems are not covered. See `FINISHING-TESTS.md` for this run.
