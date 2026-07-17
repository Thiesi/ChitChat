# Release checklist

Use this checklist for every ChitChat pre-release and stable release.

## Prepare

- [ ] Choose a semantic version and update the default `APP_VERSION` and `.env.example`.
- [ ] Add a dated `CHANGELOG.md` entry with security/privacy defaults, known limitations, and upgrade notes.
- [ ] Add or update the detailed release note under `docs/releases/`.
- [ ] Confirm no secrets, dumps, uploaded files, test artifacts, or local `.env` changes are tracked.
- [ ] Confirm all forward migrations are committed and no previously released migration was rewritten.
- [ ] Confirm Composer and npm lockfiles match their manifests.

## Validate the exact release commit

- [ ] PHP lint passes.
- [ ] Every browser and Playwright JavaScript module parses under the supported Node version.
- [ ] PHPStan level 8 passes without new suppressions.
- [ ] Every migration applies to an empty PostgreSQL database.
- [ ] The full PHPUnit suite passes.
- [ ] `composer maintenance:dry-run` succeeds.
- [ ] The Chromium two-session journey passes against a fresh database and real PHP workers.
- [ ] `/health.php` and `/ready.php` succeed in a production-like deployment.
- [ ] A manual reverse-proxy smoke test confirms SSE is unbuffered and reconnects.
- [ ] Backup and restore are rehearsed using PostgreSQL plus attachment storage.

## Review security and privacy

- [ ] The web root is `public/` only.
- [ ] HTTPS and secure session cookies are enabled.
- [ ] Attachment storage is outside the web root with restrictive ownership and permissions.
- [ ] PHP and proxy upload limits match the configured application limit.
- [ ] DM inspection is explicitly accepted or disabled, and user disclosure matches the policy.
- [ ] Retention values are intentional; `0` means permanent retention.
- [ ] The maintenance schedule and alerting for nonzero exit status are configured.
- [ ] Backup encryption, access control, retention, and restore ownership are documented.

## Publish

- [ ] Merge the release PR without changing the validated head.
- [ ] Confirm CI is green on `main` after merge.
- [ ] Create an annotated tag named `v<version>` on the exact merged commit.
- [ ] Create a GitHub release from that tag and mark release candidates as pre-releases.
- [ ] Use the committed release note as the release body.
- [ ] Verify the generated source archive contains the expected version defaults and documentation.

## Post-release

- [ ] Perform a fresh installation from the tag.
- [ ] Perform an upgrade rehearsal from the previous supported release or development snapshot.
- [ ] Record defects against the release version.
- [ ] Do not move or recreate a published tag; issue a new patch or release-candidate version.
