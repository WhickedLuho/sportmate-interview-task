<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RepositoryListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SyncTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->target = SyncTarget::factory()->for($this->user)->create(['name' => 'laravel']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function repository(array $attributes = [], ?SyncTarget $target = null): Repository
    {
        return Repository::factory()->for($target ?? $this->target)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @return list<string>
     */
    private function names(array $query = []): array
    {
        $names = [];

        $this->actingAs($this->user)->get(route('repositories.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $names = collect($page->toArray()['props']['repositories']['data'])->pluck('name')->all();
            });

        return $names;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('repositories.index'))->assertRedirect(route('login'));
    }

    public function test_it_lists_only_repositories_of_my_targets(): void
    {
        $this->repository(['name' => 'mine']);
        $this->repository(['name' => 'theirs'], SyncTarget::factory()->create());

        $this->assertSame(['mine'], $this->names());
    }

    public function test_missing_repositories_are_hidden_unless_requested(): void
    {
        $this->repository(['name' => 'present']);
        $this->repository(['name' => 'gone', 'missing_at' => now()]);

        $this->assertSame(['present'], $this->names());
        $this->assertEqualsCanonicalizing(['present', 'gone'], $this->names(['missing' => 1]));

        $this->actingAs($this->user)->get(route('repositories.index', ['missing' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.missing', true)
                ->where('repositories.data', fn ($rows) => collect($rows)->firstWhere('name', 'gone')['is_missing'] === true));
    }

    public function test_search_matches_the_name_and_the_description(): void
    {
        $this->repository(['name' => 'queue-tools', 'full_name' => 'laravel/queue-tools', 'description' => 'Helpers']);
        $this->repository(['name' => 'other', 'full_name' => 'laravel/other', 'description' => 'Works with the QUEUE']);
        $this->repository(['name' => 'unrelated', 'full_name' => 'laravel/unrelated', 'description' => 'Nothing']);

        $this->assertEqualsCanonicalizing(['queue-tools', 'other'], $this->names(['search' => 'queue']));
    }

    public function test_it_filters_by_language_and_target(): void
    {
        $second = SyncTarget::factory()->for($this->user)->create(['name' => 'symfony']);
        $this->repository(['name' => 'php-one', 'language' => 'PHP']);
        $this->repository(['name' => 'js-one', 'language' => 'JavaScript']);
        $this->repository(['name' => 'php-two', 'language' => 'PHP'], $second);

        $this->assertEqualsCanonicalizing(['php-one', 'php-two'], $this->names(['language' => 'PHP']));
        $this->assertSame(['php-two'], $this->names(['target' => $second->id]));
    }

    public function test_filtering_by_someone_elses_target_returns_nothing(): void
    {
        $this->repository(['name' => 'mine']);
        $foreign = SyncTarget::factory()->create();
        $this->repository(['name' => 'theirs'], $foreign);

        $this->assertSame([], $this->names(['target' => $foreign->id]));
    }

    public function test_it_sorts_by_stars_name_and_updated_time(): void
    {
        $this->repository(['name' => 'b', 'stargazers_count' => 5, 'external_updated_at' => now()->subDay()]);
        $this->repository(['name' => 'a', 'stargazers_count' => 50, 'external_updated_at' => now()->subDays(3)]);
        $this->repository(['name' => 'c', 'stargazers_count' => 20, 'external_updated_at' => now()]);

        $this->assertSame(['a', 'c', 'b'], $this->names(['sort' => 'stars', 'direction' => 'desc']));
        $this->assertSame(['b', 'c', 'a'], $this->names(['sort' => 'stars', 'direction' => 'asc']));
        $this->assertSame(['a', 'b', 'c'], $this->names(['sort' => 'name']), 'Names default to ascending.');
        $this->assertSame(['c', 'b', 'a'], $this->names(), 'The default is most recently updated first.');
    }

    public function test_an_unknown_sort_key_is_rejected_instead_of_reaching_the_query(): void
    {
        $this->actingAs($this->user)
            ->get(route('repositories.index', ['sort' => 'password; drop table users']))
            ->assertSessionHasErrors('sort');
    }

    public function test_results_are_paginated(): void
    {
        Repository::factory()->count(30)->for($this->target)->create();

        $this->actingAs($this->user)->get(route('repositories.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('repositories.data', 25)
                ->where('repositories.meta.total', 30)
                ->where('repositories.meta.last_page', 2));

        $this->actingAs($this->user)->get(route('repositories.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('repositories.data', 5));
    }

    public function test_filter_options_only_contain_my_languages_and_targets(): void
    {
        $this->repository(['language' => 'PHP']);
        $this->repository(['language' => 'PHP']);
        $this->repository(['language' => 'Go']);
        $this->repository(['language' => null]);
        $this->repository(['language' => 'Rust'], SyncTarget::factory()->create());

        $this->actingAs($this->user)->get(route('repositories.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('languages', ['Go', 'PHP'])
                ->has('targets', 1)
                ->where('targets.0.name', 'laravel'));
    }
}
