// A prototype's board bar has nothing of slides in it: no New slide (its
// screens are added in its own bar, at the device's size) and no Present
// (Play does that), while a template and the shortcuts are still to hand --
// on a desktop and on a phone. A plain board keeps all of it.
import {
    deleteProject,
    open,
    reopen,
    runBoard,
    shareProject,
    tinker,
} from './harness.mjs';

const EMAIL = process.env.E2E_EMAIL ?? 'test@example.com';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

await runBoard(
    '/dashboard',
    async (b) => {
        const { page, check, afterwards } = b;
        const { project, base } = await shareProject(
            page,
            `Bar ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const owner = `App\\Models\\Project::where('ref_id', '${project}')->firstOrFail(), App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()`;
        const prototype = tinker(
            `$b = (new App\\Models\\Board\\Board)->ownedBy(${owner}); $b->title = 'App'; $b->kind = App\\Models\\Board\\Board::PROTOTYPE; $b->prototype = ['device' => 'phone', 'start' => 'home']; $b->content = ['items' => [['id' => 'home', 'kind' => 'frame', 'x' => 0, 'y' => 0, 'width' => 390, 'height' => 844, 'text' => 'Home']]]; $b->save(); echo $b->ref_id;`,
        );
        const board = tinker(
            `$b = (new App\\Models\\Board\\Board)->ownedBy(${owner}); $b->title = 'Plain'; $b->content = ['items' => [['id' => 'f', 'kind' => 'frame', 'x' => 0, 'y' => 0, 'width' => 960, 'height' => 540, 'text' => 'Slide']]]; $b->save(); echo $b->ref_id;`,
        );

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const has = async (name) => (await test(name).count()) > 0;
        const bar = async () => {
            await test('board-title').waitFor();

            return {
                slide: await has('new-slide'),
                present: await has('present'),
                templates: await has('board-templates'),
                screens: await has('prototype-screens'),
            };
        };

        await open(page, `${base}/boards/${board}`);
        const plain = await bar();
        check(
            'a plain board offers a new slide and Present',
            plain.slide && plain.present && plain.templates,
            JSON.stringify(plain),
        );

        await open(page, `${base}/prototypes/${prototype}`);
        const desk = await bar();
        check(
            "a prototype's bar has no New slide and no Present",
            !desk.slide && !desk.present,
            JSON.stringify(desk),
        );
        check(
            'but its screens bar and templates are there',
            desk.screens && desk.templates,
            JSON.stringify(desk),
        );

        // On a phone: the same, with templates under the tools' More
        await page.setViewportSize({ width: 390, height: 844 });
        await reopen(page);
        const phone = await bar();
        check(
            'on a phone, too, no New slide and no Present',
            !phone.slide && !phone.present,
            JSON.stringify(phone),
        );
        const title = await test('board-title').boundingBox();
        check(
            'and its title still has room in the bar',
            !!title && title.width >= 40,
            JSON.stringify(title),
        );
        // The tools along the bottom fit across a phone, Redo and all
        for (const width of [390, 360]) {
            await page.setViewportSize({ width, height: 844 });
            const tools = await page
                .locator('[data-test="shape-library"]')
                .evaluate((rail) => ({
                    over: rail.scrollWidth - rail.clientWidth,
                    redo: (() => {
                        const box = rail
                            .querySelector('[data-test="redo"]')
                            .getBoundingClientRect();
                        const edge = rail.getBoundingClientRect();

                        return box.right <= edge.right + 0.5;
                    })(),
                }));
            check(
                `at ${width} px the tools fit across, Redo in sight`,
                tools.over <= 0 && tools.redo,
                JSON.stringify(tools),
            );
        }
        await page.setViewportSize({ width: 390, height: 844 });

        await page.screenshot({
            path: '/tmp/zyrenn-e2e/prototype-bar-phone.png',
        });
    },
    { canvas: false },
);
