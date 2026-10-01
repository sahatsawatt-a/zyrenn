import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { configureEcho } from '@laravel/echo-vue';

// The socket rides the page's own origin: nginx carries /app/ through to Reverb
// (see docker/nginx/app.conf), so it follows whatever address the page was
// opened on -- the https hostname, localhost, or an IP on the LAN. Reading a
// host out of VITE_REVERB_* would bake one address into the bundle at build
// time and break every other way in.
//
// Only in a browser: this file is also run to render pages on the server
// (Inertia SSR), where there is no page, no origin and no socket to open.
if (typeof window !== 'undefined') {
    configureEcho({
        broadcaster: 'reverb',
        wsHost: window.location.hostname,
        wsPort: Number(window.location.port || 80),
        wssPort: Number(window.location.port || 443),
        forceTLS: window.location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
    });
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Drawn for the renderer, with nothing round the board
            case name === 'Welcome' || name === 'boards/Render':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
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
