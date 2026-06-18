<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Tag from 'primevue/tag';
import { ref } from 'vue';

type Status = 'pending' | 'running' | 'completed' | 'failed';

interface LatestImport {
    status: Status;
    rows_inserted: number;
    rows_updated: number;
    rows_skipped: number;
    error_message: string | null;
    finished_at: string | null;
}

interface Download {
    id: number;
    status: Status;
    file_type: string | null;
    file_name: string | null;
    row_count: number | null;
    file_size: number | null;
    error_message: string | null;
    created_at: string | null;
    latest_import: LatestImport | null;
}

interface SyncSource {
    id: number;
    display_name: string;
    group: string;
    connection: string;
    query: string;
    resolver_class: string | null;
    last_downloaded_at: string | null;
    last_synced_at: string | null;
    downloads: Download[];
}

defineProps<{ sources: SyncSource[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Data Sync', href: '/sync' }],
    },
});

const expandedRows = ref<Record<number, boolean>>({});
const downloadingId = ref<number | null>(null);
const importingId = ref<number | null>(null);

const severity = (status?: Status | string) => {
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

const formatBytes = (bytes: number | null) => {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let n = bytes;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(1)} ${units[i]}`;
};

const runDownload = (source: SyncSource) => {
    downloadingId.value = source.id;
    router.post(
        `/sync/${source.id}/download`,
        {},
        { preserveScroll: true, onFinish: () => (downloadingId.value = null) },
    );
};

const runImport = (download: Download) => {
    importingId.value = download.id;
    router.post(
        `/sync/downloads/${download.id}/import`,
        {},
        { preserveScroll: true, onFinish: () => (importingId.value = null) },
    );
};
</script>

<template>
    <Head title="Data Sync" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold">Data Sync</h1>
            <p class="text-sm text-muted-foreground">
                Two steps per source — <strong>download</strong> the source table
                to a file, then <strong>import</strong> that file into its landing
                table.
            </p>
        </div>

        <DataTable
            v-model:expanded-rows="expandedRows"
            :value="sources"
            data-key="id"
            striped-rows
            row-group-mode="subheader"
            group-rows-by="group"
            class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <template #empty>
                <div class="p-6 text-center text-sm text-muted-foreground">
                    No sync sources configured.
                </div>
            </template>

            <template #groupheader="{ data }">
                <span class="font-semibold">{{ data.group }}</span>
            </template>

            <Column expander style="width: 3rem" />

            <Column field="display_name" header="Source">
                <template #body="{ data }">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ data.display_name }}</span>
                        <span class="text-xs text-muted-foreground">
                            connection: {{ data.connection }} ·
                            {{ data.resolver_class ?? 'no resolver' }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column header="Query">
                <template #body="{ data }">
                    <code
                        class="block max-w-md truncate text-xs text-muted-foreground"
                        :title="data.query"
                    >
                        {{ data.query }}
                    </code>
                </template>
            </Column>

            <Column header="Last synced" field="last_synced_at">
                <template #body="{ data }">
                    <span class="text-sm">{{
                        formatDate(data.last_synced_at)
                    }}</span>
                </template>
            </Column>

            <Column header="" style="width: 11rem">
                <template #body="{ data }">
                    <Button
                        label="Download"
                        icon="pi pi-download"
                        size="small"
                        :loading="downloadingId === data.id"
                        :disabled="downloadingId !== null"
                        @click="runDownload(data)"
                    />
                </template>
            </Column>

            <template #expansion="{ data }">
                <div class="p-3">
                    <h3 class="mb-2 text-sm font-semibold">
                        Downloads for {{ data.display_name }}
                    </h3>
                    <DataTable :value="data.downloads" data-key="id">
                        <template #empty>
                            <div
                                class="p-3 text-center text-xs text-muted-foreground"
                            >
                                No downloads yet — hit “Download” above.
                            </div>
                        </template>

                        <Column header="#" field="id" style="width: 4rem">
                            <template #body="{ data: d }">
                                <span class="text-xs">#{{ d.id }}</span>
                            </template>
                        </Column>

                        <Column header="Download">
                            <template #body="{ data: d }">
                                <div class="flex flex-col gap-1">
                                    <Tag
                                        :value="d.status"
                                        :severity="severity(d.status)"
                                    />
                                    <span class="text-xs text-muted-foreground">
                                        {{ d.file_type?.toUpperCase() }} ·
                                        {{ d.row_count ?? '—' }} rows ·
                                        {{ formatBytes(d.file_size) }}
                                    </span>
                                    <span
                                        v-if="d.error_message"
                                        class="text-xs text-red-500"
                                    >
                                        {{ d.error_message }}
                                    </span>
                                </div>
                            </template>
                        </Column>

                        <Column header="Last import">
                            <template #body="{ data: d }">
                                <div
                                    v-if="d.latest_import"
                                    class="flex flex-col gap-1"
                                >
                                    <Tag
                                        :value="d.latest_import.status"
                                        :severity="
                                            severity(d.latest_import.status)
                                        "
                                    />
                                    <span class="text-xs text-muted-foreground">
                                        {{ d.latest_import.rows_inserted }} new /
                                        {{ d.latest_import.rows_updated }} upd /
                                        {{ d.latest_import.rows_skipped }} skip
                                    </span>
                                </div>
                                <span v-else class="text-xs text-muted-foreground"
                                    >not imported</span
                                >
                            </template>
                        </Column>

                        <Column header="When" field="created_at">
                            <template #body="{ data: d }">
                                <span class="text-xs">{{
                                    formatDate(d.created_at)
                                }}</span>
                            </template>
                        </Column>

                        <Column header="" style="width: 9rem">
                            <template #body="{ data: d }">
                                <Button
                                    label="Import"
                                    icon="pi pi-database"
                                    size="small"
                                    severity="secondary"
                                    :loading="importingId === d.id"
                                    :disabled="
                                        d.status !== 'completed' ||
                                        importingId !== null
                                    "
                                    @click="runImport(d)"
                                />
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </template>
        </DataTable>
    </div>
</template>
