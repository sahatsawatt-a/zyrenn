// The pictures in the guide (public/images/guide), taken from the app itself
// so they can be taken again whenever a screen changes:
//
//     HEADED=0 node tests/e2e/guide-shots.mjs
//
// They are of an account kept for this alone, guide@example.com, holding only
// what is made here -- so nothing of anyone's own is ever in a picture.
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

process.env.E2E_EMAIL = 'guide@example.com';
const { runBoard, tinker } = await import('./harness.mjs');

const OUT = join(
    dirname(fileURLToPath(import.meta.url)),
    '../../public/images/guide',
);
mkdirSync(OUT, { recursive: true });

const user = `App\\Models\\User::where('email', 'guide@example.com')->first()`;
const emptied = `$u = ${user}; foreach (['notes', 'boards', 'tables', 'workflows', 'projects'] as $kind) { $u->{$kind}()->get()->each(fn ($one) => $one->delete()); } $u->tokens()->delete();`;

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

            return (await response.json()).result.structuredContent;
        };
        const shot = (name, options = {}) =>
            page.screenshot({ path: join(OUT, `${name}.png`), ...options });

        // ---- what the account holds ----
        await mcp('create-project', { name: 'Kyoto in spring' });
        await mcp('create-table', {
            title: 'Budget',
            columns: [
                { label: 'What' },
                { label: 'Cost', type: 'currency', summary: 'sum' },
            ],
            rows: [
                { What: 'Flights', Cost: 640 },
                { What: 'Hotel', Cost: 420 },
            ],
        });
        await mcp('create-board', {
            title: 'Launch plan',
            items: [
                { id: 'a', kind: 'rect', text: 'Draft' },
                { id: 'b', kind: 'rect', text: 'Review' },
                { kind: 'arrow', from: { item: 'a' }, to: { item: 'b' } },
            ],
        });
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

        // ---- Getting started: the sidebar, with what was changed lately ----
        await page.goto(`${base}/dashboard`, { waitUntil: 'networkidle' });
        await shot('getting-started');

        // ---- Notes: the "/" menu ----
        await page.goto(`${base}/notes/${note}`, { waitUntil: 'networkidle' });
        // Once the note has settled, or the caret is put back at its start
        await page.waitForTimeout(1500);
        // Out of the list by a second Enter, onto a line of its own -- each
        // key in its own time: pressed at once, they land at the note's start
        await page.locator('.tiptap li').last().click();

        for (const key of ['End', 'Enter', 'Enter', '/']) {
            await page.waitForTimeout(300);
            await page.keyboard.press(key);
        }
        const menu = page.getByText('Basic blocks').locator('..');
        await menu.waitFor();
        await page.waitForTimeout(300);
        await shot('notes-menu', await inset(await bottomOf(menu)));
        // Saved before it is left, or the page asks whether to go
        await page.keyboard.press('Escape');
        await page.getByText('Saved', { exact: true }).waitFor();

        // ---- Workflows: the steps, then a run of them ----
        await page.goto(`${base}/workflows/${workflow}`, {
            waitUntil: 'networkidle',
        });
        await page.locator('[data-step="big"]').waitFor();
        await page
            .getByRole('button', { name: 'Fit the workflow in view' })
            .click();
        await page.waitForTimeout(600);
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
        await page.waitForTimeout(300);
        await shot('workflows-run', {
            clip: await page.locator('[data-run-panel]').boundingBox(),
        });
    },
    { width: 1040, height: 760, canvas: false, scale: 2 },
);
