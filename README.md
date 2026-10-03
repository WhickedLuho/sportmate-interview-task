# GitHub repository sync

A small Laravel 13 + Inertia + Vue application that syncs the **public repositories of GitHub users and
organizations** into a local SQLite database and lets you browse them. Built from the official Laravel Vue
starter kit for the SportMate medior interview task.

- Add a GitHub username or organization, press **Sync**, and the repositories are fetched by a queue worker.
- Browse the stored repositories with search, account and language filters, sortable columns and pagination.
- Every user only sees their own targets and repositories.
- Targets are also refreshed automatically every hour.

See [`AI_USAGE.md`](AI_USAGE.md) for how AI was used, and the [submission note](#submission-note) at the end.

## Running it (Docker only, no local PHP or Node needed)

Everything runs through Docker Compose (Laravel Sail image). Services: `laravel.test` (web, port **8088**),
`queue` (worker), `scheduler` (hourly sync) and `adminer` (database browser, port **8089**, loopback only).

First start on a fresh clone:

```bash
cp .env.example .env
touch database/database.sqlite

# 1. vendor/ on the host is only needed once: Compose builds the image from vendor/laravel/sail
docker run --rm -v "$(pwd):/opt" -w /opt laravelsail/php84-composer:latest composer install --ignore-platform-reqs --no-scripts

# 2. build and start
docker compose up -d --build

# 3. install dependencies inside the container (vendor/ lives on a named volume, see "Design notes")
docker compose exec laravel.test composer install
docker compose restart laravel.test        # the web server could not start while vendor/ was still empty

# 4. app key, database, frontend (the queue and scheduler containers restart themselves until this is done)
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test npm install
docker compose exec laravel.test npm run build
```

Open <http://localhost:8088> and register.

**Email verification:** the app requires verified email addresses, but in development mail is written to the log
(`MAIL_MAILER=log`). Open `storage/logs/laravel.log`, find the `.../email/verify/...` link of your registration
mail, replace `&amp;` with `&` and open it. (Or mark the user verified from `php artisan tinker`.)

**GitHub rate limit:** without a token GitHub allows only **60 requests per hour** per IP, and a page of 100
repositories is one request, so large accounts (hundreds of repositories) exhaust it quickly. The sync then
shows _Rate limited_ and resumes by itself when the limit resets. Set `GITHUB_TOKEN` in `.env` (a token without
any scopes is enough for public data, 5,000 requests/hour) and restart the containers:

```bash
docker compose restart laravel.test queue scheduler
```

Useful commands (all inside the containers):

| Task                                 | Command                                                                               |
| ------------------------------------ | ------------------------------------------------------------------------------------- |
| Run the tests                        | `docker compose exec laravel.test php artisan test`                                   |
| Code style / static analysis         | `docker compose exec laravel.test ./vendor/bin/pint` / `./vendor/bin/phpstan analyse` |
| Frontend checks                      | `docker compose exec laravel.test npm run check` / `npm run types:check`              |
| Frontend dev server (HMR)            | `docker compose exec laravel.test npm run dev`                                        |
| Queue a sync for all due targets now | `docker compose exec laravel.test php artisan sync:targets`                           |
| Worker log                           | `docker compose logs -f queue`                                                        |
| Add a PHP package                    | `docker compose exec laravel.test composer require <package>`                         |

**Adminer:** <http://localhost:8089/?sqlite=&username=dev&db=%2Fdata%2Fdatabase.sqlite>, password `dev`
(development only; it opens the SQLite file read/write).

**Troubleshooting (Windows):** if a page returns a 500 about `tempnam()` or an unwritable `storage` directory:
`docker compose exec laravel.test sh -c "chmod -R a+rwX storage bootstrap/cache database"`.

## How it works

```
Browser (Inertia/Vue)
   │  POST /targets/{id}/sync
   ▼
SyncTargetController ── SyncDispatchService (claim + enqueue) ──► SyncTargetJob
                                                              │
                                                              ▼
                                                   RepositorySyncService
                                                      │              │
                                      GitHubClient (one page)       DB transaction: page upsert + cursor
                                                      │
                                                  GitHub API
```

| Responsibility                                    | Where                                                                      |
| ------------------------------------------------- | -------------------------------------------------------------------------- |
| Routing, validation, authorization                | `routes/web.php`, `app/Http/Requests`, `app/Policies/SyncTargetPolicy.php` |
| Controllers (thin)                                | `app/Http/Controllers`                                                     |
| GitHub communication, DTO mapping, typed failures | `app/Integrations/GitHub`                                                  |
| Synchronization logic and database writes         | `app/Services/RepositorySyncService.php`                                   |
| Queue behaviour: retries, timeouts, overlap guard | `app/Jobs/SyncTargetJob.php`                                               |
| Status transitions                                | `app/Models/SyncTarget.php`, `app/Enums/SyncStatus.php`                    |
| Scheduling                                        | `app/Console/Commands/SyncDueTargets.php`, `routes/console.php`            |
| UI                                                | `resources/js/pages/targets`, `resources/js/pages/repositories`            |

A target's status is one of `idle`, `queued`, `syncing`, `synced`, `failed`, `rate_limited`. The last error is
stored on the target as a **user-safe message**; the technical detail (exception class, message, target id,
attempt) goes to `storage/logs/laravel.log`, so a failed sync can be traced without showing raw exceptions.

## Database design

Migrations: `sync_targets` and `repositories` (plus the framework tables).

- `sync_targets.user_id` → `users` (cascade). Unique `(user_id, name)`. Names are stored lowercased, because
  GitHub logins are case-insensitive but SQLite's unique index is not.
- `repositories.sync_target_id` → `sync_targets` (cascade). Unique `(sync_target_id, external_id)`: this is what makes
  re-syncing idempotent (upsert) and prevents duplicates. The same GitHub repository can exist under two targets
  because two users may track the same account.
- `external_id` is GitHub's numeric id, which survives renames.

**Indexes**

| Index                                                | Why                                                                                                                                                                                                                                        |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `sync_targets (user_id, name)` unique                | Duplicate prevention, and lookups of one user's targets                                                                                                                                                                                    |
| `sync_targets (status, last_synced_at)`              | Meant for the scheduler's "what is due" scan. In practice that query filters on `last_attempted_at`, so this index is a candidate to change (I would move it to `(status, last_attempted_at)`); with a handful of targets it is irrelevant |
| `repositories (sync_target_id, external_id)` unique  | The upsert conflict target; also joins from a target                                                                                                                                                                                       |
| `repositories (sync_target_id, stargazers_count)`    | "Sort by stars" within the user's targets                                                                                                                                                                                                  |
| `repositories (sync_target_id, external_updated_at)` | "Sort by last updated" (the default order)                                                                                                                                                                                                 |
| `repositories (sync_target_id, language)`            | Language filter                                                                                                                                                                                                                            |

Columns I deliberately did **not** index: `full_name` and `description`. The search uses `LIKE '%term%'`, which
cannot use a B-tree index. A real application would use SQLite FTS5 or, on MySQL/PostgreSQL, full-text indexes.

## Queue behaviour

| Topic              | What is implemented                                                                                                                                                                                                                  |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Duplicate requests | An atomic target claim and its database queue entry are written in the same transaction. A repeated request cannot create another active dispatch.                                                                                   |
| Overlapping jobs   | WithoutOverlapping uses a target-level cache lock (75 s). Commit and failure updates also check run/dispatch ids. A resumed attempt supersedes old payloads.                                                                         |
| Retries            | Transient GitHub failures retry after 30 s, at most 3 exceptions. Successful page releases increment attempts but not maxExceptions. Permanent errors preserve progress without automatic retry.                                     |
| Rate limits        | The job is released until GitHub reset plus 5 seconds, only if that time is before the original 2-hour queue deadline. Otherwise the target fails with a safe message and permits a new sync. Releases do not consume maxExceptions. |
| Timeouts           | One page per execution, 60 s job timeout; overlap lease 75 s, queue retry_after 90 s. Each HTTP request has a 10 s timeout. Terminal failure keeps saved pages.                                                                      |
| Failed jobs        | `failed()` marks the target `failed` with a generic message and logs the exception; the job also lands in `failed_jobs` (`php artisan queue:failed`, `queue:retry`).                                                                 |

**Stopping a synchronization.** Queued or rate-limited targets show **Stop**. Stopping invalidates the active
dispatch and preserves committed pages. Old jobs exit without calling GitHub; **Resume** queues a new attempt from
the saved page. Running requests cannot be stopped from the UI. The hourly scheduler still resumes due targets;
Stop does not disable scheduled synchronization.

**Queue consistency and recovery.** SyncDispatchService writes the target claim and database queue entry in one
transaction on the same connection. Synchronization explicitly uses the database queue. Every five minutes,
`sync:recover` makes old pending targets without a matching queue entry failed/resumable. Delayed and reserved
jobs are never declared missing merely because they are old.

**Updating an existing checkout:** stop queue and scheduler, run `php artisan migrate`, build frontend assets and
start the services again. Legacy jobs without progress metadata safely exit; recovery makes old pending targets
retryable. Existing repository rows are preserved.

**Deploying and monitoring the worker (not set up here):** run `queue:work` under a process supervisor
(Supervisor or systemd, or a dedicated container as in `compose.yaml`) with `restart: unless-stopped`, restart it
on every deploy (`queue:restart`), and run `schedule:run` from cron (or `schedule:work` as here). Monitor queue depth
and age of the oldest job, the size of `failed_jobs`, and alert on targets stuck in `queued`/`syncing`. Laravel
Horizon would give this on Redis; with the database queue a scheduled check of `failed_jobs` is the simple option.

## GitHub integration

- `GitHubClient` is the only class that makes HTTP calls. It returns `RepositoryData` DTOs and turns every failure
  into one of `GitHubNotFoundException`, `GitHubRateLimitedException`, `GitHubUnavailableException` or
  `GitHubException`, each with a separate user-safe message.
- **Pagination:** it requests `page=1..N` (100 per page) until the `Link` header has no `rel="next"`, sorted by
  `full_name` so pages stay stable while repositories are pushed to. It does not follow arbitrary URLs from the
  header (that would send the token to whatever host the header names). A 100-page cap makes it fail rather than
  store a partial list as complete.
- `/users/{login}/repos` works for organizations as well, so one endpoint covers both. The account type is read from
  the repositories' `owner.type`, saving a request.
- `open_issues_count` is GitHub's number and **includes open pull requests**.
- The client does not retry; retry and back-off policy belongs to the queued job.
- Responses must be JSON lists of objects. Consumed fields are validated before persistence: positive integer
  repository ids, nonempty names, HTTP(S) URLs, typed optional values and valid timestamps. A malformed item or
  later page fails the current attempt with a safe message; earlier saved pages and the last successful sync time are preserved.

Rate-limit releases use the reset time plus a 5-second buffer and do not consume `maxExceptions`. The next attempt
must be strictly before the original queue payload's 2-hour `retryUntil`. Otherwise the target becomes `failed`,
clears `retry_at` and allows a new manual sync. This handled failure is recorded on the target, without a
`failed_jobs` entry. Releases still increment the queue attempt counter.

## Reconciliation, transactions and caching

- **Repositories that disappear from GitHub** are flagged (`missing_at`), not deleted, so nothing is lost and the
  action is reversible. They are hidden in the list unless _Show missing_ is ticked, and a repository that comes
  back is unflagged. Known compromise: if GitHub ever returned an empty list by mistake, every repository of that
  target would be flagged until the next good sync.
- **Transaction boundary:** each execution fetches and validates one page before a short transaction. The page upsert
  and cursor advance commit together. Later failures preserve saved pages and the last successful sync time.
  Missing reconciliation runs only with the final page.
- **Resumption:** `sync_run_id` marks the data run; `dispatch_id` fences each queue attempt and old failure callbacks.
  `next_page` points to the first uncommitted page. Stop preserves progress, Resume gets a new dispatch, and the
  next sync after full completion starts from page 1. Changed query parameters start a new data run.
- **Partial results:** saved pages are immediately browsable. GitHub pagination is not a snapshot: additions, removals
  or renames during a run can shift boundaries. Existing missing flags remain reversible; a later complete sync may
  correct skipped records. Direct absence checks would be the next improvement before treating flags as authoritative.
- **Caching:** there is no cache layer; every list is a database query scoped by user. If one were added (for example
  the language filter options), it would be keyed per user and invalidated at the end of a successful sync, in the same
  place that already marks the target `synced`.

## API authentication and authorization

The UI uses session authentication (Laravel Fortify from the starter kit). Authorization is a policy: another user's
target answers **404** (not 403), so ids cannot be probed, and every repository query is scoped to the signed-in
user's targets. A REST API for a mobile app is **not built**; I would expose the same data through the existing
resource classes with Laravel Sanctum personal access tokens (abilities per token), the same policy, and proper
status codes (202 for "sync queued", 409 if already running, 429 passthrough with `Retry-After`).

## Testing

`php artisan test` runs PHPUnit with an in-memory SQLite database. **No test touches the network**
(`Http::fake()` plus `Http::preventStrayRequests()`).

| Area          | What is covered                                                                                                                                     |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Database      | unique constraints, name normalization, cascade deletes                                                                                             |
| GitHub client | payload mapping, pagination, token header, 404, primary and secondary rate limits, 5xx, connection failure, malformed payload                       |
| Sync service  | create, update without duplicates (`created_at` kept), chunking, reconciliation both ways, isolation between targets, failure leaves data untouched |
| Job           | success, permanent failure, rate limit release, transient rethrow, `failed()`, uniqueness                                                           |
| Controllers   | authorization (404 for foreign ids), validation edge cases, atomic claim / no double dispatch, filters, sorting (whitelist), pagination             |
| Scheduler     | hourly registration, due selection, the 55-minute boundary, idempotence                                                                             |

Planned but not written test cases are listed as skipped placeholders in `tests/Feature/Planned`.

`tests/Unit` contains isolated PHPUnit tests for DTO validation, database-field mapping, optional defaults, GitHub
account types and synchronization status controls; these do not boot Laravel or use a database. Regression tests
also cover object-vs-list JSON, malformed later pages preserving stored data, and retry boundaries against actual
database-queue payloads, including release and a successful second run. New tests cover 0..8,400 repositories,
page-write rollback, saved-page resume, stale callbacks, enqueue rollback, overlap expiry, target interleaving and
Laravel Worker::process() across more than three successful pages. Tests do not launch a separate worker process.

## Compromises and what I would do next

- **Rate limits are the real bottleneck without a token.** Next: require a token in production, spread scheduled
  syncs over the hour instead of queuing everything at once, and use conditional requests (`ETag` / `If-None-Match`),
  which do not count against the limit when nothing changed.
- **Stuck targets:** `sync:recover` runs every five minutes and marks old pending targets without their current queue
  entry as failed/resumable. Existing delayed and reserved jobs are left alone. It does not diagnose a stopped worker
  while queue entries still exist; operational monitoring remains necessary.
- **No sync history:** only the latest status and error are stored. A `sync_runs` table (start, end, counts, error)
  would make failures much easier to debug.
- **Target deletion** is not in the UI (the database cascade and policy are ready).
- **README full-text search, REST API and a Sanctum-secured mobile API** were left out in favour of a solid baseline.
- **Search** uses `LIKE`; FTS5 would be the proper fix.
- **Performance on Windows:** `vendor/` is on a Docker named volume because loading the framework through a Windows
  bind mount made every request take 2-5 s (now about 0.2 s). The consequence is that PHP packages must be installed
  inside the container.

## Submission note

- **Approximate time spent:** _to be filled in_
- **Completed:** baseline (targets, GitHub client with pagination, local storage with upsert, queued sync, Inertia/Vue UI
  with search/filter/sort, tests, AI usage notes) plus pagination, per-user multitenancy, simple reconciliation and
  scheduled synchronization.
- **Incomplete:** REST API, README full-text search, sync history and confirmation of missing repositories against a changing GitHub listing.
- **Important compromises:** see the section above.
