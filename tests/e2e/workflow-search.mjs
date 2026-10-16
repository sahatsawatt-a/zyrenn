// SerpApi and Tavily in the editor: each a group of its own with a step for
// each of its calls, set up from a key chosen among one's connections. Run,
// "SerpApi: find a place" asks SerpApi itself -- it is free and takes no key
// -- and a search with a key neither service knows is refused by each, and
// the run says so. Nothing is spent.
//
// Needs the app to reach the internet.
import { paletteGroup, runBoard, SHOTS, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e search ${RUN}`;
const KEYS = { serpapi: `e2e serp ${RUN}`, tavily: `e2e tavily ${RUN}` };
const SECRET = 'e2e-not-a-real-key';
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const key = (service) =>
    tinker(
        `$u = ${user}; $c = new App\\Models\\Connection(['name' => '${KEYS[service]}', 'service' => '${service}', 'settings' => []]); $c->secrets = ['api_key' => '${SECRET}']; $c->ownedBy($u, $u)->save(); echo $c->ref_id;`,
    );
const serp = key('serpapi');
const tavily = key('tavily');

const step = (id, type, label, n, config, onError = 'stop') => ({
    id,
    key: id,
    type,
    label,
    position: { x: 300 * n, y: 120 },
    config,
    onError,
});
const nodes = [
    step('start', 'trigger.manual', 'Run by hand', 0, {
        payload: '{"city": "Chiang Mai", "dish": "khao soi"}',
    }),
    step('where', 'serpapi.locations', 'SerpApi: find a place', 1, {
        q: '{{ trigger.city }}',
        count: 3,
    }),
    step(
        'web',
        'serpapi.google',
        'Search Google',
        2,
        {
            connection: serp,
            q: '{{ trigger.dish }}',
            location: '{{ node("where").name }}',
            period: 'any',
            page: 1,
        },
        'continue',
    ),
    step('find', 'tavily.search', 'Search with Tavily', 3, {
        connection: tavily,
        query: '{{ trigger.dish }}',
        topic: 'general',
        depth: 'basic',
        count: 5,
        period: 'any',
        answer: 'no',
        raw: 'no',
    }),
    step('shops', 'serpapi.maps', 'Search Google Maps', 4, {
        connection: serp,
        q: 'khao soi',
    }),
];

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes,
            edges: nodes.slice(1).map((node, at) => ({
                id: `e${at}`,
                source: nodes[at].id,
                sourceHandle: 'out',
                target: node.id,
            })),
        },
    ).replaceAll('\\', '\\\\')}', true); $w->save(); echo $w->ref_id;`,
);

const clean = () =>
    tinker(
        `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); App\\Models\\Connection::whereIn('ref_id', ['${serp}', '${tavily}'])->delete();`,
    );

const failed = await runBoard(
    `/workflows/${ref}`,
    async ({ page, check }) => {
        const settings = page.locator('[data-step-settings]');
        const shown = async (name) =>
            (await settings.locator(`#setting-${name}`).count()) === 1;
        const kinds = async (group) =>
            (await paletteGroup(page, group))
                .locator('[data-palette-step]')
                .evaluateAll((steps) =>
                    steps.map((one) => one.getAttribute('data-palette-step')),
                );
        const open = async (id) => {
            if (await settings.count()) {
                await settings.getByRole('button', { name: 'Close' }).click();
            }

            await page
                .getByRole('button', { name: 'Fit the workflow in view' })
                .click();
            await page.waitForTimeout(400);
            await page.locator(`[data-step="${id}"]`).click();
            await settings.waitFor();
        };

        // --- in the palette, each under its own service ---
        const ofSerp = await kinds('SerpApi');
        const ofTavily = await kinds('Tavily');
        check(
            'SerpApi has a group of its own, with a step for each of its calls',
            ofSerp.join() ===
                'serpapi.google,serpapi.news,serpapi.images,serpapi.maps,serpapi.youtube,serpapi.search,serpapi.locations,serpapi.account',
            ofSerp.join(', '),
        );
        check(
            'and so has Tavily',
            ofTavily.join() ===
                'tavily.search,tavily.extract,tavily.crawl,tavily.map,tavily.usage',
            ofTavily.join(', '),
        );
        await (
            await paletteGroup(page, 'SerpApi')
        )
            .locator('[data-palette-step="serpapi.google"]')
            .scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${SHOTS}/workflow-search-palette.png` });

        // --- set up with a key chosen from one's connections ---
        await open('web');
        check(
            'a Google search asks with a SerpApi key of one’s own, for words worked out',
            (
                await settings.locator('#setting-connection').innerText()
            ).includes(KEYS.serpapi) &&
                (await settings.locator('#setting-q').inputValue()).includes(
                    'trigger.dish',
                ) &&
                (await shown('location')) &&
                (await shown('period')),
            await settings.locator('#setting-connection').innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-search-google.png` });

        await open('shops');
        check(
            'a search of the map asks how close only once it has a place to search around',
            (await shown('around')) && !(await shown('zoom')),
        );
        await settings.locator('#setting-around').fill('18.79, 98.98');
        await settings.locator('#setting-zoom').waitFor();
        check('and then it does', await shown('zoom'));

        await open('find');
        check(
            'a Tavily search asks with a Tavily key, and says what to keep to',
            (
                await settings.locator('#setting-connection').innerText()
            ).includes(KEYS.tavily) &&
                (await shown('topic')) &&
                (await shown('answer')) &&
                (await shown('only')),
            await settings.locator('#setting-connection').innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-search-tavily.png` });
        await settings.getByRole('button', { name: 'Close' }).click();
        await page
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });

        // --- run: the place is found for real, and a key nobody knows is refused ---
        await page.locator('[data-run]').click();
        const run = page.locator('[data-run-panel]');
        const status = (id) =>
            run
                .locator(`[data-run-step="${id}"]`)
                .getAttribute('data-run-step-status');
        await run
            .locator('[data-run-step="find"][data-run-step-status="failed"]')
            .waitFor({ timeout: 90000 });
        check(
            'SerpApi is asked for the place, with no key, and names it',
            (await status('where')) === 'succeeded',
            await run.locator('[data-run-step="where"]').innerText(),
        );

        const out = tinker(
            `$r = ${user}->workflows()->where('title', '${TITLE}')->first()->runs()->latest('id')->first(); echo json_encode(['where' => $r->steps()->where('node_key', 'where')->first()?->output, 'web' => $r->steps()->where('node_key', 'web')->first()?->error, 'find' => $r->steps()->where('node_key', 'find')->first()?->error]);`,
        );
        const told = JSON.parse(out);
        const place = told.where?.places?.[0] ?? {};
        check(
            'the place comes back as a search takes it, with where it is',
            /Chiang Mai/.test(told.where?.name ?? '') &&
                place.country === 'TH' &&
                Math.round(place.lat) === 19 &&
                Math.round(place.lng) === 99,
            JSON.stringify(told.where).slice(0, 300),
        );
        check(
            'SerpApi refuses a key it does not know, and the step says so without repeating it',
            /SerpApi refused the key/.test(told.web?.message ?? '') &&
                !JSON.stringify(told).includes(SECRET),
            JSON.stringify(told.web),
        );
        check(
            'and Tavily does the same',
            /Tavily refused the key/.test(told.find?.message ?? ''),
            JSON.stringify(told.find),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-search-run.png` });
    },
    { canvas: false },
);

// --- the keys themselves, on the connections page, and on a phone ---
const failedToo = await runBoard(
    '/settings/connections',
    async ({ page, check, afterwards }) => {
        afterwards(clean);

        const group = page.locator('[data-group="search"]');
        await group.waitFor();
        check(
            'both keys are kept under Search',
            (await group.getByText(KEYS.serpapi).count()) === 1 &&
                (await group.getByText(KEYS.tavily).count()) === 1,
            await group.innerText(),
        );
        check(
            'the page never holds a key',
            !(await page.content()).includes(SECRET),
        );
        check(
            'on a phone nothing runs off the side',
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        );
        await group.scrollIntoViewIfNeeded();
        await page.screenshot({
            path: `${SHOTS}/workflow-search-connections.png`,
        });

        await page.locator('[data-connection-add]').click();
        const sheet = page.locator('[role="dialog"]');
        check(
            'SerpApi and Tavily are there to connect',
            (await sheet
                .locator('[data-service="serpapi"], [data-service="tavily"]')
                .count()) === 2,
        );
        await sheet.locator('[data-service="tavily"]').click();
        await sheet.locator('#connection-api_key').waitFor();
        await sheet.locator('#connection-name').fill(`${KEYS.tavily} two`);
        await sheet.locator('#connection-api_key').fill(SECRET);
        await sheet.locator('[data-connection-save]').click();
        await sheet
            .locator('[data-connection-verdict]')
            .waitFor({ timeout: 20000 });
        const verdict = await sheet
            .locator('[data-connection-verdict]')
            .innerText();
        check(
            'a key is tried with Tavily before it is kept, and one it does not know is said so',
            /Tavily does not know this key/.test(verdict) &&
                !verdict.includes(SECRET),
            verdict,
        );
        await page.screenshot({
            path: `${SHOTS}/workflow-search-connect.png`,
        });
    },
    { canvas: false, width: 390, height: 844 },
);

process.exitCode = failed || failedToo ? 1 : 0;
