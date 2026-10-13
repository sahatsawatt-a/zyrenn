// Files in a workflow, built in the editor: the Drive's steps are a group of
// their own in the palette; "Save to Drive" shows only the settings its
// source needs, and its folder is chosen from a dropdown of the Drive's own
// folders -- or written as a path, made when it isn't there. Run, the file is
// in the Drive and a later step reads it back.
import { SHOTS, runBoard, tinker, open } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e files ${RUN}`;
const FOLDER = `e2e files ${RUN}`;
const NAME = `e2e-${RUN}.md`;
const PICTURE = `e2e-menu-${RUN}.png`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

await runBoard(
    '/workflows',
    async ({ page, check, soon, afterwards }) => {
        afterwards(() =>
            tinker(
                `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); $u->driveFiles()->whereIn('name', ['${NAME}', '${PICTURE}'])->get()->each->delete(); $u->driveFolders()->where('name', 'Deeper')->delete(); $u->driveFolders()->where('name', '${FOLDER}')->delete();`,
            ),
        );

        // A folder already in the Drive, for the dropdown to offer
        tinker(
            `$u = ${user}; App\\Support\\Folders::ensure($u->driveFolders(), '${FOLDER}', $u); echo 'ok';`,
        );

        const settings = page.locator('[data-step-settings]');
        const step = (key) => page.locator(`[data-step="${key}"]`);
        const pick = async (where, option) => {
            await where.click();
            await page
                .getByRole('option', { name: option, exact: true })
                .click();
        };

        await page
            .getByRole('button', { name: /new workflow/i })
            .first()
            .click();
        await page.waitForURL(/\/workflows\/\w+$/);
        await page.getByLabel('Workflow title').fill(TITLE);

        // --- the palette ---
        const drive = page
            .locator('[data-step-palette] section')
            .filter({ has: page.locator('h3', { hasText: /^Drive$/ }) })
            .locator('[data-palette-step]');
        const kinds = await drive.evaluateAll((steps) =>
            steps.map((s) => s.getAttribute('data-palette-step')),
        );
        check(
            "the Drive's steps are a group of their own",
            kinds.join() === 'drive.save,drive.find,drive.read,drive.link',
            kinds.join(),
        );

        // Each service's own API is its own step, in its own group
        const inGroup = (group) =>
            page
                .locator('[data-step-palette] section')
                .filter({ has: page.locator('h3', { hasText: group }) })
                .locator('[data-palette-step]')
                .evaluateAll((steps) =>
                    steps.map((s) => s.getAttribute('data-palette-step')),
                );
        const [telegram, line] = [
            await inGroup(/^Telegram$/),
            await inGroup(/^LINE$/),
        ];
        check(
            "each call to a service is a step of that service's own: getting a file, replying, pushing",
            telegram.includes('telegram.file') &&
                telegram.includes('telegram.send_file') &&
                ['line.file', 'line.reply', 'line.send'].every((kind) =>
                    line.includes(kind),
                ),
            `${telegram.join()} | ${line.join()}`,
        );

        const shown = async (name) =>
            (await settings.locator(`#setting-${name}`).count()) === 1;
        await page.locator('[data-palette-step="line.file"]').click();
        await step('line_get_the_file').waitFor();
        check(
            'it asks for its channel from a dropdown, and where to keep the file',
            (await shown('connection')) &&
                (await shown('folder')) &&
                (await settings
                    .locator('#setting-connection')
                    .getAttribute('data-pick-one')) !== null,
        );
        await settings.locator('[data-remove-step]').click();

        // --- a LINE message: what it is made of follows its kind ---
        await page.locator('[data-palette-step="line.reply"]').click();
        await step('reply_on_line').waitFor();
        check(
            'a reply is words and a picture until another kind is chosen',
            (await shown('text')) &&
                (await shown('file')) &&
                !(await shown('choices')) &&
                !(await shown('package')),
        );
        await pick(settings.locator('#setting-kind'), 'Buttons to choose from');
        const buttons = settings
            .locator('div', { has: page.getByText('Buttons', { exact: true }) })
            .last();
        await buttons.locator('[data-add-field]').click();
        await buttons.getByLabel('Name').first().fill('Yes, send it');
        check(
            'buttons are named in any words, and the picture gives way to them',
            (await shown('text')) &&
                !(await shown('file')) &&
                (await buttons.getByLabel('Name').first().inputValue()) ===
                    'Yes, send it' &&
                (await buttons
                    .getByLabel('Name')
                    .first()
                    .getAttribute('placeholder')) === 'what it says',
        );
        // What a button does is chosen, not spelt out; and its value has
        // the whole panel to be read in
        const does = buttons.locator('[data-does]').first();
        const value = buttons.getByLabel('Value', { exact: true }).first();
        await pick(does.locator('[data-pick-one]'), 'Sends data back, unseen');
        await value.fill('send:12');
        const choices = () =>
            tinker(
                `echo json_encode(collect(${user}->workflows()->where('title', '${TITLE}')->first()->definition['nodes'])->pluck('config.choices')->filter()->first());`,
            ).trim();
        await page
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });
        check(
            'what a button does is chosen from a list, and kept as it was always written',
            choices() ===
                '[{"name":"Yes, send it","value":"postback:send:12"}]',
            choices(),
        );
        await pick(
            does.locator('[data-pick-one]'),
            'Says a message in the chat',
        );
        await value.fill(
            'https://example.com/orders/12?from=line&campaign=autumn',
        );
        const room = await value.evaluate((field) => ({
            wide: field.clientWidth,
            whole: field.scrollHeight <= field.clientHeight + 1,
            panel: field.closest('[data-step-settings]').clientWidth,
        }));
        check(
            'an address written as a message is a link, and is read whole across the panel',
            (await does.innerText()).includes('Opens a link') &&
                room.whole &&
                room.wide > room.panel * 0.7,
            `${await does.innerText()} ${JSON.stringify(room)}`,
        );
        await pick(
            does.locator('[data-pick-one]'),
            'Says a message in the chat',
        );
        check(
            'a link made a message is written anew: an address can only open',
            (await value.inputValue()) === '',
            await value.inputValue(),
        );
        await page
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });
        check(
            'a button named in words is kept',
            !(await page.locator('[data-problems]').innerText()).includes(
                'letters, digits',
            ),
            await page.locator('[data-problems]').innerText(),
        );
        await settings.locator('[data-remove-step]').click();

        // --- a rich menu: its buttons follow its layout ---
        check(
            "every call about a rich menu is a step of LINE's own",
            [
                'make',
                'default',
                'default_off',
                'many',
                'many_off',
                'of',
                'alias',
                'alias_off',
                'delete',
            ].every((call) => line.includes(`line.richmenu_${call}`)) &&
                line.includes('line.richmenu') &&
                line.includes('line.richmenus'),
            line.filter((kind) => kind.includes('richmenu')).join(),
        );
        await page.locator('[data-palette-step="line.richmenu_make"]').click();
        await step('make_a_rich_menu_on_line').waitFor();
        check(
            'a rich menu asks for its picture and what each button does',
            (await shown('name')) &&
                (await shown('picture')) &&
                (await shown('layout')) &&
                (await shown('bar')) &&
                (await settings
                    .getByText('Buttons', { exact: true })
                    .count()) === 1 &&
                !(await shown('areas')),
        );
        // Its picture is chosen from the Drive, not typed in as an id
        const pictureRef = tinker(
            `$u = ${user}; $t = tempnam(sys_get_temp_dir(), 'e2e'); file_put_contents($t, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')); echo App\\Models\\Drive\\DriveFile::keep($t, '${PICTURE}', $u)->ref_id;`,
        ).trim();
        await settings.locator('[data-choose-file="picture"]').click();
        await page.locator('[data-chat-drive] input').fill(PICTURE);
        await page.locator(`[data-drive-file="${PICTURE}"]`).click();
        check(
            'a picture chosen from the Drive is filled in by its ref_id',
            pictureRef.length > 0 &&
                (await settings.locator('#setting-picture').inputValue()) ===
                    pictureRef,
            `${pictureRef} | ${await settings.locator('#setting-picture').inputValue()}`,
        );
        // Its buttons are places on the picture, set up one at a time
        const places = settings.locator('[data-places] [data-place]');
        const button = settings.locator('[data-field-row]');
        await settings.locator('[data-places] img').waitFor();
        check(
            "a rich menu's places are drawn over its picture, the next one free",
            (await places.count()) === 6 &&
                (await places.nth(0).isEnabled()) &&
                (await places.nth(1).isDisabled()) &&
                (await settings.locator('[data-add-field]').count()) === 0,
            String(await places.count()),
        );
        await places.nth(0).click();
        await button.getByLabel('Name').fill('Order');
        await button
            .getByLabel('Value', { exact: true })
            .fill('https://example.com/order');
        await places.nth(1).click();
        await button.getByLabel('Name').fill('More');
        await pick(
            button.locator('[data-does] [data-pick-one]'),
            'Turns to another menu',
        );
        await button.getByLabel('Value', { exact: true }).fill('more');
        await places.nth(0).click();
        check(
            'a place chosen is the button set up, read back from what is kept',
            (await button.count()) === 1 &&
                (await button.getByLabel('Name').inputValue()) === 'Order' &&
                (await button.locator('[data-does]').innerText()).includes(
                    'Opens a link',
                ) &&
                (await places.nth(1).innerText()).includes('More') &&
                (await places.nth(2).isEnabled()),
            await places.nth(1).innerText(),
        );
        await page
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });
        const menu = tinker(
            `echo json_encode(collect(${user}->workflows()->where('title', '${TITLE}')->first()->definition['nodes'])->pluck('config.buttons')->filter()->first(), JSON_UNESCAPED_SLASHES);`,
        ).trim();
        check(
            'a button that turns to another menu is kept as "menu:" and its name',
            menu ===
                '[{"name":"Order","value":"https://example.com/order"},{"name":"More","value":"menu:more"}]',
            menu,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-rich-menu.png` });

        // The panel is as wide as its left edge is dragged
        const wide = async () => (await settings.boundingBox()).width;
        const usual = await wide();
        const edge = await settings
            .locator('[data-resize-settings]')
            .boundingBox();
        await page.mouse.move(edge.x + 2, edge.y + 200);
        await page.mouse.down();
        await page.mouse.move(edge.x - 120, edge.y + 200, { steps: 4 });
        await page.mouse.up();
        const dragged = await wide();
        await settings.locator('[data-resize-settings]').dblclick();
        check(
            'the settings are dragged wider by their edge, and double-clicked back',
            dragged > usual + 100 && (await wide()) === usual,
            `${usual} -> ${dragged} -> ${await wide()}`,
        );
        await pick(settings.locator('#setting-layout'), 'Areas of my own');
        check(
            "areas of one's own are written out instead of buttons",
            (await shown('areas')) &&
                (await settings
                    .getByText('Buttons', { exact: true })
                    .count()) === 0,
        );
        await settings.locator('[data-remove-step]').click();

        // --- Save to Drive: only what its source needs ---
        await page.locator('[data-palette-step="drive.save"]').click();
        await step('save_to_drive').waitFor();
        check(
            'a file at an address asks for the address',
            (await shown('url')) &&
                !(await shown('text')) &&
                !(await shown('connection')),
        );
        await pick(settings.locator('#setting-from'), 'Text written here');
        check(
            'text written here asks for the text instead',
            (await shown('text')) && !(await shown('url')),
        );
        await settings.locator('#setting-text').fill('kept by a workflow');
        await settings.locator('#setting-name').fill(NAME);

        // --- its folder: chosen, or a path of one's own ---
        const folder = settings.locator('#setting-folder');
        check(
            'left alone, a file goes to the top of the Drive',
            (await folder.innerText()).includes('The top of the Drive'),
            await folder.innerText(),
        );
        await pick(folder, FOLDER);
        check(
            "a folder is chosen from the Drive's own",
            (await folder.innerText()).includes(FOLDER) &&
                (await settings.getByLabel('Folder, by its path').count()) ===
                    0,
        );
        await pick(folder, 'Another, by its path…');
        await settings
            .getByLabel('Folder, by its path')
            .fill(`${FOLDER}/Deeper`);
        check(
            'or written as a path, to be made',
            (await settings.innerText()).includes(
                'Folders not there yet are made.',
            ),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-files.png` });

        // --- read it back ---
        await page.locator('[data-palette-step="drive.read"]').click();
        await step('read_a_file').waitFor();
        await settings
            .locator('#setting-file')
            .fill('{{ node("save_to_drive").ref_id }}');
        await settings.getByRole('button', { name: 'Close' }).click();
        await page
            .getByRole('button', { name: 'Fit the workflow in view' })
            .click();
        await page.waitForTimeout(400);

        const join = async (from, to) => {
            await step(from)
                .locator('.vue-flow__handle.source')
                .first()
                .dragTo(step(to).locator('.vue-flow__handle.target'));
            await page.waitForTimeout(200);
        };
        await join('start', 'save_to_drive');
        await join('save_to_drive', 'read_a_file');
        await page
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });
        check(
            'nothing stands in the way',
            (await page.locator('[data-problems]').innerText()).includes(
                'Ready',
            ),
            await page.locator('[data-problems]').innerText(),
        );

        // --- run ---
        await page.locator('[data-run]').click();
        await page
            .locator('[data-run-status]', { hasText: 'Succeeded' })
            .waitFor({ timeout: 30000 });
        await page.locator('[data-run-step="read_a_file"]').click();
        // What the step gave is drawn a moment after it is opened
        const given = page.locator('[data-run-output]', { hasText: NAME });
        await given
            .first()
            .waitFor({ timeout: 10000 })
            .catch(() => {});
        const output = await page
            .locator('[data-run-output]')
            .first()
            .innerText();
        check(
            'run, the file is saved and read back by a later step',
            output.includes('kept by a workflow') && output.includes(NAME),
            output.replace(/\s+/g, ' ').slice(0, 200),
        );

        const kept = tinker(
            `$f = ${user}->driveFiles()->where('name', '${NAME}')->with('folder.parent')->first(); echo $f ? $f->folder->parent->name.'/'.$f->folder->name : 'none';`,
        );
        check(
            'it is in the Drive, in the folder made for it',
            kept === `${FOLDER}/Deeper`,
            kept,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-files-run.png` });

        // --- in a chat: a picture a workflow wrote is drawn, not named ---
        const PNG =
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        const roomRef = tinker(
            `$u = ${user}; $t = tempnam(sys_get_temp_dir(), 'e2e'); file_put_contents($t, base64_decode('${PNG}')); $f = App\\Models\\Drive\\DriveFile::keep($t, '${NAME}.png', $u); unlink($t); $r = new App\\Models\\Chat\\ChatRoom; $r->kind = 'personal'; $r->user_id = $u->id; $r->title = '${TITLE}'; $r->save(); $r->messages()->create(['role' => 'workflow', 'content' => '', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true, 'file' => $f->ref_id]]); $r->messages()->create(['role' => 'workflow', 'content' => '', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true, 'file' => $f->ref_id, 'sticker' => true]]); $r->messages()->create(['role' => 'workflow', 'content' => 'hi (moon) there', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true, 'emojis' => [['index' => 3, 'length' => 6, 'url' => $f->url()]]]]); $r->note(['name' => 'Ada', 'line_id' => 'U1', 'language' => 'th'], 'https://stickershop.line-scdn.net/stickershop/v1/sticker/52002734/android/sticker.png'); $r->messages()->create(['role' => 'workflow', 'content' => 'me too', 'meta' => ['name' => 'Bo (LINE)', 'outside' => true]]); $r->messages()->create(['role' => 'workflow', 'content' => '', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true, 'place' => ['lat' => 13.7563, 'lng' => 100.5018, 'title' => 'Palace ${RUN}', 'address' => 'Na Phra Lan Rd']]]); $l = (new App\\Models\\Map\\PlaceList)->ownedBy($u, $u); $l->fill(['name' => 'e2e list ${RUN}', 'color' => '#ef4444', 'icon' => 'bookmark', 'sort_order' => 99])->save(); $p = (new App\\Models\\Map\\Place)->ownedBy($u, $u); $p->list_id = $l->id; $p->fill(['name' => 'Harbour ${RUN}', 'address' => '1 Quay', 'kind' => 'place', 'lat' => 13.70, 'lng' => 100.50])->save(); echo $r->ref_id;`,
        );
        afterwards(() =>
            tinker(
                `$u = ${user}; App\\Models\\Chat\\ChatRoom::where('ref_id', '${roomRef}')->delete(); $u->driveFiles()->where('name', '${NAME}.png')->get()->each->delete(); $l = $u->placeLists()->where('name', 'e2e list ${RUN}')->first(); if ($l) { $l->places()->delete(); $l->delete(); }`,
            ),
        );
        await open(page, new URL(`/chats/${roomRef}`, page.url()).href);
        const picture = page
            .locator('[data-chat-workflow] button[data-chat-file] img')
            .first();
        await picture.waitFor({ timeout: 10000 });
        await soon(
            'a file a workflow wrote in a chat is shown under its sender, a picture as a picture',
            async () =>
                (await picture.evaluate(
                    (img) => img.complete && img.naturalWidth > 0,
                )) &&
                (
                    await page
                        .locator('[data-chat-workflow]')
                        .first()
                        .innerText()
                ).includes('Ada (LINE)'),
        );

        // The conversation reads as one: a day over it, one name over each run
        // of messages from the same sender, the time under the last of them --
        // here Ada's three, then Bo's one, then Ada again
        const names = await page
            .locator('[data-chat-workflow] [data-chat-author]')
            .allInnerTexts();
        const times = await page
            .locator('[data-chat-workflow] [data-chat-time]')
            .count();
        check(
            'messages are set out by day and by who is speaking, with the time under each turn',
            (
                await page.locator('[data-chat-day]').first().innerText()
            ).trim() === 'Today' &&
                names.map((name) => name.trim()).join() ===
                    'Ada (LINE),Bo (LINE),Ada (LINE)' &&
                times === 3 &&
                (await page.locator('[data-chat-latest]').count()) === 0,
            `${names.join()} · ${times} times`,
        );

        // A picture opens larger over the chat; a sticker is small and bare; an emoji sits among the words
        await picture.click();
        const larger = page.locator('[data-chat-picture]');
        await larger.waitFor();
        check(
            'a picture opens larger in a modal, with its name and a way to keep it',
            (await larger.innerText()).includes(`${NAME}.png`) &&
                (await larger
                    .getByRole('link', { name: 'Download' })
                    .count()) === 1,
        );
        await page.keyboard.press('Escape');
        await larger.waitFor({ state: 'detached' });
        const sticker = page.locator('[data-chat-sticker]');
        check(
            'a sticker is drawn small with nothing round it, and does not open',
            (await sticker.count()) === 1 &&
                (await sticker.evaluate(
                    (img) =>
                        img.tagName === 'IMG' &&
                        !img.closest('button') &&
                        getComputedStyle(img).borderTopWidth === '0px' &&
                        img.getBoundingClientRect().width <= 120,
                )),
        );
        // A sticker that has stopped moving plays again on a click: the same
        // picture under a new address, which is what starts it over
        const first = await sticker.getAttribute('src');
        await sticker.click();
        await page.waitForFunction(
            () =>
                document
                    .querySelector('[data-chat-sticker]')
                    ?.getAttribute('src')
                    ?.startsWith('blob:'),
            null,
            { timeout: 5000 },
        );
        const again = await sticker.getAttribute('src');
        await sticker.click();
        await page.waitForFunction(
            (before) =>
                document
                    .querySelector('[data-chat-sticker]')
                    ?.getAttribute('src') !== before,
            again,
            { timeout: 5000 },
        );
        // The new picture is drawn a moment after it is named
        const drawn = await page
            .waitForFunction(
                () => {
                    const img = document.querySelector('[data-chat-sticker]');

                    return (
                        img instanceof HTMLImageElement &&
                        img.complete &&
                        img.naturalWidth > 0
                    );
                },
                null,
                { timeout: 5000 },
            )
            .then(() => true)
            .catch(() => false);
        check(
            'a click plays a sticker again, each time, without it opening or breaking',
            !first?.startsWith('blob:') &&
                drawn &&
                (await page.locator('[data-chat-picture]').count()) === 0,
            `first ${first?.slice(0, 20)}, drawn ${drawn}`,
        );
        const worded = page.locator('[data-chat-workflow]', {
            has: page.locator('[data-chat-emoji]'),
        });
        check(
            "an outside chat's own emoji is drawn where its name was, among the words",
            (await worded.locator('[data-chat-emoji]').getAttribute('alt')) ===
                '(moon)' &&
                (await worded.locator('p').last().innerText())
                    .replace(/\s+/g, ' ')
                    .trim() === 'hi there',
            (await worded.locator('p').last().innerText()).replace(/\s+/g, ' '),
        );
        await page.screenshot({
            path: `${SHOTS}/workflow-files-chat-shown.png`,
        });

        // --- about the room: its face, what a workflow keeps of it, and notes of one's own ---
        check(
            'a room that keeps a picture shows it beside its title',
            (await page.locator('[data-chat-room-face]').count()) === 1,
        );
        await page.locator('[data-chat-room-face]').click();
        const largerFace = page.locator('[data-chat-face-larger]');
        await largerFace.waitFor();
        check(
            "the room's picture opens larger when pressed",
            (await largerFace.locator('img').getAttribute('src')) ===
                (await page
                    .locator('[data-chat-room-face]')
                    .getAttribute('src')),
        );
        await page.keyboard.press('Escape');
        await largerFace.waitFor({ state: 'detached' });

        await page.locator('[data-chat-about-toggle]').click();
        const aboutRoom = page.locator('[data-chat-about]');
        await aboutRoom.waitFor();
        const keptText = (
            await aboutRoom.locator('[data-chat-kept] dl').innerText()
        )
            .replace(/\s+/g, ' ')
            .trim();
        check(
            'what a workflow keeps about a room is shown beside it, each by a name a person can read',
            keptText === 'Name Ada Language Thai (th) Line id U1',
            keptText,
        );
        // Its picture, pressed in the panel, opens over the panel -- not under it
        await aboutRoom.locator('[data-chat-about-picture]').click();
        const overPanel = page.locator('[data-chat-face-larger]');
        await overPanel.waitFor();
        await page.waitForTimeout(400);
        const onTop = await overPanel.locator('img').evaluate((img) => {
            const box = img.getBoundingClientRect();
            const at = document.elementFromPoint(
                box.left + box.width / 2,
                box.top + box.height / 2,
            );

            return at === img;
        });
        check(
            'the picture pressed in the panel about a chat comes up over the panel',
            onTop,
        );
        await page.keyboard.press('Escape');
        await overPanel.waitFor({ state: 'detached' });
        check(
            'and closing it leaves the panel open',
            (await aboutRoom.count()) === 1,
        );

        await aboutRoom.locator('[data-chat-detail-add]').click();
        const form = aboutRoom.locator('[data-chat-details-form]');
        await form.getByLabel('Name').last().fill('VIP tier');
        await form.getByLabel('Value').last().fill('gold');
        check(
            "what the workflow keeps -- their id there -- is not offered to be changed, only one's own notes",
            (
                await form
                    .locator('input')
                    .evaluateAll((boxes) => boxes.map((box) => box.value))
            ).join() === 'VIP tier,gold' &&
                (await aboutRoom.locator('[data-chat-kept] input').count()) ===
                    0,
        );
        await form.locator('[data-chat-details-save]').click();
        await aboutRoom
            .locator('[data-chat-details]', { hasText: 'gold' })
            .waitFor({ timeout: 10000 });
        check(
            'a note added by hand is kept with the room, under a name a workflow can read',
            tinker(
                `$r = App\\Models\\Chat\\ChatRoom::where('ref_id', '${roomRef}')->first(); echo ($r->details['vip_tier'] ?? 'none').' '.($r->details['line_id'] ?? 'none');`,
            ) === 'gold U1' &&
                (
                    await aboutRoom.locator('[data-chat-details]').innerText()
                ).includes('Vip tier'),
        );
        await page.keyboard.press('Escape');
        await aboutRoom.waitFor({ state: 'detached' });

        // --- who wrote: shown by the room's own picture, kept once with the room ---
        const faces = page.locator('[data-chat-workflow] [data-chat-face]');
        // (only the last of a turn shows its face; the rest keep its place)
        await faces.first().waitFor({ state: 'attached', timeout: 10000 });
        const shownFaces = await faces.evaluateAll((imgs) =>
            imgs.map((img) => img.getAttribute('src')),
        );
        const roomsFace = await page
            .locator('[data-chat-room-face]')
            .getAttribute('src');
        check(
            "someone outside is shown by the room's picture, with none kept in the messages themselves",
            shownFaces.length >= 3 &&
                new Set(shownFaces).size === 1 &&
                shownFaces[0] === roomsFace,
            `${shownFaces.length} faces, ${new Set(shownFaces).size} different`,
        );

        // --- a place someone shared: on a map, opened larger, kept among one's own ---
        const card = page.locator('[data-chat-workflow] [data-chat-place]');
        await card.locator('canvas').first().waitFor({ timeout: 20000 });
        check(
            'a place shared in a chat is shown on a small map, named, with the way there',
            (await card.innerText()).includes(`Palace ${RUN}`) &&
                (await card.innerText()).includes('Na Phra Lan Rd') &&
                (
                    (await card
                        .getByRole('link', { name: 'Directions' })
                        .getAttribute('href')) ?? ''
                ).includes('destination=13.7563,100.5018') &&
                (
                    (await card
                        .locator('[data-chat-place-open]')
                        .getAttribute('href')) ?? ''
                ).includes('#map=15/13.75630/100.50180'),
            (await card.innerText()).replace(/\s+/g, ' '),
        );
        const inMaps =
            (await card
                .locator('[data-chat-place-open]')
                .getAttribute('href')) ?? '';
        await card.getByRole('button', { name: /Open the map/ }).click();
        const map = page.locator('[data-chat-place-larger]');
        await map.locator('canvas').first().waitFor({ timeout: 20000 });
        check(
            'its map opens larger over the chat',
            (await map.innerText()).includes(`Palace ${RUN}`),
        );
        await page.keyboard.press('Escape');
        await map.waitFor({ state: 'detached' });
        await card.locator('[data-chat-place-save]').click();
        await page.getByRole('menuitem', { name: `e2e list ${RUN}` }).click();
        await card.locator('[data-chat-place-saved]').waitFor();
        check(
            "it is kept among one's saved places, in the list chosen",
            tinker(
                `$l = ${user}->placeLists()->where('name', 'e2e list ${RUN}')->first(); echo $l->places()->where('name', 'Palace ${RUN}')->count();`,
            ) === '1' &&
                (
                    await card.locator('[data-chat-place-saved]').innerText()
                ).includes(`e2e list ${RUN}`),
        );

        // --- and a place of one's own is shared the same way ---
        await page.locator('[data-chat-attach]').click();
        await page.getByRole('menuitem', { name: 'A place' }).click();
        const sharing = page.locator('[data-chat-place-dialog]');
        await sharing
            .locator('[data-test="place-search"]')
            .fill(`Harbour ${RUN}`);
        await sharing
            .locator('[data-test="place-suggestions"]')
            .getByText(`Harbour ${RUN}`)
            .first()
            .click();
        await sharing.locator('canvas').first().waitFor({ timeout: 20000 });
        await sharing.locator('[data-chat-place-share]').click();
        await page
            .locator('[data-chat-placed]', { hasText: `Harbour ${RUN}` })
            .waitFor();
        await page.locator('[data-chat-input]').fill('meet here');
        await page.locator('[data-chat-input]').press('Enter');
        const shared = page.locator('[data-chat-mine]', {
            has: page.locator('[data-chat-place]'),
        });
        await shared.waitFor({ timeout: 15000 });
        check(
            'a saved place is found, looked at and shared with what one writes',
            (await shared.innerText()).includes('meet here') &&
                (await shared.innerText()).includes(`Harbour ${RUN}`) &&
                (await page.locator('[data-chat-placed]').count()) === 0,
            (await shared.innerText()).replace(/\s+/g, ' '),
        );
        // (a moment for the map's own pictures to arrive)
        await page.waitForTimeout(1500);
        await page.screenshot({ path: `${SHOTS}/workflow-files-place.png` });

        // --- and a file of one's own goes with what one writes ---
        await page.locator('[data-chat-file-input]').setInputFiles({
            name: `${NAME}.txt`,
            mimeType: 'text/plain',
            buffer: Buffer.from('sent from the chat'),
        });
        await page
            .locator('[data-chat-attached]', { hasText: `${NAME}.txt` })
            .waitFor();
        await page.locator('[data-chat-input]').fill('with a file');
        await page.locator('[data-chat-input]').press('Enter');
        const mine = page.locator('[data-chat-mine]', {
            has: page.locator('[data-chat-file]'),
        });
        await mine.waitFor({ timeout: 15000 });
        check(
            'a file attached in a chat is sent with the words, and shown under them',
            (await mine.innerText()).includes('with a file') &&
                (await mine.innerText()).includes(`${NAME}.txt`) &&
                (await page.locator('[data-chat-attached]').count()) === 0,
            (await mine.innerText()).replace(/\s+/g, ' '),
        );
        // --- or one already in the Drive, chosen rather than uploaded again ---
        await page.locator('[data-chat-attach]').click();
        await page.getByRole('menuitem', { name: 'From the Drive' }).click();
        const fromDrive = page.locator('[data-chat-drive]');
        await fromDrive.getByPlaceholder('Search by name').fill(`${NAME}.png`);
        await fromDrive.locator(`[data-drive-file="${NAME}.png"]`).click();
        await page
            .locator('[data-chat-attached]', { hasText: `${NAME}.png` })
            .waitFor();
        await page.getByRole('button', { name: 'Send' }).click();
        await page
            .locator('[data-chat-mine] button[data-chat-file]')
            .waitFor({ timeout: 15000 });
        check(
            'a file already in the Drive is chosen and sent without uploading it again',
            Number(
                tinker(
                    `echo ${user}->driveFiles()->where('name', '${NAME}.png')->count();`,
                ),
            ) === 1,
        );

        // --- deleting a chat asks in the app's own way ---
        let asked = false;
        page.on('dialog', (dialog) => {
            asked = true;
            void dialog.dismiss();
        });
        await page.getByRole('button', { name: 'Delete chat' }).click();
        const sure = page.getByRole('dialog');
        await sure.waitFor();
        check(
            "deleting a chat is asked in the app's own dialog, not the browser's",
            !asked &&
                (await sure.innerText()).includes(TITLE) &&
                (await sure.getByRole('button', { name: 'Cancel' }).count()) ===
                    1,
            await sure.innerText(),
        );
        await sure.getByRole('button', { name: 'Delete', exact: true }).click();
        await page.waitForURL(/\/chats\/?$/, { timeout: 10000 });
        check(
            'and deletes it once agreed to',
            tinker(
                `echo App\\Models\\Chat\\ChatRoom::where('ref_id', '${roomRef}')->exists() ? 'there' : 'gone';`,
            ) === 'gone',
        );

        // --- "Open in Maps" on a place's card: the map opens on it, pinned ---
        await open(page, new URL(inMaps, page.url()).href);
        const placeCard = page.locator('[data-test="place-card"]');
        await placeCard.waitFor({ timeout: 20000 });
        const pinned = await page.evaluate(() => {
            const map = window.__maps?.map();
            const pin = document.querySelector('.maplibregl-marker');
            const at = map?.getCenter();

            return {
                pin: !!pin,
                lat: at ? Number(at.lat.toFixed(3)) : null,
                lng: at ? Number(at.lng.toFixed(3)) : null,
            };
        });
        check(
            'a place opened in Maps from a chat is pinned there, with its card to save it from',
            // Saved from the chat a moment ago, so it opens as that saved place
            (await placeCard.innerText()).includes(`e2e list ${RUN}`) &&
                // Its name is there to be changed, so it is a box's value, not words on the page
                (await page.evaluate(
                    (name) =>
                        [
                            ...document.querySelectorAll('input, h1, h2, h3'),
                        ].some(
                            (el) =>
                                (el.value ?? el.textContent ?? '').trim() ===
                                name,
                        ),
                    `Palace ${RUN}`,
                )) &&
                pinned.pin &&
                pinned.lat === 13.756 &&
                pinned.lng === 100.502,
            `${JSON.stringify(pinned)} ${(await placeCard.innerText()).replace(/\s+/g, ' ').slice(0, 80)}`,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-files-maps.png` });
        afterwards(() =>
            tinker(
                `${user}->driveFiles()->where('name', '${NAME}.txt')->get()->each->delete();`,
            ),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-files-chat.png` });
    },
    { canvas: false },
);
