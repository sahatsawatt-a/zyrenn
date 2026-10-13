// A folder stays in the order it was last put in: sorted by title, a folder
// of notes is still by title when it is come back to, while the folder
// beside it and the top level keep their own.
import { open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/notes',
    async (b) => {
        const { page, soon, afterwards } = b;
        const base = new URL(page.url()).origin;

        // Two folders, behind the scenes: the suite is about the sorting
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;
        const folder = (name) =>
            tinker(
                `$f = new App\\Models\\Note\\NoteFolder(['name' => '${name}']); $f->user_id = ${user}->id; $f->save(); echo $f->ref_id;`,
            );
        const sorted = folder(`Sorted ${RUN}`);
        const other = folder(`Other ${RUN}`);

        afterwards(() =>
            tinker(
                `App\\Models\\Note\\NoteFolder::whereIn('ref_id', ['${sorted}', '${other}'])->delete(); DB::table('list_sorts')->whereIn('list', ['NoteFolder:${sorted}', 'NoteFolder:${other}'])->delete();`,
            ),
        );

        const sort = page.getByRole('combobox', { name: 'Sort' });
        const shownAs = async () => (await sort.innerText()).trim();

        await open(page, `${base}/notes?folder=${sorted}`);
        await sort.waitFor();
        await soon(
            'a folder starts in the usual order',
            async () => (await shownAs()) === 'Last edited',
            shownAs,
        );

        await sort.click();
        await page.getByRole('option', { name: 'Title A–Z' }).click();
        await page.waitForURL(/sort=title/);

        // Opened again by a link that says nothing of sorting
        await open(page, `${base}/notes?folder=${sorted}`);
        await sort.waitFor();
        await soon(
            'come back to, it is still in the order it was left in',
            async () => (await shownAs()) === 'Title A–Z',
            shownAs,
        );

        await open(page, `${base}/notes?folder=${other}`);
        await sort.waitFor();
        await soon(
            'the folder beside it keeps its own',
            async () => (await shownAs()) === 'Last edited',
            shownAs,
        );

        // Put back the usual way, it stays that way too
        await open(page, `${base}/notes?folder=${sorted}`);
        await sort.click();
        await page.getByRole('option', { name: 'Last edited' }).click();
        await page.waitForURL(/sort=edited/);
        await open(page, `${base}/notes?folder=${sorted}`);
        await sort.waitFor();
        await soon(
            'and the usual order is remembered like any other',
            async () => (await shownAs()) === 'Last edited',
            shownAs,
        );
    },
    { canvas: false },
);
