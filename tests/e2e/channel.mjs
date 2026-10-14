// A custom channel: any messaging platform with an API of its own, added on
// a project's connections page, its hook address shown there -- and a signed
// message sent to that address arriving in the project's inbox as a chat of
// its own, named for the platform.
import { createHmac } from 'node:crypto';
import {
    SHOTS,
    deleteProject,
    open,
    runBoard,
    shareProject,
    tinker,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);
const SECRET = `e2e-secret-${RUN}-0123456789`;

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Channel ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const sheet = page.locator('[data-slot="sheet-content"]');

        // ---------------------------------------- Added on its own page
        await open(page, `${base}/p/${project}/settings/connections`);
        const add = page.locator('[data-connection-add]');
        const start = page.getByRole('button', {
            name: /choose from everything/i,
        });
        await add.or(start).first().waitFor();
        await ((await add.count()) ? add : start).click();
        await sheet.locator('[data-service="channel"]').click();
        check(
            'it asks for the platform, where answers go, and a shared secret',
            (await sheet.locator('#connection-platform').count()) === 1 &&
                (await sheet.locator('#connection-send_url').count()) === 1 &&
                (await sheet.locator('#connection-secret').count()) === 1,
        );
        await sheet.locator('#connection-name').fill('Shop');
        await sheet.locator('#connection-platform').fill('Shop chat');
        await sheet.locator('#connection-secret').fill(SECRET);
        await sheet.locator('[data-connection-save]').click();

        const hook = page.locator('[data-connection-hook]');
        await hook.waitFor();
        check(
            'its hook address is shown, with the shape it takes',
            /\/hooks\/channel\//.test(await hook.innerText()),
            await hook.innerText(),
        );
        check(
            'and it says it has nowhere to send answers yet',
            /Shop chat · nowhere to send answers yet/.test(
                await page.locator('[data-connection]').innerText(),
            ),
        );
        await page.screenshot({ path: `${SHOTS}/channel-connection.png` });

        // --------------------------------------- A chat from the platform
        const PROJECT = `App\\Models\\Project::where((new App\\Models\\Project)->getRouteKeyName(), '${project}')->firstOrFail()`;
        const channel = tinker(
            `echo ${PROJECT}->connections()->where('service', 'channel')->sole()->ref_id;`,
        );
        // Its workflow, from the template, published and switched on as the editor's buttons do
        const call = (method, url, data) =>
            page.evaluate(
                async ({ method, url, data }) => {
                    const token = decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                    );
                    // Only ever a POST or a PATCH, which carry what is sent
                    const init = {
                        method,
                        redirect: 'manual',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': token,
                        },
                    };
                    init.body = JSON.stringify(data);
                    const response = await fetch(url, init);

                    return response.type === 'opaqueredirect'
                        ? 302
                        : response.status;
                },
                { method, url, data },
            );
        await call('POST', `${base}/p/${project}/workflows`, {
            template: 'channel-room',
            connections: { channel },
        });
        const [workflow, revision] = tinker(
            `$w = ${PROJECT}->workflows()->sole(); echo $w->ref_id.' '.$w->revision;`,
        ).split(' ');
        const published = await call(
            'POST',
            `${base}/workflows/${workflow}/publish`,
            { revision: Number(revision) },
        );
        const enabled = await call(
            'PATCH',
            `${base}/workflows/${workflow}/enabled`,
            { enabled: true },
        );
        check(
            'its workflow is made from the template and switched on',
            published === 200 && enabled === 200,
            `${published} ${enabled}`,
        );

        const body = JSON.stringify({
            id: `m-${RUN}`,
            chat: { id: `c-${RUN}` },
            sender: { id: 'u-7', name: 'Ada' },
            text: 'Where is my order?',
        });
        const sent = await page.evaluate(
            async ({ url, body, signature }) =>
                (
                    await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Zyrenn-Signature': signature,
                        },
                        body,
                    })
                ).status,
            {
                url: `${base}/hooks/channel/${channel}`,
                body,
                signature: `sha256=${createHmac('sha256', SECRET).update(body).digest('hex')}`,
            },
        );
        check('the platform signs it, and it is taken', sent === 200, sent);

        await open(page, `${base}/p/${project}/inbox`);
        const rows = page.locator('[data-room]');
        await soon(
            'it arrives in the inbox, named for the platform',
            async () => {
                if (!(await rows.count())) {
                    await open(page, `${base}/p/${project}/inbox`);
                }

                const text = (await rows.allInnerTexts()).join(' ');

                return (
                    /Ada on Shop chat/.test(text) &&
                    /Where is my order\?/.test(text)
                );
            },
            async () => (await rows.allInnerTexts()).join(' | '),
            { timeout: 20000, every: 500 },
        );
        await page.screenshot({ path: `${SHOTS}/channel-inbox.png` });
    },
    { canvas: false },
);
