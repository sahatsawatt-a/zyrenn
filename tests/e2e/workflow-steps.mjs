// A workflow that starts by itself, built from dropdowns: a chat message
// starts it, it adds a row to a table -- its table and columns chosen, never
// typed -- and answers in the chat as the workflow. Published and switched
// on, it runs when someone writes in the chat, and the chat shows its answer
// as it comes.
import { SHOTS, runBoard, tinker, open as openPage } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e steps ${RUN}`;
const ROOM = `e2e room ${RUN}`;
const TABLE = `e2e orders ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const [roomRef, tableRef] = tinker(
    `$u = ${user}; $r = new App\\Models\\Chat\\ChatRoom; $r->kind = 'personal'; $r->user_id = $u->id; $r->title = '${ROOM}'; $r->save(); $t = App\\Models\\Table\\Table::factory()->for($u)->create(['title' => '${TABLE}']); App\\Support\\Table\\TableStorage::newColumn($t, ['label' => 'Said', 'type' => 'varchar']); App\\Support\\Table\\TableStorage::newColumn($t, ['label' => 'Who', 'type' => 'varchar']); echo $r->ref_id.' '.$t->ref_id;`,
).split(' ');

await runBoard(
    '/workflows',
    async ({ page, check, afterwards }) => {
        afterwards(() =>
            tinker(
                `${user}->workflows()->where('title', '${TITLE}')->delete(); App\\Models\\Chat\\ChatRoom::where('ref_id', '${roomRef}')->delete(); $t = App\\Models\\Table\\Table::where('ref_id', '${tableRef}')->first(); App\\Support\\Table\\TableStorage::drop($t); $t->delete();`,
            ),
        );

        const settings = page.locator('[data-step-settings]');
        const step = (key) => page.locator(`[data-step="${key}"]`);
        const saved = () =>
            page
                .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
                .waitFor({ timeout: 10000 });
        const pick = async (where, option) => {
            await where.click();
            await page
                .getByRole('option', { name: option, exact: true })
                .click();
        };
        const fit = async () => {
            await page
                .getByRole('button', { name: 'Fit the workflow in view' })
                .click();
            await page.waitForTimeout(400);
        };
        const open = async (key) => {
            if (await settings.count()) {
                await settings.getByRole('button', { name: 'Close' }).click();
            }

            await fit();
            await step(key).click();
            await settings.waitFor();
        };

        // --- templates offer only what one has ---
        await page.locator('[data-from-template]').click();
        await page.locator('[data-template="telegram-ai"]').click();
        check(
            'a template asks for its connections from dropdowns, and says when there are none',
            (await page
                .getByRole('dialog')
                .getByText('Add one in Connections first.')
                .count()) >= 1 &&
                (await page.locator('[data-make-from-template]').isDisabled()),
        );
        await page.keyboard.press('Escape');

        // --- a new workflow, its palette grouped by service ---
        await page
            .getByRole('button', { name: /new workflow/i })
            .first()
            .click();
        await page.waitForURL(/\/workflows\/\w+$/);
        const editor = page.url();
        await page.getByLabel('Workflow title').fill(TITLE);
        const groups = await page
            .locator('[data-step-palette] h3')
            .allInnerTexts();
        check(
            'the palette is grouped by service, each with its trigger first',
            ['FLOW', 'CHAT', 'TABLES', 'AI', 'WEB', 'TELEGRAM', 'LINE'].every(
                (g) => groups.map((t) => t.toUpperCase()).includes(g),
            ) &&
                (await page
                    .locator('[data-step-palette] section')
                    .filter({
                        has: page.locator('h3', { hasText: /^Telegram$/ }),
                    })
                    .locator('[data-palette-step]')
                    .first()
                    .getAttribute('data-palette-step')) === 'trigger.telegram',
            groups.join(', '),
        );

        // The chat trigger in place of the one made to start with
        await open('start');
        await settings.locator('[data-remove-step]').click();
        await page.locator('[data-palette-step="trigger.chat"]').click();
        await step('chat_message').waitFor();
        await pick(settings.locator('#setting-room'), ROOM);
        check(
            "a chat is chosen from a dropdown of one's own chats",
            (await settings.locator('#setting-room').innerText()).includes(
                ROOM,
            ),
        );
        check(
            'a choice is a dropdown too',
            (await settings
                .locator('#setting-include [data-pick-one], #setting-include')
                .count()) === 1 &&
                (await settings
                    .locator('button', { hasText: "Workflows' messages too" })
                    .count()) === 0,
        );

        // Add a row: the table, then its columns, all chosen
        await page.locator('[data-palette-step="table.add"]').click();
        await step('add_a_row').waitFor();
        await pick(settings.locator('#setting-table'), TABLE);
        await settings.locator('[data-add-field]').click();
        await settings.locator('[data-add-field]').click();
        const rows = settings.locator('[data-field-row]');
        await pick(rows.nth(0).locator('[data-pick-one]'), 'Said');
        await rows
            .nth(0)
            .getByLabel('Value', { exact: true })
            .fill('{{ trigger.text }}');
        await pick(rows.nth(1).locator('[data-pick-one]'), 'Who');
        await rows
            .nth(1)
            .getByLabel('Value', { exact: true })
            .fill('{{ trigger.message.author }}');
        check(
            "a row's values are named by the chosen table's columns, from a dropdown",
            (await rows.nth(0).locator('[data-pick-one]').innerText()).includes(
                'Said',
            ) &&
                (
                    await rows.nth(1).locator('[data-pick-one]').innerText()
                ).includes('Who'),
        );

        // Answer in the chat, as the workflow
        await page.locator('[data-palette-step="chat.send"]').click();
        await step('write_in_a_room').waitFor();
        await pick(settings.locator('#setting-room'), ROOM);
        await settings
            .locator('#setting-message')
            .fill('Saved: {{ trigger.text }}');

        await settings.getByRole('button', { name: 'Close' }).click();
        await fit();
        const join = async (from, to) => {
            await step(from)
                .locator('.vue-flow__handle.source')
                .first()
                .dragTo(step(to).locator('.vue-flow__handle.target'));
            await page.waitForTimeout(200);
        };
        await join('chat_message', 'add_a_row');
        await join('add_a_row', 'write_in_a_room');
        await saved();
        check(
            'with everything chosen, nothing stands in the way',
            (await page.locator('[data-problems]').innerText()).includes(
                'Ready',
            ),
            await page.locator('[data-problems]').innerText(),
        );
        check(
            'a workflow with only a chat trigger has no Run, only a switch',
            (await page.locator('[data-run]').count()) === 0,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-steps.png` });

        // --- published and switched on ---
        const toggle = page.locator('[data-enabled] [role="switch"]');
        check(
            'it can only be switched on once published',
            await toggle.isDisabled(),
        );
        await page.locator('[data-publish]').click();
        await page.locator('[data-published]', { hasText: 'v1' }).waitFor();
        await toggle.click();
        await page.locator('[data-enabled]', { hasText: 'On' }).waitFor();
        check('switched on, it listens', true);

        // --- someone writes in the chat ---
        await openPage(page, new URL(`/chats/${roomRef}`, page.url()).href);
        await page.locator('[data-chat-input]').fill('two coffees');
        await page.locator('[data-chat-input]').press('Enter');
        await page.locator('[data-chat-workflow]').waitFor({ timeout: 20000 });
        const said = await page.locator('[data-chat-workflow]').innerText();
        check(
            'the workflow answers in the chat as itself, and it shows as it comes',
            said.includes('Saved: two coffees') && said.includes(TITLE),
            said.replace(/\s+/g, ' '),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-chat.png` });

        const row = tinker(
            `echo json_encode(App\\Support\\Table\\TableStorage::rows(App\\Models\\Table\\Table::where('ref_id', '${tableRef}')->first())[0] ?? null);`,
        );
        check(
            'and the row is in the table',
            row.includes('"said":"two coffees"') &&
                row.includes('"who":"Test User"'),
            row,
        );

        // --- set up from what came: that run's data, beside a step ---
        await openPage(page, editor);
        await open('write_in_a_room');
        const data = page.locator('[data-test-data]');
        const part = data.locator('[data-sample="trigger.text"]');
        await part.waitFor({ timeout: 10000 });
        check(
            'a step shows what its trigger gave in the latest run, and each step before it',
            (await part.innerText()).includes('two coffees') &&
                (await data
                    .locator('[data-sample="node(\\"add_a_row\\")"]')
                    .count()) === 1,
            await data.innerText(),
        );

        const message = settings.locator('#setting-message');
        await message.fill('Got ');
        await part.dragTo(message);
        check(
            'a part dragged into a template goes in as {{ }}',
            (await message.inputValue()).includes('{{ trigger.text }}'),
            await message.inputValue(),
        );

        await page.locator('[data-palette-step="logic.if"]').click();
        const condition = settings.locator('#setting-condition');
        await condition.waitFor();
        await part.dragTo(condition);
        await page.waitForTimeout(100);
        check(
            'and bare into a formula',
            (await condition.inputValue()) === 'trigger.text',
            await condition.inputValue(),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-test-data.png` });
    },
    { canvas: false },
);
