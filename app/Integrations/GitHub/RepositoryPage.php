<?php

namespace App\Integrations\GitHub;

final readonly class RepositoryPage
{
    /** @param list<RepositoryData> $repositories */
    public function __construct(
        public array $repositories,
        public bool $hasNextPage,
    ) {}
}
