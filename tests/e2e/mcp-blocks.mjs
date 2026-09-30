// An AI changing one block of a note over MCP while someone types in another:
// the change arrives live, and the typing is kept.
//
// Uses the running app's own MCP endpoint (/mcp/user) with a token made for
// the test account through `docker exec` into E2E_APP_CONTAINER (zyrenn-app-1),
// and taken away again after.
import { execFileSync } from 'node:child_process';
import { SHOTS, runBoard } from './harness.mjs';

const APP_CONTAINER = process.env.E2E_APP_CONTAINER ?? 'zyrenn-app-1';
const RUN = Date.now().toString().slice(-6);
const TOKEN_NAME = `e2e mcp-blocks ${RUN}`;

const tinker = (php) =>
    execFileSync(
        'docker',
        ['exec', APP_CONTAINER, 'php', 'artisan', 'tinker', '--execute', php],
        { encoding: 'utf8' },
    )
        .trim()
        .split('\n')
        .pop();

await runBoard(
    '/notes',
    async ({ page, check, afterwards, ready, draw, screenOf, itemCount }) => {
        const base = new URL(page.url()).origin;
        const token = tinker(
            `echo App\\Models\\User::where('email', 'test@example.com')->first()->createToken('${TOKEN_NAME}', ['mcp'])->plainTextToken;`,
        );
        afterwards(() =>
            tinker(
                `App\\Models\\User::where('email', 'test@example.com')->first()->tokens()->where('name', '${TOKEN_NAME}')->delete();`,
            ),
        );

        let call = 0;
        const mcp = async (name, args) => {
            const response = await fetch(`${base}/mcp/user`, {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${token}`,
                    'Content-Type': 'application/json',
                    Accept: 'application/json, text/event-stream',
                },
                body: JSON.stringify({
                    jsonrpc: '2.0',
                    id: ++call,
                    method: 'tools/call',
                    params: { name, arguments: args },
                }),
            });

            return (await response.json()).result;
        };

        // ---------------------------------------- A note written in the editor
        await page
            .getByRole('button', { name: /new note/i })
            .first()
            .click();
        await page.waitForURL(/\/notes\/\w+$/);
        const ref = page.url().match(/\/notes\/(\w+)$/)[1];
        afterwards(() =>
            tinker(
                `App\\Models\\Note\\Note::where('ref_id', '${ref}')->first()?->delete();`,
            ),
        );
        await page.waitForFunction(
            () => /Saved/.test(document.body.innerText),
            null,
            { timeout: 10000 },
        );

        await page.getByPlaceholder('Untitled').fill(`Blocks ${RUN}`);
        const editor = page.locator('.ProseMirror');
        await editor.click();
        await page.keyboard.type('First paragraph.');
        await page.keyboard.press('Enter');
        await page.keyboard.type('Second paragraph.');
        await page.keyboard.press('Enter');
        await page.keyboard.type('Third paragraph');
        await page.waitForTimeout(800);

        // --------------------------------------------- MCP reads it as blocks
        const read = await mcp('get-note', { ref_id: ref });
        const outline = read.structuredContent?.outline ?? [];
        const onPage = await page
            .locator('.ProseMirror > p[data-id]')
            .evaluateAll((nodes) => nodes.map((node) => node.dataset.id));
        check(
            'get-note gives the note as blocks, with the ids the editor gave them',
            outline.length === 3 &&
                outline.every((block, at) => block.id === onPage[at]),
            outline.map((block) => `${block.id}:${block.text}`).join(' | '),
        );

        // ------------------- One block changed while another is being typed in
        const typing = page.keyboard.type(' is still being typed', {
            delay: 60,
        });
        await page.waitForTimeout(300);
        const edited = await mcp('edit-note', {
            ref_id: ref,
            changes: [
                {
                    op: 'replace',
                    block: outline[0].id,
                    markdown: `First, rewritten by MCP ${RUN}.`,
                },
            ],
        });
        await typing;
        await page.waitForTimeout(1200);

        const text = await editor.innerText();
        check(
            'edit-note answers with the block it changed',
            edited.structuredContent?.changed?.[0] === outline[0].id,
        );
        check(
            'the change arrives in the open note',
            text.includes(`First, rewritten by MCP ${RUN}.`) &&
                !text.includes('First paragraph.'),
        );
        check(
            'and what was being typed elsewhere in it is kept',
            text.includes('Second paragraph.') &&
                text.includes('Third paragraph is still being typed'),
            text.replace(/\s+/g, ' ').slice(0, 120),
        );
        await page.screenshot({ path: `${SHOTS}/mcp-blocks.png` });

        const answerSize = JSON.stringify(edited).length;
        check(
            'and its answer is small',
            answerSize < 800,
            `${answerSize} bytes`,
        );

        // --------------------------------------------- A board, drawn on
        await page.goto(`${base}/boards`);
        await page
            .getByRole('button', { name: /new board/i })
            .first()
            .click();
        await page.waitForURL(/\/boards\/\w+$/);
        const board = page.url().match(/\/boards\/(\w+)$/)[1];
        afterwards(() =>
            tinker(
                `App\\Models\\Board\\Board::where('ref_id', '${board}')->first()?->delete();`,
            ),
        );
        await ready();
        await page.waitForFunction(
            () => /Saved/.test(document.body.innerText),
            null,
            { timeout: 10000 },
        );
        await draw('rect', 0.2, 0.3);
        await draw('rect', 0.5, 0.3);
        await page.waitForTimeout(800);

        const outlined = (
            await mcp('get-board', { ref_id: board, outline: true })
        ).structuredContent;
        const [first, second] = outlined.items;
        check(
            'get-board gives the board in outline, without colours',
            outlined.items.length === 2 &&
                !('fill' in first) &&
                typeof first.x === 'number',
        );

        // --------- One shape dragged while MCP changes another and adds a sticky
        const grab = await screenOf(
            second.x + second.width / 2,
            second.y + second.height / 2,
        );
        await page.mouse.move(grab.x, grab.y);
        await page.mouse.down();
        await page.mouse.move(grab.x + 60, grab.y + 40, { steps: 6 });
        const changed = await mcp('update-board', {
            ref_id: board,
            update_items: [{ id: first.id, text: `From MCP ${RUN}` }],
            add_items: [{ kind: 'sticky', text: `Added by MCP ${RUN}` }],
        });
        await page.mouse.move(grab.x + 140, grab.y + 90, { steps: 6 });
        await page.mouse.up();
        await page.waitForTimeout(2500);

        const after = (await mcp('get-board', { ref_id: board, outline: true }))
            .structuredContent.items;
        const byId = Object.fromEntries(after.map((item) => [item.id, item]));
        check(
            'update-board answers with what it added and changed, not the board',
            changed.structuredContent?.changed?.[0] === first.id &&
                changed.structuredContent?.added?.length === 1 &&
                !('items' in (changed.structuredContent ?? {})),
        );
        check(
            'the changed shape and the new sticky are on the open board',
            byId[first.id]?.text === `From MCP ${RUN}` &&
                after.some((item) => item.text === `Added by MCP ${RUN}`) &&
                (await itemCount()) === 3,
            `${after.length} items, ${await itemCount()} drawn`,
        );
        check(
            'and the shape being dragged meanwhile went where it was dragged',
            byId[second.id] !== undefined && byId[second.id].x > second.x + 50,
            `${second.x} → ${byId[second.id]?.x}`,
        );
        await page.screenshot({ path: `${SHOTS}/mcp-board.png` });
    },
    { canvas: false },
);
