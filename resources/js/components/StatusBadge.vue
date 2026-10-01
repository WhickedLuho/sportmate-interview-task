<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { SyncStatus } from '@/types';

const props = defineProps<{ status: SyncStatus }>();

const presentation = computed(() => {
    switch (props.status) {
        case 'queued':
            return { label: 'Queued', variant: 'outline' as const, class: '' };
        case 'syncing':
            return {
                label: 'Syncing',
                variant: 'default' as const,
                class: 'animate-pulse',
            };
        case 'synced':
            return {
                label: 'Synced',
                variant: 'secondary' as const,
                class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
            };
        case 'failed':
            return {
                label: 'Failed',
                variant: 'destructive' as const,
                class: '',
            };
        case 'rate_limited':
            return {
                label: 'Rate limited',
                variant: 'secondary' as const,
                class: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            };
        default:
            return {
                label: 'Not synced yet',
                variant: 'secondary' as const,
                class: '',
            };
    }
});
</script>

<template>
    <Badge :variant="presentation.variant" :class="presentation.class">
        {{ presentation.label }}
    </Badge>
</template>
