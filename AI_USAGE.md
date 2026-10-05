# AI usage

## Tools used and scope

**OpenAI Codex** prepared this disclosure and reviewed the assignment, application source, commit history, test coverage and configuration. The initial review changed documentation only. Later implementation passes added strict GitHub response validation, retry-deadline checks, resumable page-by-page persistence, dispatch fencing, recovery and regression/unit tests under my direction.

**Claude Code** assisted with application development in small, explained blocks, including planning, implementation, tests, local setup and troubleshooting. AI assistance was substantial rather than limited to autocomplete.

The official Laravel Vue starter kit supplied the initial authentication, layouts, UI components and framework configuration. Those inherited files should not be counted as custom application code generated for this task.

## Important prompts and instructions

The assignment was supplied in full as the starting point. The workflow was to discuss the approach, implement one block at a time and explain the result before moving on.

The following summarizes the instructions and decisions recorded in the development notes; it is not a verbatim prompt transcript:

- Start from the official Laravel Vue/Inertia starter kit and match the Laravel 13 version linked in the assignment.
- Use Laravel Sail, SQLite and a dedicated queue worker for local development.
- Keep GitHub HTTP communication, external-data mapping, persistence, queue behaviour and controllers separate.
- Scope targets and repository browsing to the signed-in user from the beginning.
- Store the latest status and error on the target, using a status enum, instead of introducing a synchronization-history table.
- Build the baseline first, with faked external HTTP in tests, then add selected improvements.
- For the later waiting-sync improvement, show the rate-limit retry time and allow stopping waiting work. The development notes explicitly attribute the choice of `idle` after stopping, and keeping scheduled synchronization enabled, to the developer.

For this documentation review, I instructed Codex to inspect the code and development commits, write a traceable disclosure and leave the code unchanged.

For the later implementation pass, I asked for a plan to fix response validation and retry deadlines and add meaningful unit tests, then approved that plan. Codex edited `GitHubClient`, `RepositoryData`, `SyncTargetJob`, the relevant feature tests, isolated DTO/enum unit tests and this documentation. The starter unit example was replaced with tests of actual application behaviour.

## Development blocks and substantially AI-generated areas

The custom feature code and its tests were substantially AI-generated under my direction. The commits below make the development blocks traceable; a commit groups the resulting work, but does not establish who typed every line.

| Block                     | Commit    | Result and main locations                                                                                                                                                                           |
| ------------------------- | --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Starter kit               | `84fcb55` | Official Laravel 13 Vue starter kit; primarily upstream scaffold.                                                                                                                                   |
| Local runtime             | `b9a1498` | Sail/SQLite setup, dedicated database-queue worker and environment defaults in `compose.yaml` and `.env.example`.                                                                                   |
| Data model                | `01b170a` | `SyncTarget`, `Repository`, ownership relations, enums, factories, migrations, foreign keys, composite unique constraints and database tests.                                                       |
| Database inspection       | `3c26a44` | Local Adminer service and development login plugin in `docker/adminer/`.                                                                                                                            |
| GitHub integration        | `fd32459` | `app/Integrations/GitHub/`: HTTP client, `RepositoryData` DTO, paginated fetching, optional token, typed failures and faked-HTTP tests.                                                             |
| Persistence and queue     | `03f6756` | `RepositorySyncService`, `SyncTargetJob`, status helpers and tests: fetch before the transaction, chunked upserts, reversible missing-repository flags, uniqueness, retries and rate-limit release. |
| User interface            | `10ebae9` | Controllers, Form Requests, policy, resources, routes and Vue target/repository pages; user-scoped browsing, search, filters, whitelisted sorting, pagination and status polling.                   |
| Windows performance       | `f860dbb` | Move `vendor/` to a Docker named volume to reduce framework file-loading overhead.                                                                                                                  |
| Scheduled synchronization | `1cc9f9b` | `SyncDueTargets`, hourly schedule, scheduler container and tests; reuse the manual flow's atomic target claim.                                                                                      |
| Handover documentation    | `5d28e9d` | README, setup-verification notes and explicitly skipped planned tests.                                                                                                                              |
| Waiting-sync controls     | `4b585bd` | `retry_at` migration, Stop action, retry-time display, cooperative cancellation and tests for stopping then requesting another sync.                                                                |

The current flow is: validate and save a target locally; atomically claim and enqueue a database job; fetch one GitHub page per queue execution; upsert the validated page and advance its database cursor in one transaction; release for the next page; reconcile and mark success only on the final page. Partial data is browsable, and a stopped or failed run can resume from its saved page.

## Suggestions changed, rejected or corrected

The following examples summarize changes to AI suggestions and corrections made during development. The current code and listed commits show the resulting design.

| Original suggestion or problem                                      | Change and reason                                                                                                                                                                                          | Trace                                                 |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| The tagged starter kit resolved to Laravel 12.                      | Recreated the scaffold from `dev-main` to match Laravel 13.                                                                                                                                                | `84fcb55`.                                            |
| Sail changed the test database to a file-based database.            | Restored in-memory SQLite for isolated tests.                                                                                                                                                              | `phpunit.xml`, `b9a1498`.                             |
| Ask the user to choose user or organization.                        | Discover the type from repository `owner.type`, avoiding another input and API request. With no repositories, an unknown type remains unknown.                                                             | `RepositoryData`, `RepositorySyncService`, `fd32459`. |
| Follow pagination URLs from the `Link` header directly.             | Request numbered pages against the configured base URL and use the header only to detect a next page. This avoids using arbitrary header URLs for authenticated requests.                                  | `GitHubClient`, `fd32459`.                            |
| Add job overlap middleware as well as uniqueness.                   | Use `ShouldBeUnique` per target and an atomic `markQueued()` claim; omit additional job overlap middleware for this single job class. The scheduler command separately uses `withoutOverlapping`.          | `03f6756`, `routes/console.php`.                      |
| Use a plain attempt count as the retry policy.                      | Use `retryUntil()` and `maxExceptions` to separate rate-limit releases from exception exhaustion, with a 60-second job timeout below the connection's 90-second `retry_after`.                             | `SyncTargetJob`, `03f6756`.                           |
| Repeated HTTP fakes in a test did not replace the earlier response. | Reset the HTTP factory in `FakesGitHub`; also correct the promise-interface import.                                                                                                                        | `tests/Concerns/FakesGitHub.php`, `03f6756`.          |
| Manual Wayfinder generation omitted form helpers.                   | Regenerate with form variants to match the Vue forms and Vite configuration.                                                                                                                               | `10ebae9`, `vite.config.ts`.                          |
| A full 60-minute due threshold could miss the next hourly tick.     | Use 55 minutes of slack and add a regression test for an attempt just under an hour old.                                                                                                                   | `SyncDueTargets`, `ScheduledSyncTest`, `1cc9f9b`.     |
| Waiting jobs needed a way to stop.                                  | Change target state and let the waiting job exit on wake-up, instead of deleting serialized database-queue payloads. Reuse that waiting job if Sync is requested again while its unique lock remains held. | `4b585bd`, `SyncTargetJobTest`.                       |

## How generated code was validated

### Automated checks and coverage

The repository contains PHPUnit tests using in-memory SQLite, `RefreshDatabase`, faked GitHub responses and `Http::preventStrayRequests()` in the integration tests. The assignment's baseline cases are implemented: creating a target, synchronizing faked repository data and preventing duplicates.

Additional completed tests cover database constraints, mapping and pagination, token headers, GitHub failures, updates preserving `created_at`, chunked persistence, missing repositories returning, separation between targets, job failure handling, rate-limit release, duplicate dispatch, cancellation, authorization, filters, sorting, pagination and scheduled selection.

Most integration tests are in `tests/Feature/`, including client and service tests. `tests/Unit/` now tests DTO validation/mapping/defaults and enum behaviour without booting Laravel or accessing a database. Retry-boundary tests serialize jobs through the actual database queue, read its original deadline and exercise release and a successful second run. These checks do not launch a separate worker process or prove every worker behaviour.

The development notes record running PHPUnit, Pint and PHPStan during the implementation. The project also provides frontend formatting/lint and Vue TypeScript checks. Reproducible verification commands are:

```bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test ./vendor/bin/pint --test
docker compose exec laravel.test ./vendor/bin/phpstan analyse
docker compose exec laravel.test npm run check
docker compose exec laravel.test npm run types:check
docker compose exec laravel.test npm run build
```

### Checks recorded during development

The original notes report a live worker check against GitHub, including repeated dispatch, eight stored repositories and a nonexistent account producing a safe failure message. They also record browser checks for target creation, validation, worker-driven status changes, search and sorting; a fresh-clone walkthrough of the README; and observed rate-limit release with larger accounts.

Those are historical development records, not checks repeated by Codex for this document. The notes also record an incorrectly isolated scheduler check that queued targets in the development database; future manual checks should use a separate database.

### This documentation review

Codex compared the assignment, all 11 development commits, current feature code, test coverage and configuration. Runtime checks could not be repeated because the local Docker engine was unavailable. No fresh test-pass count, production-build success or browser verification is claimed here.

### Later implementation validation (2026-10-02)

Docker was available for this pass. Codex ran the complete PHPUnit suite: **167 passed, 11 skipped, 632 assertions**.
Pint checked all 94 PHP files successfully, and PHPStan reported no errors. Git diff whitespace checks also passed.
All GitHub responses in the new tests were faked; no live API requests were needed. The changed PHP code was
tested without changing the development database. Frontend build and browser checks were not repeated for this
backend-only change.

The response-validation fix preserves JSON object/list distinctions and rejects invalid consumed DTO fields before
any upsert or missing-repository reconciliation. The retry fix reads the deadline already serialized by Laravel,
including the 5-second reset buffer, instead of calculating a fresh deadline from `retryUntil()`. Boundary tests
exercise real database-queue serialization and release, but do not launch a separate worker process.

## Resumable pagination implementation

After a Microsoft synchronization repeatedly exceeded the 60-second job timeout, I asked Codex for a code plan,
100 model variations and an implementation that preserves existing behaviour without pushing changes. The model
variations were planning exercises, not PHPUnit results. They identified stale callbacks, missing queue entries
and page-number drift. The implementation keeps completed pages and the next-page cursor in the database, uses
separate data-run and queue-dispatch ids, and processes one page per execution of the same released job.

Codex changed the integration client and page DTO, synchronization service/job, models and additive migration,
shared dispatch service, scheduled missing-job recovery, progress/Resume display, tests and documentation.
Under my instruction to preserve existing behaviour, final missing-repository reconciliation remains in place;
it runs only after the final page. Partial results are visible and this changes the previous all-or-nothing data
refresh semantics deliberately. Completed syncs still refresh every repository on their next full run.

Dispatch and queue insertion use the same database transaction. An overlap lease supplements conditional run,
dispatch and page guards; cache uniqueness was replaced. The original queue deadline survives page releases.
The tests exercise real SQLite rollback with an injected trigger, real database queue payloads and Laravel's
Worker::process() logic. A separate OS worker process and browser E2E tests are not part of the automated suite.

For this pagination pass, the full PHPUnit suite completed with **197 passed, 11 skipped, 1,524 assertions**.
Pint checked 101 PHP files successfully; PHPStan, frontend lint/formatting, Vue TypeScript checks and the production
build passed. Before the additive migration, Codex created a consistent SQLite backup in the ignored local
documentation directory, paused queue/scheduler, migrated and restarted them. A live Microsoft synchronization
was then started through the normal dispatch service: pages were stored incrementally, and all 84 pages completed
with 8,340 repositories, a successful synchronization timestamp, no target error and no remaining queue jobs.

## Method documentation cleanup (2026-10-03)

I asked Codex to review the method docblocks and propose a plan before editing. After approval, Codex updated
77 method docblocks across 24 application files with concise summaries, parameter types and descriptions,
return values and exception types. Parameter, return and exception sections are separated by blank lines.
Pint's PHPDoc rules were adjusted to preserve this format; two existing test helper docblocks received matching
section spacing.

Pint checked all 101 PHP files successfully, PHPStan reported no errors, and formatting of `pint.json` and Git
diff whitespace checks passed. PHP token comparisons against HEAD confirmed that executable code in all
26 changed PHP files was unchanged. PHPUnit and browser checks were not repeated for this documentation-only pass.

## Remaining limits and responsibility

The code and README document compromises rather than treating AI-generated output as production-ready. In particular:

- Real-worker retry exhaustion, timeout handling and rate-limit resume still have skipped planned tests.
- There is no synchronization-run history. The database queue claim and enqueue are transactional. `sync:recover` makes old pending targets with missing queue entries resumable, but does not detect a stopped worker while valid queue entries remain.
- Search currently treats `%` and `_` as SQL `LIKE` wildcards; the skipped test documents this gap.
- The scheduler filters on `last_attempted_at`, while its existing composite index uses `last_synced_at`.
- Syntactically valid but incorrect GitHub data cannot be detected in every case. Page-number pagination is not a snapshot: changes during a run may skip items and cause reversible missing flags. Malformed pages preserve earlier saved pages and cannot trigger final reconciliation.
- Stopping waiting work does not interrupt a running request or disable future scheduled synchronization.

Approximate implementation time is still unspecified in the README. Commit timestamps are not a measure of active work and cannot establish compliance with the assignment's eight-hour limit.

AI assistance does not replace my responsibility to review and understand the submitted code. This disclosure describes the assistance and available evidence; it does not claim unaided authorship or independently verified historical execution results.
