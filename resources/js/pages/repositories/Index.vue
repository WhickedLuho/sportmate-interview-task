<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { reactive, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatNumber } from '@/lib/format';
import { index } from '@/routes/repositories';
import type {
    Paginated,
    RepositoryFilters,
    RepositoryRow,
    SortKey,
} from '@/types';

const props = defineProps<{
    repositories: Paginated<RepositoryRow>;
    filters: RepositoryFilters;
    targets: { id: number; name: string }[];
    languages: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Repositories', href: index() }],
    },
});

// Local copy of the filters; every change re-queries the server (the server owns
// filtering, sorting and pagination, so the URL always reflects what is shown).
const form = reactive<RepositoryFilters>({ ...props.filters });

function visit() {
    const query: Record<string, string | number> = {};

    if (form.search) query.search = form.search;
    if (form.target) query.target = form.target;
    if (form.language) query.language = form.language;
    if (form.missing) query.missing = 1;
    query.sort = form.sort;
    query.direction = form.direction;

    router.get(index().url, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// Typing is debounced; selects and the checkbox apply right away.
watchDebounced(() => form.search, visit, { debounce: 300 });
watch(() => [form.target, form.language, form.missing], visit);

function sortBy(key: SortKey) {
    if (form.sort === key) {
        form.direction = form.direction === 'asc' ? 'desc' : 'asc';
    } else {
        form.sort = key;
        form.direction = key === 'name' ? 'asc' : 'desc';
    }

    visit();
}

function arrow(key: SortKey): string {
    if (form.sort !== key) return '';

    return form.direction === 'asc' ? ' ▲' : ' ▼';
}

const columns: { key: SortKey; label: string; class?: string }[] = [
    { key: 'name', label: 'Repository' },
    { key: 'stars', label: 'Stars', class: 'text-right' },
    { key: 'issues', label: 'Open issues', class: 'text-right' },
    { key: 'updated', label: 'Updated on GitHub' },
];
</script>

<template>
    <Head title="Repositories" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Repositories"
            description="Repositories stored locally from your sync targets."
        />

        <div class="flex flex-wrap items-end gap-4">
            <div class="grid gap-1">
                <Label for="search">Search</Label>
                <Input
                    id="search"
                    v-model="form.search"
                    class="w-64"
                    placeholder="Name or description"
                />
            </div>

            <div class="grid gap-1">
                <Label for="target">Account</Label>
                <select
                    id="target"
                    v-model="form.target"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                >
                    <option :value="null">All accounts</option>
                    <option v-for="t in targets" :key="t.id" :value="t.id">
                        {{ t.name }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1">
                <Label for="language">Language</Label>
                <select
                    id="language"
                    v-model="form.language"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">All languages</option>
                    <option v-for="l in languages" :key="l" :value="l">
                        {{ l }}
                    </option>
                </select>
            </div>

            <label class="flex h-9 items-center gap-2 text-sm">
                <input v-model="form.missing" type="checkbox" />
                Show missing
            </label>
        </div>

        <p
            v-if="repositories.data.length === 0"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No repositories match. Synchronize a target or adjust the filters.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-2 font-medium"
                            :class="column.class"
                        >
                            <button
                                type="button"
                                class="font-medium hover:text-foreground"
                                @click="sortBy(column.key)"
                            >
                                {{ column.label }}{{ arrow(column.key) }}
                            </button>
                        </th>
                        <th class="px-4 py-2 font-medium">Language</th>
                        <th class="px-4 py-2 font-medium">Account</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="repository in repositories.data"
                        :key="repository.id"
                        :class="{ 'opacity-60': repository.is_missing }"
                    >
                        <td class="max-w-md px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <a
                                    :href="repository.html_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-medium underline underline-offset-4"
                                >
                                    {{ repository.full_name }}
                                </a>
                                <Badge
                                    v-if="repository.is_archived"
                                    variant="outline"
                                >
                                    Archived
                                </Badge>
                                <Badge
                                    v-if="repository.is_missing"
                                    variant="destructive"
                                >
                                    Missing on GitHub
                                </Badge>
                            </div>
                            <p
                                v-if="repository.description"
                                class="mt-1 line-clamp-2 text-muted-foreground"
                            >
                                {{ repository.description }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatNumber(repository.stargazers_count) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatNumber(repository.open_issues_count) }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDate(repository.external_updated_at) }}
                        </td>
                        <td class="px-4 py-3">
                            {{ repository.language ?? '—' }}
                        </td>
                        <td class="px-4 py-3">{{ repository.target.name }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="repositories.meta.total > 0"
            class="flex items-center justify-between text-sm text-muted-foreground"
        >
            <span>
                {{ repositories.meta.from }}–{{ repositories.meta.to }} of
                {{ formatNumber(repositories.meta.total) }}
            </span>
            <div class="flex gap-4">
                <Link
                    v-if="repositories.links.prev"
                    :href="repositories.links.prev"
                    preserve-scroll
                    class="underline underline-offset-4"
                >
                    Previous
                </Link>
                <Link
                    v-if="repositories.links.next"
                    :href="repositories.links.next"
                    preserve-scroll
                    class="underline underline-offset-4"
                >
                    Next
                </Link>
            </div>
        </div>
    </div>
</template>
