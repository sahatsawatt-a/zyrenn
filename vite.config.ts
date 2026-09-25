import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import type { Plugin } from 'vite';
import { defineConfig, lazyPlugins } from 'vite-plus';

// The origin the *browser* uses to reach Vite. It differs from the container's
// own host and port whenever a proxy sits in front, so it is configurable. The
// default suits a plain `npm run dev` on the host.
const devOrigin = process.env.VITE_DEV_ORIGIN ?? 'http://localhost:5173';
const devUrl = new URL(devOrigin);
const devIsSecure = devUrl.protocol === 'https:';

// @inertiajs/vite links SSR'd page CSS using the dev server's first *local*
// URL (http://localhost:5173) and ignores server.origin, so browsers on other
// devices (LAN access) request CSS from their own localhost. Report the
// browser-facing origin as the local URL instead.
const advertiseDevOrigin: Plugin = {
    name: 'advertise-dev-origin',
    configureServer(server) {
        let resolvedUrls = server.resolvedUrls;

        Object.defineProperty(server, 'resolvedUrls', {
            configurable: true,
            get: () => resolvedUrls,
            set: (urls: typeof resolvedUrls) => {
                resolvedUrls = urls && {
                    ...urls,
                    local: [`${devUrl.origin}/`],
                };
            },
        });
    },
};

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        advertiseDevOrigin,
    ]),
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: true,
        origin: devOrigin,
        hmr: {
            host: devUrl.hostname,
            protocol: devIsSecure ? 'wss' : 'ws',
            clientPort: Number(devUrl.port || (devIsSecure ? 443 : 80)),
        },
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            entryPoint: 'resources/css/app.css',
        },
    },
});
