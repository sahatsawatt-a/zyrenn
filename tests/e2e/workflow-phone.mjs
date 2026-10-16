// The workflow editor on a phone: the canvas has the width to itself; the
// palette and a step's settings open over it, and close again. A step is
// added already joined from another's "+", set up from a run's data by
// tapping a part, and the run looked at step by step -- none of it by
// dragging, which a finger does badly.
import { paletteStep, runBoard, SHOTS, tinker } from './harness.mjs';

const TITLE = `e2e phone ${Date.now().toString().slice(-6)}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
const at = (x, y) => ({ x, y });

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'start',
                    key: 'start',
                    type: 'trigger.manual',
                    label: 'Run by hand',
                    position: at(0, 120),
                    config: { payload: '{"n": 20, "name": "Ada"}' },
                },
                {
                    id: 'big',
                    key: 'big',
                    type: 'logic.if',
                    label: 'Is it big?',
                    position: at(320, 120),
                    config: { condition: 'trigger.n > 10' },
                },
                {
                    id: 'yes',
                    key: 'yes',
                    type: 'output.log',
                    label: 'Log',
                    position: at(660, 40),
                    config: { message: 'big' },
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
                    target: 'yes',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

await runBoard(
    `/workflows/${ref}`,
    async ({ page, check, afterwards }) => {
        afterwards(() =>
            tinker(
                `${user}->workflows()->where('title', '${TITLE}')->delete();`,
            ),
        );

        const step = (key) => page.locator(`[data-step="${key}"]`);
        const palette = page.locator('[data-step-palette]');
        const settings = page.locator('[data-step-settings]');
        const data = page.locator('[data-test-data]');
        const panels = page.locator('[data-step-panels]');
        const edges = () => page.locator('.vue-flow__edge').count();
        const saved = () =>
            page
                .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
                .waitFor({ timeout: 10000 });
        const fit = async () => {
            await page
                .getByRole('button', { name: 'Fit the workflow in view' })
                .click();
            await page.waitForTimeout(500);
        };
        const width = page.viewportSize().width;

        await step('big').waitFor();
        const canvas = await page.locator('[data-canvas]').boundingBox();
        check(
            'the canvas has the width of the screen, with nothing beside it',
            Math.round(canvas.width) === width &&
                (await palette.count()) === 0 &&
                (await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <=
                        window.innerWidth,
                )),
            `${canvas.width} of ${width}`,
        );

        const header = await page.locator('header').last().boundingBox();
        const publish = await page.locator('[data-publish]').boundingBox();
        check(
            'what is at the top stays on the screen',
            publish.x + publish.width <= width && header.height < 130,
            `publish ends at ${publish.x + publish.width}, header ${header.height} high`,
        );

        // --- a step's settings, over the canvas ---
        await fit();
        await step('big').click();
        await settings.waitFor();
        const over = await panels.boundingBox();
        check(
            "a step's settings open over the canvas, as wide as the screen",
            Math.round(over.width) === width &&
                (await settings.locator('#setting-condition').isVisible()) &&
                !(await data.isVisible()),
        );
        await page.locator('[data-step-tab="data"]').click();
        check(
            'its test data is the other tab',
            (await data.isVisible()) && !(await settings.isVisible()),
        );
        await page.locator('[data-step-panels-close]').click();
        check('and close, back to the canvas', (await panels.count()) === 0);

        // --- the next step, from a step's "+" ---
        check(
            'a way out that leads nowhere has a "+", one that leads somewhere has none',
            (await step('big').locator('[data-step-next="false"]').count()) ===
                1 &&
                (await step('big')
                    .locator('[data-step-next="true"]')
                    .count()) === 0,
        );
        await step('big').locator('[data-step-next="false"]').click();
        await palette.waitFor();
        const offered = await palette.boundingBox();
        check(
            'it opens the palette over the canvas, saying what the step comes after',
            Math.round(offered.width) === width &&
                (
                    await palette.locator('[data-palette-after]').innerText()
                ).includes('Is it big?') &&
                (await palette
                    .locator('[data-palette-step^="trigger."]')
                    .count()) === 0,
            await palette.locator('[data-palette-after]').innerText(),
        );
        await (await paletteStep(page, 'output.log')).click();
        await settings.waitFor();
        check(
            'the step chosen is added already joined, and the palette is gone',
            (await edges()) === 3 && (await palette.count()) === 0,
            `${await edges()} connections`,
        );
        await settings.locator('#step-label').fill('Log small');
        await settings.locator('#setting-message').fill('small');
        await page.locator('[data-step-panels-close]').click();
        await saved();

        // --- by "Add step", on its own ---
        await page.locator('[data-add-step]').click();
        await (await paletteStep(page, 'data.set')).click();
        await settings.waitFor();
        check(
            '"Add step" adds one on its own',
            (await edges()) === 3 && (await step('set_data').count()) === 1,
        );
        await settings.locator('[data-remove-step]').click();
        await saved();

        // --- a run, step by step ---
        await page.locator('[data-run]').click();
        const run = page.locator('[data-run-panel]');
        await run
            .locator('[data-run-status]', { hasText: 'Succeeded' })
            .waitFor({ timeout: 20000 });
        const first = await run
            .locator('[data-run-step="start"]')
            .boundingBox();
        const second = await run.locator('[data-run-step="big"]').boundingBox();
        check(
            "the run's steps are one under another, each as wide as the screen",
            second.y > first.y && Math.round(first.width) === width,
        );
        await run.locator('[data-run-step="yes"]').click();
        await run.locator('[data-run-input]').waitFor();
        check(
            'a step chosen there shows what it was given, not its settings',
            (await panels.count()) === 0 &&
                (await run.locator('[data-run-input]').innerText()).includes(
                    'Ada',
                ),
        );
        const [above, below] = [
            await run.locator('[data-run-input]').boundingBox(),
            await run.locator('[data-run-output]').boundingBox(),
        ];
        check(
            'with what it gave under that, where there is not the width for two',
            below.y > above.y + above.height - 1,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-phone-run.png` });
        await run.locator('[data-run-expand]').click();
        const modal = page.locator('[data-run-modal]');
        await modal.waitFor();
        const opened = await modal.boundingBox();
        check(
            'opened larger, it stays on the screen',
            opened.x >= 0 && opened.x + opened.width <= width,
        );
        await page.keyboard.press('Escape');
        await modal.waitFor({ state: 'detached' });
        await run.getByRole('button', { name: 'Close the run panel' }).click();

        // --- set up from what came, by tapping ---
        await fit();
        await step('yes').click();
        await settings.waitFor();
        const message = settings.locator('#setting-message');
        await message.fill('Big order from ');
        await page.locator('[data-step-tab="data"]').click();
        const part = data.locator('[data-sample="trigger.name"]');
        await part.waitFor({ timeout: 10000 });
        check(
            'what earlier steps gave is folded away until it is wanted',
            (await data.locator('[data-sample="node(\\"big\\")"]').count()) ===
                1 &&
                (await data
                    .locator('[data-sample^="node(\\"big\\")."]')
                    .count()) === 0,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-phone-data.png` });
        await part.click();
        await message.waitFor();
        check(
            'a part tapped goes into the setting being written, as {{ }}',
            (await message.inputValue()) ===
                'Big order from {{ trigger.name }}' &&
                (await settings.isVisible()),
            await message.inputValue(),
        );
        await page.locator('[data-step-panels-close]').click();
        await saved();

        // --- a connection, taken away without a keyboard ---
        await fit();
        await page.locator('.vue-flow__edge').first().click({ force: true });
        await page.locator('[data-unlink]').click();
        check(
            'a connection chosen can be removed',
            (await edges()) === 2,
            `${await edges()} connections`,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-phone.png` });
    },
    { canvas: false, width: 390, height: 844 },
);
