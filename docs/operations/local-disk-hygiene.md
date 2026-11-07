# Local disk hygiene

These rules are for anyone, person or agent, who runs ChitChat's tests and
tooling on a development machine. They do not affect production installs.

## Why

Another project on the shared development host let scratch from test runs,
proofs and review captures pile up: a new folder for every run and every
review head, inside its git worktrees. It grew to several million files and
took the host down, and recovery took a full day, including an offline disk
check. Moving that scratch into the system temp folder without a limit only
moved the pile: it passed half a million entries there and had to be cleared
by hand. The cause was the same both times. Scratch went into a new folder
every run, nothing deleted it, and nothing counted it.

ChitChat's own scratch is small, but earlier test code had the same pattern
(random per-run names in the temp folder, and a few files that were never
deleted). The rules below keep it bounded.

## Rules

1. **One fixed folder per kind of scratch.** PHPUnit tests write temporary
   files only through `ChitChat\Tests\Support\TestScratch` (or the
   `TemporaryPaths` trait), which puts everything under
   `<temp>/chitchat-tests`. Tests delete what they create, and the PHPUnit
   bootstrap removes the whole folder when the run ends. The stabilization
   rehearsals each use one fixed `chitchat-*-rehearsal` folder and remove it
   on exit. Playwright replaces `test-results/` on every run. Never create
   a new folder per run, per branch or per review head.
2. **No bare temp calls in tests.** `sys_get_temp_dir()`, `tempnam()` and
   `tmpfile()` are allowed only in `tests/Support/TestScratch.php`. A unit
   test (`TestScratchTest`) fails if they appear anywhere else under
   `tests/`.
3. **The temp folder is not storage.** Evidence you want to keep belongs in
   `docs/`, on the PR, or in a CI artifact. Never cite a temp path as where
   something is kept: the cleanup below will delete it.
4. **50,000-file guard.** The PHPUnit bootstrap refuses to start when the
   checkout's scratch (`test-results`, `playwright-report`, `.phpunit.cache`,
   `var/phpstan`) or ChitChat's entries in the temp folder hold more than
   50,000 files. The message names the cleanup command.
   `CHITCHAT_SCRATCH_LIMIT` raises the limit for a single run.
5. **Cleanup** (`bin/clean-scratch`):
   - `composer clean-scratch` removes the checkout's ignored scratch
     (the folders above plus `.phpunit.result.cache`). It never touches
     `vendor`, `node_modules`, `var/log`, `var/uploads` or tracked files.
   - `composer clean-scratch -- --temp` removes ChitChat's temp entries that
     have had nothing written for 24 hours. Add `--older-than 0` once no run
     is active.
   - `composer clean-scratch -- --report` prints the counts and deletes
     nothing. It exits with status 1 when a limit is exceeded.
   A link is removed itself, never its target.
6. **One worktree per active branch.** When its PR merges or closes, remove
   it with `git worktree remove`. Clean up scratch before you go idle.
7. **Run full suites one at a time.** Don't run PHPUnit or Playwright suites
   in parallel on the same machine, and never stop another session's
   processes.
8. **Keep repositories outside OneDrive** or any other synced folder. On the
   shared host they live under `C:\Users\Thiesi\Local\Dev`.
9. **Git.** Reconcile by merging, never by force-pushing (and that includes
   `--force-with-lease`). Don't use a bare `git stash`, because the stash is
   shared by every worktree. GitHub is the backup, so push often.

## Checking a checkout after a host incident

- `git fsck --full`: there should be no missing or corrupt objects.
  Dangling objects are harmless.
- `git status`: the tree should be clean. Line-ending or mode changes across
  many files mean something rewrote them.
- `git worktree list`, then `git worktree prune` to drop entries whose
  folders are gone.
- `git fetch`, then `git status -sb` and `git log origin/<branch>..HEAD`
  to find anything unpushed.
- Look for leftover `.lock` files under `.git`. Remove one only when no git
  process is running.
- `composer install` and `npm ci` from the lockfiles, then run the suite
  once.
- `composer clean-scratch -- --report` for a baseline count.
- Look for duplicate files named with a `-<COMPUTERNAME>` suffix. Sync
  conflicts leave those behind.
