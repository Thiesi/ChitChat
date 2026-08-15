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
- [ ] Every browser, Playwright, and stabilization script passes syntax validation.
- [ ] PHPStan level 8 passes without new suppressions.
- [ ] Locked Composer and npm dependency audits pass at the documented release-blocking thresholds.
- [ ] Every migration applies to an empty PostgreSQL database.
- [ ] The full PHPUnit suite passes.
- [ ] `composer maintenance:dry-run` succeeds.
- [ ] The complete two-session journey passes independently in Chromium, Firefox, and WebKit.
- [ ] Structural and keyboard accessibility regression checks pass in all three browser engines.
- [ ] The previous supported release archive installs in a clean directory with production Composer dependencies.
- [ ] PostgreSQL and attachment backups pass checksum and structural verification.
- [ ] The backup restores under new database and storage names and current migrations apply successfully.
- [ ] Restored users, room history, direct messages, and attachment bytes are verified.
- [ ] Real Nginx and PHP-FPM deliver an authenticated SSE event before the stream closes.
- [ ] `/health.php` and `/ready.php` succeed in the production-like deployment.

## Review security and privacy

- [ ] The web root is `public/` only.
- [ ] HTTPS and secure session cookies are enabled.
- [ ] Attachment storage is outside the web root with restrictive ownership and permissions.
- [ ] PHP and proxy upload limits match the configured application limit.
- [ ] DM inspection is explicitly accepted or disabled, and user disclosure matches the policy.
- [ ] Historical revision review is explicitly accepted or remains disabled, and the bounded participant-notification behavior (a durable notice only — no reviewer identity, reason, or body) is documented.
- [ ] Retention values are intentional; `0` means permanent retention.
- [ ] The maintenance schedule and alerting for nonzero exit status are configured.
- [ ] Backup encryption, access control, retention, and restore ownership are documented.

## Publish

- [ ] Merge the release PR without changing the validated head.
- [ ] Confirm the merged source tree matches the fully validated release head.
- [ ] Wait for the `main` push runs of CI, Security, and the independent WebKit workflow to finish successfully.
- [ ] Copy the full 40-character merge commit SHA.
- [ ] Confirm the repository has a `release` GitHub Actions environment with required reviewers and deployment restricted to `main`.
- [ ] Run **Publish release** from `main` in `validate-only` mode with the version and exact merge commit SHA.
- [ ] Review the validation summary and confirm every required check passed on the exact commit.
- [ ] Run **Publish release** again from `main` with the same version and SHA in `stable` or `prerelease` mode.
- [ ] Approve the protected `release` environment deployment.
- [ ] Verify the workflow created an annotated `v<version>` tag on the exact commit.
- [ ] Verify the GitHub release uses the committed release note and the intended latest/pre-release classification.
- [ ] Verify the generated source archive contains the expected version defaults and documentation.

See [`../operations/release-publication.md`](../operations/release-publication.md) for the complete guarded publication procedure and safe-resumption behavior.

## Post-release

- [ ] Perform a fresh installation from the tag.
- [ ] Perform an upgrade rehearsal from the previous supported release.
- [ ] Record defects against the release version.
- [ ] Do not move or recreate a published tag; issue a new patch or release-candidate version.
