# WebAuthn security model

ChitChat's passkey implementation is intentionally narrow. It provides a second factor after the account password and does not attempt passwordless or discoverable-credential login in this milestone.

## Accepted ceremony profile

- exact relying-party ID and origin configured by the operator;
- cryptographically random 32-byte server challenge;
- one active session-bound ceremony at a time;
- five-minute default challenge lifetime;
- `public-key` credentials only;
- user presence and user verification required;
- privacy-preserving `none` attestation only;
- ES256 and RS256 public-key algorithms;
- definite-length CBOR with duplicate-map-key rejection;
- backup-state consistency validation;
- nonzero signature counters must advance;
- cross-origin ceremonies and `topOrigin` are rejected.

The credential private key remains in the authenticator. PostgreSQL stores the opaque credential ID, COSE public key, algorithm, counter, transports, backup flags, user-provided label, and timestamps.

## Challenge and session binding

Registration and assertion challenges are stored in the PHP session with the ceremony purpose, account ID, and expiry. Reading a challenge for verification consumes it before cryptographic validation, so a failed or successful response cannot replay the ceremony.

Password-first MFA login uses a separate pending state rather than the authenticated `auth` session. The pending state is bound to account ID, current session version, source IP, expiry, and the rotated session cookie. A final successful factor rotates the session identifier again.

## Recovery codes

Each recovery code contains 96 random bits represented in grouped hexadecimal. The server stores only SHA-256 hashes. Because the input space is already cryptographically random, an intentionally expensive password hash is unnecessary and would only increase denial-of-service exposure.

A recovery code is selected and marked used inside one database statement and transaction. Plaintext codes are generated once, returned once over the authenticated response, and never included in audits or exports.

## Privileged step-up

Accounts without MFA may establish privileged step-up by re-entering the current password. Once MFA is enabled, the password endpoint refuses step-up; a passkey assertion or recovery code is required. Step-up remains bound to account ID, session version, method, and a short maximum age.

## Administrative enforcement

Enabling the administrative MFA policy locks the singleton settings row and validates every current protected-role holder in the same transaction. Role assignment takes the same settings lock. A PostgreSQL trigger independently rejects protected-role inserts while policy is active unless the target has enabled MFA with at least one passkey.

## Account closure

Closure pending retains MFA material so restoration cannot silently weaken the account. Restoration returns to the pending-MFA login state. The final `closed` state invokes a PostgreSQL trigger that deletes credentials and recovery hashes and clears the WebAuthn user handle and MFA timestamp.

## Deliberate limitations

- no passwordless login;
- no conditional mediation or autofill UI;
- no attestation trust-chain evaluation;
- no administrator bypass or recovery reset;
- no support for legacy U2F `appid` extensions;
- no resident-key requirement;
- no imported third-party WebAuthn framework.

The implementation keeps the accepted protocol surface small and is backed by protocol-level tests plus a real Chromium virtual-authenticator journey. Firefox and WebKit continue to exercise the non-MFA and recovery-code-compatible browser paths.
