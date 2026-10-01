<script setup lang="ts">
import { Form, Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import SyncTargetController from '@/actions/App/Http/Controllers/SyncTargetController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatNumber } from '@/lib/format';
import { index as repositoriesIndex } from '@/routes/repositories';
import { index } from '@/routes/targets';
import type { SyncTarget } from '@/types';

const props = defineProps<{ targets: SyncTarget[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sync targets', href: index() }],
    },
});

// While any synchronization is pending or running, refresh the list every few
// seconds so the status updates without a manual reload. Polling stops by itself
// once everything has settled.
const inProgress = computed(() => props.targets.some((t) => !t.can_sync));
const { start, stop } = usePoll(
    4000,
    { only: ['targets'] },
    { autoStart: false },
);

watch(inProgress, (active) => (active ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="Sync targets" />

    <div class="flex flex-1 flex-col gap-8 p-4">
        <section class="max-w-xl space-y-4">
            <Heading
                title="Sync targets"
                description="Add a GitHub user or organization, then synchronize its public repositories."
            />

            <Form
                v-bind="SyncTargetController.store.form()"
                :reset-on-success="['name']"
                class="space-y-2"
                v-slot="{ errors, processing }"
            >
                <Label for="name">GitHub username or organization</Label>
                <div class="flex gap-2">
                    <Input
                        id="name"
                        name="name"
                        placeholder="e.g. laravel"
                        autocomplete="off"
                        required
                    />
                    <Button type="submit" :disabled="processing">Add</Button>
                </div>
                <InputError :message="errors.name" />
            </Form>
        </section>

        <section>
            <p
                v-if="targets.length === 0"
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No targets yet. Add a GitHub account above to get started.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Account</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 font-medium">Repositories</th>
                            <th class="px-4 py-2 font-medium">Last synced</th>
                            <th class="px-4 py-2 font-medium">Last error</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="target in targets" :key="target.id">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ target.name }}</div>
                                <div
                                    v-if="target.type"
                                    class="text-xs text-muted-foreground capitalize"
                                >
                                    {{ target.type }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge :status="target.status" />
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="target.repositories_count > 0"
                                    :href="
                                        repositoriesIndex({
                                            query: { target: target.id },
                                        })
                                    "
                                    class="underline underline-offset-4"
                                >
                                    {{
                                        formatNumber(target.repositories_count)
                                    }}
                                </Link>
                                <span v-else>0</span>
                                <span
                                    v-if="target.missing_repositories_count > 0"
                                    class="ml-1 text-xs text-muted-foreground"
                                >
                                    (+{{ target.missing_repositories_count }}
                                    missing)
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatDateTime(target.last_synced_at) }}
                            </td>
                            <td
                                class="max-w-xs px-4 py-3 text-destructive"
                                data-test="last-error"
                            >
                                {{ target.last_error ?? '' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Form
                                    v-bind="
                                        SyncTargetController.sync.form({
                                            target: target.id,
                                        })
                                    "
                                    v-slot="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="
                                            !target.can_sync || processing
                                        "
                                    >
                                        {{
                                            target.can_sync
                                                ? 'Sync'
                                                : 'In progress…'
                                        }}
                                    </Button>
                                </Form>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
