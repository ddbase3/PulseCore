# PulseCore – technical privacy and data lifecycle

This document describes the processing visible in the PulseCore source code. It does not
replace the privacy notice, retention policy or legal assessment of the deploying operator.

## Processing and stored data

- `GameSession` derives a per-user key from the host `IAccesscontrol` identity via
  SHA-256; the hash is a pseudonymous identifier, not proof of anonymity. No separate
  PulseCore account directory is introduced.
- `DatabaseGameRepository` stores one `pulsecore_snapshots` row per user key with a
  revision, JSON snapshot of the current attempt and prior results, and an update time.
  Game progress, scoring, levels, runs and achievements can be linked to the host identity
  by the operator. The CAS update prevents lost writes from multiple browser tabs.
- `GameSession` stores `pulsecore.csrf` in the host `ISession`, associated with that
  user key. It is renewed on identity change and checked on every state API request.
  Game progress is not stored in the session. Session lifetime and cookie behavior are
  determined by the embedding host.
- The discoverable `PulseCorePlayFeature` authorizes `feature/pulsecoreplayfeature`.
  The display and JSON API both call `IAuthorizationService`. Under the ILIAS integration,
  an optional audience expression is stored by the host in the single
  `base3_authorization_features/catalog` dataset (after migration to `rules`).
  In addition to BASE3 group names, a rule may include native ILIAS user IDs and
  configured criterion attributes, such as the domain `qualitus.de`. PulseCore
  does not write these rules itself and has no separate permission database.
- `GameApiOutput` logs rejected invalid requests and backend errors through the injected
  `ILogger`. Which log backend, log data, and retention apply depends on the host.

## Access, retention, deletion and recipients

The game requires a valid host identity and explicit feature permission; server-side
state writes additionally require a session-bound CSRF token. The authoritative game
repository uses the host-provided `IDatabase`. Deletion and backup handling for
`pulsecore_snapshots`, session expiry, log retention, database access and any external
recipients or cross-border transfers depend on the actual deployment. The reviewed code
does not itself establish automatic snapshot expiry, user-initiated deletion or a fixed
retention period. Operators must define these and support applicable data-subject rights.

## Security and documented gaps

Requests use POST/JSON, refuse invalid fields, and are authenticated and authorized at
operation time. Authorization grants are read via the host contract, not duplicated.
The source alone cannot establish the security of the deployment's infrastructure,
encryption, backups, audit logging or GDPR compliance. Those remain deployment-specific
verification requirements. The product UI must be tested against the BASE3 WCAG 2.2 AA
baseline, including embedded host focus behavior and keyboard operation.
