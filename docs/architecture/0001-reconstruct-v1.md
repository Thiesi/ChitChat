# ADR 0001: Reconstruct ChitChat as a coherent v1 application

- Status: Accepted
- Date: 2026-07-16

## Context

The source previously identified as v0.10.25 contains missing endpoints, incomplete persistence, contradictory installation claims, broken browser JavaScript and incompatible frontend/backend contracts. Repairing it incrementally would preserve accidental interfaces and make security behaviour difficult to verify.

## Decision

The existing snapshot is preserved on the `legacy/v0.10.25` branch. The v1 application is reconstructed on a clean tree, with the legacy source used only as a product-specification and salvage reference.

The initial architecture is:

- PHP with Composer autoloading;
- PDO and PostgreSQL;
- vanilla JavaScript in the browser;
- PHP sessions and CSRF protection;
- Server-Sent Events for realtime delivery;
- one application server for the initial deployment target;
- only `public/` exposed by the web server.

No large PHP framework is introduced unless later requirements demonstrate a clear need.

## Consequences

- The v1 API is free to use consistent documented contracts.
- Legacy database compatibility is not assumed.
- Features are reintroduced in tested milestones.
- The old implementation remains available for comparison and recovery.
