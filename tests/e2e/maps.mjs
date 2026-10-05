// Maps (/maps) and Trips (/trips): search, save and find a place again,
// directions, Google's details -- then a trip: the landing day and its ride
// in, a leg typed in, hours typed in, the quickest order, a stop dragged, a
// place added from the map, saving, and someone else's save arriving first --
// and a table with a Location column, seen on a map.
//
// It asks Photon, Valhalla and OpenFreeMap through the app (and SerpAPI when
// SERPAPI_KEY is set), so it needs the internet -- which is why it is not
// part of `npm run test:e2e`.
//
//     node tests/e2e/maps.mjs
import { openBoard, removeAt, SHOTS, tinker } from './harness.mjs';

const app = await openBoard('/maps', { canvas: false });
const { page, check, afterwards } = app;
const byTest = (id) => page.locator(`[data-test="${id}"]`);

// DEBUG=1: where a page error came from, and when each route came back
if (process.env.DEBUG) {
    const began = Date.now();
    page.on('pageerror', (error) =>
        console.log(
            'pageerror',
            error.stack?.split('\n').slice(0, 10).join('\n'),
        ),
    );
    page.on('response', (response) => {
        if (/map-services\/(route|quickest)/.test(response.url())) {
            console.log(
                `+${((Date.now() - began) / 1000).toFixed(1)}s ${response.url().split('/').pop()} ${response.status()}`,
            );
        }
    });
}
const base = new URL(page.url()).origin;
const me = "App\\Models\\User::where('email', 'test@example.com')->first()";

// Each run starts with no saved places, and leaves none behind
const clearPlaces = () => tinker(`${me}->placeLists()->delete(); echo 'ok';`);
clearPlaces();
afterwards(clearPlaces);

/** Sends a request as the page would: same cookies, its CSRF token. */
const request = (path, method, body) =>
    page.evaluate(
        async ({ path, method, body }) => {
            const token = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );
            const response = await fetch(path, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': token,
                },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });

            const json = await response.json().catch(() => null);

            return { status: response.status, url: response.url, json };
        },
        { path, method, body },
    );

try {
    // ---- the map ----

    await page.goto(`${base}/maps`, { waitUntil: 'networkidle' });
    const mapReady = () =>
        page.waitForFunction(
            () =>
                window.__maps?.map()?.loaded() &&
                window.__maps.map().getLayer('saved-points'),
            null,
            { timeout: 30000 },
        );
    await mapReady();
    const box = await byTest('maps-map').boundingBox();
    check(
        'the map fills the page',
        box.height > 400,
        `${Math.round(box.width)}×${Math.round(box.height)}`,
    );

    // Search through our server, then save -- the first save makes a list
    const search = byTest('map-panel').locator('[data-test="place-search"]');
    await search.fill('Central World Bangkok');
    await byTest('place-suggestions').locator('li').first().waitFor();
    await page.waitForTimeout(500);
    await search.press('Enter');
    await byTest('place-card').waitFor();
    check(
        'a suggestion opens its card',
        /central\s*world/i.test(
            await byTest('place-card').locator('h2').innerText(),
        ),
    );

    await byTest('save-place').click();
    await page.getByRole('menuitem', { name: 'Saved' }).click();
    await byTest('saved-in').waitFor({ timeout: 10000 });
    const saved = () =>
        page.evaluate(() =>
            window.__maps.store.places.value.map((place) => place.name),
        );
    check(
        'saving keeps it, in a list made for it',
        (await saved()).length === 1,
        (await saved()).join(),
    );

    // Google's details, kept on the saved place
    if ((await byTest('look-up').count()) === 1) {
        await byTest('look-up').click();
        await page
            .waitForFunction(
                () =>
                    !/Asking Google/.test(
                        document.querySelector('[data-test="place-info"]')
                            ?.innerText ?? '',
                    ),
                null,
                { timeout: 30000 },
            )
            .catch(() => {});
        const info = await byTest('place-info').innerText();

        if (/SERPAPI_KEY is not set/.test(info)) {
            app.results.push('SKIP  Google details: SERPAPI_KEY is not set');
        } else {
            check(
                'Google’s rating and hours show on the card',
                (await byTest('rating').count()) === 1 &&
                    (await byTest('hours').count()) === 1,
            );
        }
    }

    await page.screenshot({ path: `${SHOTS}/maps-explore-card.png` });

    // A click on empty ground: what is there, asked through our server
    await page.locator('[aria-label="Close"]').first().click();
    await page.mouse.click(box.x + box.width * 0.75, box.y + box.height * 0.75);
    await byTest('place-card').waitFor();
    await page
        .waitForFunction(
            () =>
                document
                    .querySelector('[data-test="place-card"]')
                    ?.innerText.includes('Address'),
            null,
            { timeout: 15000 },
        )
        .catch(() => {});
    check(
        'a click on the map looks up what is there',
        /Address/.test(await byTest('place-card').innerText()),
    );

    // Directions to it, from a second click
    await byTest('directions-to').click();
    await byTest('directions').waitFor();
    await page.mouse.click(box.x + box.width * 0.6, box.y + box.height * 0.4);
    await byTest('route-summary')
        .waitFor({ timeout: 45000 })
        .catch(() => {});
    const summary = (await byTest('route-summary').count())
        ? await byTest('route-summary').innerText()
        : `(no route: ${(await byTest('directions').innerText()).replace(/\n/g, ' | ').slice(0, 240)})`;
    check(
        'a route is found and timed',
        /^\d+ (min|h)/.test(summary),
        summary.replace('\n', ' · '),
    );
    await page.screenshot({ path: `${SHOTS}/maps-explore-route.png` });
    await page.locator('[aria-label="Close directions"]').click();
    await page
        .locator('[aria-label="Close"]')
        .first()
        .click()
        .catch(() => {});

    // A hidden list is off the map
    const drawn = () =>
        page.evaluate(
            async () =>
                (await window.__maps.map().getSource('saved').getData())
                    .features.length,
        );
    await byTest('toggle-Saved').click();
    await page.waitForTimeout(300);
    check('hiding a list takes its places off the map', (await drawn()) === 0);
    await byTest('toggle-Saved').click();

    // And it is all still there after a reload
    await page.reload({ waitUntil: 'networkidle' });
    await mapReady();
    check(
        'saved places come back from the server',
        (await saved()).length === 1 && (await drawn()) === 1,
    );

    // ---- a trip ----

    const day = (n, mode, start, stops) => ({
        id: `d${n}`,
        mode,
        start,
        stops,
        legs: {},
    });
    const stop = (id, name, lat, lng, minutes, cost = 0) => ({
        id,
        name,
        address: '',
        kind: 'tourism/attraction',
        lat,
        lng,
        minutes,
        cost,
        note: '',
    });
    const pvg = {
        ...stop(
            'pvg',
            'Shanghai Pudong International Airport',
            31.1434,
            121.8052,
            0,
        ),
        kind: 'aeroway/aerodrome',
    };
    const created = await request('/trips', 'POST', {
        title: 'Shanghai',
        content: {
            startDate: '2026-11-13',
            currency: '¥',
            fromHotel: true,
            arrival: {
                airport: pvg,
                at: '2026-11-13T08:30',
                clearMinutes: 60,
                restMinutes: 120,
            },
            departure: {
                airport: { ...pvg, id: 'pvg2' },
                at: '2026-11-15T21:30',
                earlyMinutes: 180,
            },
            stays: [
                {
                    id: 'h1',
                    place: {
                        ...stop(
                            'hotel',
                            "Hotel near People's Square",
                            31.2323,
                            121.4737,
                            0,
                        ),
                        kind: 'tourism/hotel',
                    },
                    checkIn: '2026-11-13T14:00',
                    checkOut: '2026-11-15T12:00',
                },
            ],
            days: [
                day(1, 'pedestrian', '09:30', [
                    stop('bund', 'The Bund', 31.239, 121.4876, 90),
                    stop('yu', 'Yu Garden', 31.2272, 121.4921, 120, 40),
                    stop(
                        'nanjing',
                        'Nanjing Road Pedestrian Street',
                        31.2349,
                        121.4747,
                        90,
                    ),
                ]),
                day(2, 'auto', '08:00', [
                    stop(
                        'disney',
                        'Shanghai Disneyland',
                        31.144,
                        121.657,
                        600,
                        599,
                    ),
                ]),
                day(3, 'pedestrian', '10:00', [
                    stop(
                        'pearl',
                        'Oriental Pearl Tower',
                        31.2397,
                        121.4998,
                        90,
                        199,
                    ),
                    stop('tianzifang', 'Tianzifang', 31.2083, 121.4687, 90),
                    stop('tower', 'Shanghai Tower', 31.2335, 121.5055, 90, 180),
                    stop('jingan', "Jing'an Temple", 31.2236, 121.4456, 60, 50),
                ]),
            ],
        },
    });
    const tripPath = new URL(created.url).pathname;
    check(
        'a trip can be started with a plan',
        /^\/trips\/\w+$/.test(tripPath),
        tripPath,
    );
    afterwards(() => removeAt(page, tripPath));

    await page.goto(`${base}${tripPath}`, { waitUntil: 'networkidle' });
    const tripReady = () =>
        page.waitForFunction(() => window.__trip?.map()?.loaded(), null, {
            timeout: 30000,
        });
    await tripReady();
    const timed = () =>
        page
            .waitForFunction(
                () => {
                    const legs = [
                        ...document.querySelectorAll('[data-test="leg"]'),
                    ];

                    return (
                        legs.length &&
                        legs.every((leg) => /min|h /.test(leg.innerText))
                    );
                },
                null,
                { timeout: 40000 },
            )
            .catch(() => {});

    await byTest('day-1').click();
    await timed();
    // The day's routes are in -- the car ride from the airport among them
    await page
        .waitForFunction(
            async () =>
                /by car/.test(
                    document.querySelector('[data-test="timeline"]')
                        ?.innerText ?? '',
                ) &&
                (await window.__trip.map().getSource('trip-lines').getData())
                    .features.length === 5,
            null,
            { timeout: 60000, polling: 500 },
        )
        .catch(() => {});
    const landing = await byTest('timeline').innerText();
    check(
        'day 1 lands, rides in by car, checks in and rests',
        /Land at Shanghai Pudong/.test(landing) &&
            /by car/.test(landing) &&
            /Check in at Hotel near People's Square/.test(landing) &&
            /Rest after the flight/.test(landing),
    );
    check(
        'getting there before check-in is flagged',
        /leave the bags/.test(
            await byTest('warnings')
                .innerText()
                .catch(() => ''),
        ),
    );
    const onMap = await page.evaluate(async () => {
        const map = window.__trip.map();

        return {
            stops: (await map.getSource('trip-stops').getData()).features
                .length,
            lines: (await map.getSource('trip-lines').getData()).features
                .length,
        };
    });
    check(
        'the map numbers every stop and draws each day’s way and the airport rides',
        onMap.stops === 8 && onMap.lines === 5,
        JSON.stringify(onMap),
    );
    await page.screenshot({ path: `${SHOTS}/trip-day1.png` });

    // A leg typed in: Metro, 20 minutes, ¥4
    const costNow = async () =>
        Number(
            (await byTest('trip-cost').innerText()).replace(/[^\d.]/g, ''),
        ) || 0;
    const before = await costNow();
    const leg = byTest('leg').nth(1);
    await leg.hover();
    await leg.locator('[data-test="leg-edit"]').click();
    await byTest('leg-mode-metro').click();
    await byTest('leg-minutes').fill('20');
    await byTest('leg-cost').fill('4');
    await byTest('leg-note').fill('Line 2');
    await byTest('leg-save').click();
    const typed = await byTest('leg-manual')
        .innerText()
        .catch(() => '');
    check(
        'a leg can be typed in -- Metro, minutes, fare and line',
        /20 min metro · ¥4 · Line 2/.test(typed),
        typed,
    );
    check(
        'its fare counts toward the trip',
        (await costNow()) - before === 4,
        `${before} → ${await costNow()}`,
    );

    // Hours typed in, used like Google's: the first sight shut that Friday
    await byTest('stop').first().locator('[data-test="stop-edit"]').click();
    await byTest('hours-input').fill('Closed');
    await byTest('stop-done').click();
    check(
        'hours typed in raise a warning',
        /closed on Fridays/.test(
            await byTest('warnings')
                .innerText()
                .catch(() => ''),
        ),
    );

    // Dragging the last sight to the top
    const names = () =>
        byTest('stop').locator('button.font-medium').allInnerTexts();
    const order = await names();
    // Held by its times, as a person would: the middle of the row is the
    // button that opens the stop's settings
    await byTest('stop')
        .nth(2)
        .dragTo(byTest('stop').nth(0), {
            sourcePosition: { x: 12, y: 10 },
            targetPosition: { x: 12, y: 10 },
        });
    await page.waitForTimeout(300);
    check(
        'dragging a stop reorders the day',
        (await names())[0] === order[2],
        (await names()).join(' → '),
    );

    // Day 3 zig-zags across the river: the quickest order walks less
    await byTest('day-3').click();
    await timed();
    const routed = (n) =>
        page.evaluate(
            (n) =>
                window.__trip.plan.routes[
                    window.__trip.plan.trip.days[n - 1].id
                ]?.route?.seconds ?? 0,
            n,
        );
    const zigzag = await routed(3);
    await byTest('quickest').click();
    await page
        .waitForFunction(
            (before) => {
                const plan = window.__trip.plan;
                const seconds =
                    plan.routes[plan.trip.days[2].id]?.route?.seconds;

                return seconds && seconds !== before;
            },
            zigzag,
            { timeout: 40000 },
        )
        .catch(() => {});
    check(
        'the quickest order walks less',
        (await routed(3)) < zigzag,
        `${Math.round(zigzag / 60)} → ${Math.round((await routed(3)) / 60)} min`,
    );
    check(
        'the last day ends at the airport for the flight',
        /flight 21:30 -- be there by 18:30/.test(
            await byTest('timeline').innerText(),
        ),
    );

    // A place clicked on the map goes into the day
    const tripBox = await byTest('trip-map').boundingBox();
    const stopsOnDay3 = await page.evaluate(
        () => window.__trip.plan.trip.days[2].stops.length,
    );
    await page.mouse.click(
        tripBox.x + tripBox.width * 0.85,
        tripBox.y + tripBox.height * 0.15,
    );
    await byTest('trip-place-card').waitFor();
    await byTest('add-to-day').click();
    check(
        'a place on the map can be added to the day',
        (await page.evaluate(
            () => window.__trip.plan.trip.days[2].stops.length,
        )) ===
            stopsOnDay3 + 1,
    );
    await byTest('trip-place-card').locator('[aria-label="Close"]').click();

    // The hotels are a click away
    await byTest('open-hotels').click();
    check(
        'the hotels dialog shows the booking and its nights',
        /2 nights/.test(await byTest('hotels-dialog').innerText()),
    );
    const beforeHotel = await costNow();
    await byTest('stay-cost').first().fill('1000');
    check(
        'what the hotel costs counts toward the trip, once',
        (await costNow()) - beforeHotel === 1000,
        `${beforeHotel} → ${await costNow()}`,
    );
    await page.keyboard.press('Escape');

    // Saved by itself, and all there after a reload
    await page
        .waitForFunction(
            () =>
                document.querySelector('[data-test="save-status"]')?.dataset
                    .status === 'saved',
            null,
            { timeout: 15000 },
        )
        .catch(() => {});
    check(
        'it saves itself',
        (await byTest('save-status').getAttribute('data-status')) === 'saved',
    );
    await page.reload({ waitUntil: 'networkidle' });
    await tripReady();
    const kept = await page.evaluate(() => {
        const days = window.__trip.plan.trip.days;

        return {
            legs: Object.keys(days[0].legs).length,
            closed: days[0].stops.some(
                (stop) => stop.hours?.friday === 'Closed',
            ),
            added: days[2].stops.length,
        };
    });
    check(
        'the typed leg, the hours and the added place survive a reload',
        kept.legs === 1 && kept.closed && kept.added === stopsOnDay3 + 1,
        JSON.stringify(kept),
    );

    // Someone else saves while nothing is unsaved here: it just shows up
    await request(tripPath, 'PATCH', { title: 'Shanghai (from elsewhere)' });
    await page
        .waitForFunction(
            () =>
                document.querySelector('[data-test="trip-title"]')?.value ===
                'Shanghai (from elsewhere)',
            null,
            { timeout: 15000 },
        )
        .catch(() => {});
    check(
        'a save from elsewhere shows up by itself, without asking',
        (await byTest('trip-title').inputValue()) ===
            'Shanghai (from elsewhere)' &&
            !(await byTest('trip-conflict').isVisible()),
        await byTest('trip-title').inputValue(),
    );

    // With a change of its own on the way, the page asks instead. Its save
    // (the one sent with X-Requested-With) is held back until the other has
    // landed, so the order is the same every run
    let release;
    const held = new Promise((resolve) => (release = resolve));
    await page.route(`**${tripPath}`, async (route) => {
        if (route.request().headers()['x-requested-with']) {
            await held;
        }

        await route.continue();
    });
    await byTest('trip-title').fill('Shanghai, mine');
    await page.waitForFunction(
        () =>
            document.querySelector('[data-test="save-status"]')?.dataset
                .status === 'saving',
        null,
        { timeout: 5000 },
    );
    await request(tripPath, 'PATCH', { title: 'Shanghai (again)' });
    release();
    await byTest('trip-conflict').waitFor({ timeout: 15000 });
    await page.unroute(`**${tripPath}`);
    check('a save over someone else’s newer one asks first', true);
    await byTest('take-theirs').click();
    // The turned-away save is a 409 the browser logs as an error: expected here
    app.problems.splice(
        0,
        app.problems.length,
        ...app.problems.filter((problem) => !/status of 409/.test(problem)),
    );
    check(
        'taking theirs shows their version',
        (await byTest('trip-title').inputValue()) === 'Shanghai (again)',
    );
    await page.screenshot({ path: `${SHOTS}/trip-after.png` });

    // ---- a table with places in it ----

    const table = await request('/tables', 'POST', { title: 'Sites' });
    const tablePath = new URL(table.url).pathname;
    afterwards(() => removeAt(page, tablePath));
    const tableRef = tablePath.split('/').pop();
    const column = (label, type, extra = {}) =>
        request(`/tables/${tableRef}/columns`, 'POST', {
            label,
            type,
            ...extra,
        });
    await column('Name', 'varchar');
    await column('Status', 'select', {
        options: [
            { id: 'a', value: 'Active', color: 'emerald' },
            { id: 'l', value: 'Lead', color: 'sky' },
        ],
    });
    await column('Where', 'location');

    const rowsMade = [
        [
            'Siam Paragon',
            'Active',
            { lat: 13.7462, lng: 100.5347, label: 'Siam Paragon' },
        ],
        [
            'Yu Garden',
            'Lead',
            { lat: 31.2272, lng: 121.4921, label: 'Yu Garden' },
        ],
        ['The Bund', 'Active', null],
    ];

    for (const [name, status, where] of rowsMade) {
        const row = (await request(`/tables/${tableRef}/rows`, 'POST')).json
            .row;

        for (const [key, value] of [
            ['name', name],
            ['status', status],
            ['where', where],
        ]) {
            await request(`/tables/${tableRef}/rows/${row.id}`, 'PATCH', {
                column: key,
                value,
            });
        }
    }

    await page.goto(`${base}${tablePath}`, { waitUntil: 'networkidle' });
    check(
        'a table with a place in it offers a map',
        (await byTest('table-view').count()) === 1,
    );

    // The Bund's place, chosen in its cell by searching
    const cells = byTest('cell-location');
    check(
        'a location cell shows its place',
        /Siam Paragon/.test(await cells.first().innerText()),
    );
    await cells.nth(2).click();
    const cellSearch = page.locator('[data-test="place-search"]').last();
    await cellSearch.fill('The Bund Shanghai');
    await byTest('place-suggestions')
        .locator('li')
        .first()
        .waitFor({ timeout: 15000 });
    await page.waitForTimeout(500);
    // Photon puts a restaurant in Canada called "The Bund Shanghai" first: a
    // person picks the one in Shanghai's Huangpu district, and so does this
    await byTest('place-suggestions')
        .locator('li', { hasText: '黄浦区' })
        .first()
        .click();
    await page.waitForTimeout(800);
    check(
        'a place chosen in the cell is kept',
        /外滩|Bund/.test(await cells.nth(2).innerText()) &&
            /黄浦区/.test(await cells.nth(2).innerText()),
        (await cells.nth(2).innerText()).trim(),
    );

    // The rows on a map, coloured by status
    await byTest('view-map').click();
    await page.waitForFunction(() => window.__tableMap?.map()?.loaded(), null, {
        timeout: 30000,
    });
    check(
        'the map shows every row with a place',
        /3 of 3 rows placed/.test(await byTest('map-count').innerText()),
        await byTest('map-count').innerText(),
    );
    check(
        'and colours them by a choice column, with a legend',
        /Active\s*2/.test(await byTest('map-legend').innerText()) &&
            /Lead\s*1/.test(await byTest('map-legend').innerText()),
        (await byTest('map-legend').innerText()).replace(/\n/g, ' '),
    );
    await page.screenshot({ path: `${SHOTS}/table-map.png` });

    // A row picked on the map, then found in the grid
    await page
        .waitForFunction(() => window.__tableMap.map()?.loaded(), null, {
            timeout: 20000,
        })
        .catch(() => {});
    const dot = await page.evaluate(() => {
        const map = window.__tableMap.map();
        map.jumpTo({ center: [100.5347, 13.7462], zoom: 14 });

        return null;
    });
    void dot;
    await page.waitForTimeout(1200);
    const mapBox = await byTest('table-map').boundingBox();
    await page.mouse.click(
        mapBox.x + mapBox.width / 2,
        mapBox.y + mapBox.height / 2,
    );
    await byTest('map-row-card').waitFor({ timeout: 10000 });
    check(
        'a dot opens its row',
        /Siam Paragon/.test(await byTest('map-row-card').innerText()),
    );
    await byTest('map-row-card')
        .getByRole('button', { name: /show in the grid/i })
        .click();
    check(
        'and leads back to it in the grid',
        (await byTest('view-grid').getAttribute('aria-selected')) === 'true' &&
            (await page.evaluate(
                () =>
                    document.querySelectorAll('[data-test="cell-location"]')
                        .length,
            )) === 3,
    );
} catch (error) {
    app.problems.push(`threw: ${error.message.split('\n')[0].slice(0, 200)}`);
}

// A trip page joins its live channel as it opens; reloading or leaving it
// moments later, as this suite does, closes the socket mid-handshake
app.problems.splice(
    0,
    app.problems.length,
    ...app.problems.filter(
        // Cut short by the harness: "...failed: WebSocket is closed be"
        (problem) => !/failed: WebSocket is closed/.test(problem),
    ),
);

process.exitCode = (await app.done()) ? 1 : 0;
