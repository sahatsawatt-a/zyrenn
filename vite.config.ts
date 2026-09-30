import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { readFileSync, watch, writeFileSync } from 'node:fs';
import type { Plugin } from 'vite';
import { defineConfig, lazyPlugins } from 'vite-plus';

// Where the *browser* loads dev assets from.
//
// Unset (the default): from the page's own origin. nginx proxies the dev server,
// so the https hostname, localhost and a phone on the LAN all work with no
// address baked into the HTML -- see the Vite section of docker/nginx/app.conf.
//
// Set it to an absolute URL to have the browser talk to the dev server directly
// instead, e.g. http://localhost:5173 for a plain `npm run dev` with no proxy.
const devOrigin = process.env.VITE_DEV_ORIGIN;
const devUrl = devOrigin ? new URL(devOrigin) : null;
const devIsSecure = devUrl?.protocol === 'https:';

// Vite answers "Blocked request" for a Host header it does not recognise. nginx
// forwards the host the user typed, and bare IP addresses are allowed already,
// so only the named hosts need listing -- a tunnel's host goes in
// VITE_ALLOWED_HOSTS, comma separated. `web` is the PDF printer, which opens
// notes on the compose network (docker/chrome).
const allowedHosts = [
    'web',
    process.env.APP_HOST,
    process.env.VITE_HOST,
    ...(process.env.VITE_ALLOWED_HOSTS ?? '').split(','),
]
    .map((host) => host?.trim())
    .filter((host): host is string => !!host);

// Same-origin mode: Laravel builds dev asset URLs by prefixing the contents of
// public/hot, so an empty file gives "/resources/js/app.ts" -- served by nginx,
// on whatever origin the page came from. laravel-vite-plugin writes the dev
// server's own URL there when the server starts; this blanks it straight after,
// on the same 'listening' event.
const relativeHotFile: Plugin = {
    name: 'relative-hot-file',
    configureServer(server) {
        const hotFile = 'public/hot';

        const blank = () => {
            try {
                if (readFileSync(hotFile, 'utf8') !== '') {
                    writeFileSync(hotFile, '');
                }
            } catch {
                // not written yet, or already cleaned up on shutdown
            }
        };

        server.httpServer?.once('listening', () => {
            blank();

            // laravel-vite-plugin writes the address again after this point --
            // its hook runs after ours under vite-plus's lazy plugins -- so
            // watch the file rather than race it. Writing only when the
            // contents are not already empty keeps this from looping.
            const watcher = watch('public', (_event, name) => {
                if (name === 'hot') {
                    blank();
                }
            });

            server.httpServer?.once('close', () => watcher.close());
        });
    },
};

// @inertiajs/vite links SSR'd page CSS using the dev server's first *local*
// URL (http://localhost:5173) and ignores server.origin, so browsers on other
// devices (LAN access) request CSS from their own localhost. Report the
// browser-facing origin as the local URL instead. Only needed when the browser
// talks to the dev server directly; same-origin URLs are relative already.
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
                    local: [`${devUrl?.origin}/`],
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
        devUrl ? advertiseDevOrigin : relativeHotFile,
    ]),
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: true,
        allowedHosts,
        ...(devUrl ? { origin: devUrl.origin } : {}),
        ws: devUrl
            ? {
                  host: devUrl.hostname,
                  protocol: devIsSecure ? 'wss' : 'ws',
                  clientPort: Number(devUrl.port || (devIsSecure ? 443 : 80)),
              }
            : // No host or port, so the client dials the address the page itself
              // came from, on a path nginx forwards to the dev server.
              { path: '/vite-hmr' },
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
