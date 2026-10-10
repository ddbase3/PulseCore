# Test results: PulseCore 0.3.0, campaign expansion

Date: 2026-10-10.
Baseline: the accepted PulseCore 0.2.2 installation, including the 0.2.1
transaction-free persistence fix, diagnostic logging fix and artwork update.

## Executed checks

| Suite | Passed | Result |
| --- | ---: | --- |
| PHP contracts, services, endpoint and persistence | 247 assertions | Passed |
| Atomic upgrade and interleaved writes | 3 assertions | Passed |
| Campaign gates, XP, legacy saves and invariants | 40 assertions | Passed |
| Node engine, PHP/JavaScript parity and campaign tests | 292 tests | Passed, no skips |
| Chromium gameplay, persistence, responsive UI and AJAX | 213 assertions | Passed |

PHP source/template/test files passed syntax checks. Changed JavaScript files
passed Node syntax checks. Release packaging is checked by applying the ZIP to
an exact copy of 0.2.2 and comparing every shipped file against the tested tree.
All archive members are complete files below `PulseCore/`.

### Campaign and game rules

All 48 three-star reference solutions complete in the JavaScript engine,
the PHP engine, and the browser controller with the actual PHP API. The
PHP/JavaScript suite compares snapshots after individual commands, including
restoration and deterministic mixed actions. Additional tests cover mandatory
LIVE changes, incorrect HOLD settings and exact multi-charge target capacities.
There is no runtime-generated puzzle or untested claimed reference solution.

The six original level objects and their SHA-256 hashes are unchanged. A
synthetic snapshot in the actual 0.2.2 format, containing those six completions
and an existing run, loads without invalidating its revision, run or results.
It yields 1,050 XP for six three-star results and recommends mission 7.

Server-side tests deny entry to a locked sector even through a direct API
request. Sector gates open only after all eight preceding missions complete.
Returning from the campaign resumes the existing attempt without changing its
layout or logical tick. Failed atomic writes grant no XP, unlock or result.
Equal or inferior replays grant no additional XP. A two-to-three-star upgrade
grants 25 XP. Sector bonuses cannot be collected repeatedly.

### Browser coverage

The full suite plays all 48 missions through browser controls and the PHP API,
crosses all sector boundaries and exercises the former six-level endpoint.
The final test account has 48 completed missions, 144 stars, 9,900 XP, rank 9
and twelve earned achievements. These are synthetic test results, not a copy
of a user's production data.

Checks include artwork decoding, sector previews and disabled locked starts,
save/reopen, session changes, user separation, stale tabs, lost responses after
successful writes, the final campaign screen, fullscreen denial handling,
mobile widths, no horizontal overflow and no uncaught JavaScript errors.
AJAX tests insert a trusted fragment after page load, pause on hide, resume on
return, remove/recreate the display and reject duplicate mounting. Host DOM
content remains intact. The focused AJAX script is a subset of this suite and
was not counted as a separate additional run.

## Execution environment and limitations

PHP 8.4.24 CLI; Node.js 22.16.0; Python 3.13 with Playwright and Chromium.
PHP contracts come from the supplied `framework-api.zip`.

The database fixture uses PHP FFI and SQLite, implements the actual IDatabase
interface and deliberately refuses transactions. It translates the limited
MySQL syntax used by the repository. This is not a MySQL/MariaDB or live ILIAS
installation test and does not prove their SQL, locks, collation or routing.

The restricted browser environment disallows native loopback-page navigation.
The test harness renders the actual PHP template, loads the shipped assets as
data URLs and forwards unchanged browser request payloads through a Python
cookie-jar transport to the real local PHP endpoint. Native browser network
transport, live host cookies/CSP and the actual ILIAS TabDisplay are not tested
by that bridge.

Native fullscreen is blocked by the environment. Request, exit, state-change
and denial behavior are exercised with a simulated DOM API. Native fullscreen
entry, Escape and `:fullscreen` rendering still require a real target-browser
check. No browser security policy was disabled.

No full live runtime test of ILIAS 9, 10, 11 or 12 was performed here. The user
has confirmed that the baseline persistence works in their host. This release
leaves DatabaseGameRepository, GameSession, both migration steps, the migration
provider, the PHP/JavaScript engine rules and all Base3Ilias code unchanged.
No new migration, database transaction or storage backend is introduced.

## Reproduction

See `docs/TESTING.md` for commands and dependencies. Test sources and reference
solutions are included with this patch. Development harnesses stay under
`test/` and are not production endpoints or installers.
