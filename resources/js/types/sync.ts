export type SyncStatus =
    | 'idle'
    | 'queued'
    | 'syncing'
    | 'synced'
    | 'failed'
    | 'rate_limited';

export type SyncTarget = {
    id: number;
    name: string;
    type: 'user' | 'organization' | null;
    status: SyncStatus;
    can_sync: boolean;
    last_attempted_at: string | null;
    last_synced_at: string | null;
    last_error: string | null;
    repositories_count: number;
    missing_repositories_count: number;
};

export type RepositoryRow = {
    id: number;
    name: string;
    full_name: string;
    description: string | null;
    html_url: string;
    language: string | null;
    stargazers_count: number;
    open_issues_count: number;
    is_archived: boolean;
    is_missing: boolean;
    external_updated_at: string | null;
    target: { id: number; name: string };
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        per_page: number;
        total: number;
    };
};

export type SortKey = 'name' | 'stars' | 'issues' | 'updated';

export type RepositoryFilters = {
    search: string;
    target: number | null;
    language: string;
    sort: SortKey;
    direction: 'asc' | 'desc';
    missing: boolean;
};
