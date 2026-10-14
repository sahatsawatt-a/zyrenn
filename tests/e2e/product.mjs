// A project's product, tracked, as someone builds it up: parts of the tree
// with codes, a shared one and what uses it, a capability made live; what a
// customer wrote kept from the inbox as a request, with a way back to the
// chat; a complaint filed from the inbox as a bug on the part it is about;
// working a request out with an agent, before there is one and with one
// that has no model; a request kept word for word and worked out into a requirement,
// verified only once its criteria are met; and coverage saying where the
// trail has gaps.
//
// Needs a second account, E2E_MEMBER.
import {
    SHOTS,
    deleteProject,
    open,
    runBoard,
    shareProject,
    tinker,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Product ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const node = (code) =>
            page.locator(`[data-element="${code}"] [data-test="product-node"]`);
        const pick = async (dropdown, label) => {
            await dropdown.click();
            await page
                .getByRole('option', { name: label, exact: true })
                .click();
        };
        const dialogGone = () =>
            page.getByRole('dialog').waitFor({ state: 'detached' });

        await page.evaluate(() => localStorage.removeItem('product.tab'));
        await open(page, `${base}/p/${project}/product`);
        await test('product-tree').waitFor();

        // ------------------------------------------------------ The tree
        const part = async (kind, name, under = null, shared = false) => {
            await test('product-new-part').click();
            await test(`part-${kind}`).click();
            await test('part-name').fill(name);

            if (under) {
                await pick(
                    page
                        .getByRole('dialog')
                        .getByRole('combobox', { name: 'Under' }),
                    under,
                );
            }

            if (shared) {
                await page.getByRole('dialog').getByRole('switch').click();
            }

            await test('part-make').click();
            await dialogGone();
        };

        await part('component', 'Board');
        await part('component', 'Shape', 'CMP-1 Board');
        await part('component', 'Colour picker', null, true);
        await test('product-add-CMP-2').click();
        await test('part-name').fill('Change border colour');
        await test('part-make').click();
        await dialogGone();
        await test('product-add-CMP-2').click();
        await test('part-name').fill('Change fill colour');
        await test('part-make').click();
        await dialogGone();

        await soon(
            'parts are made with codes of their own, in a tree',
            async () =>
                (await page.locator('[data-element]').count()) === 5 &&
                (await node('CAP-2').count()) === 1,
        );
        check(
            'the shared one is listed on its own too',
            /Colour picker/.test(await test('product-shared').innerText()),
        );

        // ---------------------------------------- Live, and what it uses
        await node('CAP-1').click();
        await test('product-element').waitFor();
        await pick(test('element-status'), 'Live');
        await pick(test('element-link-to'), '★ CMP-3 Colour picker');
        await test('element-link-add').click();
        await soon(
            'a capability made live, using the shared picker',
            async () =>
                /Colour picker/.test(await test('element-links').innerText()),
        );
        await page.keyboard.press('Escape');
        await test('product-element').waitFor({ state: 'detached' });
        await soon('its component counts it as live', async () =>
            /1/.test(await page.locator('[data-element="CMP-1"]').innerText()),
        );

        await node('CAP-2').click();
        await test('product-element').waitFor();
        await pick(test('element-link-to'), '★ CMP-3 Colour picker');
        await test('element-link-add').click();
        await soon('the fill colour uses it too', async () =>
            /Colour picker/.test(await test('element-links').innerText()),
        );
        await page.keyboard.press('Escape');
        await test('product-element').waitFor({ state: 'detached' });

        await node('CMP-3').click();
        await test('element-impact').waitFor();
        await soon(
            'changing the picker would reach both',
            async () => (await test('impact-part').count()) === 2,
        );
        await test('product-element').screenshot({
            path: `${SHOTS}/product-impact.png`,
        });
        await page.keyboard.press('Escape');
        await test('product-element').waitFor({ state: 'detached' });
        await page.screenshot({ path: `${SHOTS}/product-tree.png` });

        // ------------------------------ What a customer wrote, from the inbox
        const room = tinker(
            `$p = App\\Models\\Project::where((new App\\Models\\Project)->getRouteKeyName(), '${project}')->firstOrFail(); $r = App\\Models\\Chat\\ChatRoom::startGroup($p, $p->members()->firstOrFail(), 'Ada on LINE'); $r->forceFill(['link' => ['service' => 'line', 'connection' => 'none', 'chat' => 'U1']])->save(); $r->becomeCustomers(); $r->messages()->create(['role' => 'workflow', 'content' => 'Could you add gift wrapping?', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]); echo $r->ref_id;`,
        );
        await open(page, `${base}/chats/${room}`);
        const said = page
            .locator('[data-chat-theirs], [data-chat-workflow]')
            .filter({ hasText: 'Could you add gift wrapping?' });
        await said.hover();
        await said.locator('[data-test="chat-request"]').click();
        await soon(
            'the team is told in the chat that it was kept',
            async () =>
                (await page
                    .getByText('Kept as request RQ-1 of the product')
                    .count()) === 1,
        );

        await open(page, `${base}/p/${project}/product`);
        await test('product-tab-requests').click();
        await test('product-request')
            .filter({ hasText: 'gift wrapping' })
            .click();
        await test('request-sheet').waitFor();
        check(
            'kept from the chat, word for word, with a way back to it',
            (await test('request-words').innerText()).trim() ===
                'Could you add gift wrapping?' &&
                /From a chat, from Ada on LINE/.test(
                    await test('request-sheet').innerText(),
                ),
        );
        await test('request-chat').click();
        await soon('back in the chat it came from', async () =>
            page.url().endsWith(`/chats/${room}`),
        );

        // -------------------- A complaint about what is live: a bug on its part
        tinker(
            `$r = App\\Models\\Chat\\ChatRoom::where('ref_id', '${room}')->firstOrFail(); $m = $r->messages()->create(['role' => 'workflow', 'content' => 'The border colour goes back after undo', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]); broadcast(new App\\Events\\ChatMessagePosted($r, $m));`,
        );
        const complaint = page
            .locator('[data-chat-theirs], [data-chat-workflow]')
            .filter({ hasText: 'The border colour goes back after undo' });
        await complaint.waitFor();
        await complaint.hover();
        await complaint.locator('[data-test="chat-promote"]').click();
        await test('promote-dialog').waitFor();
        await pick(test('promote-part'), 'CAP-1 Change border colour');
        await test('promote-save').click();
        await test('promote-dialog').waitFor({ state: 'detached' });
        await soon(
            'filed as a bug, about the part it is about',
            async () =>
                (await page
                    .getByText(
                        'Made ticket #1: The border colour goes back after undo, about CAP-1 Change border colour',
                    )
                    .count()) === 1,
        );

        await open(page, `${base}/p/${project}/product`);
        await test('product-tab-tree').click();
        await soon('the tree shows the bug on the part', async () =>
            /1/.test(
                await page
                    .locator('[data-element="CAP-1"]')
                    .locator('.text-red-600')
                    .innerText()
                    .catch(() => ''),
            ),
        );

        // --------------------------------- A request, and a requirement
        await test('product-tab-requests').click();
        await test('request-original').fill('Make the colour picking better.');
        await test('request-keep').click();
        await test('product-request')
            .filter({ hasText: 'colour picking' })
            .click();
        await test('request-sheet').waitFor();
        check(
            'kept word for word',
            (await test('request-words').innerText()).trim() ===
                'Make the colour picking better.',
        );
        await test('request-requirement-title').fill(
            'A colour can be typed as HEX',
        );
        await test('request-requirement-add').click();
        await soon('a requirement is made from it', async () =>
            /REQ-1/.test(await test('request-sheet').innerText()),
        );
        await page.keyboard.press('Escape');
        await test('request-sheet').waitFor({ state: 'detached' });

        await test('product-tab-requirements').click();
        await test('product-requirement').first().click();
        await test('requirement-sheet').waitFor();
        await pick(test('req-element-add'), 'CMP-3 Colour picker');
        await test('req-criterion-input').fill(
            'Typing #RRGGBB sets the colour',
        );
        // On the box itself: a dropdown just closed hands focus back to itself a moment later
        await test('req-criterion-input').press('Enter');
        await test('req-criterion').waitFor();
        await pick(test('req-status'), 'Verified');
        await soon(
            'it cannot be verified while a criterion is not met',
            async () =>
                /every criterion/.test(
                    await test('requirement-sheet').innerText(),
                ),
        );
        await test('req-criterion').click();
        await pick(test('req-status'), 'Verified');
        await soon(
            'met, it is verified',
            async () =>
                /Verified/.test(await test('req-status').innerText()) &&
                !/every criterion/.test(
                    await test('requirement-sheet').innerText(),
                ),
        );
        await test('requirement-sheet').screenshot({
            path: `${SHOTS}/product-requirement.png`,
        });
        await page.keyboard.press('Escape');
        await test('requirement-sheet').waitFor({ state: 'detached' });

        // -------------- Worked out by an agent: none yet, then one with no model
        const theRequest = async () => {
            await open(page, `${base}/p/${project}/product`);
            await test('product-tab-requests').click();
            await test('product-request')
                .filter({ hasText: 'colour picking' })
                .click();
            await test('request-sheet').waitFor();
        };
        await theRequest();
        check(
            'with no agent yet, it says where to make one',
            /No agents in this project yet/.test(
                await test('work-out').innerText(),
            ),
        );
        await open(page, `${base}/p/${project}/agents`);
        await test('agent-new').click();
        await test('agent-preset-analyst').click();
        await test('agent-sheet').waitFor();
        await page.keyboard.press('Escape');
        await test('agent-sheet').waitFor({ state: 'detached' });

        await theRequest();
        await test('work-out-ask').click();
        await soon(
            'an agent with no model to ask says so, and the request stays open',
            async () =>
                (await test('work-out-failed').count()) === 1 &&
                /no model|no AI connection/.test(
                    await test('work-out-failed').innerText(),
                ) &&
                (await test('request-sheet').count()) === 1,
        );
        await page.keyboard.press('Escape');
        await test('request-sheet').waitFor({ state: 'detached' });

        // ------------------------------------------------------ Coverage
        await test('product-tab-coverage').click();
        await test('product-coverage').waitFor();
        await soon(
            'coverage says the live part has a bug open, and work with no requirement',
            async () => {
                const gaps = await test('product-gaps').innerText();

                return (
                    /Live with 1 open bug/.test(gaps) &&
                    /Work on it, and no requirement/.test(gaps)
                );
            },
        );
        await page.screenshot({ path: `${SHOTS}/product-coverage.png` });

        // ------------------------------------------------------ On a phone
        await page.setViewportSize({ width: 390, height: 844 });
        await test('product-tab-tree').click();
        await test('product-tree').waitFor();
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        check('the tree fits a phone', overflow <= 0, `${overflow}px too wide`);
        await page.screenshot({ path: `${SHOTS}/product-phone.png` });
    },
    { canvas: false },
);
