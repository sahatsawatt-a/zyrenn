// Stripe: a connection by a key and a webhook signing secret, whose page
// shows where Stripe is to send; its calls as steps of Stripe's own group, in
// parts; and a trigger whose settings follow what it waits for. Nothing here
// reaches Stripe: the connection is made, not tried, and nothing is run.
import { paletteGroup, runBoard, SHOTS, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e stripe ${RUN}`;
const ACCOUNT_NAME = `e2e stripe ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const account = tinker(
    `$u = ${user}; $c = new App\\Models\\Connection(['name' => '${ACCOUNT_NAME}', 'service' => 'stripe', 'settings' => []]); $c->secrets = ['secret_key' => 'sk_test_e2e-not-a-real-key', 'webhook_secret' => 'whsec_e2e']; $c->ownedBy($u, $u)->save(); echo $c->ref_id;`,
);

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'heard',
                    key: 'heard',
                    type: 'trigger.stripe',
                    label: 'Something happens on Stripe',
                    position: { x: 0, y: 120 },
                    config: { connection: account, on: 'checkout' },
                },
                {
                    id: 'say',
                    key: 'say',
                    type: 'stripe.payment_link_create',
                    label: 'Stripe: make a payment link',
                    position: { x: 320, y: 120 },
                    config: {
                        connection: account,
                        amount: '149.50',
                        name: 'Khao soi for two',
                    },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'heard',
                    sourceHandle: 'out',
                    target: 'say',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

const forget = () =>
    tinker(
        `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); $u->connections()->where('name', '${ACCOUNT_NAME}')->delete();`,
    );

// --- the connection: a key and a webhook signing secret ---
const failedForm = await runBoard(
    '/settings/connections',
    async ({ page, check }) => {
        const sheet = page.locator('[data-slot="sheet-content"]');

        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="stripe"]').click();
        await sheet.locator('#connection-secret_key').waitFor();
        check(
            'a Stripe account asks for a key and a webhook signing secret',
            (await sheet.locator('#connection-webhook_secret').count()) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/connection-stripe.png` });
        await sheet.getByRole('button', { name: 'Cancel' }).click();

        const saved = page.locator('[data-connection]', {
            hasText: ACCOUNT_NAME,
        });
        await saved.waitFor();
        check(
            'a saved one says what it does',
            (await saved.innerText()).includes('Takes payments'),
            await saved.innerText(),
        );

        // Its row shows where Stripe is to send what happens, and how to set it
        const hook = saved.locator('[data-connection-hook]');
        check(
            'it shows its webhook address for Stripe, and where to add it',
            (await hook.innerText()).includes(`/hooks/stripe/${account}`) &&
                (await hook.innerText()).includes('Endpoint URL'),
            await hook.innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/connection-stripe-hook.png` });
    },
    { canvas: false },
);

// --- its steps, in a group of their own; the trigger's settings ---
const failedEditor = await runBoard(
    `/workflows/${ref}`,
    async ({ page, check }) => {
        const settings = page.locator('[data-step-settings]');
        const group = await paletteGroup(page, 'Stripe');
        const kinds = await group
            .locator('[data-palette-step]')
            .evaluateAll((steps) =>
                steps.map((step) => step.getAttribute('data-palette-step')),
            );
        check(
            'Stripe has a group of its own: payments, customers, invoices, products',
            [
                'stripe.payment_link_create',
                'stripe.checkout_create',
                'stripe.refund_create',
                'stripe.customer_find',
                'stripe.invoice_create',
                'stripe.product_create',
                'stripe.request',
            ].every((kind) => kinds.includes(kind)),
            kinds.join(', '),
        );

        await page.locator('[data-step="heard"]').click();
        await settings.waitFor();
        check(
            'the trigger listens through the connection chosen, for paid checkouts',
            (
                await settings.locator('#setting-connection').innerText()
            ).includes(ACCOUNT_NAME) &&
                (await settings.locator('#setting-on').innerText()).includes(
                    'A checkout or payment link is paid',
                ),
        );
        check(
            'waiting for a kind of event, it asks for no event names',
            (await settings.locator('#setting-events').count()) === 0,
        );

        await settings.locator('#setting-on').click();
        await page
            .getByRole('option', { name: 'The events named here' })
            .click();
        await settings.locator('#setting-events').waitFor();
        check('waiting for events by name, it asks which', true);
        await page.screenshot({ path: `${SHOTS}/workflow-stripe.png` });
    },
    { canvas: false },
);

// --- on a phone ---
const failedPhone = await runBoard(
    '/settings/connections',
    async ({ page, check, afterwards }) => {
        afterwards(forget);

        const sheet = page.locator('[data-slot="sheet-content"]');

        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="stripe"]').click();
        await sheet.locator('#connection-secret_key').waitFor();
        check(
            'on a phone the form fits, and nothing runs off the side',
            await page.evaluate(
                () =>
                    document.documentElement.scrollWidth <=
                    document.documentElement.clientWidth,
            ),
        );
        await page.screenshot({ path: `${SHOTS}/connection-stripe-phone.png` });
    },
    { canvas: false, width: 390, height: 844 },
);

// One part's failures are not forgotten for another's passing
if (failedForm || failedEditor || failedPhone) {
    process.exitCode = 1;
}
