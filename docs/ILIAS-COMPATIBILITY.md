# PulseCore 0.2.1 and ILIAS 9 through 12

## Scope of the change

`Base3Ilias` and the ILIAS installation remain unchanged. This patch only
updates PulseCore. The ILIAS database adapter provided for review implements
`IDatabase::nonQuery()` through `ilDBInterface::manipulate()`, retaining the
number of affected rows in `IDatabase::affectedRows()`. The new PulseCore
repository uses that contract for optimistic concurrency control.

No PulseCore production code calls `beginTransaction()`, `commit()` or
`rollback()`. In particular, the adapter does not bypass the explicit ILIAS
transaction-support restriction by sending raw transaction commands.

## Data consistency

All data that must change together is now held in one
`pulsecore_snapshots` row. An operation loads one versioned snapshot,
calculates the new state in memory, and commits by exactly one statement:

```sql
UPDATE pulsecore_snapshots
SET revision = <new_revision>, snapshot_json = <new_state>, updated_at = <timestamp>
WHERE user_key = <user_key> AND revision = <previous_revision>;
```

Exactly one affected row indicates successful commit. Zero affected rows means
that another request advanced the user revision. In that case the operation
returns the existing conflict response, and unconfirmed local changes are not
accepted. A failed update cannot partially store a completed level.

The update must run against an atomic MySQL/MariaDB storage engine, normally
InnoDB. Migration `002` explicitly creates that table as InnoDB.

## Upgrade behavior

Migration `002` creates `pulsecore_snapshots` and imports the active run,
revision, view selection and all stored per-level results from the original
three PulseCore tables. It does not delete those tables. A partial migration
can be retried; existing snapshots are not overwritten. From version 0.2.1
onward, the original tables are not written by the game.

For rollback planning: game progress saved after the upgrade is *only* in
`pulsecore_snapshots`. Reverting the code to 0.2.0 will not recover that newer
progress. Back up the database before deployment, and prefer fixing forward
if a problem occurs. Do not drop the original tables without a separate,
reviewed retention/migration decision.

## Compatibility status

- ILIAS 9: reviewed public ilDB interfaces and transaction capability;
  no live target-system test performed.
- ILIAS 10: reviewed public ilDB interfaces and the supplied Base3Ilias
  adapter; no live target-system test performed.
- ILIAS 11: reviewed publicly available database code and the supplied
  Base3Ilias adapter; no live target-system test performed.
- ILIAS 12: as of 2026-10-10 not released. Compatibility is intended through
  stable `IDatabase` operations but cannot yet be confirmed by runtime tests.

A local runtime test on each supported version is still required before
claiming verified end-to-end support. Especially verify `affectedRows()`.

## Recommended ILIAS smoke test

1. Confirm migration `pulsecore002atomicsnapshots` version `002` in
   `base3_migrations` and verify that `pulsecore_snapshots` uses InnoDB.
2. Open the single `pulsecoredisplay` in the already working AJAX tab.
3. Rotate a tile, wait for a confirmed save and reload the AJAX tab. Confirm
   that the changed circuit is restored.
4. Open the same account in a second tab. Make changes in one tab, then use
   the stale other tab. Verify conflict detection and server-state recovery.
5. Complete a level. Reload the page and confirm that the best result and
   current attempt survive.
6. Verify that no new `Transactions are not supported` exception occurs.

No special-case code or ILIAS-version branch is added in this patch.
