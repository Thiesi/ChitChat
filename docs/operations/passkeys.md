# Passkeys and multi-factor authentication

ChitChat implements password-first multi-factor authentication with WebAuthn passkeys and one-time recovery codes. It does not provide passwordless sign-in in this milestone.

## Enabling passkeys for an installation

Passkeys are disabled until both variables are configured:

```text
WEBAUTHN_RP_ID=chat.example.org
WEBAUTHN_ORIGIN=https://chat.example.org
```

`WEBAUTHN_RP_ID` is the lowercase DNS domain used as the WebAuthn relying-party identifier. It has no scheme or port. `WEBAUTHN_ORIGIN` is the exact browser origin, including a non-default port when applicable. Its host must equal the RP ID or be a subdomain of it.

Production origins must use HTTPS. Local development and tests may use `http://localhost:<port>` when `APP_ENV` is `development` or `test`.

Changing either value after users enroll passkeys makes the existing credentials unusable at the new relying party. Treat these values as durable installation identity, include them in deployment configuration management, and verify them before onboarding users.

The PHP OpenSSL extension is required when passkeys are enabled. ChitChat accepts privacy-preserving `none` attestation and ES256 or RS256 public credentials. Authenticator private keys never reach the server.

## Account enrollment

An authenticated user opens **Account → Passkeys and recovery codes**, completes recent privileged authentication, and registers a passkey. The first successful passkey atomically:

1. assigns the account an opaque 32-byte WebAuthn user handle;
2. stores the credential ID and public COSE key;
3. enables MFA for the account;
4. creates ten one-time recovery codes.

Recovery-code plaintext is returned only in that successful response. ChitChat stores SHA-256 hashes of 96-bit random values and cannot display the same set again. Users should save the codes outside the browser session.

Users may register multiple passkeys, rename them, remove all but the final passkey, and replace the complete recovery-code set. Replacing the set invalidates every previously unused code.

## Sign-in and privileged authentication

The password remains the first factor. For an MFA-enabled account, a correct password creates only a short-lived pending sign-in context. No authenticated application session exists until a registered passkey or unused recovery code succeeds.

The pending context is bound to:

- the PHP session and CSRF token;
- account ID and session version;
- source IP address;
- a bounded expiration time.

A successful second factor rotates the session identifier and also establishes recent privileged authentication. Later sensitive actions use a passkey or recovery code rather than accepting password-only step-up for an MFA-enabled account.

Each recovery code works once. An account may temporarily reach zero unused recovery codes after consuming its last code; passkeys continue to work, and the user should generate a new set immediately.

## Administrative enforcement

A Super-Administrator may enable **Require MFA for administrative roles** under Operational settings. The protected roles are:

- Super-Administrator;
- Administrator;
- Chat Administrator;
- Global Moderator.

Enforcement cannot be enabled unless every active account currently holding one of those roles has enabled passkey MFA and has at least one unused recovery code at the time of activation.

Once enabled, new protected-role grants require enabled MFA with at least one registered passkey. The application validates this before the role change, and PostgreSQL enforces the invariant again inside the role-assignment transaction.

An administrator may consume the final recovery code without losing their role or passkey access. The account page exposes the remaining count and allows a replacement set after privileged authentication.

## Account closure

A closure-pending account retains its passkeys and recovery-code hashes so an explicit restoration preserves the original authentication policy. After username and password restore the account state, an MFA-enabled account remains unauthenticated until it completes the ordinary passkey or recovery-code sign-in step.

When maintenance irreversibly tombstones the account, a database trigger deletes all WebAuthn credentials and recovery-code rows and clears the WebAuthn user handle and MFA timestamp. Shared message history and other retained evidence remain governed by their existing retention rules.

## Recovery and support

There is no administrator bypass that converts an MFA-enabled login into password-only authentication. Recovery options are deliberately limited to:

- another registered passkey;
- an unused recovery code;
- an explicit, separately reviewed administrative recovery procedure added by a future milestone.

Operators should therefore encourage users—especially administrators—to register more than one authenticator and store recovery codes securely.

## Rate limits and audit records

The named policies are:

- `mfa_assertion`: passkey option and completion requests;
- `mfa_recovery`: recovery-code attempts;
- `mfa_management`: enrollment and credential-management operations.

Successful and failed MFA login/step-up attempts are audited without storing challenges, credential IDs, signatures, public keys, recovery-code plaintext, or recovery hashes. Credential-management audits use the internal credential row ID and non-secret state such as the algorithm and backup eligibility.
