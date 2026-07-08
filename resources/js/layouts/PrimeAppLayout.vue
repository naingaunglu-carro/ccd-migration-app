<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import Avatar from 'primevue/avatar';
import Breadcrumb from 'primevue/breadcrumb';
import Menu from 'primevue/menu';
import Menubar from 'primevue/menubar';
import type { MenuItem } from 'primevue/menuitem';
import { computed, ref } from 'vue';
import { logout } from '@/routes';
import ccdPartyStaging from '@/routes/ccd-party-staging';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(' ')
        .map((p: string) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

const navItems: MenuItem[] = [
    { label: 'CCD Party Staging', icon: 'pi pi-users', command: () => router.visit(ccdPartyStaging.index().url) },
    { label: 'Data Sync', icon: 'pi pi-database', command: () => router.visit('/sync') },
];

const breadcrumbModel = computed<MenuItem[]>(() =>
    breadcrumbs.map((b) => ({ label: b.title, url: b.href as string })),
);

const userMenu = ref();
const userMenuItems: MenuItem[] = [
    { label: 'Settings', icon: 'pi pi-cog', command: () => router.visit('/settings/profile') },
    { separator: true },
    {
        label: 'Log out',
        icon: 'pi pi-sign-out',
        command: () => router.post(logout().url),
    },
];
const toggleUserMenu = (event: Event) => userMenu.value.toggle(event);
</script>

<template>
    <div class="min-h-screen bg-surface-50 dark:bg-surface-950">
        <Menubar :model="navItems" class="rounded-none border-x-0 border-t-0">
            <template #start>
                <Link
                    :href="ccdPartyStaging.index().url"
                    class="mr-6 flex items-center gap-2 font-semibold"
                >
                    <i class="pi pi-sync text-primary" />
                    <span>CCD Migration</span>
                </Link>
            </template>

            <template #end>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-surface-100 dark:hover:bg-surface-800"
                    @click="toggleUserMenu"
                >
                    <Avatar :label="initials" shape="circle" />
                    <span class="hidden text-sm font-medium sm:inline">
                        {{ user?.name }}
                    </span>
                    <i class="pi pi-angle-down text-xs" />
                </button>
                <Menu ref="userMenu" :model="userMenuItems" popup>
                    <template #start>
                        <div class="px-3 py-2">
                            <div class="text-sm font-medium">{{ user?.name }}</div>
                            <div class="text-xs text-surface-500">
                                {{ user?.email }}
                            </div>
                        </div>
                    </template>
                </Menu>
            </template>
        </Menubar>

        <main class="mx-auto w-full max-w-7xl p-4">
            <Breadcrumb
                v-if="breadcrumbModel.length"
                :model="breadcrumbModel"
                class="mb-4 bg-transparent p-0"
            >
                <template #item="{ item }">
                    <Link
                        v-if="item.url"
                        :href="item.url"
                        class="text-sm text-surface-500 hover:text-primary"
                    >
                        {{ item.label }}
                    </Link>
                </template>
            </Breadcrumb>

            <slot />
        </main>
    </div>
</template>
