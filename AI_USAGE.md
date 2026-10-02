# AI usage

## Tools used and scope

**OpenAI Codex** prepared this disclosure and reviewed the assignment, application source, commit history, test coverage and configuration. This review changed documentation only.

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

## Development blocks and substantially AI-generated areas

The custom feature code and its tests were substantially AI-generated under my direction. The commits below make the development blocks traceable; a commit groups the resulting work, but does not establish who typed every line.

| Block | Commit | Result and main locations |
| --- | --- | --- |
| Starter kit | `84fcb55` | Official Laravel 13 Vue starter kit; primarily upstream scaffold. |
| Local runtime | `b9a1498` | Sail/SQLite setup, dedicated database-queue worker and environment defaults in `compose.yaml` and `.env.example`. |
| Data model | `01b170a` | `SyncTarget`, `Repository`, ownership relations, enums, factories, migrations, foreign keys, composite unique constraints and database tests. |
| Database inspection | `3c26a44` | Local Adminer service and development login plugin in `docker/adminer/`. |
| GitHub integration | `fd32459` | `app/Integrations/GitHub/`: HTTP client, `RepositoryData` DTO, paginated fetching, optional token, typed failures and faked-HTTP tests. |
| Persistence and queue | `03f6756` | `RepositorySyncService`, `SyncTargetJob`, status helpers and tests: fetch before the transaction, chunked upserts, reversible missing-repository flags, uniqueness, retries and rate-limit release. |
| User interface | `10ebae9` | Controllers, Form Requests, policy, resources, routes and Vue target/repository pages; user-scoped browsing, search, filters, whitelisted sorting, pagination and status polling. |
| Windows performance | `f860dbb` | Move `vendor/` to a Docker named volume to reduce framework file-loading overhead. |
| Scheduled synchronization | `1cc9f9b` | `SyncDueTargets`, hourly schedule, scheduler container and tests; reuse the manual flow's atomic target claim. |
| Handover documentation | `5d28e9d` | README, setup-verification notes and explicitly skipped planned tests. |
| Waiting-sync controls | `4b585bd` | `retry_at` migration, Stop action, retry-time display, cooperative cancellation and tests for stopping then requesting another sync. |

The present flow is: validate and save a target locally; atomically mark it queued; dispatch a unique job; fetch GitHub pages through the client; map them to DTOs; upsert and reconcile inside a database transaction; expose local data and safe status/error messages through Inertia.

## Suggestions changed, rejected or corrected

The following examples summarize changes to AI suggestions and corrections made during development. The current code and listed commits show the resulting design.

| Original suggestion or problem | Change and reason | Trace |
| --- | --- | --- |
| The tagged starter kit resolved to Laravel 12. | Recreated the scaffold from `dev-main` to match Laravel 13. | `84fcb55`. |
| Sail changed the test database to a file-based database. | Restored in-memory SQLite for isolated tests. | `phpunit.xml`, `b9a1498`. |
| Ask the user to choose user or organization. | Discover the type from repository `owner.type`, avoiding another input and API request. With no repositories, an unknown type remains unknown. | `RepositoryData`, `RepositorySyncService`, `fd32459`. |
| Follow pagination URLs from the `Link` header directly. | Request numbered pages against the configured base URL and use the header only to detect a next page. This avoids using arbitrary header URLs for authenticated requests. | `GitHubClient`, `fd32459`. |
| Add job overlap middleware as well as uniqueness. | Use `ShouldBeUnique` per target and an atomic `markQueued()` claim; omit additional job overlap middleware for this single job class. The scheduler command separately uses `withoutOverlapping`. | `03f6756`, `routes/console.php`. |
| Use a plain attempt count as the retry policy. | Use `retryUntil()` and `maxExceptions` to separate rate-limit releases from exception exhaustion, with a 60-second job timeout below the connection's 90-second `retry_after`. | `SyncTargetJob`, `03f6756`. |
| Repeated HTTP fakes in a test did not replace the earlier response. | Reset the HTTP factory in `FakesGitHub`; also correct the promise-interface import. | `tests/Concerns/FakesGitHub.php`, `03f6756`. |
| Manual Wayfinder generation omitted form helpers. | Regenerate with form variants to match the Vue forms and Vite configuration. | `10ebae9`, `vite.config.ts`. |
| A full 60-minute due threshold could miss the next hourly tick. | Use 55 minutes of slack and add a regression test for an attempt just under an hour old. | `SyncDueTargets`, `ScheduledSyncTest`, `1cc9f9b`. |
| Waiting jobs needed a way to stop. | Change target state and let the waiting job exit on wake-up, instead of deleting serialized database-queue payloads. Reuse that waiting job if Sync is requested again while its unique lock remains held. | `4b585bd`, `SyncTargetJobTest`. |

## How generated code was validated

### Automated checks and coverage

The repository contains PHPUnit tests using in-memory SQLite, `RefreshDatabase`, faked GitHub responses and `Http::preventStrayRequests()` in the integration tests. The assignment's baseline cases are implemented: creating a target, synchronizing faked repository data and preventing duplicates.

Additional completed tests cover database constraints, mapping and pagination, token headers, GitHub failures, updates preserving `created_at`, chunked persistence, missing repositories returning, separation between targets, job failure handling, rate-limit release, duplicate dispatch, cancellation, authorization, filters, sorting, pagination and scheduled selection.

Most custom tests are in `tests/Feature/`, including client and service tests. `tests/Unit/` currently contains only the starter kit's example test; a separate custom unit-test suite has not been added. Queue tests use fakes and direct handler calls, so they do not prove every behaviour of a real worker.

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

## Remaining limits and responsibility

The code and README document compromises rather than treating AI-generated output as production-ready. In particular:

- Real-worker retry exhaustion, timeout handling and rate-limit resume still have skipped planned tests.
- There is no stuck-target recovery or synchronization-run history. The status claim and queue dispatch are separate operations, so a dispatch failure can leave a target queued without work.
- Search currently treats `%` and `_` as SQL `LIKE` wildcards; the skipped test documents this gap.
- The scheduler filters on `last_attempted_at`, while its existing composite index uses `last_synced_at`.
- Repository DTO mapping assumes expected required fields. The client checks that the decoded payload is an array, but does not strictly validate a JSON list or every item.
- Stopping waiting work does not interrupt a running request or disable future scheduled synchronization.

Approximate implementation time is still unspecified in the README. Commit timestamps are not a measure of active work and cannot establish compliance with the assignment's eight-hour limit.

AI assistance does not replace my responsibility to review and understand the submitted code. This disclosure describes the assistance and available evidence; it does not claim unaided authorship or independently verified historical execution results.
