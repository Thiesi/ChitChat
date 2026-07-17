# Dependency security automation

ChitChat checks its locked Composer and npm dependency graphs through the independent **Security** GitHub Actions workflow.

## When audits run

The `dependency-audit` job runs:

- for every pull request;
- for every push to `main`;
- every Monday at 03:17 UTC;
- when manually dispatched.

The permanent release workflow also requires the newest `dependency-audit` check on the exact release commit to have completed successfully.

## Audit policy

Composer is audited from `composer.lock`. Active security advisories fail the job. Abandoned packages remain visible in the report but do not fail the job by themselves; they still require an explicit maintenance decision.

npm is audited from `package-lock.json`. High and critical vulnerabilities fail the job. Lower-severity findings remain visible for review without making every advisory an immediate release blocker.

The audit jobs report findings only. They never run an automatic fix command and never modify a lockfile.

## Dependabot version updates

`.github/dependabot.yml` checks Composer, npm, and GitHub Actions dependencies every Monday morning in the `Europe/Berlin` timezone. Each ecosystem has its own update stream and a limit of three open version-update pull requests so updates remain small and independently reviewable.

Dependabot pull requests must pass the same application, browser, release-rehearsal, reverse-proxy, WebKit, and dependency-audit gates as ordinary changes. Major updates require the same compatibility and release-note judgment as manually proposed dependency changes.

Repository administrators remain responsible for enabling the desired Dependabot security-update and alert settings in GitHub. The checked-in configuration controls version-update pull requests but cannot configure every repository-level security setting.

## CodeQL evaluation

GitHub CodeQL currently supports the browser JavaScript and GitHub Actions portions of ChitChat but does not support PHP. PHP contains the primary server-side authorization, data-access, privacy, and retention trust boundaries. A CodeQL-only green result would therefore be materially incomplete.

CodeQL is not enabled by this milestone. It should be reconsidered if PHP support becomes available, or if the JavaScript and workflow surface grows enough that a deliberately partial scanner provides clear value without being mistaken for whole-application analysis.

## Responding to a failure

1. Open the failing `dependency-audit` job and identify the package, installed version, advisory, severity, and available fixed version.
2. Confirm whether the package is a runtime dependency, development dependency, or transitive dependency.
3. Prefer the smallest compatible lockfile update that removes the finding.
4. Run the full CI and browser matrix; do not merge an audit-only lockfile change without application validation.
5. When no safe update exists, document the exposure and compensating controls in an issue. Do not suppress or lower the audit threshold silently.
