// WhatsApp, Facebook, Instagram and Threads in the editor: each a group of
// its own, its steps set up from dropdowns that show only what a choice
// needs. Nothing is run: every one of them would reach a real service.
import { SHOTS, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e meta ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
const at = (n) => ({ x: 300 * n, y: 120 });
const step = (key, type, label, n) => ({
    id: key,
    key,
    type,
    label,
    position: at(n),
    config: {},
});

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    ...step('start', 'trigger.manual', 'Run by hand', 0),
                    config: { payload: '{}' },
                },
                step('say', 'whatsapp.send', 'Send on WhatsApp', 1),
                step('insta', 'instagram.post', 'Post on Instagram', 2),
                step('page', 'facebook.post', 'Post on a Facebook Page', 3),
                step('thread', 'threads.post', 'Post on Threads', 4),
            ],
            edges: [],
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

        const settings = page.locator('[data-step-settings]');
        const shown = async (name) =>
            (await settings.locator(`#setting-${name}`).count()) === 1;
        const pick = async (where, option) => {
            await where.click();
            await page
                .getByRole('option', { name: option, exact: true })
                .click();
        };
        const open = async (key) => {
            if (await settings.count()) {
                await settings.getByRole('button', { name: 'Close' }).click();
            }

            await page
                .getByRole('button', { name: 'Fit the workflow in view' })
                .click();
            await page.waitForTimeout(400);
            await page.locator(`[data-step="${key}"]`).click();
            await settings.waitFor();
        };

        const kinds = (group) =>
            page
                .locator('[data-step-palette] section')
                .filter({
                    has: page.locator('h3', {
                        hasText: new RegExp(`^${group}$`),
                    }),
                })
                .locator('[data-palette-step]')
                .count();
        const counted = [
            await kinds('WhatsApp'),
            await kinds('Facebook'),
            await kinds('Instagram'),
            await kinds('Threads'),
        ];
        check(
            'each of the four has its steps in a group of its own',
            counted.join() === '8,8,15,7',
            counted.join(', '),
        );

        // WhatsApp: who to, what to say, and buttons named one by one
        await open('say');
        check(
            'a WhatsApp message goes to whoever wrote, unless told otherwise',
            (await settings.locator('#setting-to').inputValue()).includes(
                'trigger.chat.id',
            ) && (await shown('text')),
        );
        await settings.locator('[data-add-field]').click();
        check(
            'its buttons are named one by one',
            (await settings.locator('[data-field-row]').count()) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-meta-whatsapp.png` });

        // Instagram: what is posted decides what is asked for
        await open('insta');
        check(
            'an Instagram post is a picture unless told otherwise, with its caption',
            (await shown('picture')) &&
                (await shown('caption')) &&
                !(await shown('video')),
        );
        await pick(settings.locator('#setting-what'), 'A reel');
        check(
            'a reel asks for a video instead',
            (await shown('video')) && !(await shown('picture')),
        );
        await pick(settings.locator('#setting-what'), 'A story');
        check(
            'a story has no caption',
            (await shown('story')) && !(await shown('caption')),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-meta-instagram.png` });

        // Facebook and Threads: words, and one thing more
        await open('page');
        await pick(
            settings.locator('#setting-with'),
            'Several pictures from the Drive',
        );
        check(
            'a Page’s post takes several pictures',
            (await shown('pictures')) && !(await shown('video')),
        );
        await open('thread');
        check(
            'a Threads post can be an answer to another',
            (await shown('reply_to')) && (await shown('text')),
        );
    },
    { canvas: false },
);
