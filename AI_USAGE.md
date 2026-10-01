# AI usage

> Draft maintained during development; to be rewritten in my own words before submission.

## Tools used

- Claude Code (Claude Sonnet 5.5) in the Claude desktop app, working directly in this repository.

## Important prompts / instructions

- Task description pasted in full, then discussed and planned step by step before any code was written.
- Agreed decisions: Laravel Sail, per-user multitenancy from the start, a simple status enum
  (no run-history table), and working in small steps with an explanation after each block.

## Substantially AI-generated areas

_(to be filled in as the work progresses)_

## Suggestions changed or rejected

- **Starter kit version.** `composer create-project laravel/vue-starter-kit` resolved to the `v1.0.2` tag,
  which is Laravel 12 with PHPUnit and Ziggy. The task links the Laravel 13 docs, so the scaffold was
  discarded and recreated from `dev-main` (Laravel 13, PHP 8.3+, Fortify).
- **Test database.** `sail:install` rewrote `phpunit.xml` to a file-based `testing` database. I reverted it
  to the starter kit's in-memory SQLite, which is faster and isolated.

- **Target type.** The first plan had the user choose "user" or "organization". Changed so the type is
  discovered from the GitHub payload (`owner.type`), saving the user a choice and us an extra API request.
- **Pagination.** Instead of following the `Link` header's URLs (which would send the token to whatever host
  the header names), the client requests `page=N` against the configured base URL and only uses the header
  to learn whether another page exists.
- **Adminer login.** The AI-written plugin file was saved with a UTF-8 BOM by Windows PowerShell, which broke
  Adminer's headers. Found by testing in the browser; the same BOM was then removed from `compose.yaml`.

- **Duplicate/overlap protection.** `ShouldBeUnique` (per target) plus an atomic conditional `UPDATE`
  (`markQueued()`) instead of also adding `WithoutOverlapping`, which would be redundant for a single job class.
- **Retry policy.** `retryUntil` + `maxExceptions` instead of a plain `tries` count, so that releasing the job
  because of a GitHub rate limit does not consume the retry budget. Job timeout (60s) is kept below the
  queue's `retry_after` (90s) so a slow job is never picked up twice.
- **Test mistakes found by running them.** `Http::fake()` appends stubs and the first match wins, so a second
  fake inside one test was silently ignored (5 failures that looked like service bugs but were test bugs);
  fixed with a `fakeGitHub()` helper that resets the fake. Also a wrong `PromiseInterface` import in the helper.

- **Wayfinder generation.** Running `php artisan wayfinder:generate` by hand overwrote the generated route
  helpers without the `.form()` variants that `vite.config.ts` enables (`formVariants: true`), which broke
  type-checking across the starter kit. Regenerated with `--with-form`.
- **Authorization.** Other users' targets answer 404 (`Response::denyAsNotFound()`), not 403, so ids cannot be probed.
- **Sorting.** The sort key from the query string is mapped through a whitelist; a PHPStan finding made the
  direction type explicit (`'asc'|'desc'`).

- **Scheduled sync threshold.** A due check of "last attempt older than 60 minutes" would skip a target by a
  whole extra hour (the previous run started a few seconds past the hour, so it is always just under 60
  minutes old at the next tick). Uses 55 minutes and has a test for exactly that boundary. One of my own
  assertions in that test was meaningless (`55 + 1 < 60`) and was replaced after review.
- **Command style.** Used `$signature`/`$description` properties like the existing command rather than the
  newer attributes I first wrote, to match the surrounding code.

- **README setup steps were verified, not assumed.** I followed them literally on a fresh `git clone` (separate
  Compose project and ports): it exposed that the web server cannot start while `vendor/` is still empty and
  needs one restart, which the first draft of the README did not say.
- **Placeholder tests list real gaps only.** Cases that are already covered (pagination, rate limit release,
  reconciliation) are not repeated as placeholders. One placeholder documents a known defect in my own code
  (the `LIKE` search does not escape `%` and `_`).

- **Stopping a rate-limited sync.** Triggered by a screenshot of three targets waiting for a rate limit reset
  with no way to see when or to stop them. Chosen design: cooperative cancellation (the waiting job checks the
  target on wake-up) instead of deleting the job from the database queue, plus a stored `retry_at` for the UI.
  The dangerous edge case (stop, then Sync again while the old job still holds the unique lock) has its own test.
  Decisions on status after stopping (`idle`) and on the scheduler (not disabled) were made by the developer.

## How generated code was validated

- Starter kit test suite run inside the container (`php artisan test`): 40 passed on the untouched scaffold.
- Feature tests with faked HTTP (`Http::fake()`, `preventStrayRequests()`), Pint and PHPStan on every step.
- Real end-to-end check with the Docker queue worker against the live GitHub API: two dispatches produced one
  job, the sync stored 8 repositories and detected the account type, and an unknown account ended as `failed`
  with a friendly message while the raw exception only reached the log. Temporary rows were removed afterwards.
- Scheduled sync: tests for the due selection, the hourly registration and double-run idempotence. A live run
  also showed the rate limit handling working for real: with large accounts (hundreds of repositories) added
  through the UI and no token, targets went to `rate_limited` and were released instead of failing.
  Mistake worth noting: I ran the command against the developer's real database without re-checking for
  data added since my last check, so it queued their targets as well (no data lost, but it should have been
  tested in an isolated database).
- Controller tests (authorization, validation, filters, sorting, pagination) and Inertia prop assertions.
- UI checked in a real browser against the running stack with a throwaway user (deleted afterwards):
  validation error display, adding a target, Sync button -> worker -> "Synced" with 8 repositories,
  debounced search, sortable column headers, and URLs that reflect the filter state.
  Requests take ~4s on this Windows bind-mount setup, which first made my scripted checks look like failures.
