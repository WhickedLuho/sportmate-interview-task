<?php

namespace App\Integrations\GitHub;

final readonly class RepositoryPage
{
    /**
     * Represent one validated repository page and its pagination state.
     *
     * @param  list<RepositoryData>  $repositories  Repositories returned on this page.
     * @param  bool  $hasNextPage  Whether GitHub advertises another page.
     */
    public function __construct(
        public array $repositories,
        public bool $hasNextPage,
    ) {}
}
