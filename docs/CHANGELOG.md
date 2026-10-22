# 0.3.1 patch: Remove game feature authorization

* Removed the discoverable BASE3 game feature and its permission checks from
  the display and the JSON API.
* Host identity and session-bound CSRF protection remain mandatory.
* Anonymous embedded displays now show the existing localized sign-in notice.
* Removed the unused authorization test double and adjusted all direct callers.
* Game engine, campaign content, persistence, database migrations and assets
  are unchanged.
* Existing installations must run the included file-removal script after
  extracting the update, because `unzip -o` does not delete obsolete files.

# 0.3.0: Campaign expansion

* 48 missions in six sectors; original six rule objects unchanged.
* Existing sector art reused for campaign cards, sector headers and results.
* Server-checked sector unlocks, sector completions and campaign completion.
* XP, nine ranks and twelve achievements derived from existing verified results.
* Explicit return from the sector map to the existing attempt.
* Emission schedule and multi-charge core indicators.
* Atomic snapshot repository, migrations, host adapter and engine rules unchanged.
* Updated full campaign, PHP/JavaScript parity and browser regression tests.

This release upgrades the artwork-enabled 0.2.2 installation. No new database
migration or external dependency is required.
