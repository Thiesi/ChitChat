# Release publication

ChitChat publishes releases through the permanent **Publish release** GitHub Actions workflow. Disposable publication pull requests are no longer part of the supported process.

The workflow deliberately separates validation from publication. Validation has read-only repository permissions. Publication runs only for `stable` or `prerelease` mode and uses the `release` GitHub environment with `contents: write` permission.

## One-time repository setup

Create a GitHub Actions environment named `release` in the repository settings and configure required reviewers. Restrict deployment branches to `main`; this is part of the publication trust boundary, not an optional convenience.

The workflow references the environment even when no protection rule exists, but the approval boundary is effective only after required reviewers and the `main` deployment-branch restriction are configured in GitHub.

## Prepare the release commit

1. Complete the release checklist and merge the release pull request without changing its validated head.
2. Wait for the `main` push workflows to finish.
3. Copy the full 40-character merge commit SHA.
4. Confirm the version is declared consistently in:
   - `.env.example`;
   - the default in `src/Config.php`;
   - the dated `CHANGELOG.md` entry;
   - `docs/releases/v<version>.md`.

The release workflow must itself be dispatched from `main`, and it does not publish an arbitrary branch commit. The requested commit must be reachable from `main`.

## Validate without publishing

Run **Actions → Publish release → Run workflow** from the `main` branch with:

- `version`: the semantic version without the leading `v`;
- `commit_sha`: the full merge commit SHA;
- `publication`: `validate-only`.

Validation checks the repository metadata and requires successful GitHub Actions check runs with these exact names on that same commit:

- `static`;
- `integration`;
- `browser (chromium)`;
- `browser (firefox)`;
- `release-rehearsal`;
- `reverse-proxy`;
- `browser-webkit`.

The newest check run with each required name must be complete and successful. A previous success does not hide a newer pending, cancelled, or failed rerun. A pull-request run on another SHA does not satisfy publication.

## Publish

Run the workflow again from `main` with the same version and SHA, selecting:

- `stable` for a version without a pre-release suffix; or
- `prerelease` for a version such as `1.2.0-rc.1`.

The validation job runs again. The publication job then waits on the `release` environment approval, revalidates the exact state, creates an annotated `v<version>` tag, and creates the GitHub release from the committed notes.

Stable releases are marked latest. Pre-releases are marked as pre-releases.

## Safe resumption

Tag creation and GitHub release creation cannot be one atomic GitHub operation. The workflow is therefore resumable:

- when no tag exists, it creates the annotated tag;
- when the tag already exists, it must be annotated and resolve to the requested commit;
- when the GitHub release already exists, the workflow verifies it rather than editing it;
- the existing release must be non-draft, use the expected tag and title, have the requested stable/pre-release classification, and exactly match the committed release notes;
- an existing stable release must still be the repository's latest release;
- a tag pointing elsewhere, a lightweight tag, or mismatched release metadata fails publication rather than rewriting history.

Do not delete, move, or recreate a successfully published tag. Correct a released defect with a new semantic version.

## Permissions and trust boundary

The workflow uses the GitHub-provided token only. It does not require a long-lived personal access token.

The validation job has read access to repository contents, Actions, and checks. The publication job receives `contents: write` only after entering the `release` environment. Required reviewers and a deployment-branch restriction to `main` remain repository-setting responsibilities and cannot be established by the workflow file itself.
