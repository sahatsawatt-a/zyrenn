// A sticky holds every word written on it: its paper grows to them -- one
// kept too short from before, one sent over MCP, one typed into -- so none
// are left out on a dark board, and what is chosen is the paper drawn.
import {
    SHOTS,
    boardKept,
    open,
    reopen,
    runBoard,
    tinker,
    until,
} from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';
const LONG =
    'Every customer, by name, with their plan and status. Tap one to see it, or add one with the button at the top. A trial ends after two weeks, unless they move to Pro.';

await runBoard(
    '/demo/konva',
    async (b) => {
        const { page, check, soon, afterwards, screenOf } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;

        // Three stickies in the hand font. "old" is put back to the height a
        // board kept before its paper grew, as the samples' were
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Stickies ${RUN}', 'items' => [
                ['id' => 'old', 'kind' => 'sticky', 'text' => '${LONG}', 'x' => 0, 'y' => 0, 'width' => 220, 'height' => 160, 'fontSize' => 20, 'fontFamily' => 'hand'],
                ['id' => 'sent', 'kind' => 'sticky', 'text' => '${LONG}', 'x' => 300, 'y' => 0, 'width' => 220, 'height' => 160, 'fontSize' => 20, 'fontFamily' => 'hand'],
                ['id' => 'typed', 'kind' => 'sticky', 'text' => 'Idea', 'x' => 600, 'y' => 0, 'width' => 220, 'height' => 160, 'fontSize' => 20, 'fontFamily' => 'hand']]]);
             $board = ${user}->boards()->where('title', 'Stickies ${RUN}')->sole();
             $content = $board->content;
             $content['items'] = array_map(fn ($item) => $item['id'] === 'old' ? [...$item, 'height' => 160] : $item, $content['items']);
             $board->forceFill(['content' => $content])->save();
             echo $board->ref_id;`.replace(/\n\s*/g, ' '),
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Board\\Board::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        await page.emulateMedia({ colorScheme: 'dark' });
        await open(page, `${base}/boards/${ref}`);
        await b.ready();
        // The panels put away: they float over the stickies' corner
        await page.locator('[data-test="layers-close"]').click();
        await page.locator('[data-test="inspector-close"]').click();

        // A sticky as drawn: how tall its paper is, and how tall it is with
        // its words -- what choosing it puts a box round
        const drawn = (id) =>
            page.evaluate((id) => {
                const group = window.Konva.stages[0].findOne(`#${id}`);
                const paper = group?.findOne('Rect');
                const words = group?.findOne('Text');

                return paper && words?.isVisible()
                    ? {
                          paper: Math.round(paper.height()),
                          whole: Math.round(
                              group.getClientRect({
                                  relativeTo: group,
                                  skipShadow: true,
                                  skipStroke: true,
                              }).height,
                          ),
                      }
                    : null;
            }, id);
        const holds = async (id) => {
            const now = await drawn(id);

            return now && now.paper > 160 && now.whole <= now.paper + 1;
        };
        const said = (id) => async () => JSON.stringify(await drawn(id));

        await soon(
            'a sticky kept shorter than its words is drawn as tall as they are',
            () => holds('old'),
            said('old'),
        );

        const kept = await boardKept(page, ref);
        check(
            'one sent over MCP too short is kept as tall as its words',
            kept.sent.height > 160 && (await holds('sent')),
            `${kept.sent.height} ${await said('sent')()}`,
        );
        await page.screenshot({ path: `${SHOTS}/sticky-fit-dark.png` });

        // Typed into until the words outgrow it: the paper grows with them
        const typed = await screenOf(710, 80);
        await page.mouse.dblclick(typed.x, typed.y);
        await page.locator('[data-test="text-editor"]').waitFor();
        await page.keyboard.press('End');
        await page.keyboard.type(`. ${LONG}`);
        await page.keyboard.press('Enter');
        await soon(
            'a sticky typed into grows to hold its words',
            () => holds('typed'),
            said('typed'),
        );
        let grown;
        await soon(
            'and is kept that tall',
            async () => {
                grown = (await boardKept(page, ref)).typed;

                return grown?.height > 160 && /customer/.test(grown.text);
            },
            () => JSON.stringify(grown?.height),
            { timeout: 15000 },
        );

        // What was chosen agrees with what is drawn: the box round it, on
        // the screen, as tall as its paper and the outline a chosen one has
        await page.mouse.click(typed.x, typed.y);
        const heights = () =>
            page.evaluate(() => {
                const stage = window.Konva.stages[0];
                const transformer = stage.findOne('Transformer');
                const paper = stage.findOne('#typed').findOne('Rect');

                return transformer?.nodes().length
                    ? [
                          transformer.findOne('.back').getClientRect().height,
                          paper.getClientRect({ skipShadow: true }).height,
                      ].map(Math.round)
                    : null;
            });
        const [box, paper] = await until(heights);
        check(
            'chosen, the box round it is the paper drawn',
            Math.abs(box - paper) <= 2,
            `${box} vs ${paper}`,
        );

        await reopen(page);
        await b.ready();
        await soon(
            'after a reload, each still holds its words',
            async () =>
                (await holds('old')) &&
                (await holds('sent')) &&
                (await holds('typed')),
            async () =>
                [
                    await said('old')(),
                    await said('sent')(),
                    await said('typed')(),
                ].join(' '),
        );
        await page.emulateMedia({ colorScheme: 'light' });
    },
    { width: 1200, height: 800 },
);
