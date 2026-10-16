// The steps most workflows want, in the editor: a feed read in Web, the new
// data steps in Data, and Slack, Discord and Email each a group of its own;
// a step's form showing only what its choices need. And their connections:
// an SMTP server asks for what sending needs, and a Slack webhook of the
// wrong address is refused, nothing kept.
import { paletteGroup, runBoard, SHOTS, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e common ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'start',
                    key: 'start',
                    type: 'trigger.manual',
                    label: 'Run by hand',
                    position: { x: 0, y: 120 },
                    config: { payload: '{}' },
                },
                {
                    id: 'when',
                    key: 'when',
                    type: 'data.date',
                    label: 'Date and time',
                    position: { x: 320, y: 120 },
                    config: { value: '', from: 'auto', format: 'iso' },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'start',
                    sourceHandle: 'out',
                    target: 'when',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

// One connection already, so the page lists them under an "Add" button
tinker(
    `$u = ${user}; $c = new App\\Models\\Connection(['name' => 'e2e common ${RUN} secret', 'service' => 'webhook_secret', 'settings' => []]); $c->secrets = ['secret' => 'e2e']; $c->ownedBy($u, $u)->save(); echo $c->ref_id;`,
);

const forget = () =>
    tinker(
        `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); $u->connections()->where('name', 'like', 'e2e common ${RUN}%')->delete(); echo 'ok';`,
    );

try {
    await runBoard(
        '/settings/connections',
        async ({ page, check, soon }) => {
            const sheet = page.locator('[data-slot="sheet-content"]');

            await page.locator('[data-connection-add]').click();
            await sheet.locator('[data-service="smtp"]').click();
            check(
                'a mail server asks for its server, port, user, whom it sends from and a password',
                (
                    await Promise.all(
                        [
                            'host',
                            'port',
                            'username',
                            'from',
                            'from_name',
                            'password',
                        ].map((field) =>
                            sheet.locator(`#connection-${field}`).count(),
                        ),
                    )
                ).every((count) => count === 1),
            );
            await page.screenshot({ path: `${SHOTS}/connection-smtp.png` });
            await sheet.getByRole('button', { name: 'Cancel' }).click();

            await page.locator('[data-connection-add]').click();
            await sheet.locator('[data-service="slack"]').click();
            await sheet.locator('#connection-name').fill(`e2e common ${RUN}`);
            await sheet
                .locator('#connection-url')
                .fill('https://evil.example.com/services/x');
            await sheet.locator('[data-connection-save]').click();
            await soon('a webhook of another address is refused', async () =>
                (await sheet.innerText()).includes(
                    "That is not a webhook's address",
                ),
            );
            check(
                'and nothing is kept',
                tinker(
                    `echo ${user}->connections()->where('name', 'e2e common ${RUN}')->count();`,
                ) === '0',
            );
        },
        { canvas: false },
    );

    await runBoard(
        `/workflows/${ref}`,
        async ({ page, check, soon }) => {
            const kindsIn = async (name) =>
                (await paletteGroup(page, name))
                    .locator('[data-palette-step]')
                    .evaluateAll((steps) =>
                        steps.map((step) =>
                            step.getAttribute('data-palette-step'),
                        ),
                    );

            await soon('the palette is there', async () =>
                page.locator('[data-step-palette]').isVisible(),
            );
            const data = await kindsIn('Data');
            check(
                'Data has the new steps',
                [
                    'data.dedupe',
                    'data.csv',
                    'data.date',
                    'data.number',
                    'data.text',
                    'data.hash',
                ].every((kind) => data.includes(kind)),
                data.join(', '),
            );
            check(
                'a feed is read in Web',
                (await kindsIn('Web')).includes('feed.read'),
            );
            check(
                'Slack, Discord and Email are groups of their own',
                JSON.stringify([
                    await kindsIn('Slack'),
                    await kindsIn('Discord'),
                    await kindsIn('Email'),
                ]) ===
                    JSON.stringify([
                        ['slack.send'],
                        ['discord.send'],
                        ['mail.send'],
                    ]),
            );

            const settings = page.locator('[data-step-settings]');
            await page.locator('[data-step="when"]').click();
            await settings.waitFor();
            check(
                'a date step asks for no pattern until it is told the date is written in one',
                (await settings.getByText('Its pattern').count()) === 0 &&
                    (await settings.getByText('Write it as').count()) > 0,
            );
            await page.screenshot({ path: `${SHOTS}/workflow-date-step.png` });
        },
        { canvas: false },
    );
} finally {
    forget();
}
