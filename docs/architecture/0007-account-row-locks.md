# 0007: Account-row lock strength and order

Status: accepted (#248, following the #243 review). Applies to every transaction that locks or updates a row in `users`.

## Context

Staff actions write audit, event, case and notice rows that reference the acting staff member. Each such insert takes an FK `FOR KEY SHARE` on the actor's `users` row. PostgreSQL row locks conflict like this:

| Held \ requested | KEY SHARE | SHARE | NO KEY UPDATE | UPDATE |
| --- | --- | --- | --- | --- |
| KEY SHARE | | | | conflict |
| SHARE | | | conflict | conflict |
| NO KEY UPDATE | | conflict | conflict | conflict |
| UPDATE | conflict | conflict | conflict | conflict |

An `UPDATE` that changes a column of a unique index usable by a foreign key (here `username_canonical`) is a key update and takes the `UPDATE` level. Other `UPDATE`s take `NO KEY UPDATE`.

So an actor/target deadlock between two staff actions needs one of them to hold `FOR UPDATE` on another person's row while it waits for the other's actor `KEY SHARE`. #243 found two such cycles: two moderators requiring new usernames for each other, and a moderator removing an avatar while the owner required a new username for that moderator.

## Decision

1. **Only a key change takes `FOR UPDATE` on a `users` row.** Today that's a username change (`UsernameChangeService`) and closure tombstoning (`AccountClosureService::finalizeDue`, which has no staff actor). The self-only closure request/restore lock is also `FOR UPDATE`; it is harmless because actor and target are the same account.
2. **Every other row lock that intends to update a `users` row uses `FOR NO KEY UPDATE`.** It still serializes those writes with each other and with a concurrent key change, but never blocks another transaction's FK `KEY SHARE`. Moderator avatar removal and avatar upload follow this rule since #248.
3. **A staff action that changes a key of another account locks through `AccountLocks::forKeyChange($actorId, $targetId)`:** the target `FOR UPDATE` and the actor `FOR KEY SHARE`, lower ID first, before any other lock on either row. Locking the actor first regardless of IDs is not enough: two moderators acting on each other would each hold their own row and want the other's.
4. **No deadlock retry.** Staff actions have post-commit side effects (file removal, events, push wake-ups), and a retry at the transaction boundary would hide new ordering bugs instead of failing loudly in tests.

The other documented orders still apply: accounts in numeric ID order where several are locked; account before login rows, subscriptions and deliveries; account before a moderation case. Resolving a case takes the subject's `FOR KEY SHARE` before the case lock (#243).

## Enforcement

- `tests/Unit/AccountRowLockScanTest.php` scans `src` for `FOR UPDATE` on `users`, `FOR UPDATE OF u` and key-changing `UPDATE users`. It fails on any site not in its reviewed list. Adding one needs a reason in that list and a review.
- `tests/Integration/UsernameChangeConcurrencyTest.php` proves the two cycles red/green (two Super-Administrators acting on each other; avatar removal against a rename).
