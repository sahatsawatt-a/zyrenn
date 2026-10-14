// The pictures in the guide (public/images/guide), taken from the app itself
// so they can be taken again whenever a screen changes:
//
//     HEADED=0 node tests/e2e/guide-shots.mjs
//
// They are of an account kept for this alone, guide@example.com, holding only
// what is made here -- so nothing of anyone's own is ever in a picture.
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

process.env.E2E_EMAIL = 'guide@example.com';
const { open, runBoard, saved, steady, tinker, until } =
    await import('./harness.mjs');

const OUT = join(
    dirname(fileURLToPath(import.meta.url)),
    '../../public/images/guide',
);
mkdirSync(OUT, { recursive: true });

const user = `App\\Models\\User::where('email', 'guide@example.com')->first()`;
const emptied = `$u = ${user}; foreach (['notes', 'boards', 'tables', 'workflows', 'trips', 'places', 'placeLists', 'chatRooms', 'driveFiles', 'projects'] as $kind) { $u->{$kind}()->get()->each(fn ($one) => $one->delete()); } $u->tokens()->delete();`;

tinker(
    `App\\Models\\User::firstOrCreate(['email' => 'guide@example.com'], ['name' => 'Alex Morgan', 'password' => Illuminate\\Support\\Facades\\Hash::make('password'), 'email_verified_at' => now()]); ${emptied}`,
);

const at = (x, y) => ({ x, y });
const workflow = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = 'Order alerts'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'start',
                    key: 'start',
                    type: 'trigger.manual',
                    label: 'Run by hand',
                    position: at(0, 120),
                    config: { payload: '{"customer": "Ada", "total": 120}' },
                },
                {
                    id: 'big',
                    key: 'big',
                    type: 'logic.if',
                    label: 'Is it a big order?',
                    position: at(320, 120),
                    config: { condition: 'trigger.total > 100' },
                },
                {
                    id: 'tell',
                    key: 'tell',
                    type: 'output.log',
                    label: 'Tell the team',
                    position: at(680, 30),
                    config: { message: 'A big order came in' },
                },
                {
                    id: 'file',
                    key: 'file',
                    type: 'output.log',
                    label: 'File it',
                    position: at(680, 220),
                    config: { message: 'Filed' },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'start',
                    sourceHandle: 'out',
                    target: 'big',
                },
                {
                    id: 'e2',
                    source: 'big',
                    sourceHandle: 'true',
                    target: 'tell',
                },
                {
                    id: 'e3',
                    source: 'big',
                    sourceHandle: 'false',
                    target: 'file',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

// A room that a workflow answers in, as one built from the Chat message
// trigger, Ask AI and Write in a room would
const room = tinker(
    `$u = ${user}; $r = new App\\Models\\Chat\\ChatRoom; $r->user_id = $u->id; $r->title = 'Ask anything'; $r->save(); $r->messages()->create(['role' => 'user', 'user_id' => $u->id, 'content' => 'What should I pack for Kyoto in April?']); $r->messages()->create(['role' => 'workflow', 'content' => 'Layers: mornings are cool and afternoons warm, around 8 to 20 degrees. Bring a light rain jacket, and shoes you can walk in all day and slip off at temples.', 'meta' => ['workflow' => '${workflow}', 'name' => 'Ask AI']]); echo $r->ref_id;`,
);

// A landscape drawn here, to have a photo of no one's in the photo editor
const SCENE = `<div id="scene" style="position:relative;width:1200px;height:800px;overflow:hidden;background:linear-gradient(#f7b267 0%,#f79d65 30%,#f4845f 55%,#7a5c99 100%)">
<div style="position:absolute;left:760px;top:250px;width:170px;height:170px;border-radius:50%;background:#fff3c4"></div>
<div style="position:absolute;inset:auto 0 0 0;height:420px;background:#3c2f5c;clip-path:polygon(0 60%,18% 25%,34% 55%,52% 10%,70% 50%,84% 30%,100% 55%,100% 100%,0 100%)"></div>
<div style="position:absolute;inset:auto 0 0 0;height:260px;background:#241d3b;clip-path:polygon(0 45%,22% 70%,40% 35%,62% 75%,80% 50%,100% 70%,100% 100%,0 100%)"></div>
</div>`;

await runBoard(
    '/dashboard',
    async ({ page, afterwards }) => {
        afterwards(() => tinker(emptied));

        const base = new URL(page.url()).origin;
        const token = tinker(
            `echo ${user}->createToken('guide shots', ['mcp'])->plainTextToken;`,
        );
        let call = 0;
        const mcp = async (name, args) => {
            const response = await fetch(`${base}/mcp/user`, {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${token}`,
                    'Content-Type': 'application/json',
                    Accept: 'application/json, text/event-stream',
                },
                body: JSON.stringify({
                    jsonrpc: '2.0',
                    id: ++call,
                    method: 'tools/call',
                    params: { name, arguments: args },
                }),
            });

            const { result, error } = await response.json();

            if (error || result?.isError) {
                throw new Error(
                    `${name}: ${JSON.stringify(error ?? result.content)}`,
                );
            }

            return result.structuredContent;
        };
        const shot = (name, options = {}) =>
            page.screenshot({ path: join(OUT, `${name}.png`), ...options });
        // Once what is drawn there has stopped changing: tiles, routes, a
        // board fitting itself to the window
        const settled = (locator, timeout = 20000) =>
            steady(
                async () =>
                    createHash('sha1')
                        .update(await locator.screenshot())
                        .digest('hex'),
                { timeout, every: 400 },
            );

        // ---- what the account holds ----
        const project = (
            await mcp('create-project', { name: 'Kyoto in spring' })
        ).ref_id;
        const table = (
            await mcp('create-table', {
                title: 'Budget',
                columns: [
                    { label: 'What' },
                    { label: 'Cost', type: 'currency', summary: 'sum' },
                    { label: 'Paid', type: 'boolean' },
                ],
                rows: [
                    { What: 'Flights', Cost: 640, Paid: true },
                    { What: 'Hotel', Cost: 420, Paid: true },
                    { What: 'Rail pass', Cost: 210, Paid: false },
                    { What: 'Food', Cost: 300, Paid: false },
                ],
            })
        ).ref_id;
        const board = (
            await mcp('create-board', {
                title: 'Launch plan',
                items: [
                    { id: 'idea', kind: 'pill', text: 'Idea', x: 0, y: 0 },
                    { id: 'draft', kind: 'rect', text: 'Draft', x: 260, y: 0 },
                    {
                        id: 'ready',
                        kind: 'diamond',
                        text: 'Ready?',
                        x: 520,
                        y: 0,
                    },
                    { id: 'ship', kind: 'pill', text: 'Publish', x: 800, y: 0 },
                    {
                        kind: 'arrow',
                        from: { item: 'idea' },
                        to: { item: 'draft' },
                    },
                    {
                        kind: 'arrow',
                        from: { item: 'draft' },
                        to: { item: 'ready' },
                    },
                    {
                        kind: 'arrow',
                        from: { item: 'ready' },
                        to: { item: 'ship' },
                    },
                    {
                        kind: 'sticky',
                        text: 'Ask Sam about the date',
                        x: 540,
                        y: 180,
                    },
                ],
            })
        ).ref_id;
        await mcp('create-note', {
            title: 'Packing list',
            markdown: '- [x] Passport\n- [ ] Charger\n- [ ] Rain jacket\n',
        });
        const note = (
            await mcp('create-note', {
                title: 'Weekly review',
                markdown:
                    'What went well this week, and what to change next.\n\n## Went well\n\n- The launch plan is agreed\n- Budget is under by 8%\n',
            })
        ).ref_id;

        const kyoto = {
            inari: {
                name: 'Fushimi Inari Taisha',
                lat: 34.9671,
                lng: 135.7727,
                kind: 'shrine',
            },
            kiyomizu: {
                name: 'Kiyomizu-dera',
                lat: 34.9949,
                lng: 135.785,
                kind: 'temple',
            },
            gion: {
                name: 'Gion',
                lat: 35.0037,
                lng: 135.7788,
                kind: 'neighbourhood',
            },
            bamboo: {
                name: 'Arashiyama Bamboo Grove',
                lat: 35.017,
                lng: 135.6713,
                kind: 'park',
            },
            kinkaku: {
                name: 'Kinkaku-ji',
                lat: 35.0394,
                lng: 135.7292,
                kind: 'temple',
            },
        };
        for (const place of Object.values(kyoto)) {
            await mcp('save-place', { ...place, list: 'Want to go' });
        }
        const trip = (
            await mcp('create-trip', {
                title: 'Kyoto',
                start_date: '2027-04-03',
                currency: '¥',
                days: [
                    {
                        stops: [
                            { ...kyoto.inari, minutes: 90, cost: 0 },
                            { ...kyoto.kiyomizu, minutes: 75, cost: 500 },
                            { ...kyoto.gion, minutes: 120 },
                        ],
                    },
                    {
                        stops: [
                            { ...kyoto.bamboo, minutes: 60 },
                            { ...kyoto.kinkaku, minutes: 60, cost: 500 },
                        ],
                    },
                ],
                stays: [
                    {
                        name: 'Hotel by Kyoto Station',
                        lat: 34.9858,
                        lng: 135.7588,
                        check_in: '2027-04-03T15:00',
                        check_out: '2027-04-05T10:00',
                    },
                ],
            })
        ).ref_id;

        const tickets = [
            ['bug', 'The map jumps when a stop is dragged', 'high', 'open'],
            ['feature', 'Show the weather on each day', 'normal', 'open'],
            ['feature', 'A packing list for each trip', 'low', 'in_progress'],
            ['bug', 'Hotel nights are off by one', 'high', 'done'],
        ];
        for (const [type, title, priority, status] of tickets) {
            const filed = await mcp('create-ticket', {
                project,
                type,
                title,
                priority,
            });
            if (!filed?.number) {
                throw new Error(`no ticket filed: ${JSON.stringify(filed)}`);
            }
            if (status !== 'open') {
                await mcp('update-ticket', {
                    project,
                    number: filed.number,
                    status,
                });
            }
        }

        // The photo, drawn and put in the Drive
        const sketch = await page.context().newPage();
        await sketch.setContent(SCENE);
        const photo = join(tmpdir(), 'guide-sunset.png');
        await sketch.locator('#scene').screenshot({ path: photo });
        await sketch.close();
        const { upload_url } = await mcp('request-upload', {});
        const form = new FormData();
        form.append(
            'file',
            new Blob([readFileSync(photo)], { type: 'image/png' }),
            'Sunset.png',
        );
        await fetch(upload_url, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: form,
        });

        // The page without the sidebar, down to `bottom` when the rest says nothing
        const inset = async (bottom) => {
            const box = await page
                .locator('[data-slot="sidebar-inset"]')
                .boundingBox();

            return {
                clip: {
                    ...box,
                    height: Math.min(bottom ?? Infinity, box.height) - box.y,
                },
            };
        };
        const bottomOf = async (locator, room = 20) => {
            const box = await locator.boundingBox();

            return box.y + box.height + room;
        };
        const sidebarInset = page.locator('[data-slot="sidebar-inset"]');

        // ---- Getting started: the sidebar, with what was changed lately ----
        await open(page, `${base}/dashboard`);
        await page.getByText('Weekly review').first().waitFor();
        await settled(page.locator('body'));
        await shot('getting-started');

        // ---- Notes: the "/" menu ----
        await open(page, `${base}/notes/${note}`);
        const lastItem = page.locator('.tiptap li').last();
        await lastItem.waitFor();
        // Out of the list by a second Enter, onto a line of its own
        await lastItem.click();
        await page.keyboard.press('End');
        await page.keyboard.press('Enter');
        await page.keyboard.press('Enter');
        await until(() =>
            page.evaluate(() => {
                const last =
                    document.querySelector('.tiptap')?.lastElementChild;

                return last?.tagName === 'P' && last.textContent === '';
            }),
        );
        await page.keyboard.press('/');
        const menu = page.getByText('Basic blocks').locator('..');
        await menu.waitFor();
        await settled(menu);
        await shot('notes-menu', await inset(await bottomOf(menu)));
        // Saved before it is left, or the page asks whether to go
        await page.keyboard.press('Escape');
        await saved(page);

        // ---- Boards: a few steps, joined ----
        await open(page, `${base}/boards/${board}`);
        await page.locator('.konvajs-content canvas').first().waitFor();
        await page.getByText('Connecting').waitFor({ state: 'detached' });
        // The layers folded away, for the board to have the room
        await page.locator('[title="Hide the layers"]').first().click();
        await settled(sidebarInset);
        await page.locator('[title="Fit everything"]').first().click();
        await settled(sidebarInset);
        await shot('boards', await inset());

        // ---- Tables: the budget, with its sum ----
        await open(page, `${base}/tables/${table}`);
        await until(
            async () =>
                (await page.locator('[data-test="table-row"]').count()) === 4,
        );
        await settled(sidebarInset);
        await shot('tables', await inset(560));

        // ---- Maps and trips ----
        // Tiles come from OpenFreeMap: some have to have come before the map
        // can be still for the right reason
        const tiles = () =>
            page.waitForResponse((response) =>
                /tiles\.openfreemap|\.pbf/.test(response.url()),
            );
        let tile = tiles();
        await open(page, `${base}/maps`);
        await page.locator('[data-test="list-Want to go"]').click();
        await page.getByText('Kiyomizu-dera').click();
        await page.getByText('Opening hours & rating').waitFor();
        await tile;
        await settled(sidebarInset);
        await shot('maps', await inset());

        tile = tiles();
        await open(page, `${base}/trips/${trip}`);
        await page.getByText('Kiyomizu-dera').first().waitFor();
        await tile;
        await settled(sidebarInset);
        await shot('trips', await inset());

        // ---- Drive: the photo editor ----
        await open(page, `${base}/drive`);
        await page.getByText('Sunset.png').first().waitFor();
        await page
            .getByRole('button', { name: 'File actions' })
            .first()
            .click();
        await page.getByRole('menuitem', { name: 'Edit photo' }).click();
        const editor = page.getByRole('dialog');
        await editor.getByText('Light & colour').waitFor();
        await settled(editor);
        await shot('drive-photo', { clip: await editor.boundingBox() });
        await page.keyboard.press('Escape');
        await editor.waitFor({ state: 'detached' });

        // ---- Chat: a room a workflow answers in ----
        await open(page, `${base}/chats/${room}`);
        await page.getByText('a light rain jacket', { exact: false }).waitFor();
        await settled(sidebarInset);
        await shot('chat', await inset());

        // ---- Projects: the tickets as a board ----
        await open(page, `${base}/p/${project}/tickets`);
        await page.getByText('The map jumps when a stop is dragged').waitFor();
        await page.locator('[data-test="tickets-as-board"]').click();
        await page.locator('[data-test="ticket-board"]').waitFor();
        await settled(sidebarInset);
        await shot('tickets', await inset(620));

        // ---- Settings: MCP ----
        await open(page, `${base}/settings/mcp`);
        await page.getByText('Connect a client').waitFor();
        await settled(sidebarInset);
        await shot('settings-mcp', await inset());

        // ---- Workflows: the steps, then a run of them ----
        await open(page, `${base}/workflows/${workflow}`);
        await page.locator('[data-step="big"]').waitFor();
        await page
            .getByRole('button', { name: 'Fit the workflow in view' })
            .click();
        await settled(sidebarInset);
        await shot(
            'workflows-steps',
            await inset(await bottomOf(page.locator('[data-step="file"]'), 60)),
        );

        await page.locator('[data-run]').click();
        await page
            .locator('[data-run-status]', { hasText: 'Succeeded' })
            .waitFor({ timeout: 60000 });
        await page.locator('[data-run-step="big"]').click();
        await page.locator('[data-run-output]').waitFor();
        await settled(page.locator('[data-run-panel]'));
        await shot('workflows-run', {
            clip: await page.locator('[data-run-panel]').boundingBox(),
        });
    },
    { width: 1040, height: 760, canvas: false, scale: 2 },
);
