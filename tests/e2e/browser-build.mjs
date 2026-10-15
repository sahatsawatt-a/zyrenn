// Building a browser check by clicking through the page (/checks), on a
// page kept open in the browser and drawn at a phone's size: starting from
// its address, a field clicked and typed into, a button clicked and the page
// going on to what it shows next, words expected there, and the check made
// of it -- then the suite set to run by itself, with a hidden value it types. The page is one of its own (a data: URL), so
// nothing reaches the internet.
import { runBoard, tinker, until } from './harness.mjs';

const RUN = Date.now();
const email = process.env.E2E_EMAIL ?? 'test@example.com';
const user = `App\\Models\\User::where('email', '${email}')->first()`;

const PAGE = `data:text/html,${encodeURIComponent(
    '<meta name="viewport" content="width=device-width"><body style="font:20px system-ui;padding:20px"><p id="screen"></p><label>Your name <input oninput="document.getElementById(\'hi\').textContent = \'Hello, \' + this.value"></label><p id="hi"></p> <label>Size <select onchange="document.getElementById(\'size\').textContent = \'You chose \' + this.value"><option>Small</option><option>Large</option></select></label><p id="size"></p> <button onclick="document.getElementById(\'out\').textContent = \'Thanks, \' + document.querySelector(\'input\').value">Sign up</button><p id="out"></p><div onmouseenter="this.lastChild.hidden = false">Menu<span hidden> Profile</span></div><p id="wait">Loading…</p><iframe style="height:70px;border:0" srcdoc="<button onclick=&quot;document.body.append(\'Paid\')&quot;>Pay</button>"></iframe><script>document.getElementById(\'screen\').textContent = innerWidth < 500 ? \'On a narrow screen\' : \'On a wide screen\'; setTimeout(() => document.getElementById(\'wait\').remove(), 300)</script></body>',
)}`;

await runBoard(
    '/checks',
    async ({ page, check, soon, afterwards }) => {
        const test = (name) => page.locator(`[data-test="${name}"]`);

        afterwards(() =>
            tinker(
                `${user}->browserSuites()->where('title', 'Built ${RUN}')->get()->each->delete(); echo 'ok';`,
            ),
        );

        await test('suite-new').click();
        await page
            .getByRole('dialog')
            .getByRole('textbox')
            .fill(`Built ${RUN}`);
        await page.getByRole('button', { name: 'Make it' }).click();
        await test('suite').waitFor();

        // ------------------------------------------------- From its address
        await test('check-build-new').click();
        const dialog = test('build-dialog');
        await dialog.locator('[data-test="build-name"]').fill('Signing up');
        await dialog.locator('[data-test="build-address"]').fill(PAGE);
        // Built on a phone's screen: the page lays itself out for one
        await dialog.locator('[data-test="build-phone"]').click();
        await dialog.locator('[data-test="build-open"]').click();
        await dialog
            .locator('[data-test="pick-picture"]')
            .waitFor({ timeout: 30000 });
        check(
            "the page opens in the browser on a phone's screen, its things marked",
            (await dialog
                .locator('[data-test="pick-thing"]', {
                    hasText: 'On a narrow screen',
                })
                .count()) === 1,
        );

        // A field asks what to type
        await dialog
            .locator('[data-test="pick-thing"]', { hasText: 'Your name' })
            .filter({ hasText: 'field' })
            .click();
        await dialog.locator('[data-test="build-typed"]').fill('Ada');
        await soon(
            'what is typed shows on the page before it is added',
            async () =>
                (await dialog
                    .locator('[data-test="pick-thing"]', {
                        hasText: 'Hello, Ada',
                    })
                    .count()) === 1,
            '',
            { timeout: 15000 },
        );
        await dialog.locator('[data-test="build-add-typing"]').click();

        // A button is clicked, and the page goes on
        await dialog
            .locator('[data-test="pick-thing"]', { hasText: 'Sign up' })
            .click();
        await soon(
            'clicking a button moves the page on to what it leads to',
            async () =>
                (await dialog
                    .locator('[data-test="pick-thing"]', {
                        hasText: 'Thanks, Ada',
                    })
                    .count()) === 1,
            '',
            { timeout: 30000 },
        );

        // Words expected there
        await dialog.locator('[data-test="build-see"]').click();
        await dialog
            .locator('[data-test="pick-thing"]', { hasText: 'Thanks, Ada' })
            .click();
        // A step is kept once the page has done it
        const said = await until(
            async () => {
                const now = await dialog
                    .locator('[data-test="build-step"]')
                    .allInnerTexts();

                return now.length === 4 ? now : null;
            },
            { timeout: 15000 },
        );
        check(
            'each click a step, said in words',
            said?.length === 4 &&
                /Type "Ada" into "Your name"/.test(said[1]) &&
                /Click "Sign up"/.test(said[2]) &&
                /Expect to see "Thanks, Ada"/.test(said[3]),
            said?.join(' | '),
        );
        // A list the browser draws itself: its options offered here
        await dialog.locator('[data-test="build-click"]').click();
        await dialog
            .locator('[data-test="pick-thing"]', { hasText: 'Size' })
            .filter({ hasText: 'list' })
            .click();
        await dialog
            .locator('[data-test="build-option"]', { hasText: 'Large' })
            .click();
        await soon(
            'an option chosen from a list, on the page, kept as a step',
            async () =>
                (await dialog
                    .locator('[data-test="pick-thing"]', {
                        hasText: 'You chose Large',
                    })
                    .count()) === 1 &&
                /Choose "Large" in "Size"/.test(
                    (await dialog
                        .locator('[data-test="build-step"]')
                        .last()
                        .innerText()) ?? '',
                ),
            '',
            { timeout: 15000 },
        );
        // Pointed at, a menu opens on its own
        await dialog.locator('[data-test="build-hover"]').click();
        await dialog
            .locator('[data-test="pick-thing"]', { hasText: /^Menu/ })
            .first()
            .click();
        await soon(
            'pointed at, what opens on its own shows, and it is a step',
            async () =>
                (await dialog
                    .locator('[data-test="pick-thing"]', { hasText: 'Profile' })
                    .count()) >= 1 &&
                /Point at "Menu"/.test(
                    await dialog
                        .locator('[data-test="build-step"]')
                        .last()
                        .innerText(),
                ),
            '',
            { timeout: 15000 },
        );

        // A button inside a frame of the page, clicked there
        await dialog.locator('[data-test="build-click"]').click();
        await dialog
            .locator('[data-test="pick-thing"]', { hasText: 'Pay' })
            .filter({ hasText: 'in a frame' })
            .click();
        await soon(
            'a button inside a frame is clicked in it, and kept',
            async () =>
                /Click "Pay"/.test(
                    await dialog
                        .locator('[data-test="build-step"]')
                        .last()
                        .innerText(),
                ) &&
                (await dialog
                    .locator('[data-test="build-problem"]')
                    .count()) === 0,
            '',
            { timeout: 15000 },
        );

        // What should be gone, written rather than picked
        await dialog.locator('[data-test="build-gone"]').click();
        await page.locator('[data-test="build-gone-text"]').fill('Loading');
        await page.locator('[data-test="build-gone-text"]').press('Enter');
        await soon(
            'what should be gone is expected not to be, and kept',
            async () =>
                /Expect not to see "Loading"/.test(
                    await dialog
                        .locator('[data-test="build-step"]')
                        .last()
                        .innerText(),
                ),
            '',
            { timeout: 15000 },
        );

        await dialog.locator('[data-test="build-done"]').click();
        await dialog.waitFor({ state: 'detached' });

        await soon(
            'the check is made of them, on a phone, and kept',
            async () => {
                const kept = tinker(
                    `echo json_encode(collect(${user}->browserSuites()->where('title', 'Built ${RUN}')->first()->checks)->firstWhere('title', 'Signing up'));`,
                );

                return (
                    kept.includes('"target":"Thanks, Ada"') &&
                    kept.includes('"size":"phone"')
                );
            },
        );

        // ------------------------------------------------- On its own
        await test('tab-settings').click();
        await test('auto-every').click();
        await page.getByRole('option', { name: 'Every day' }).click();
        await page.getByRole('listbox').waitFor({ state: 'detached' });
        await soon('set to run every day, it says when next', async () =>
            /Next: /.test((await test('auto-next').textContent()) ?? ''),
        );

        await test('value-add').click();
        await test('value-value').fill('hunter2');
        await soon(
            'a hidden value is kept, and not shown again',
            async () =>
                tinker(
                    `echo ${user}->browserSuites()->where('title', 'Built ${RUN}')->first()->values()['password'] ?? 'none';`,
                ) === 'hunter2',
        );
        check(
            'the page does not hold it once kept',
            !(await page.content()).includes('hunter2') ||
                (await test('value-value').inputValue()) === 'hunter2',
        );
    },
    { canvas: false },
);
