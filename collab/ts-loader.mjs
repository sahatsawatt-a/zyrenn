// Lets Node run the app's own TypeScript as it is written -- the code the
// browser runs, with nothing built in between. Node takes the types off by
// itself; what it will not do is find "@/features/…" or a file named without
// its ending, or read a .json file imported like any other. This does.
//
//     node --import ./collab/ts-loader.mjs some-script.mjs
import { existsSync, readFileSync, statSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { dirname, resolve as pathOf } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const source = pathOf(
    dirname(fileURLToPath(import.meta.url)),
    '../resources/js',
);
const ENDINGS = ['', '.ts', '.mjs', '.js', '.json', '/index.ts'];

const fileFor = (path) =>
    ENDINGS.map((ending) => path + ending).find(
        (each) => existsSync(each) && statSync(each).isFile(),
    );

registerHooks({
    resolve(specifier, context, next) {
        const from = context.parentURL?.startsWith('file:')
            ? dirname(fileURLToPath(context.parentURL))
            : null;
        const path = specifier.startsWith('@/')
            ? pathOf(source, specifier.slice(2))
            : specifier.startsWith('.') && from
              ? pathOf(from, specifier)
              : null;
        const file = path && fileFor(path);

        return file
            ? { url: pathToFileURL(file).href, shortCircuit: true }
            : next(specifier, context);
    },
    load(url, context, next) {
        if (url.endsWith('.json')) {
            return {
                format: 'module',
                source: `export default ${readFileSync(fileURLToPath(url), 'utf8')};`,
                shortCircuit: true,
            };
        }

        return next(url, context);
    },
});
