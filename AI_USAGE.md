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

## How generated code was validated

- Starter kit test suite run inside the container (`php artisan test`): 40 passed on the untouched scaffold.
- Feature tests with faked HTTP (`Http::fake()`, `preventStrayRequests()`), Pint and PHPStan on every step.
- Real end-to-end check with the Docker queue worker against the live GitHub API: two dispatches produced one
  job, the sync stored 8 repositories and detected the account type, and an unknown account ended as `failed`
  with a friendly message while the raw exception only reached the log. Temporary rows were removed afterwards.
- _(UI checks in the browser to be added)_
