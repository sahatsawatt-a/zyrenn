// The "Show me" tours on each topic of the Guide past the first two
// (guide-tours.mjs has those): each is played from its topic to its end, at a
// phone's width as well as a laptop's, and what one makes is there afterwards.
import { open, runBoard, tinker } from './harness.mjs';

const email = process.env.E2E_EMAIL ?? 'test@example.com';
const user = `App\\Models\\User::where('email', '${email}')->first()`;

// [tour, topic], in the order they are played
const TOURS = [
    ['shapes', 'boards'],
    ['table', 'tables'],
    ['places', 'maps-and-trips'],
    ['drive', 'drive'],
    ['chat', 'chat'],
    ['projects', 'projects'],
    ['workflow', 'workflows'],
    ['settings', 'settings'],
];

await runBoard(
    '/guide/getting-started',
    async ({ page, check, soon, afterwards }) => {
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const base = new URL(page.url()).origin;

        afterwards(() =>
            tinker(
                `${user}->boards()->where('title', 'Shapes from the tour')->get()->each->delete(); ${user}->tables()->where('title', 'A table from the tour')->get()->each->delete(); echo 'ok';`,
            ),
        );

        const whereItStopped = async () =>
            (await test('tour-problem').count())
                ? test('tour-problem').textContent()
                : page.url();

        const playAll = async (width) => {
            for (const [tour, topic] of TOURS) {
                await open(page, `${base}/guide/${topic}`);
                await page.evaluate(() =>
                    sessionStorage.setItem('zyrenn:tour-pace', '0.15'),
                );
                await test(`tour-play-${tour}`).click();
                await soon(
                    `at ${width}px, "${tour}" on ${topic} plays to its end`,
                    async () => test('tour-done').isVisible(),
                    whereItStopped,
                    { timeout: 30000 },
                );
                await test('tour-stop').click();
                await test('tour-card').waitFor({ state: 'detached' });
            }
        };

        await playAll(1500);

        await soon(
            'the board tour leaves a rectangle and a sticky joined by a connector',
            async () =>
                tinker(
                    `$items = collect(${user}->boards()->where('title', 'Shapes from the tour')->latest('id')->first()?->content['items'] ?? []); echo $items->pluck('kind')->sort()->values()->join(',');`,
                ) === 'arrow,rect,sticky',
            () =>
                tinker(
                    `echo json_encode(collect(${user}->boards()->where('title', 'Shapes from the tour')->latest('id')->first()?->content['items'] ?? [])->pluck('kind'));`,
                ),
            { timeout: 10000 },
        );
        check(
            'the table tour leaves a table of its name',
            tinker(
                `echo ${user}->tables()->where('title', 'A table from the tour')->count();`,
            ) === '1',
        );

        await page.setViewportSize({ width: 390, height: 844 });

        await playAll(390);
    },
    { canvas: false },
);
