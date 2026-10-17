// What is done on a shared board before its document has arrived is kept:
// with the collaboration server's answer held back, a shape drawn and one
// taken away while the board still says "Connecting…" are still so once the
// shared board comes, and are what the app keeps.
import { boardKept, open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const SAVED = { timeout: 15000 };
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards, draw, screenOf } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Early ${RUN}', 'items' => [
                ['id' => 'keep', 'kind' => 'rect', 'text' => 'Kept', 'x' => 0, 'y' => 0],
                ['id' => 'drop', 'kind' => 'rect', 'text' => 'Taken away', 'x' => 300, 'y' => 0]]]);
             echo ${user}->boards()->where('title', 'Early ${RUN}')->value('ref_id');`.replace(
                /\n\s*/g,
                ' ',
            ),
        );
        afterwards(() =>
            tinker(
                `App\\Models\\Board\\Board::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        // The collaboration server's messages to the page wait until let go
        let letGo = () => {};
        await page.routeWebSocket(/\/collab/, (socket) => {
            const server = socket.connectToServer();
            const held = [];
            let holding = true;

            server.onMessage((message) =>
                holding ? held.push(message) : socket.send(message),
            );
            letGo = () => {
                holding = false;
                held.splice(0).forEach((message) => socket.send(message));
            };
        });

        await open(page, `${base}/boards/${ref}`);
        await b.ready();

        const status = page.locator('[data-test="board-status"]');
        check(
            'the board opens before its shared document has come',
            /Connecting/.test(await status.innerText()),
            await status.innerText(),
        );

        // A shape drawn, and one of the page's own taken away, meanwhile
        await draw('rect', 0.55, 0.55);
        const gone = await screenOf(400, 70);
        await page.mouse.click(gone.x, gone.y);
        await page.keyboard.press('Delete');
        await page.keyboard.press('Escape');
        check(
            'both are done while it still says Connecting…',
            /Connecting/.test(await status.innerText()),
            await status.innerText(),
        );

        letGo();
        await soon(
            'once the shared board comes, it says Saved',
            async () => /Saved/.test(await status.innerText()),
            () => status.innerText(),
            SAVED,
        );
        await soon(
            'and the shape drawn and the one taken away stay so, kept by the app',
            async () => {
                const items = Object.values(await boardKept(page, ref));

                return (
                    items.length === 2 &&
                    items.some((item) => item.id === 'keep') &&
                    !items.some((item) => item.id === 'drop')
                );
            },
            async () =>
                JSON.stringify(
                    Object.values(await boardKept(page, ref)).map((item) => [
                        item.id,
                        item.kind,
                        item.text,
                    ]),
                ),
            SAVED,
        );
    },
    { canvas: false },
);
