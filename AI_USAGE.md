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

## How generated code was validated

- Starter kit test suite run inside the container (`php artisan test`): 40 passed on the untouched scaffold.
- _(feature tests, manual checks in the browser, etc. to be added)_
