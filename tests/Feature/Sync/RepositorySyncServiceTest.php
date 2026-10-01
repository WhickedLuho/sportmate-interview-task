<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Enums\TargetType;
use App\Integrations\GitHub\Exceptions\GitHubUnavailableException;
use App\Models\Repository;
use App\Models\SyncTarget;
use App\Services\RepositorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

class RepositorySyncServiceTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private function sync(SyncTarget $target): void
    {
        $this->app->make(RepositorySyncService::class)->sync($target);
    }

    public function test_it_creates_repositories_and_marks_the_target_synced(): void
    {
        $target = SyncTarget::factory()->failed()->create(['name' => 'laravel']);
        $this->fakeGitHubRepositories([
            $this->githubRepository(1, 'framework', ['stargazers_count' => 30000, 'language' => null]),
            $this->githubRepository(2, 'docs', ['archived' => true]),
        ]);

        $this->sync($target);

        $this->assertSame(2, $target->repositories()->count());
        $framework = $target->repositories()->where('external_id', 1)->sole();
        $this->assertSame('laravel/framework', $framework->full_name);
        $this->assertSame(30000, $framework->stargazers_count);
        $this->assertNull($framework->language);
        $this->assertTrue($target->repositories()->where('external_id', 2)->sole()->is_archived);

        $target->refresh();
        $this->assertSame(SyncStatus::Synced, $target->status);
        $this->assertSame(TargetType::Organization, $target->type);
        $this->assertNotNull($target->last_synced_at);
        $this->assertNull($target->last_error, 'A successful sync clears the previous error.');
    }

    public function test_syncing_twice_updates_existing_repositories_without_duplicating_them(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->fakeGitHubRepositories([$this->githubRepository(1, 'framework', ['stargazers_count' => 10])]);
        $this->sync($target);
        $createdAt = Repository::query()->sole()->created_at;

        $this->travel(1)->hour();
        $this->fakeGitHubRepositories([$this->githubRepository(1, 'framework', ['stargazers_count' => 25, 'description' => 'Renamed'])]);
        $this->sync($target);

        $repository = Repository::query()->sole();
        $this->assertSame(25, $repository->stargazers_count);
        $this->assertSame('Renamed', $repository->description);
        $this->assertTrue($repository->created_at->equalTo($createdAt), 'created_at must survive an update.');
    }

    public function test_it_handles_more_repositories_than_one_upsert_chunk(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $payload = array_map(fn (int $id) => $this->githubRepository($id, "repo-{$id}"), range(1, 450));
        $this->fakeGitHubRepositories($payload);

        $this->sync($target);
        $this->sync($target);

        $this->assertSame(450, $target->repositories()->count());
    }

    public function test_repositories_that_disappear_are_flagged_and_unflagged_when_they_return(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->fakeGitHubRepositories([$this->githubRepository(1, 'a'), $this->githubRepository(2, 'b')]);
        $this->sync($target);

        $this->fakeGitHubRepositories([$this->githubRepository(1, 'a')]);
        $this->sync($target);

        $this->assertNull($target->repositories()->where('external_id', 1)->sole()->missing_at);
        $this->assertNotNull($target->repositories()->where('external_id', 2)->sole()->missing_at, 'Missing repositories are flagged, not deleted.');

        $this->fakeGitHubRepositories([$this->githubRepository(1, 'a'), $this->githubRepository(2, 'b')]);
        $this->sync($target);

        $this->assertNull($target->repositories()->where('external_id', 2)->sole()->missing_at);
    }

    public function test_an_account_without_repositories_flags_everything_and_keeps_an_unknown_type(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->fakeGitHubRepositories([$this->githubRepository(1, 'a')]);
        $this->sync($target);

        $this->fakeGitHubRepositories([]);
        $this->sync($target);

        $this->assertNotNull(Repository::query()->sole()->missing_at);
        $this->assertSame(TargetType::Organization, $target->refresh()->type, 'An empty list must not erase a known type.');
    }

    public function test_the_same_github_repository_can_exist_under_two_targets(): void
    {
        $mine = SyncTarget::factory()->create(['name' => 'laravel']);
        $theirs = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->fakeGitHubRepositories([$this->githubRepository(1, 'framework')]);

        $this->sync($mine);
        $this->sync($theirs);
        $this->fakeGitHubRepositories([]);
        $this->sync($mine);

        $this->assertNotNull($mine->repositories()->sole()->missing_at);
        $this->assertNull($theirs->repositories()->sole()->missing_at, 'Reconciling one target must not touch another.');
    }

    public function test_a_github_failure_leaves_stored_repositories_untouched(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->fakeGitHubRepositories([$this->githubRepository(1, 'a')]);
        $this->sync($target);

        $this->fakeGitHub(Http::response('Bad gateway', 502));

        try {
            $this->sync($target);
            $this->fail('Expected the GitHub failure to bubble up.');
        } catch (GitHubUnavailableException) {
            // The job, not the service, decides how to react.
        }

        $this->assertNull(Repository::query()->sole()->missing_at, 'A failed sync must not flag repositories as missing.');
    }
}
