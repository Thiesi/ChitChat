# Signed-in session registry: one-time upgrade

**Everyone will need to sign in again once after this update.** This includes
ChitChat app sessions. Guest visitors must restart guest access.

Announce this before upgrading. Back up as usual, then apply
0051_login_sessions.sql using php bin/migrate in the normal maintenance procedure.
It advances account session versions and removes previous push registrations.
There is no PHP-session-file scan or backfill. Successful new sign-ins create
enforceable login records; clients can register push again through the existing lifecycle.

A retained pre-upgrade cookie cannot regain access; missing records never create
themselves during bootstrap. Accounts, passwords, passkeys, provider identities,
recovery keys and chat history remain available. No new member idle timeout is
introduced. This is a forward-only schema migration.

Maintenance cleanup, including dry-run counts, prunes revoked login metadata
after seven days. The registry stores classified client/OS hints and public IDs,
not raw PHP SIDs, IP addresses or location. It lists issued logins, not online devices.

## Ready-to-post site announcement

We're updating ChitChat's sign-in security so you can see your signed-in sessions
and sign out other sessions from your account.

Everyone will need to sign in again once after this update, including in
the ChitChat app. If you're visiting as a guest, start guest access again.
Your account and conversations will still be here. After signing in,
your device can reconnect notifications as usual.

Please make sure you can use your usual sign-in method and any required second
factor before the announced maintenance time.
