<?php

namespace Tests\Feature\Planned;

use Tests\TestCase;

/**
 * Test cases I would write next. Each one is skipped on purpose and says what it would
 * prove and why it matters, so the gaps in the suite are visible instead of implicit.
 *
 * Already covered elsewhere (so not listed here): pagination, rate limit release, GitHub
 * 404/5xx/connection failures, reconciliation of missing repositories, scheduled selection.
 */
class PlannedTest extends TestCase
{
    public function test_a_job_that_keeps_failing_ends_in_failed_jobs_after_three_exceptions(): void
    {
        $this->markTestSkipped(
            'Needs a real queue worker (or Queue::after hooks): three transient GitHub failures in a row should '
            .'exhaust maxExceptions, call failed() and leave one row in failed_jobs. The pieces are tested separately.'
        );
    }

    public function test_a_rate_limited_job_resumes_after_the_reset_time_and_finishes_the_sync(): void
    {
        $this->markTestSkipped(
            'End-to-end with travel(): first run hits the limit and is released, the second run after the reset '
            .'succeeds, and the target ends up synced without using a retry. Today only the release is asserted.'
        );
    }

    public function test_a_job_that_exceeds_its_timeout_marks_the_target_failed(): void
    {
        $this->markTestSkipped(
            'Requires a real worker process to hit the 60 s limit; would verify failOnTimeout plus the failed() '
            .'handler, and that the timeout stays below the connection retry_after.'
        );
    }

    public function test_the_unique_lock_expires_so_a_crashed_worker_cannot_block_a_target_forever(): void
    {
        $this->markTestSkipped(
            'Dispatch, simulate a worker crash (lock never released), travel past uniqueFor and assert the next '
            .'dispatch is accepted again.'
        );
    }

    public function test_a_sync_aborts_without_flagging_repositories_when_the_page_limit_is_exceeded(): void
    {
        $this->markTestSkipped(
            'Fake 101 pages: the client must throw instead of returning a partial list, and the service must not '
            .'mark the repositories it did not see as missing.'
        );
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->markTestSkipped(
            'Known gap: the LIKE search does not escape % and _, so searching for "_" matches every repository. '
            .'Write the failing test first, then escape the term (or move to FTS5).'
        );
    }

    public function test_a_target_stuck_in_queued_is_picked_up_again_by_the_scheduler(): void
    {
        $this->markTestSkipped(
            'Depends on the stuck-target reaper that does not exist yet (see README, next steps): a target queued '
            .'for several hours with no job should be reset and queued again.'
        );
    }

    public function test_deleting_a_user_removes_their_targets_and_repositories(): void
    {
        $this->markTestSkipped(
            'Cascade from users to sync_targets to repositories is declared in the migrations, but only the '
            .'target-to-repository step is asserted today.'
        );
    }

    public function test_adding_a_target_for_a_github_account_that_does_not_exist_is_reported_at_the_first_sync(): void
    {
        $this->markTestSkipped(
            'Design decision: existence is not checked when adding (no network call in the request). The first '
            .'sync ends as failed with a friendly message; this test would pin that behaviour from end to end.'
        );
    }

    public function test_the_language_filter_options_are_refreshed_after_a_sync(): void
    {
        $this->markTestSkipped(
            'Only relevant once the filter options are cached (see README, caching): the cache entry must be '
            .'invalidated at the end of a successful sync, per user.'
        );
    }

    public function test_scheduled_syncs_are_spread_over_the_hour_to_stay_within_the_rate_limit(): void
    {
        $this->markTestSkipped(
            'Planned improvement: dispatch due targets with staggered delays instead of all at once, and assert '
            .'the delays grow with the number of targets.'
        );
    }
}
