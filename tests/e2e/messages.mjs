// Talking with people: a message between two people in a project, and a group
// in that project that the other one finds and joins -- each heard live by the
// other, with the unread count in the sidebar following along.
//
// Needs a second account, E2E_MEMBER (e2e-member@example.com by default), with
// the password "password".
import {
    deleteProject,
    runBoard,
    shareProject,
    signIn,
    tinker,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const OWNER = process.env.E2E_EMAIL ?? 'test@example.com';
const RUN = Date.now().toString().slice(-6);

await runBoard(
    '/messages',
    async ({ browser, page, check, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Talk ${RUN}`,
            MEMBER,
            'viewer',
        );
        const ids = tinker(
            `echo App\\Models\\User::where('email', '${OWNER}')->value('id').','.App\\Models\\User::where('email', '${MEMBER}')->value('id');`,
        ).split(',');
        // The groups go with the project; the two people's own room is taken away by hand
        afterwards(() =>
            tinker(
                `App\\Models\\Chat\\ChatRoom::where('direct_key', '${[...ids].sort((a, b) => a - b).join(':')}')->delete();`,
            ),
        );

        const { context, page: other } = await signIn(browser, base, MEMBER);
        const badge = (on) =>
            on.locator('[data-badge="Messages"], [data-badge="Chat"]');
        // The sidebar's own links, not the breadcrumb's of the same name
        const link = (on, name) =>
            on
                .locator('[data-sidebar="menu"]')
                .getByRole('link', { name, exact: true });

        // ---------------------------------------- AI chat and messages are apart
        await page.goto(`${base}/messages`, { waitUntil: 'networkidle' });
        check(
            'your own sidebar has AI chat and Messages, apart',
            (await link(page, 'AI chat').count()) === 1 &&
                (await link(page, 'Messages').count()) === 1,
        );

        // ------------------------------------------------ One to one, live
        await page.getByRole('button', { name: /new message/i }).click();
        await page
            .locator('input[aria-label="Search people"]')
            .fill('e2e-member');
        await page.locator('[data-person]').first().click();
        await page.waitForURL(/\/chats\/\w+$/);
        const directUrl = page.url();
        check('picking someone opens the room with them', true);

        await other.goto(`${base}/messages`, { waitUntil: 'networkidle' });
        await page.locator('[data-chat-input]').fill(`Hello ${RUN}`);
        await page.locator('[data-chat-input]').press('Enter');
        await page.waitForSelector('[data-chat-mine]');

        await other
            .waitForFunction(
                () =>
                    document
                        .querySelector('[data-badge="Messages"]')
                        ?.textContent?.trim() === '1',
                null,
                { timeout: 15000 },
            )
            .catch(() => {});
        check(
            "the other one's unread count goes up without a reload",
            (
                await badge(other)
                    .innerText()
                    .catch(() => '')
            ).trim() === '1',
        );

        await other.goto(directUrl, { waitUntil: 'networkidle' });
        check(
            'they see it as from the other person, on the left',
            (await other.locator('[data-chat-theirs]').innerText()).includes(
                `Hello ${RUN}`,
            ),
        );
        check('opening it reads it', (await badge(other).count()) === 0);

        await other.locator('[data-chat-input]').fill(`Hi back ${RUN}`);
        await other.locator('[data-chat-input]').press('Enter');
        await page.waitForSelector('[data-chat-theirs]', { timeout: 15000 });
        check(
            'and the answer arrives live, while the first has it open',
            (await page.locator('[data-chat-theirs]').innerText()).includes(
                `Hi back ${RUN}`,
            ),
        );
        // Read as it came, and told to the server at once: leaving straight after keeps it read
        await page.goto(`${base}/p/${project}/notes`, {
            waitUntil: 'networkidle',
        });
        const unread = tinker(
            `echo App\\Models\\Chat\\ChatRoom::unreadTotal(App\\Models\\User::where('email', '${OWNER}')->first());`,
        );
        check(
            'what arrived while it was open counts as read, even leaving at once',
            unread === '0' && (await badge(page).count()) === 0,
            `server says ${unread} unread`,
        );
        await page.goto(directUrl, { waitUntil: 'networkidle' });
        check(
            'and who else has it open is shown',
            (await page.locator('[data-test="presence-member"]').count()) === 1,
        );

        // ------------------------------------------------ A group in the project
        await page.goto(`${base}/p/${project}/notes`, {
            waitUntil: 'networkidle',
        });
        check(
            "a project's sidebar has its Chat, not your AI chat",
            (await link(page, 'Chat').count()) === 1 &&
                (await link(page, 'AI chat').count()) === 0,
        );
        await link(page, 'Chat').click();
        await page.waitForURL(/\/p\/\w+\/chat$/);

        // Started alone, so the other has to find it and join
        await page.locator('[data-new-group]').click();
        await page.locator('#group-title').fill(`Launch ${RUN}`);
        await page.locator('[data-start-group]').click();
        await page.waitForURL(/\/chats\/\w+$/);
        const groupUrl = page.url();
        check(
            'a group is started',
            (await page
                .locator('input[aria-label="Chat title"]')
                .inputValue()) === `Launch ${RUN}`,
        );

        await other.goto(`${base}/p/${project}/chat`, {
            waitUntil: 'networkidle',
        });
        const row = other.locator('[data-room]', { hasText: `Launch ${RUN}` });
        check(
            'someone else in the project sees it, not joined',
            (await row.innerText()).includes('not joined') &&
                (await row.locator('[data-join]').count()) === 1,
        );
        const outsiderCannotOpen = (await other.goto(groupUrl)).status();
        check('and cannot open it before joining', outsiderCannotOpen === 403);

        await other.goto(`${base}/p/${project}/chat`, {
            waitUntil: 'networkidle',
        });
        await row.locator('[data-join]').click();
        await other.waitForURL(groupUrl);
        check('joining opens it', true);

        await page.goto(groupUrl, { waitUntil: 'networkidle' });
        await page.locator('[data-chat-input]').fill(`Welcome ${RUN}`);
        await page.locator('[data-chat-input]').press('Enter');
        await other.waitForSelector('[data-chat-theirs]', { timeout: 15000 });
        check(
            "in a group the others' words come with their name",
            (
                await other.locator('[data-chat-author]').first().innerText()
            ).trim().length > 0 &&
                (
                    await other.locator('[data-chat-theirs]').innerText()
                ).includes(`Welcome ${RUN}`),
        );

        other.on('dialog', (dialog) => dialog.accept());
        await other.locator('[data-leave]').click();
        await other.waitForURL(/\/p\/\w+\/chat$/);
        check(
            'leaving goes back to the groups, where it can be joined again',
            (await other
                .locator('[data-room]', { hasText: `Launch ${RUN}` })
                .locator('[data-join]')
                .count()) === 1,
        );

        await page.screenshot({ path: '/tmp/zyrenn-e2e/group.png' });
        await context.close();
        await deleteProject(page, base, project);
    },
    { canvas: false },
);
