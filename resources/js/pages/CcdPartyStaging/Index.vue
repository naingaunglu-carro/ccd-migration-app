<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { computed, ref, watch } from 'vue';
import ccdPartyStaging from '@/routes/ccd-party-staging';

interface Row {
    id: number;
    tenant_id: number;
    reference_id: number;
    name: string | null;
    person_national_id: string | null;
    person_passport_number: string | null;
    identification_key: string | null;
    identification_column: string | null;
    status: string | null;
    reason: string | null;
    canonical_reference_id: number | null;
    merged_reference_ids: string | null;
    created_at: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    rows: Paginated<Row>;
    total: number;
    statusCounts: Record<string, number>;
    reasonCounts: Record<string, number>;
    tenants: number[];
    filters: {
        tenant_id: number | null;
        status: string | null;
        reason: string | null;
        search: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'CCD Party Staging', href: '/ccd-party-staging' },
        ],
    },
});

const tenantId = ref<number | null>(props.filters.tenant_id);
const status = ref<string | null>(props.filters.status);
const reason = ref<string | null>(props.filters.reason);
const search = ref(props.filters.search ?? '');

const tenantOptions = computed(() => [
    { label: 'All tenants', value: null },
    ...props.tenants.map((t) => ({ label: `Tenant ${t}`, value: t })),
]);

const statusOptions = computed(() => [
    { label: 'All statuses', value: null },
    ...Object.keys(props.statusCounts).map((s) => ({
        label: `${s} (${props.statusCounts[s]})`,
        value: s,
    })),
]);

const reasonOptions = computed(() => [
    { label: 'All reasons', value: null },
    ...Object.keys(props.reasonCounts).map((r) => ({
        label: `${r} (${props.reasonCounts[r]})`,
        value: r,
    })),
]);

const applyFilters = () => {
    router.get(
        ccdPartyStaging.index().url,
        {
            tenant_id: tenantId.value ?? undefined,
            status: status.value ?? undefined,
            reason: reason.value ?? undefined,
            search: search.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

watch([tenantId, status, reason], applyFilters);

let searchTimeout: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 350);
});

const clearFilters = () => {
    tenantId.value = null;
    status.value = null;
    reason.value = null;
    search.value = '';
};

const severity = (s?: string | null) => {
    switch (s) {
        case 'identified':
            return 'success';
        case 'unidentified':
            return 'danger';
        default:
            return 'secondary';
    }
};

const goToPage = (url: string | null) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};

const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <Head title="CCD Party Staging" />

    <div class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-6">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                CCD Party Staging
            </h1>
            <p class="text-sm text-muted-foreground">
                Contacts staged for merge-by-identification before import into
                ccd_parties.
            </p>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <button
                type="button"
                class="rounded-xl border p-4 text-left transition-colors"
                :class="
                    status === null
                        ? 'border-primary bg-primary/5'
                        : 'border-sidebar-border/70 hover:bg-muted/40'
                "
                @click="status = null"
            >
                <div class="text-xs uppercase tracking-wide text-muted-foreground">
                    Total
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ total }}
                </div>
            </button>

            <button
                v-for="(count, s) in statusCounts"
                :key="s"
                type="button"
                class="rounded-xl border p-4 text-left transition-colors"
                :class="
                    status === s
                        ? 'border-primary bg-primary/5'
                        : 'border-sidebar-border/70 hover:bg-muted/40'
                "
                @click="status = status === s ? null : s"
            >
                <div class="text-xs uppercase tracking-wide text-muted-foreground">
                    {{ s }}
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ count }}
                </div>
            </button>
        </div>

        <!-- Reason breakdown -->
        <div class="space-y-2">
            <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                By reason
            </h2>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="(count, r) in reasonCounts"
                    :key="r"
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm transition-colors"
                    :class="
                        reason === r
                            ? 'border-transparent bg-primary text-primary-foreground'
                            : 'border-sidebar-border/70 text-muted-foreground hover:bg-muted'
                    "
                    @click="reason = reason === r ? null : r"
                >
                    {{ r }}
                    <span class="opacity-70">{{ count }}</span>
                </button>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="flex flex-wrap items-center gap-2">
            <Select
                v-model="tenantId"
                :options="tenantOptions"
                option-label="label"
                option-value="value"
                class="w-44"
                size="small"
            />
            <Select
                v-model="status"
                :options="statusOptions"
                option-label="label"
                option-value="value"
                class="w-48"
                size="small"
            />
            <Select
                v-model="reason"
                :options="reasonOptions"
                option-label="label"
                option-value="value"
                class="w-56"
                size="small"
            />

            <div class="relative">
                <i
                    class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground"
                />
                <InputText
                    v-model="search"
                    placeholder="Search name / national id / passport / reference id…"
                    class="w-80 pl-8"
                    size="small"
                />
            </div>

            <Button
                label="Clear"
                icon="pi pi-times"
                size="small"
                text
                class="ml-auto"
                @click="clearFilters"
            />
        </div>

        <!-- Empty -->
        <div
            v-if="!rows.data.length"
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center text-sm text-muted-foreground"
        >
            <i class="pi pi-inbox text-2xl opacity-60" />
            No staged parties match your filters.
        </div>

        <!-- Table -->
        <div
            v-else
            class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-left">ID</th>
                        <th class="px-3 py-2 text-left">Tenant</th>
                        <th class="px-3 py-2 text-left">Reference</th>
                        <th class="px-3 py-2 text-left">Name</th>
                        <th class="px-3 py-2 text-left">Identification</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Reason</th>
                        <th class="px-3 py-2 text-left">Canonical</th>
                        <th class="px-3 py-2 text-left">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/60">
                    <tr
                        v-for="row in rows.data"
                        :key="row.id"
                        class="hover:bg-muted/30"
                    >
                        <td class="px-3 py-2 tabular-nums text-muted-foreground">
                            #{{ row.id }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ row.tenant_id }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ row.reference_id }}
                        </td>
                        <td class="px-3 py-2">{{ row.name ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-muted-foreground">
                            <span v-if="row.identification_key">
                                {{ row.identification_column }}:
                                {{ row.identification_key }}
                            </span>
                            <span v-else>—</span>
                        </td>
                        <td class="px-3 py-2">
                            <Tag
                                v-if="row.status"
                                :value="row.status"
                                :severity="severity(row.status)"
                                rounded
                            />
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2 text-xs text-muted-foreground">
                            {{ row.reason ?? '—' }}
                        </td>
                        <td class="px-3 py-2 tabular-nums text-muted-foreground">
                            {{ row.canonical_reference_id ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-xs tabular-nums text-muted-foreground">
                            {{ formatDate(row.created_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div
            v-if="rows.last_page > 1"
            class="flex flex-wrap items-center justify-center gap-1"
        >
            <button
                v-for="(link, i) in rows.links"
                :key="i"
                type="button"
                class="min-w-8 rounded-md border px-2 py-1 text-xs"
                :class="[
                    link.active
                        ? 'border-transparent bg-primary text-primary-foreground'
                        : 'border-sidebar-border/70 text-muted-foreground hover:bg-muted',
                    !link.url && 'cursor-not-allowed opacity-40',
                ]"
                v-html="link.label"
                :disabled="!link.url"
                @click="goToPage(link.url)"
            />
        </div>
    </div>
</template>
