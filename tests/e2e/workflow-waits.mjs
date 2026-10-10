// Steps that wait, run by the worker: an approval answered from the run
// panel, a delay that carries on by itself, and a run stopped while it waits.
import { SHOTS, runBoard, tinker } from './harness.mjs';

const TITLE = `e2e waits ${Date.now().toString().slice(-6)}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

// start → ask → approved: yes / rejected: no;  start → pause (3 s) → later
const definition = {
    nodes: [
        {
            id: 'start',
            key: 'start',
            type: 'trigger.manual',
            position: { x: 0, y: 120 },
            config: { payload: '{"amount": 40}' },
        },
        {
            id: 'ask',
            key: 'ask',
            type: 'logic.approval',
            position: { x: 320, y: 0 },
            config: { message: 'Refund {{ trigger.amount }}?' },
        },
        {
            id: 'yes',
            key: 'yes',
            type: 'output.log',
            position: { x: 640, y: -60 },
            config: { message: 'refunded {{ trigger.amount }}' },
        },
        {
            id: 'no',
            key: 'no',
            type: 'output.log',
            position: { x: 640, y: 60 },
            config: { message: 'kept' },
        },
        {
            id: 'pause',
            key: 'pause',
            type: 'logic.delay',
            position: { x: 320, y: 240 },
            // Long enough that Stop comes first, even on a busy machine
            config: { amount: '6', unit: 'seconds' },
        },
        {
            id: 'later',
            key: 'later',
            type: 'output.log',
            position: { x: 640, y: 240 },
            config: { message: 'after the pause' },
        },
    ],
    edges: [
        { id: 'e1', source: 'start', sourceHandle: 'out', target: 'ask' },
        { id: 'e2', source: 'ask', sourceHandle: 'approved', target: 'yes' },
        { id: 'e3', source: 'ask', sourceHandle: 'rejected', target: 'no' },
        { id: 'e4', source: 'start', sourceHandle: 'out', target: 'pause' },
        { id: 'e5', source: 'pause', sourceHandle: 'out', target: 'later' },
    ],
};

const ref = tinker(
    `$w = (new App\\Models\\Workflow\\Workflow)->ownedBy(${user}, ${user}); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(definition)}', true); $w->save(); echo $w->ref_id;`,
);

await runBoard(
    `/workflows/${ref}`,
    async ({ page, check, afterwards }) => {
        afterwards(() =>
            tinker(
                `${user}->workflows()->where('title', '${TITLE}')->delete();`,
            ),
        );

        const runStatus = page.locator('[data-run-status]');
        const stepIs = (key) =>
            page
                .locator(`[data-run-step="${key}"]`)
                .getAttribute('data-run-step-status');

        await page.locator('[data-step="ask"]').waitFor();
        const askText = await page.locator('[data-step="ask"]').innerText();
        check(
            'an approval step shows its two ways out, named',
            askText.includes('approved') && askText.includes('rejected'),
            askText.replace(/\s+/g, ' '),
        );

        // --- an approval, and a delay beside it ---
        await page.locator('[data-run]').click();
        await page.locator('[data-approval]').waitFor({ timeout: 20000 });
        check(
            'the run panel asks for the approval, its message worked out',
            (await page.locator('[data-approval]').innerText()).includes(
                'Refund 40?',
            ),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-approval.png` });

        // The delay carries on by itself meanwhile
        await page.waitForFunction(
            () =>
                document
                    .querySelector('[data-run-step="later"]')
                    ?.getAttribute('data-run-step-status') === 'succeeded',
            null,
            { timeout: 30000 },
        );
        check(
            'a delay carries on by itself while the approval still waits',
            (await stepIs('pause')) === 'succeeded' &&
                (await runStatus.innerText()).includes('Waiting'),
            await runStatus.innerText(),
        );

        await page.getByLabel('Note').fill('Looks right');
        await page.locator('[data-approve]').click();
        await runStatus
            .filter({ hasText: 'Succeeded' })
            .waitFor({ timeout: 30000 });
        check(
            'approving carries the run on down "approved", and it ends',
            (await stepIs('yes')) === 'succeeded' &&
                (await stepIs('no')) === 'skipped',
        );
        // The log is asked for a moment after the status arrives
        await page
            .locator('[data-run-logs]', { hasText: 'refunded 40' })
            .waitFor({ timeout: 10000 })
            .catch(() => {});
        check(
            'the log has both ways it went',
            (await page.locator('[data-run-logs]').innerText()).includes(
                'refunded 40',
            ) &&
                (await page.locator('[data-run-logs]').innerText()).includes(
                    'after the pause',
                ),
            (await page.locator('[data-run-logs]').innerText()).replace(
                /\s+/g,
                ' ',
            ),
        );

        await page.locator('[data-run-step="ask"]').click();
        check(
            'the approval says who answered, and their note',
            (await page.locator('[data-run-panel]').innerText()).includes(
                'Approved by Test User: “Looks right”',
            ),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-approved.png` });

        // --- stopped while it waits ---
        await page.locator('[data-run]').click();
        await page.locator('[data-approval]').waitFor({ timeout: 20000 });
        await page.locator('[data-run-cancel]').click();
        await runStatus
            .filter({ hasText: 'Cancelled' })
            .waitFor({ timeout: 10000 });
        await page
            .waitForFunction(
                () =>
                    document
                        .querySelector('[data-run-step="yes"]')
                        ?.getAttribute('data-run-step-status') === 'cancelled',
                null,
                { timeout: 5000 },
            )
            .catch(() => {});
        check(
            'Stop ends a waiting run, and what waited or had not begun is cancelled',
            (await stepIs('ask')) === 'cancelled' &&
                (await stepIs('yes')) === 'cancelled',
        );

        // Its delay, due a moment later, does nothing
        await page.waitForTimeout(7000);
        const stillStopped = tinker(
            `$r = App\\Models\\Workflow\\Workflow::where('ref_id', '${ref}')->first()->runs()->latest('id')->first(); echo $r->status->value.' '.$r->steps()->where('node_key', 'later')->first()->status->value;`,
        );
        check(
            'its delay, due after, never wakes it',
            stillStopped === 'cancelled cancelled',
            stillStopped,
        );
    },
    { canvas: false },
);
