// What people make of a prototype, on its board: a viewer's comment shows as
// a pin on its screen and in the bar's count, opens to say what it says, is
// made a ticket and resolved; a comment left elsewhere comes in live; and the
// Plays panel lists who played it, the screens in order, and shows each
// screen's visits and missed taps on the screens.
import {
    SHOTS,
    deleteProject,
    open,
    runBoard,
    shareProject,
    tinker,
    until,
} from './harness.mjs';

const EMAIL = process.env.E2E_EMAIL ?? 'test@example.com';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

const screen = (id, text, x) => ({
    id,
    kind: 'frame',
    x,
    y: 0,
    width: 390,
    height: 844,
    text,
    fill: '#ffffff',
});
const ITEMS = [screen('home', 'Home', 0), screen('menu', 'Menu', 470)];

await runBoard(
    '/dashboard',
    async (b) => {
        const { page, check, soon, afterwards, screenOf } = b;
        const { project, base } = await shareProject(
            page,
            `Feedback ${RUN}`,
            MEMBER,
            'viewer',
        );
        afterwards(() => deleteProject(page, base, project));

        const viewer = `App\\Models\\User::where('email', '${MEMBER}')->firstOrFail()`;
        const board = tinker(
            `$b = (new App\\Models\\Board\\Board)->ownedBy(App\\Models\\Project::where('ref_id', '${project}')->firstOrFail(), App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()); $b->title = 'Coffee order'; $b->kind = App\\Models\\Board\\Board::PROTOTYPE; $b->prototype = ['device' => 'phone', 'start' => 'home']; $b->content = ['items' => json_decode('${JSON.stringify(ITEMS)}', true)]; $b->save(); echo $b->ref_id;`,
        );
        // The viewer's comment and play, behind the scenes: the suite is
        // about how the board shows them
        const say = (body, frame, x, y) =>
            tinker(
                `$b = App\\Models\\Board\\Board::where('ref_id', '${board}')->firstOrFail(); $c = new App\\Models\\Prototype\\PrototypeComment(['frame_id' => '${frame}', 'x' => ${x}, 'y' => ${y}, 'body' => '${body}']); $c->board_id = $b->id; $c->user_id = ${viewer}->id; $c->save(); broadcast(new App\\Events\\PrototypeCommentChanged($b, $c)); echo $c->ref_id;`,
            );
        say('Make this say Order now', 'home', 0.5, 0.25);
        tinker(
            `$p = new App\\Models\\Prototype\\PrototypePlay; $p->board_id = App\\Models\\Board\\Board::where('ref_id', '${board}')->value('id'); $p->user_id = ${viewer}->id; $p->started_at = now()->subMinute(); $p->ended_at = now()->subSeconds(20); $p->path = [['screen' => 'home', 'at' => 0], ['screen' => 'menu', 'at' => 12.5], ['screen' => 'home', 'at' => 30]]; $p->misses = [['screen' => 'home', 'x' => 0.2, 'y' => 0.5, 'at' => 4], ['screen' => 'home', 'x' => 0.8, 'y' => 0.9, 'at' => 6]]; $p->save(); echo 'ok';`,
        );

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const count = async () =>
            Number(await test('prototype-comment-count').innerText());

        await open(page, `${base}/prototypes/${board}`);
        await b.ready();

        // ------------------------------------------------------- the pin
        await soon(
            'the bar counts the open comment',
            async () => (await count()) === 1,
        );
        const pin = test('comment-pin');
        await pin.waitFor();
        // Measured together, again and again until they agree: the board
        // may still be settling its camera, and the pin follows it
        const placing = async () => {
            const from = await screenOf(0, 0);
            const to = await screenOf(390, 844);
            const spot = await pin.boundingBox();

            return { from, to, spot };
        };
        const onItsSpot = ({ from, to, spot }) =>
            !!spot &&
            Math.abs(spot.x + 4 - (from.x + to.x) / 2) < 6 &&
            Math.abs(spot.y + spot.height - (from.y + (to.y - from.y) * 0.25)) <
                6;
        await soon(
            'its pin sits on the spot on its screen it was left at',
            async () => onItsSpot(await placing()),
            async () => JSON.stringify(await placing()),
        );

        await pin.click();
        const card = test('comment-card');
        await card.waitFor();
        check(
            'opened, it says what was said and by whom',
            /Make this say Order now/.test(await card.innerText()) &&
                /E2E Member/.test(await card.innerText()),
        );

        // ------------------------------------------- a ticket, resolved
        await test('comment-make-ticket').click();
        await soon('made a ticket, the card says which', async () =>
            /Ticket #\d+/.test(await card.innerText()),
        );
        const filed = tinker(
            `echo App\\Models\\Ticket\\Ticket::whereHas('project', fn ($q) => $q->where('ref_id', '${project}'))->value('title');`,
        );
        check(
            'and the ticket is filed on the project',
            filed === 'Make this say Order now',
            filed,
        );

        await test('comment-resolve').click();
        await soon(
            'resolved, it leaves the count',
            async () => (await count()) === 0,
        );
        await page.keyboard.press('Escape');
        await soon('and its pin goes', async () => (await pin.count()) === 0);

        await test('prototype-comments').click();
        await page.locator('[data-pins="all"]').click();
        await soon(
            'showing resolved ones too brings it back, greyed',
            async () =>
                (await page.locator('.comment-pin.is-resolved').count()) === 1,
        );
        await test('prototype-comments').click();
        await page.locator('[data-pins="open"]').click();

        // ------------------------------------------------------- live
        say('The menu needs prices', 'menu', 0.3, 0.6);
        await soon(
            'a comment left in Play comes in live',
            async () => (await count()) === 1 && (await pin.count()) === 1,
        );

        // ------------------------------------------------------- plays
        await test('prototype-plays').click();
        const row = test('play-row');
        await row.waitFor();
        check(
            'the Plays panel lists who played it and the screens in order',
            /E2E Member/.test(await row.innerText()) &&
                (
                    await row
                        .locator('[data-test="play-path"] span')
                        .allInnerTexts()
                ).join(' > ') === 'Home > Menu > Home',
            await row.innerText(),
        );
        await soon(
            "and shows each screen's visits and missed taps on the screens",
            async () =>
                (await test('play-miss').count()) === 2 &&
                (await page
                    .locator('[data-test="screen-visits"][data-screen="home"]')
                    .innerText()) === '2 visits',
        );
        await page.screenshot({ path: `${SHOTS}/prototype-feedback.png` });

        await page.keyboard.press('Escape');
        await soon(
            'closed, the marks go',
            async () => (await test('play-miss').count()) === 0,
        );

        // ------------------------------------------------- in Play itself
        const me = `App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()`;
        const plays = () =>
            Number(
                tinker(
                    `echo App\\Models\\Prototype\\PrototypePlay::where('user_id', ${me}->id)->count();`,
                ),
            );
        const before = plays();

        await open(page, `${base}/prototypes/${board}/play`);
        await test('play-screen').waitFor();
        // Settled on its start screen: the move in has finished
        await until(async () => (await test('play-view').count()) === 1);
        await until(async () => plays() === before + 1);
        check('opening Play starts a play', plays() === before + 1);

        const layer = test('play-comments');
        const spotAt = async (x, y) => {
            const area = await layer.boundingBox();

            return { x: area.x + area.width * x, y: area.y + area.height * y };
        };

        // A tap on nothing, before anything else: a missed tap
        const empty = await spotAt(0.3, 0.4);
        await page.mouse.click(empty.x, empty.y);

        // Comment mode: a tap leaves a pin there, and a box to say why
        await test('play-comment').click();
        await soon('Comment mode marks the screen', async () =>
            /is-commenting/.test(await layer.getAttribute('class')),
        );
        const there = await spotAt(0.5, 0.5);
        await page.mouse.click(there.x, there.y);
        await test('comment-body').fill('Bigger photo here');
        await test('comment-save').click();
        await soon(
            'a comment said in Play pins itself where it was left',
            async () =>
                (await layer.locator('[data-test="comment-pin"]').count()) ===
                    1 &&
                !/is-commenting/.test(await layer.getAttribute('class')),
        );
        const kept = tinker(
            `$c = App\\Models\\Prototype\\PrototypeComment::where('body', 'Bigger photo here')->firstOrFail(); echo json_encode([$c->frame_id, round($c->x, 1), round($c->y, 1)]);`,
        );
        check(
            'and is kept on that screen, at that spot',
            kept === '["home",0.5,0.5]',
            kept,
        );
        await page.screenshot({ path: `${SHOTS}/prototype-feedback-play.png` });

        await test('play-pins').click();
        await soon(
            'the pins can be hidden',
            async () =>
                (await layer.locator('[data-test="comment-pin"]').count()) ===
                0,
        );
        await test('play-pins').click();

        // On to the next screen, then away: what was seen is kept
        await page.keyboard.press('ArrowRight');
        await soon(
            'going on in Play shows the next screen',
            async () =>
                (await test('play-screen').getAttribute('data-screen')) ===
                'menu',
        );
        await open(page, `${base}/prototypes/${board}`);
        const recorded = () =>
            JSON.parse(
                tinker(
                    `$p = App\\Models\\Prototype\\PrototypePlay::where('user_id', ${me}->id)->latest('id')->firstOrFail(); echo json_encode(['path' => array_column($p->path, 'screen'), 'misses' => count($p->misses), 'ended' => $p->ended_at !== null]);`,
                ),
            );
        await soon(
            'leaving keeps the screens seen, in order, and the missed tap',
            async () => {
                const play = recorded();

                return (
                    play.path.join(' > ') === 'home > menu' &&
                    play.misses === 1 &&
                    play.ended
                );
            },
            async () => JSON.stringify(recorded()),
            { timeout: 15000 },
        );
    },
    { canvas: false },
);
