// A project's inbox: its chats with customers listed by where they stand,
// one answered, noted on, handed over and tagged from its own page, what a
// customer sends arriving while the inbox is open -- and all of it on a phone.
//
// Needs a second account, E2E_MEMBER, to be given a chat.
import {
    SHOTS,
    deleteProject,
    open,
    reopen,
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
            `Inbox ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const rows = () => page.locator('[data-room]');
        const badge = () => page.locator('[data-badge="Inbox"]');
        // One of the app's own dropdowns: opened, then the choice clicked
        const pick = async (dropdown, value) => {
            await dropdown.click();
            await page.locator(`[data-pick="${value}"]`).click();
        };
        const overflow = () =>
            page.evaluate(
                () =>
                    document.documentElement.scrollWidth -
                    document.documentElement.clientWidth,
            );

        // A chat with a customer, as a workflow would have made it; and what they write in it,
        // told to whoever is watching as a workflow's step tells it
        const PROJECT = `App\\Models\\Project::where((new App\\Models\\Project)->getRouteKeyName(), '${project}')->firstOrFail()`;
        const customer = (title, service, status) =>
            tinker(
                `$p = ${PROJECT}; $r = App\\Models\\Chat\\ChatRoom::startGroup($p, $p->members()->firstOrFail(), '${title}'); $r->forceFill(['link' => ['service' => '${service}', 'connection' => 'none', 'chat' => 'U1']])->save(); $r->becomeCustomers(); $r->forceFill(['status' => '${status}'])->save(); echo $r->ref_id;`,
            );
        const hears = (room, words) =>
            tinker(
                `$r = App\\Models\\Chat\\ChatRoom::where('ref_id', '${room}')->firstOrFail(); $m = $r->messages()->create(['role' => 'workflow', 'content' => '${words}', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]); broadcast(new App\\Events\\ChatMessagePosted($r, $m)); echo $m->id;`,
            );

        await open(page, `${base}/p/${project}/inbox`);
        check(
            'a project with none says how they come in',
            await page.getByText('No chats with customers yet').isVisible(),
        );

        // --------------------------------------------------- Where they stand
        const ada = customer('Ada on LINE', 'line', 'open');
        const bo = customer('Bo on WhatsApp', 'whatsapp', 'waiting');
        hears(ada, 'Is it open today?');

        await open(page, `${base}/p/${project}/inbox`);
        await rows().first().waitFor();
        check(
            'those to be answered come first, with what was last said',
            (await rows().count()) === 1 &&
                /Ada on LINE/.test(await rows().first().innerText()) &&
                /Is it open today\?/.test(await rows().first().innerText()),
            (await rows().allInnerTexts()).join(' | ').replace(/\s+/g, ' '),
        );
        check(
            'each tab says how many it holds',
            /1/.test(await test('inbox-open').innerText()) &&
                /1/.test(await test('inbox-waiting').innerText()) &&
                /2/.test(await test('inbox-all').innerText()),
        );
        check(
            'the sidebar counts those still to be answered',
            (await badge().innerText()).trim() === '1',
        );

        await pick(test('inbox-channel'), 'whatsapp');
        await soon(
            'narrowed to one service, nothing of it is open',
            async () =>
                (await rows().count()) === 0 &&
                (await test('inbox-none').isVisible()),
        );
        await test('inbox-waiting').click();
        await soon(
            'and its waiting one is found',
            async () =>
                (await rows().count()) === 1 &&
                /Bo on WhatsApp/.test(await rows().first().innerText()),
        );
        await pick(test('inbox-channel'), 'every');
        await test('inbox-open').click();
        await soon('back to the open ones', async () =>
            /Ada on LINE/.test((await rows().allInnerTexts()).join(' ')),
        );

        // ------------------------------------------------- Answering in one
        await rows().first().locator('a').click();
        await page.locator('[data-chat-desk]').waitFor();
        check(
            'a chat says where it stands under its name',
            /Open/.test(await test('desk-status').innerText()),
        );

        const write = async (words) => {
            await page.locator('[data-chat-input]').fill(words);
            await page.keyboard.press('Enter');
        };

        await test('chat-as-note').click();
        await write('She asked last week too');
        await page.locator('[data-team-notes] [data-chat-note]').waitFor();
        check(
            "a note goes to the team's part, apart from the conversation, and leaves the chat to be answered",
            /She asked last week too/.test(
                await page.locator('[data-team-notes]').innerText(),
            ) &&
                !/She asked last week too/.test(
                    await page.locator('[data-chat-messages]').innerText(),
                ) &&
                /Open/.test(await test('desk-status').innerText()),
        );

        await test('chat-as-reply').click();
        await write('Yes, until six.');
        await soon('an answer leaves it waiting on them', async () =>
            /Waiting/.test(await test('desk-status').innerText()),
        );
        await soon(
            'and the sidebar has nothing left to answer',
            async () => (await badge().count()) === 0,
        );

        // ------------------------------------- What they asked, a ticket
        const asked = page
            .locator('[data-chat-theirs], [data-chat-workflow]')
            .filter({ hasText: 'Is it open today?' });
        await asked.hover();
        await asked.locator('[data-test="chat-promote"]').click();
        await test('promote-dialog').waitFor();
        check(
            'a ticket starts from what they said',
            (await test('promote-title').inputValue()) === 'Is it open today?',
        );
        await test('promote-feature').click();
        await test('promote-title').fill('Say opening hours in the menu');
        await test('promote-save').click();
        await test('promote-dialog').waitFor({ state: 'detached' });
        await soon(
            'the chat lists the ticket made of it, and notes it',
            async () =>
                (await test('desk-ticket').count()) === 1 &&
                /Made ticket #1/.test(
                    await page.locator('[data-team-notes]').innerText(),
                ),
        );

        await test('desk-ticket').click();
        await test('ticket-asked-in').waitFor();
        check(
            'the ticket says where it was asked',
            (await test('ticket-asked-in').innerText()).trim() ===
                'Ada on LINE',
        );
        await test('ticket-asked-in').click();
        await page.locator('[data-chat-desk]').waitFor();

        // ----------------------------------------------- A saved reply
        const input = page.locator('[data-chat-input]');

        await input.fill('Hi {name}, we open at 9.');
        await test('replies-open').click();
        await test('reply-new').click();
        check(
            'what is written is offered as a new saved reply',
            (await test('reply-body').inputValue()) ===
                'Hi {name}, we open at 9.',
        );
        await test('reply-title').fill('Hours');
        await test('reply-save').click();
        await test('reply').filter({ hasText: 'Hours' }).waitFor();
        await page.keyboard.press('Escape');
        await test('replies').waitFor({ state: 'detached' });

        await input.fill('');
        await test('replies-open').click();
        await test('reply').filter({ hasText: 'Hours' }).click();
        await soon(
            'picked, it is put in with who the chat is with',
            async () =>
                (await input.inputValue()) === 'Hi Ada on LINE, we open at 9.',
            async () => await input.inputValue(),
        );
        await input.fill('');

        // ------------------------------------------- Handing over and tagging
        await test('desk-assignee').click();
        await page.getByRole('option', { name: 'E2E Member' }).click();
        await soon('it is given to someone in the project', async () =>
            /E2E Member/.test(await test('desk-assignee').innerText()),
        );

        await test('desk-add-tag').click();
        await test('desk-tag-input').fill('VIP');
        await page.keyboard.press('Enter');
        await soon(
            'and tagged, in small letters',
            async () =>
                (await test('desk-tag').allInnerTexts()).join().trim() ===
                'vip',
        );

        await pick(test('desk-status'), 'closed');
        await soon('closed by hand', async () =>
            /Closed/.test(await test('desk-status').innerText()),
        );

        // ------------------------------------ One person on two services
        const about = () => page.locator('[data-chat-about]');

        await page.locator('[data-chat-about-toggle]').click();
        await test('contact-name').waitFor();
        await test('contact-same').click();
        await test('same-candidate').first().waitFor();
        await test('same-candidate')
            .filter({ hasText: 'Bo on WhatsApp' })
            .click();
        await soon(
            'two chats found to be one person show each other',
            async () =>
                (await test('contact-chat').count()) === 2 &&
                (await test('desk-also').count()) === 1 &&
                (await test('desk-also').innerText()).trim() === 'WhatsApp',
        );

        await about().locator('[data-chat-detail-add]').click();
        await about().getByLabel('Name', { exact: true }).fill('Phone');
        await about().getByLabel('Value').fill('081 234 5678');
        await about().locator('[data-chat-details-save]').click();
        await about().locator('[data-detail="phone"]').waitFor();
        await page.keyboard.press('Escape');
        await about().waitFor({ state: 'detached' });

        // Over on their other chat, what was noted about them is there too
        await test('desk-also').click();
        await soon('the link opens their other chat', async () =>
            page.url().endsWith(`/chats/${bo}`),
        );
        await page.locator('[data-chat-about-toggle]').click();
        await soon('with what was noted about them', async () =>
            /081 234 5678/.test(
                await about().locator('[data-detail="phone"]').innerText(),
            ),
        );

        await about().screenshot({ path: `${SHOTS}/inbox-contact.png` });

        // Not the same after all: apart again, the note staying with each
        await test('contact-apart').click();
        await soon(
            'and they are taken apart again',
            async () =>
                (await test('contact-chat').count()) === 1 &&
                (await test('desk-also').count()) === 0,
        );
        await page.keyboard.press('Escape');
        await about().waitFor({ state: 'detached' });

        // --------------------------------------- Arriving while it is open
        await open(page, `${base}/p/${project}/inbox`);
        await test('inbox-none').waitFor();
        hears(ada, 'One more thing');
        await soon(
            'what a customer sends opens their chat again, without asking',
            async () =>
                (await rows().count()) === 1 &&
                /One more thing/.test(await rows().first().innerText()),
        );
        check(
            'with its tag and who has it',
            (await page.locator('[data-room-tag]').innerText()) === 'vip' &&
                (await page.locator('[data-room-assignee]').count()) === 1,
        );
        await soon(
            'and the sidebar counts it',
            async () =>
                (await badge().count()) === 1 &&
                (await badge().innerText()).trim() === '1',
        );
        await page.screenshot({ path: `${SHOTS}/inbox.png` });

        // ------------------------------------------------------ On a phone
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, `${base}/p/${project}/inbox`);
        await rows().first().waitFor();
        check(
            'the inbox fits a phone',
            (await overflow()) <= 0,
            `${await overflow()}px too wide`,
        );
        await page.screenshot({ path: `${SHOTS}/inbox-phone.png` });

        await rows().first().locator('a').click();
        await page.locator('[data-chat-desk]').waitFor();
        const send = await page
            .locator('button[aria-label="Send"]')
            .boundingBox();
        check(
            'a chat fits it too, with Send in reach',
            (await overflow()) <= 0 &&
                send.x + send.width <= 390 &&
                send.y + send.height <= 844,
            JSON.stringify({ overflow: await overflow(), send }),
        );
        await page.screenshot({ path: `${SHOTS}/inbox-phone-chat.png` });

        // One part at a time on a phone: the conversation, or the team's
        await test('chat-part-team').click();
        await soon(
            "the team's part takes the screen, the conversation out of the way",
            async () =>
                (await page.locator('[data-team-notes]').isVisible()) &&
                !(await page.locator('[data-chat-messages]').isVisible()),
        );
        await page.screenshot({ path: `${SHOTS}/inbox-phone-team.png` });
        await test('chat-part-customer').click();
        await page.locator('[data-chat-messages]').waitFor();

        // ------------------------------------- A service that answers for a day
        const says = () => page.locator('[data-chat-window]');

        hears(bo, 'Do you deliver?');
        await open(page, `${base}/chats/${bo}`);
        await says().waitFor();
        check(
            'WhatsApp says until when it takes an answer',
            /WhatsApp takes an answer until/.test(await says().innerText()),
            await says().innerText(),
        );
        tinker(
            `App\\Models\\Chat\\ChatMessage::where('chat_room_id', App\\Models\\Chat\\ChatRoom::where('ref_id', '${bo}')->value('id'))->update(['created_at' => now()->subDays(2)]); echo 'ok';`,
        );
        await reopen(page);
        await says().waitFor();
        check(
            'and says so once that has passed',
            /will not take an answer/.test(await says().innerText()),
            await says().innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/inbox-phone-late.png` });
    },
    { canvas: false },
);
