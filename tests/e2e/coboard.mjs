// Two people drawing on one board at once: each sees what the other draws
// and where their pointer is, undo takes back only one's own, and a change
// made from elsewhere (MCP) reaches the open board.
//
// Needs the collaboration server (the `collab` service) and the second account
// E2E_MEMBER (e2e-member@example.com, password "password"). The outside change
// is made with `docker exec` into E2E_APP_CONTAINER (zyrenn-app-1).
import { execFileSync } from 'node:child_process';
import {
    SHOTS,
    boardOn,
    deleteProject,
    runBoard,
    shareProject,
    signIn,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const APP_CONTAINER = process.env.E2E_APP_CONTAINER ?? 'zyrenn-app-1';
const RUN = Date.now().toString().slice(-6);

const tinker = (php) =>
    execFileSync(
        'docker',
        ['exec', APP_CONTAINER, 'php', 'artisan', 'tinker', '--execute', php],
        { encoding: 'utf8' },
    );

/** Waits until a board shows `count` items, and says how many it shows. */
const settle = async (board, count, within = 4000) => {
    const until = Date.now() + within;

    while (Date.now() < until && (await board.itemCount()) !== count) {
        await board.page.waitForTimeout(150);
    }

    return board.itemCount();
};

await runBoard(
    '/notes',
    async (owner) => {
        const { browser, page, check, afterwards } = owner;
        const { project, base } = await shareProject(
            page,
            `Coboard ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        await page.goto(`${base}/p/${project}/boards`);
        await page
            .getByRole('button', { name: /new board/i })
            .first()
            .click();
        await page.waitForURL(/\/boards\/\w+$/);
        const boardUrl = page.url();
        const ref = boardUrl.match(/\/boards\/(\w+)$/)[1];
        await owner.ready();
        await page.waitForFunction(
            () => /Saved/.test(document.body.innerText),
            null,
            { timeout: 10000 },
        );
        const start = await owner.itemCount();

        const { context, page: otherPage } = await signIn(
            browser,
            base,
            MEMBER,
        );
        afterwards(() => context.close());
        await otherPage.goto(boardUrl, { waitUntil: 'networkidle' });
        const member = { ...boardOn(otherPage), page: otherPage };
        await member.ready();
        await otherPage.waitForFunction(
            () => /Saved/.test(document.body.innerText),
            null,
            { timeout: 10000 },
        );

        check(
            'both open the same board',
            (await member.itemCount()) === start,
            `${start} items`,
        );

        // ------------------------------------------------ Each draws for both
        await owner.draw('rect', 0.2, 0.3);
        check(
            'a shape the owner draws appears for the member',
            (await settle(member, start + 1)) === start + 1,
        );

        await member.draw('ellipse', 0.6, 0.3);
        check(
            'and one the member draws appears for the owner',
            (await settle({ ...owner, page }, start + 2)) === start + 2,
        );

        // ---------------------------------------------- Where the other one is
        const box = owner.view.box;
        await page.mouse.move(
            box.x + box.width * 0.5,
            box.y + box.height * 0.6,
            { steps: 4 },
        );
        await otherPage.waitForTimeout(800);
        check(
            'the member sees the owner’s pointer, by name',
            (await otherPage
                .locator('[data-test="board-pointer"]', {
                    hasText: 'Test User',
                })
                .count()) === 1,
        );
        await otherPage.screenshot({ path: `${SHOTS}/coboard-1-member.png` });

        // ------------------------------------------- Undo is one's own only
        await otherPage.locator('[data-test="undo"]').click();
        check(
            'the member’s undo takes back only the member’s shape',
            (await settle(member, start + 1)) === start + 1 &&
                (await settle({ ...owner, page }, start + 1)) === start + 1,
        );

        await otherPage
            .locator('[data-test="board-title"]')
            .fill(`Site map ${RUN}`);
        await page.waitForTimeout(1200);
        check(
            'a title one gives it reaches the other',
            (await page.locator('[data-test="board-title"]').inputValue()) ===
                `Site map ${RUN}`,
        );

        // ------------------------------------------ Kept, for search and MCP
        await page.waitForTimeout(3500);
        const [title, count, hasState] = JSON.parse(
            tinker(
                `$b = App\\Models\\Board\\Board::where('ref_id', '${ref}')->first(); echo json_encode([$b->title, count($b->content['items'] ?? []), $b->ydoc !== null]);`,
            )
                .trim()
                .split('\n')
                .pop(),
        );
        check(
            'the app keeps the board as it now is',
            title === `Site map ${RUN}` && count === start + 1 && hasState,
            `${title} | ${count} items`,
        );

        // ---------------------------------------- A change from elsewhere, open
        tinker(
            `$b = App\\Models\\Board\\Board::where('ref_id', '${ref}')->first(); $b->update(['content' => ['items' => [['id' => 'mcp1', 'kind' => 'sticky', 'text' => 'From MCP ${RUN}', 'x' => 0, 'y' => 0, 'width' => 200, 'height' => 200]]]]);`,
        );
        check(
            'a board rewritten over MCP changes for everyone who has it open',
            (await settle({ ...owner, page }, 1)) === 1 &&
                (await settle(member, 1)) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/coboard-2-owner.png` });
    },
    { canvas: false },
);
