// One's own chat room, as it is while AI comes from workflows (CHAT_AGENTS
// off): made from the list, written in, named after what it was first about,
// still there after a reload -- and nothing offers to set up an agent.
//
// A model answering in a room is a workflow's: tests/e2e/workflow-steps.mjs
// (a chat answered by a workflow) and tests/e2e/workflow-ai.mjs (Ask AI).
import { writeFileSync } from 'node:fs';
import { SHOTS, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const SAID = `Note to self ${RUN}: buy coffee`;

await runBoard(
    '/chats',
    async ({ page, check, afterwards }) => {
        const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
        afterwards(() =>
            tinker(
                `${user}->chatRooms()->where('title', 'like', 'Note to self ${RUN}%')->delete();`,
            ),
        );

        check(
            'the list makes a chat room, not an AI chat',
            (await page.locator('[data-new-room]').innerText()).includes(
                'New Chat Room',
            ) && (await page.getByText('Personal AI Chats').count()) === 0,
        );

        await page.locator('[data-new-room]').click();
        await page.waitForURL(/\/chats\/\w+/, { timeout: 30000 });
        await page.waitForSelector('[data-chat-input]');
        check(
            'a new room offers no agent to set up',
            (await page.locator('[data-chat-no-agent]').count()) === 0 &&
                (await page.getByRole('button', { name: /agent/i }).count()) ===
                    0,
        );

        await page.locator('[data-chat-input]').fill(SAID);
        await page.locator('[data-chat-input]').press('Enter');
        await page.waitForSelector('[data-chat-user]');
        check('what was said appears at once', true);
        await page.waitForTimeout(800);
        check(
            'and nothing answers it',
            (await page.locator('[data-chat-assistant]').count()) === 0 &&
                (await page.locator('[data-chat-streaming]').count()) === 0,
        );

        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('[data-chat-user]');
        check(
            'after a reload it is still there',
            (await page.locator('[data-chat-user]').count()) === 1,
        );
        check(
            'and the room is named after it',
            (await page.locator('header').first().innerText()).includes(
                `Note to self ${RUN}`,
            ),
        );

        // --- emoji: chosen from a list, put where the cursor was ---
        const input = page.locator('[data-chat-input]');
        const picker = page.locator('[data-chat-emoji-picker]');
        await page.evaluate(() => localStorage.removeItem('chat.emoji.recent'));
        await input.fill('Well done');
        await input.evaluate((box) => box.setSelectionRange(4, 4));
        await page.locator('[data-chat-emoji]').click();
        await picker.waitFor();
        await picker.locator('[data-emoji="😀"]').click();
        await picker.getByRole('tab', { name: 'People' }).click();
        await picker.locator('[data-emoji="👍"]').click();
        check(
            'emoji chosen go where the cursor was, one after another, the list still open',
            (await input.inputValue()) === 'Well😀👍 done' &&
                (await picker.count()) === 1,
            await input.inputValue(),
        );
        await page.keyboard.press('Escape');
        await picker.waitFor({ state: 'detached' });
        await page.keyboard.type('!');
        check(
            'the list closed, writing carries on after them',
            (await input.inputValue()) === 'Well😀👍! done' &&
                (await picker.count()) === 0,
            await input.inputValue(),
        );
        await input.press('Enter');
        await page
            .locator('[data-chat-user]', { hasText: 'Well😀👍! done' })
            .waitFor();

        await page.locator('[data-chat-emoji]').click();
        const lately = await picker
            .locator('[role="tabpanel"] [data-emoji]')
            .evaluateAll((all) => all.map((one) => one.dataset.emoji).join(''));
        check(
            'the ones used lately are offered first, the last first',
            lately === '👍😀' &&
                (await picker
                    .getByRole('tab', { name: 'Lately' })
                    .getAttribute('aria-selected')) === 'true',
            lately,
        );
        await picker.locator('[data-emoji="👍"]').click();
        await page.keyboard.press('Escape');
        await picker.waitFor({ state: 'detached' });
        await input.press('Enter');
        const large = page.locator('[data-chat-emoji-only]');
        await large.waitFor();
        const sizes = await page.evaluate(() => ({
            emoji: parseFloat(
                getComputedStyle(
                    document.querySelector('[data-chat-emoji-only]'),
                ).fontSize,
            ),
            words: parseFloat(
                getComputedStyle(
                    document.querySelector(
                        '[data-chat-user] p:not([data-chat-emoji-only])',
                    ),
                ).fontSize,
            ),
        }));
        check(
            'a message that is only an emoji is drawn large, with nothing round it',
            (await large.count()) === 1 &&
                (await large.innerText()).trim() === '👍' &&
                sizes.emoji > sizes.words * 2,
            JSON.stringify(sizes),
        );
        await page.screenshot({ path: `${SHOTS}/chat-emoji.png` });

        // --- pictures sent one after another are drawn as one ---
        const PIXEL =
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        const pictures = [1, 2, 3].map(
            (n) => `${SHOTS}/e2e-album-${RUN}-${n}.png`,
        );
        pictures.forEach((path) =>
            writeFileSync(path, Buffer.from(PIXEL, 'base64')),
        );
        afterwards(() =>
            tinker(
                `${user}->driveFiles()->where('name', 'like', 'e2e-album-${RUN}-%')->get()->each->delete();`,
            ),
        );
        // Sent, and landed: the page takes one message at a time
        const sendPicture = async (path, words = '') => {
            const before = await page.locator('[data-chat-file]').count();

            await page.locator('[data-chat-file-input]').setInputFiles(path);
            await page.locator('[data-chat-attached]').waitFor();
            await input.fill(words);
            await input.press('Enter');
            await page
                .locator('[data-chat-file]')
                .nth(before)
                .waitFor({ timeout: 15000 });
            await page.locator('button[aria-label="Send"]').waitFor();
        };
        const album = page.locator('[data-chat-album]');

        await sendPicture(pictures[0], 'Three of them');
        await page
            .locator('[data-chat-mine]', { hasText: 'Three of them' })
            .locator('[data-chat-file]')
            .waitFor();
        check('one picture is shown as a picture', (await album.count()) === 0);
        await sendPicture(pictures[1]);
        await sendPicture(pictures[2]);
        await album.locator('[data-chat-file]').nth(2).waitFor();
        check(
            'pictures sent one after another are drawn as one, under what the first said',
            (await album.count()) === 1 &&
                (await album.locator('[data-chat-file]').count()) === 3 &&
                (await page
                    .locator('[data-chat-mine]', { hasText: 'Three of them' })
                    .locator('[data-chat-album]')
                    .count()) === 1,
            String(await album.locator('[data-chat-file]').count()),
        );
        await album.locator('[data-chat-file]').nth(1).click();
        const viewer = page.locator('[data-chat-picture]');
        const place = viewer.locator('[data-chat-picture-place]');
        await viewer.waitFor();
        const opened = await place.innerText();
        await page.keyboard.press('ArrowRight');
        check(
            'one opens larger, and the others are a step away',
            opened === '2 of 3' && (await place.innerText()) === '3 of 3',
            `${opened} -> ${await place.innerText()}`,
        );
        await page.keyboard.press('Escape');
        await viewer.waitFor({ state: 'detached' });
        await page.screenshot({ path: `${SHOTS}/chat-album.png` });

        const chatLink = page
            .locator('[data-sidebar="menu"]')
            .getByRole('link', { name: 'Chat', exact: true });
        await page.goto(new URL('/notes', page.url()).href, {
            waitUntil: 'networkidle',
        });
        check('your own sidebar has Chat', (await chatLink.count()) === 1);
    },
    { canvas: false },
);
