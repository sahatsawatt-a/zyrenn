// What "Run code" may use, as the app's admins set it on the Code page: the
// libraries of each language, switched on and off, and Python allowed or
// not -- and a step choosing its language and libraries from what is there.
// Libraries are put in ready-made, so nothing here reaches npm.
import { SHOTS, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e code ${RUN}`;
const LIB = `e2e-lib-${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

tinker(
    `${user}->forceFill(['is_admin' => true])->save(); App\\Models\\Workflow\\CodeLibrary::create(['language' => 'javascript', 'name' => '${LIB}', 'version' => '1.0.0', 'status' => 'ready', 'size' => 2048, 'source' => 'module.exports = { twice: function (n) { return n * 2; } };']); echo 'ok';`,
);

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'start',
                    key: 'start',
                    type: 'trigger.manual',
                    label: 'Run by hand',
                    position: { x: 0, y: 120 },
                    config: { payload: '{}' },
                },
                {
                    id: 'work',
                    key: 'work',
                    type: 'data.code',
                    label: 'Run code',
                    position: { x: 320, y: 120 },
                    config: {},
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'start',
                    sourceHandle: 'out',
                    target: 'work',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

const forget = () =>
    tinker(
        `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); App\\Models\\Workflow\\CodeLibrary::where('name', 'like', 'e2e-lib-%')->delete(); App\\Support\\Workflow\\Code\\CodeSettings::set(['python' => false, 'seconds' => ['javascript' => 30, 'python' => 30], 'python_memory' => 512]); $u->forceFill(['is_admin' => false])->save();`,
    );

// --- the admin page ---
const failedPage = await runBoard(
    '/admin/code',
    async ({ page, check, soon }) => {
        const row = page.locator(`[data-code-library="${LIB}"]`);
        await row.waitFor();
        check(
            'a ready library shows its version and size',
            /1\.0\.0[\s\S]*2 KB/.test(await row.innerText()),
            await row.innerText(),
        );

        // A name npm would never have is turned away before anything is fetched
        await page.locator('[data-test="code-library-name"]').fill('../etc');
        await page.locator('[data-test="code-library-add"]').click();
        await soon('a bad name is said plainly', () =>
            page.getByText('That is not an npm package').isVisible(),
        );

        // Switched off here, a step can no longer choose it
        await row.locator('[role="switch"]').click();
        await soon(
            'switched off, it is kept off',
            () =>
                tinker(
                    `echo (int) App\\Models\\Workflow\\CodeLibrary::where('name', '${LIB}')->value('enabled');`,
                ) === '0',
        );
        await row.locator('[role="switch"]').click();
        await soon(
            'and back on',
            () =>
                tinker(
                    `echo (int) App\\Models\\Workflow\\CodeLibrary::where('name', '${LIB}')->value('enabled');`,
                ) === '1',
        );

        await page.locator('[data-test="code-python"]').click();
        await page.locator('[data-test="code-python-on"]').click();
        await soon(
            'Python is allowed from the Python tab',
            () =>
                tinker(
                    `echo App\\Support\\Workflow\\Code\\CodeSettings::pythonOn() ? 'on' : 'off';`,
                ) === 'on',
        );
        check(
            'Python has a memory to choose',
            (await page.locator('[data-test="code-memory"]').count()) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/admin-code.png` });
    },
    { canvas: false },
);

// --- a step choosing its language and libraries ---
const failedStep = await runBoard(
    `/workflows/${ref}`,
    async ({ page, check, soon }) => {
        const settings = page.locator('[data-step-settings]');

        await page.locator('[data-step="work"]').click();
        await settings.waitFor();
        const chip = settings.locator(`[data-library="${LIB}"]`);
        await chip.waitFor();
        check(
            'JavaScript offers the libraries added for it, none chosen yet',
            (await chip.getAttribute('aria-pressed')) === 'false',
        );
        await chip.click();
        await soon(
            'chosen, it is on',
            async () => (await chip.getAttribute('aria-pressed')) === 'true',
        );

        await settings.locator('#setting-language').click();
        await page.getByRole('option', { name: 'Python' }).click();
        await soon('in Python, the code starts again as Python', async () =>
            (await settings.locator('#setting-code').inputValue()).startsWith(
                '# trigger',
            ),
        );
        check(
            'and JavaScript’s libraries are not offered to it',
            (await settings.locator(`[data-library="${LIB}"]`).count()) === 0,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-code-python.png` });
    },
    { canvas: false },
);

// --- on a phone ---
const failedPhone = await runBoard(
    '/admin/code',
    async ({ page, check, afterwards }) => {
        afterwards(forget);

        await page.locator(`[data-code-library="${LIB}"]`).waitFor();
        check(
            'on a phone the page fits, and nothing runs off the side',
            await page.evaluate(
                () =>
                    document.documentElement.scrollWidth <=
                    document.documentElement.clientWidth,
            ),
        );
        await page.screenshot({ path: `${SHOTS}/admin-code-phone.png` });
    },
    { canvas: false, width: 390, height: 844 },
);

if (failedPage || failedStep || failedPhone) {
    process.exitCode = 1;
}
