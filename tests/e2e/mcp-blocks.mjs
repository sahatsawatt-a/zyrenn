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

        // ------------------------------------ Live values in a note, {{ … }}
        const TABLE = `Values ${RUN}`;
        await mcp('create-table', {
            title: TABLE,
            parameters: { rate: 5 },
            columns: [
                { label: 'CNY', type: 'numeric' },
                { label: 'THB', type: 'formula', expression: 'cny * rate' },
            ],
            rows: [{ CNY: 100 }],
        });
        afterwards(() =>
            tinker(
                `App\\Models\\Table\\Table::where('title', '${TABLE}')->first()?->delete();`,
            ),
        );
        const valued = await mcp('create-note', {
            title: `Values ${RUN}`,
            markdown: `Spent {{ sum(table("${TABLE}").thb) }} baht`,
        });
        const valueNote = valued.structuredContent.ref_id;
        afterwards(() =>
            tinker(
                `App\\Models\\Note\\Note::where('ref_id', '${valueNote}')->first()?->delete();`,
            ),
        );

        await page.goto(`${base}/notes/${valueNote}`, {
            waitUntil: 'networkidle',
        });
        const chip = page.locator('[data-test="note-value"]');
        await chip.first().waitFor();
        await page.waitForTimeout(800);
        check(
            'a live value shows what its formula comes to',
            (await chip.first().innerText()).trim() === '500',
            await chip.first().innerText(),
        );

        // The table changes elsewhere; the open note follows without reloading
        await mcp('update-table', {
            ref_id: (await mcp('list-tables', { search: TABLE }))
                .structuredContent.tables[0].ref_id,
            update_rows: [{ id: 1, values: { CNY: 200 } }],
        });
        await page.waitForTimeout(2500);
        check(
            'and follows the table it reads as it changes',
            (await chip.first().innerText()).trim() === '1,000',
            await chip.first().innerText(),
        );

        // Typed out in full, {{ … }} becomes a value
        await page.locator('.ProseMirror p').first().click();
        await page.keyboard.press('End');
        await page.keyboard.type(' and {{ 2 * 21 }}');
        await page.waitForTimeout(1500);
        const line = (await page.locator('.ProseMirror p').first().innerText())
            .replace(/\s+/g, ' ')
            .trim();
        check(
            'a value typed out becomes one, braces and all',
            (await chip.count()) === 2 && line === 'Spent 1,000 baht and 42',
            line,
        );
        // Clicked, a value opens its formula to change
        await chip.nth(1).click();
        await page
            .locator('[data-test="note-value-expression"]')
            .fill('6 * 7 + 1');
        await page.locator('[data-test="note-value-save"]').click();
        await page.waitForTimeout(1200);
        check(
            'a value clicked can be given another formula',
            (await chip.nth(1).innerText()).trim() === '43',
            await chip.nth(1).innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/note-values.png` });

        await page.waitForTimeout(2500);
        const valuesRead = (await mcp('get-note', { ref_id: valueNote }))
            .structuredContent;
        check(
            'get-note gives the formulas, and what each comes to',
            valuesRead.markdown?.trim() ===
                `Spent {{ sum(table("${TABLE}").thb) }} baht and {{ 6 * 7 + 1 }}` &&
                valuesRead.values?.['6 * 7 + 1'] === '43' &&
                valuesRead.values?.[`sum(table("${TABLE}").thb)`] === '1000',
            JSON.stringify(valuesRead.values),
        );
    },
    { canvas: false },
);
