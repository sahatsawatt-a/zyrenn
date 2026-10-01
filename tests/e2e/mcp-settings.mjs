// The MCP settings page: pick a client, get the right snippet for it, with a
// freshly made token filled in.
import { chromium } from 'playwright-core';
import { signIn } from './harness.mjs';

const base = process.env.APP_URL ?? 'http://localhost:8001';
const fail = [];
const check = (ok, what) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}`);
    if (!ok) fail.push(what);
};

const browser = await chromium.launch({
    channel: 'chrome',
    headless: process.env.HEADED !== '1',
});
const { page, context } = await signIn(
    browser,
    base,
    process.env.E2E_EMAIL ?? 'test@example.com',
    process.env.E2E_PASSWORD ?? 'password',
);

const byTest = (id) => page.locator(`[data-test="${id}"]`);

await page.goto(`${base}/settings/mcp`, { waitUntil: 'networkidle' });
await page.evaluate(() => localStorage.clear());
await page.reload({ waitUntil: 'networkidle' });

await page.getByLabel('Token name').fill('e2e-mcp-settings');
await page.getByRole('button', { name: 'Create token' }).click();
const token = (await byTest('new-mcp-token').textContent()).trim();
check(token.length > 20, 'a token is shown once, made');

const snippet = () => byTest('mcp-snippet').textContent();
const pick = (id) => byTest(`client-${id}`).click();

check(
    (await snippet()).startsWith('claude mcp add --transport http zyrenn'),
    'Claude Code is the default, over http',
);
check((await snippet()).includes(token), 'the new token is in the snippet');

const expectations = {
    cursor: ['"url"', '"headers"'],
    vscode: ['"servers"', '"type": "http"'],
    windsurf: ['"serverUrl"'],
    antigravity: ['"serverUrl"'],
    gemini: ['"httpUrl"'],
    codex: ['[mcp_servers.zyrenn]', 'bearer_token_env_var'],
    zed: ['"context_servers"'],
    cline: ['"streamableHttp"'],
    'claude-desktop': ['mcp-remote', 'Bearer ' + token],
};
for (const [id, needles] of Object.entries(expectations)) {
    await pick(id);
    const text = await snippet();
    check(
        needles.every((n) => text.includes(n)),
        `${id}: http snippet has ${needles.join(', ')}`,
    );
}

await byTest('transport-stdio').click();
await pick('vscode');
let text = await snippet();
check(
    text.includes('"type": "stdio"') &&
        text.includes('MCP_TOKEN') &&
        text.includes(token),
    'vscode: stdio snippet',
);
await pick('codex');
text = await snippet();
check(
    text.includes('command = "docker"') && text.includes('MCP_TOKEN'),
    'codex: stdio snippet is TOML',
);
await byTest('transport-http').click();

await pick('other');
check(
    (await snippet()).includes('Authorization: Bearer'),
    'other: plain url and header',
);
check(
    (await byTest('mcp-insecure').count()) === 0,
    'no plain-http warning on localhost',
);

await pick('gemini');
await page.reload({ waitUntil: 'networkidle' });
check(
    (await byTest('client-gemini').getAttribute('aria-pressed')) === 'true',
    'the chosen client is remembered',
);

// The snippet really works: the token it carries is accepted by the server
const res = await page.request.post(`${base}/mcp/user`, {
    headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json, text/event-stream',
    },
    data: {
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
            protocolVersion: '2025-03-26',
            capabilities: {},
            clientInfo: { name: 'e2e', version: '1' },
        },
    },
});
check(res.ok(), 'the token opens /mcp/user');

// Clean up the tokens this made, including any a failed run left behind
const mine = page.getByRole('listitem').filter({ hasText: 'e2e-mcp-settings' });
while (await mine.count()) {
    const before = await mine.count();
    await mine.first().getByRole('button', { name: 'Revoke' }).click();
    await page.waitForFunction(
        ([sel, n]) => document.querySelectorAll(sel).length < n,
        ['li', await page.locator('li').count()],
    );
    if ((await mine.count()) >= before) break;
}
check((await mine.count()) === 0, 'the tokens are revoked');

await context.close();
await browser.close();
if (fail.length) {
    console.log(`\n${fail.length} failed`);
    process.exit(1);
}
