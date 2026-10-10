// One's own chat room, as it is while AI comes from workflows (CHAT_AGENTS
// off): made from the list, written in, named after what it was first about,
// still there after a reload -- and nothing offers to set up an agent.
//
// A model answering in a room is a workflow's: tests/e2e/workflow-steps.mjs
// (a chat answered by a workflow) and tests/e2e/workflow-ai.mjs (Ask AI).
import { runBoard, tinker } from './harness.mjs';

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
