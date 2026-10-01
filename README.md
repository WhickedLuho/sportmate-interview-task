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
SyncTargetController ── markQueued() (atomic claim) ──► SyncTargetJob (queue, unique per target)
                                                              │
                                                              ▼
                                                   RepositorySyncService
                                                      │              │
                                      GitHubClient (HTTP, pages)     DB transaction: upsert + reconcile
                                                      │
                                                  GitHub API
```

| Responsibility                                    | Where                                                                      |
| ------------------------------------------------- | -------------------------------------------------------------------------- |
| Routing, validation, authorization                | `routes/web.php`, `app/Http/Requests`, `app/Policies/SyncTargetPolicy.php` |
| Controllers (thin)                                | `app/Http/Controllers`                                                     |
| GitHub communication, DTO mapping, typed failures | `app/Integrations/GitHub`                                                  |
| Synchronization logic and database writes         | `app/Services/RepositorySyncService.php`                                   |
| Queue behaviour: retries, timeouts, uniqueness    | `app/Jobs/SyncTargetJob.php`                                               |
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

| Topic              | What is implemented                                                                                                                                                                                                                                                                                          |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Duplicate requests | `markQueued()` is one conditional `UPDATE ... WHERE status NOT IN (queued, syncing, rate_limited)`, so two simultaneous clicks cannot both win. The job is also `ShouldBeUnique` per target, so a duplicate dispatch is dropped.                                                                             |
| Overlapping jobs   | The unique lock is held while the job is queued **and** running, so one target never syncs twice at once. (`WithoutOverlapping` would only be needed if several different job classes touched the same target.) The lock expires after 2 h (`uniqueFor`), so a crashed worker cannot block a target forever. |
| Retries            | Transient failures (5xx, connection errors) are rethrown: retried after 30 s, then 120 s, at most 3 exceptions (`maxExceptions`). Permanent failures (unknown account, invalid token) are recorded on the target and **not** retried.                                                                        |
| Rate limits        | The job is `release()`d until GitHub's reset time (from `Retry-After` / `X-RateLimit-Reset`). Releasing does not count against the retry budget; `retryUntil` (2 h) bounds the total.                                                                                                                        |
| Timeouts           | The job times out after 60 s and fails (`failOnTimeout`). It must stay below the queue connection's `retry_after` (90 s), otherwise a second worker would pick up a job that is still running. Each HTTP call has its own 10 s timeout.                                                                      |
| Failed jobs        | `failed()` marks the target `failed` with a generic message and logs the exception; the job also lands in `failed_jobs` (`php artisan queue:failed`, `queue:retry`).                                                                                                                                         |

**Stopping a synchronization.** A target that is `queued` or `rate_limited` shows a **Stop** button, and a
rate-limited one shows the time it will retry (`retry_at`). Stopping sets the target back to `idle` (a manual stop is
not an error, so no error is recorded). The job is deliberately **not** removed from the queue (with the database
queue that would mean searching serialized payloads); instead the job checks on wake-up whether the target is still
pending and exits without calling GitHub if it is not. If the user presses Sync again before that job wakes up, the
new dispatch is dropped by the unique lock, and the waiting job finds the target pending again and does the work, so a
target can never be left `queued` without a job. A request that is already running (`syncing`) is not interrupted;
it finishes within seconds. Stopping is not a pause: the hourly scheduler will queue the target again once its last
attempt is older than 55 minutes.

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

## Reconciliation, transactions and caching

- **Repositories that disappear from GitHub** are flagged (`missing_at`), not deleted, so nothing is lost and the
  action is reversible. They are hidden in the list unless _Show missing_ is ticked, and a repository that comes
  back is unflagged. Known compromise: if GitHub ever returned an empty list by mistake, every repository of that
  target would be flagged until the next good sync.
- **Transaction boundary:** all network I/O happens first, then one short database transaction writes the batch
  (upsert in chunks of 200) and reconciles. A failure while talking to GitHub therefore never leaves a half-synced
  target, and the transaction never waits on the network.
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

## Compromises and what I would do next

- **Rate limits are the real bottleneck without a token.** Next: require a token in production, spread scheduled
  syncs over the hour instead of queuing everything at once, and use conditional requests (`ETag` / `If-None-Match`),
  which do not count against the limit when nothing changed.
- **Stuck targets:** a target left in `queued`/`syncing` (for example if the queue is wiped) is never picked up
  again by the scheduler. A small "reaper" for statuses older than a few hours would fix that.
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
- **Incomplete:** REST API, README full-text search, sync history, stuck-target reaper (see above).
- **Important compromises:** see the section above.
