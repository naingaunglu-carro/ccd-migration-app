<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';
import { computed, ref } from 'vue';

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
    target_table: string;
    resolver_class: string | null;
    last_downloaded_at: string | null;
    last_synced_at: string | null;
    downloads: Download[];
}

const props = defineProps<{ sources: SyncSource[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Data Sync', href: '/sync' }],
    },
});

const downloadingId = ref<number | null>(null);
const importingId = ref<number | null>(null);
const expanded = ref<Set<number>>(new Set());
const selectedGroup = ref<string | null>(null);
const search = ref('');

const groups = computed(() => [
    ...new Set(props.sources.map((s) => s.group)),
]);

const groupCount = (group: string) =>
    props.sources.filter((s) => s.group === group).length;

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.sources.filter((s) => {
        if (selectedGroup.value && s.group !== selectedGroup.value) return false;
        if (!term) return true;
        return (
            s.display_name.toLowerCase().includes(term) ||
            s.target_table.toLowerCase().includes(term)
        );
    });
});

const grouped = computed(() => {
    const map = new Map<string, SyncSource[]>();
    for (const s of filtered.value) {
        (map.get(s.group) ?? map.set(s.group, []).get(s.group)!).push(s);
    }
    return [...map.entries()].map(([group, items]) => ({ group, items }));
});

const toggle = (id: number) => {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
};

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

const statusIcon = (status?: Status | string) => {
    switch (status) {
        case 'completed':
            return 'pi pi-check-circle';
        case 'failed':
            return 'pi pi-times-circle';
        case 'running':
            return 'pi pi-spin pi-spinner';
        default:
            return 'pi pi-clock';
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

    <div class="mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-6 p-6">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">Data Sync</h1>
            <p class="text-sm text-muted-foreground">
                Two steps per source — <strong>download</strong> the source table
                to a file, then <strong>import</strong> that file into its landing
                table.
            </p>
        </div>

        <!-- Filter bar -->
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="
                    selectedGroup === null
                        ? 'border-transparent bg-primary text-primary-foreground'
                        : 'border-sidebar-border/70 text-muted-foreground hover:bg-muted'
                "
                @click="selectedGroup = null"
            >
                All
                <span class="opacity-70">{{ sources.length }}</span>
            </button>
            <button
                v-for="group in groups"
                :key="group"
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="
                    selectedGroup === group
                        ? 'border-transparent bg-primary text-primary-foreground'
                        : 'border-sidebar-border/70 text-muted-foreground hover:bg-muted'
                "
                @click="selectedGroup = group"
            >
                {{ group }}
                <span class="opacity-70">{{ groupCount(group) }}</span>
            </button>

            <div class="relative ml-auto">
                <i
                    class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground"
                />
                <InputText
                    v-model="search"
                    placeholder="Search sources…"
                    class="w-56 pl-8"
                    size="small"
                />
            </div>
        </div>

        <!-- Empty -->
        <div
            v-if="!filtered.length"
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center text-sm text-muted-foreground"
        >
            <i class="pi pi-inbox text-2xl opacity-60" />
            {{
                sources.length
                    ? 'No sources match your filter.'
                    : 'No sync sources configured.'
            }}
        </div>

        <!-- Grouped list -->
        <div v-for="block in grouped" :key="block.group" class="space-y-2">
            <h2
                class="px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
            >
                {{ block.group }}
            </h2>

            <div
                class="divide-y divide-sidebar-border/60 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <div v-for="source in block.items" :key="source.id">
                    <!-- Source row -->
                    <div
                        class="flex cursor-pointer items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/40"
                        @click="toggle(source.id)"
                    >
                        <i
                            class="pi text-xs text-muted-foreground transition-transform"
                            :class="
                                expanded.has(source.id)
                                    ? 'pi-chevron-down'
                                    : 'pi-chevron-right'
                            "
                        />

                        <div class="min-w-0 flex-1">
                            <div class="font-medium">
                                {{ source.display_name }}
                            </div>
                            <div
                                class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <code class="rounded bg-muted px-1 py-0.5">{{
                                    source.connection
                                }}</code>
                                <i class="pi pi-arrow-right text-[0.6rem]" />
                                <code class="rounded bg-muted px-1 py-0.5">{{
                                    source.target_table
                                }}</code>
                            </div>
                        </div>

                        <div
                            class="hidden w-44 shrink-0 flex-col gap-0.5 text-xs text-muted-foreground sm:flex"
                        >
                            <span class="flex items-center gap-1">
                                <i class="pi pi-download text-[0.65rem]" />
                                <span class="tabular-nums">{{
                                    formatDate(source.last_downloaded_at)
                                }}</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <i class="pi pi-database text-[0.65rem]" />
                                <span class="tabular-nums">{{
                                    formatDate(source.last_synced_at)
                                }}</span>
                            </span>
                        </div>

                        <Button
                            label="Download"
                            icon="pi pi-download"
                            size="small"
                            outlined
                            class="shrink-0"
                            :loading="downloadingId === source.id"
                            :disabled="downloadingId !== null"
                            @click.stop="runDownload(source)"
                        />
                    </div>

                    <!-- Expanded: downloads -->
                    <div
                        v-if="expanded.has(source.id)"
                        class="border-t border-sidebar-border/60 bg-muted/30 px-4 py-3"
                    >
                        <code
                            class="mb-3 block truncate rounded bg-muted/60 px-2 py-1 text-xs text-muted-foreground"
                            :title="source.query"
                        >
                            {{ source.query }}
                        </code>

                        <div
                            v-if="!source.downloads.length"
                            class="py-2 text-center text-xs text-muted-foreground"
                        >
                            No downloads yet — hit “Download” above.
                        </div>

                        <div v-else class="space-y-2">
                            <div
                                v-for="d in source.downloads"
                                :key="d.id"
                                class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-sidebar-border/60 bg-background px-3 py-2"
                            >
                                <span
                                    class="text-xs tabular-nums text-muted-foreground"
                                    >#{{ d.id }}</span
                                >

                                <div class="flex flex-col gap-1">
                                    <Tag
                                        :value="d.status"
                                        :severity="severity(d.status)"
                                        :icon="statusIcon(d.status)"
                                        rounded
                                        class="w-fit"
                                    />
                                    <span class="text-xs text-muted-foreground">
                                        {{ d.file_type?.toUpperCase() }} ·
                                        {{ d.row_count ?? '—' }} rows ·
                                        {{ formatBytes(d.file_size) }}
                                    </span>
                                    <span
                                        v-if="d.error_message"
                                        class="max-w-md truncate text-xs text-red-500"
                                        :title="d.error_message"
                                    >
                                        {{ d.error_message }}
                                    </span>
                                </div>

                                <div class="flex flex-col gap-1">
                                    <span
                                        class="text-[0.7rem] uppercase tracking-wide text-muted-foreground"
                                        >Last import</span
                                    >
                                    <template v-if="d.latest_import">
                                        <Tag
                                            :value="d.latest_import.status"
                                            :severity="
                                                severity(d.latest_import.status)
                                            "
                                            :icon="
                                                statusIcon(d.latest_import.status)
                                            "
                                            rounded
                                            class="w-fit"
                                        />
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ d.latest_import.rows_inserted }} new
                                            /
                                            {{ d.latest_import.rows_updated }} upd /
                                            {{ d.latest_import.rows_skipped }} skip
                                        </span>
                                    </template>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >not imported</span
                                    >
                                </div>

                                <span
                                    class="text-xs tabular-nums text-muted-foreground"
                                >
                                    {{ formatDate(d.created_at) }}
                                </span>

                                <Button
                                    label="Import"
                                    icon="pi pi-database"
                                    size="small"
                                    severity="secondary"
                                    class="ml-auto shrink-0"
                                    :loading="importingId === d.id"
                                    :disabled="
                                        d.status !== 'completed' ||
                                        importingId !== null
                                    "
                                    @click="runImport(d)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
