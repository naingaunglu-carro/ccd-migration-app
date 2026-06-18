import { createInertiaApp } from '@inertiajs/vue3';
import PrimeVue from 'primevue/config';
import 'primeicons/primeicons.css';
import { AppTheme } from '@/lib/theme';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PrimeAppLayout from '@/layouts/PrimeAppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    withApp: (app) => {
        app.use(PrimeVue, {
            theme: {
                preset: AppTheme,
                options: {
                    // Match the app's class-based dark mode (.dark on <html>)...
                    darkModeSelector: '.dark',
                    // Keep PrimeVue styles below Tailwind utilities in the cascade...
                    cssLayer: {
                        name: 'primevue',
                        order: 'theme, base, primevue',
                    },
                },
            },
        });
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name === 'auth/Login':
            case name === 'auth/ForgotPassword':
                return null; // self-contained PrimeVue auth pages
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return PrimeAppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
