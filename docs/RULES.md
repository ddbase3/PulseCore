# PulseCore rules, version 1

## Ownership

`src/Engine/CircuitEngine.php` is the authoritative rules implementation.
`assets/js/engine.js` runs the same deterministic rules for immediate browser
feedback. `renderer.js` remains presentation-only; its particle randomness
never enters the simulation. Both engines have snapshot/restore support.
Parity tests compare their state after every submitted command, including
restoration between commands.

PHP loads authored definitions through `ILevelRepository`, restores the saved
server state and applies validated commands. It never accepts a browser score,
user identity or arbitrary replacement snapshot. Rendering frame rate and
network latency do not determine the logical order of pulse arrivals.

This validates game rules, not human participation or tamper-proof real time.
The client supplies ordered logical ticks; an automated client may submit a
valid solution. No competitive anti-bot or wall-clock attestation is claimed.

## Definitions

`resources/levels/campaign.json` contains schema version 1 and engine version 1.
Each mission has an id, size (5 or 7), tick interval in milliseconds, maximum
ticks, a gold action threshold, a sector and uniquely positioned modules.

Coordinates start at zero in data. Directions are north=0, east=1, south=2,
west=3. UI coordinates start at A1. Energy is plasma=1 (triangle), ion=2
(square), fusion=3 (diamond). Every mission is authored and ships with a
successful solution fixture.

A straight connects opposite ports. A corner connects adjacent ports and
rotates in quarter turns. A source emits at its declared ticks through its
one output; a reactor requires its declared energy and capacity at its one
input. Blocks and empty cells cannot carry energy.

A mixer accepts one ion and one plasma pulse through its north/west inputs
and outputs fusion to the east. Equal energies, equal input sides or fusion
as a mixer input are invalid. The first pulse may wait for at most
`windowTicks`; zero requires simultaneous arrival in the same tick.

A LIVE switch accepts from the west and routes north initially, south after
switching. It can change only during a run. A HOLD delay accepts vertically,
holds for one to three additional ticks and then sends through the opposite
port. An adjustable HOLD can change only during planning. Locked modules
reject edits. Ordinary unlocked straight/corner modules can rotate during
planning or flight.

## Tick order

Launch emits sources scheduled at tick zero. Each `step()` advances one tick,
checks the time limit and expired mixer waits, and moves all existing pulses
one cell. Reverse edge traversal collides. Multiple arrivals at one non-mixer
module collide. Invalid ports, leaks, wrong energy and exhausted capacity fail.

The engine processes arrivals, releases due HOLD pulses, then emits sources
scheduled for this tick. A produced, released or newly emitted pulse moves on
the next tick. Success requires every reactor to reach exactly its required
capacity, with no remaining pulses, waits or future source emissions.

A failed run stops immediately. The renderer does not invent a route to repair
it. Errors identify the failed cell when a location is available. Simulation
and input stop after completion, except for controls that start a new attempt.

## Attempts and score

Actions count individual rotations, delay changes and live switches. Three
stars require at most `goldTurns`; up to four additional actions gives two;
more gives one. Using a hint caps stars at two. No time bonus depends on a
wall-clock measurement.

Full reset restores authored modules, actions and hints. Retry preserves the
wire layout and accumulated action/hint budget, clears moving energy and
resets live switches. It is a convenience for refining a layout, not a second
persistence model. The snapshot action list records accepted engine changes; the HTTP protocol
uses explicitly validated commands, not a client-supplied snapshot.

## Display lifecycle

The template invokes an async bootstrap immediately after its markup. Module
URLs are resolved from the injected asset URLs against `document.baseURI`.
`mount()` associates one controller with one root and is idempotent.

One document child-list observer releases controllers whose roots are removed.
It exists only while at least one game is mounted. A per-display resize
observer detects hidden tab containers and visible dimensions. An invisible
run pauses without moving host focus, and its animation request is cancelled.
Showing the tab restarts rendering, not the simulation; the player resumes.

Destroy aborts DOM listeners, stops animation, disconnects resize observers,
closes the optional audio context and drops the mounted reference. Pending
commands are flushed best-effort; a newly mounted display loads the last
confirmed server state and pauses any running attempt. Imports
that finish after root removal do not mount. No host-specific router,
standalone iframe or alternate initialization mode is used.

## Persistence boundary

`GameSession` uses the host's authenticated `IAccesscontrol::getUserId()` and
stores an account-bound CSRF token in `ISession`. Progress is never stored in
the session. Authoritative domain data belongs to a single revision-controlled `pulsecore_snapshots` row. The three original tables are retained only as immutable upgrade sources.

The JSON output supports POST operations `load`, `select`, `resume`, `overview` and
`checkpoint`. Every operation checks identity and CSRF. Unknown fields,
invalid types and ownership violations are rejected. Checkpoints contain a
run ID, the last acknowledged player revision and up to 128 ordered commands:

- `change`: integer x/y and direction equal to 1 or -1.
- `start`, `hint`, `retry` and `reset`: no additional fields.
- `step`: 1 to 500 integer ticks, at most 500 advanced ticks per batch.

The repository stages all changes in memory, then commits them with one
revision-checked SQL UPDATE of the authoritative user snapshot. A mismatching revision or
non-current run returns a conflict. A completed engine accepts no more actions;
a deliberate replay creates a new run. Duplicate submission cannot award the
same run twice. A best-effort completion event is fired only after commit.

Best results rank by stars, then score, matching step 1. The overall score is
the sum of the current level versions' best results. There is one active run
per account. Selecting a different mission deliberately replaces that active
run reference; old attempts are not separate selectable save slots.

Level hashes and engine versions bind saved runs to their original rules.
Incompatible saved state is not converted silently. The overview prompts a
new attempt; adding unchanged levels preserves previous compatible results.

## Campaign end and fullscreen

The final mission's confirmed result leads to the campaign overview. It does
not reset the last level. Completion is computed from every current mission,
so merely selecting and winning the last mission does not mark the earlier
ones complete. Replay cards create explicit new attempts.

The fullscreen control calls the native API on the game section from a direct
user gesture. Fullscreen events synchronize its accessible label and pressed
state, including external exits. Unavailable or denied fullscreen does not
change simulation, saved data or host markup. No alternate fullscreen mode
is introduced.

## Campaign progression in 0.3.0

The ordered `sectors` collection in `campaign.json` determines campaign
membership and art. Each current sector contains eight levels. The original
six rule objects, including their legacy sector labels, are kept unchanged
so previously stored hashes and attempts remain valid. The UI uses the
campaign sector collection for navigation; presentation changes do not rewrite
old simulations.

All missions in the first sector are available initially. Opening another
sector requires completion of every preceding sector. The API checks this
on `load` when creating a first attempt and on every `select`. Artwork previews
do not unlock missions. `resume` returns to the same compatible saved run;
it cannot choose another run or alter its board.

XP equals 100 per completed mission plus 25 per best star plus 250 per
completed sector. Ranks and achievements are projections of these verified
results, not separately maintained state. Equal or worse replays do not award
XP again. New-star improvements award only the difference. Incompatible
changed level versions remain excluded from current progress, as before.

On a confirmed win the response may include the XP delta, rank-up flag and
newly earned achievement IDs for presentation. These values are derived inside
the existing revision-controlled operation and sent only after its successful
commit. Loading an old confirmed result does not replay its reward message.
