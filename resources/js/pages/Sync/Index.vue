<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Tag from 'primevue/tag';
import { ref } from 'vue';

interface LatestLog {
    status: 'pending' | 'running' | 'completed' | 'failed';
    rows_read: number;
    rows_inserted: number;
    rows_updated: number;
    error_message: string | null;
    finished_at: string | null;
}

interface SyncSource {
    id: number;
    name: string;
    display_name: string;
    connection: string;
    source_table: string;
    target_table: string;
    columns: string[];
    last_synced_at: string | null;
    latest_log: LatestLog | null;
}

defineProps<{ sources: SyncSource[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Data Sync', href: '/sync' }],
    },
});

// Track which source is currently syncing so we can show a per-row spinner.
const syncing = ref<number | null>(null);

const statusSeverity = (status?: string) => {
    switch (status) {
        case 'completed':
            return 'success';
        case 'failed':
            return 'danger';
        case 'running':
            return 'info';
        default:
            return 'secondary';
    }
};

const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleString() : '—';

const runSync = (source: SyncSource) => {
    syncing.value = source.id;

    router.post(
        `/sync/${source.id}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncing.value = null;
            },
        },
    );
};
</script>

<template>
    <Head title="Data Sync" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Data Sync</h1>
                <p class="text-sm text-muted-foreground">
                    Pull source tables into their local landing tables.
                </p>
            </div>
        </div>

        <DataTable
            :value="sources"
            data-key="id"
            striped-rows
            class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <template #empty>
                <div class="p-6 text-center text-sm text-muted-foreground">
                    No sync sources configured.
                </div>
            </template>

            <Column field="display_name" header="Source">
                <template #body="{ data }">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ data.display_name }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ data.connection }}.{{ data.source_table }} →
                            {{ data.target_table }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column header="Columns">
                <template #body="{ data }">
                    <span class="text-xs text-muted-foreground">
                        {{ data.columns.join(', ') }}
                    </span>
                </template>
            </Column>

            <Column header="Last run">
                <template #body="{ data }">
                    <div class="flex flex-col gap-1">
                        <Tag
                            :value="data.latest_log?.status ?? 'never'"
                            :severity="statusSeverity(data.latest_log?.status)"
                        />
                        <span
                            v-if="data.latest_log"
                            class="text-xs text-muted-foreground"
                        >
                            {{ data.latest_log.rows_inserted }} new /
                            {{ data.latest_log.rows_updated }} updated ·
                            {{ formatDate(data.latest_log.finished_at) }}
                        </span>
                        <span
                            v-if="data.latest_log?.error_message"
                            class="text-xs text-red-500"
                            :title="data.latest_log.error_message"
                        >
                            {{ data.latest_log.error_message }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column header="Synced at" field="last_synced_at">
                <template #body="{ data }">
                    <span class="text-sm">{{
                        formatDate(data.last_synced_at)
                    }}</span>
                </template>
            </Column>

            <Column header="" style="width: 8rem">
                <template #body="{ data }">
                    <Button
                        label="Sync"
                        icon="pi pi-sync"
                        size="small"
                        :loading="syncing === data.id"
                        :disabled="syncing !== null"
                        @click="runSync(data)"
                    />
                </template>
            </Column>
        </DataTable>
    </div>
</template>
