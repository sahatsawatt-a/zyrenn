// Every page of the Guide, each topic and each part of one, read on a phone:
// nothing wider than the screen, each picture and video there, and each
// diagram, circuit, graph and formula drawn.
import { open, runBoard, tinker } from './harness.mjs';

await runBoard(
    '/guide',
    async ({ page: first, soon }) => {
        const base = new URL(first.url()).origin;

        const slugs = JSON.parse(
            tinker(
                `echo json_encode(collect(App\\Support\\Guide\\Guide::topics())->flatMap(fn ($topic) => [$topic['slug'], ...array_column($topic['parts'], 'slug')]));`,
            ),
        );
        // Read three at a time, each in a tab of its own
        const read = async (page) => {
            await page.setViewportSize({ width: 390, height: 844 });

            for (let slug = slugs.shift(); slug; slug = slugs.shift()) {
                await check(page, slug);
            }
        };
        const check = async (page, slug) => {
            await open(page, `${base}/guide/${slug}`);
            await page.locator('[data-guide-topic] .tiptap').waitFor();
            await soon(
                `at 390px, the ${slug} topic fits, its pictures and videos loaded`,
                () =>
                    page.evaluate(
                        () =>
                            document.documentElement.scrollWidth <=
                                innerWidth &&
                            [
                                ...document.querySelectorAll(
                                    '[data-guide-topic] img[src]',
                                ),
                            ].every(
                                (img) => img.complete && img.naturalWidth > 0,
                            ) &&
                            [
                                ...document.querySelectorAll(
                                    '[data-guide-topic] video',
                                ),
                            ].every((video) => video.readyState >= 1),
                    ),
                () =>
                    page.evaluate(
                        () =>
                            `${document.documentElement.scrollWidth}px wide; not loaded: ${[
                                ...document.querySelectorAll(
                                    '[data-guide-topic] img[src], [data-guide-topic] video',
                                ),
                            ]
                                .filter((media) =>
                                    media.tagName === 'IMG'
                                        ? !media.naturalWidth
                                        : media.readyState < 1,
                                )
                                .map((media) => media.getAttribute('src'))
                                .join(', ')}`,
                    ),
                { timeout: 15000 },
            );

            // A diagram is drawn once it is scrolled to
            await page.evaluate(async () => {
                for (let y = 0; y < document.body.scrollHeight; y += 600) {
                    scrollTo(0, y);
                    await new Promise(requestAnimationFrame);
                }
            });
            await soon(
                `and every diagram and formula on ${slug} is drawn`,
                () =>
                    page.evaluate(
                        () =>
                            !document
                                .querySelector('[data-guide-topic]')
                                .innerText.includes('Rendering…') &&
                            !document.querySelector(
                                '[data-guide-topic] .katex-error',
                            ),
                    ),
                () =>
                    page.evaluate(() =>
                        [
                            ...document.querySelectorAll(
                                '[data-guide-topic] .katex-error',
                            ),
                        ]
                            .map((error) => error.getAttribute('title'))
                            .join('; '),
                    ),
                { timeout: 20000 },
            );
        };

        await Promise.all([
            read(first),
            read(await first.context().newPage()),
            read(await first.context().newPage()),
        ]);
    },
    { canvas: false },
);
