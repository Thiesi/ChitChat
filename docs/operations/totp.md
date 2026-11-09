# TOTP keys and recovery

Apply migration 0052_totp.sql before using the feature. AES-256-GCM through PHP
OpenSSL is required. Nothing automatically creates or installs a server key.
The default has no server ring and no DB key. Account enrollment and capability
discovery also require the integrated web interface.

## Recommended server key ring

Run php bin/totp-key generate [--key-id=NAME] in a private terminal. It prints
TOTP_ENCRYPTION_KEYS and TOTP_ACTIVE_KEY_ID assignments only. It never loads an
environment, connects to a DB, writes a file or changes deployment configuration.
Protect the terminal output: it is a secret. Copy it deliberately into the
deployment's secret configuration; do not paste it into a ticket or log.

TOTP_ENCRYPTION_KEYS is a JSON object of at most eight key-ID to Base64 entries,
bounded to 8192 bytes. IDs contain 1-32 ASCII letters/digits/underscores/hyphens.
Values must be canonical Base64 for exactly 32 random bytes. An enabled ring
requires TOTP_ACTIVE_KEY_ID to name one configured entry. The active key is used
for new writes; other configured keys are decrypt-only. IVs are fresh random
12-byte values and tags are exactly 16 bytes. The account, credential ID, key ID
and format version are authenticated with the ciphertext.

Deliver every new key to every reader/worker before changing the active write
key. Old readers without a key fail closed. Never remove a key merely because
it is no longer active.

## Optional DB-key mode

A Super-Administrator can explicitly enable a generated DB key through Operational
settings, with current step-up and a strong warning. This mode suits an operator
who accepts the backup tradeoff for a small/family installation:

**A DB backup contains both the master key and encrypted seeds. A leaked backup
exposes every authenticator secret. Server-key mode is recommended.**

No API/export/status displays the generated key. The optional key is stored in
its own table. Server-ring configuration wins for all new writes even when an
old DB key remains for decryption. The UI switch is unavailable in server mode.
Without server keys, switching the DB key off is refused while any active or
pending credential references it.

## Bounded rotation and DB-to-server migration

First escrow old keys and deliver the new server key ring to all readers.
Keep the old server keys, or the DB key, available to decrypt existing rows.

1. php bin/totp-key references prints key-ID active/pending reference counts.
2. php bin/totp-key rotate --limit=100 --after-user=0 performs a read-only dry
   run and verifies decryption. Maximum is 100 accounts/two credentials each.
3. Repeat with --apply to commit that bounded batch. Follow next_after_user_id
   while more is true. Each invocation is one bounded batch; no unbounded loop.
4. Repeat reference checks from cursor zero after the sweep, including newly
   created/replaced pending enrollments. New writes already use the new active
   server key. Keep old keys until their active AND pending counts reach zero.
5. For DB-to-server migration only, run php bin/totp-key retire-database-key
   (dry run), then add --apply after zero references are confirmed. It refuses
   retirement while any row references the DB key.

Rotation preserves the seed, credential ID, accepted-step replay fence, enrollment
context, original timestamps and MFA policy generation. Every rewrap gets a fresh
IV. A failed batch rolls back completely. Neither dry runs nor writes display
secrets or generate audit material containing seeds. Key IDs/counts are CLI-only
operator details; HTTP status gives modes/counts only.

## Backup, restore and key loss

The existing backup includes the database and attachments, not deployment
environment secrets. In server mode it carries encrypted TOTP rows, not server
master keys. Escrow the complete key ring separately with access control and
encryption. Retain old keys for every retained backup, even after current DB
references reach zero. In DB-key mode the DB dump includes its key by explicit
operator choice: give those backups the same protection as plaintext secrets.

Rehearse restore in an isolated installation: restore DB/attachments, restore
the matching escrowed server ring, verify protected key health, then complete a
fresh authenticator proof with a test account. Also rehearse a missing/wrong key
and a corrupted tag: affected TOTP returns 503 totp_unavailable; password/provider
proof must never bypass enabled MFA. Passkeys or unused recovery codes remain
alternative factors. Re-enroll only after a valid alternate proof, or use the
existing explicit policy-permitted MFA recovery/disable process. A lost server
key cannot be reconstructed from ciphertext.

Protected system status exposes security.totp with mode, available,
checked_credentials, unavailable_credentials and scan_complete, never key IDs
or values. Its scan is bounded to the first 100 accounts (up to 200 credentials);
scan_complete=false means it is not a complete integrity proof. The rotation
dry-run command provides resumable verification of the remaining rows.

Keep server clocks synchronized. Accepted codes are one-use across purposes,
and accepting the next time step early may require waiting for another code.
Factor changes invalidate cached step-up across sessions without signing out.
Expired pending enrollment is unusable immediately. The existing maintenance command also removes up to 100 expired pending rows per invocation (dry-run supported), with account-before-factor locks and no policy-generation change. Repeat maintenance when clearing a larger backlog. This releases stale pending key references without touching active authenticators.
