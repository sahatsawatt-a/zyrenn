// An erDiagram in a note turned into SQL from its toolbar: inserted below it,
// rewritten in place in another dialect, and copied.
//
// The note is made over the app's own MCP endpoint (/mcp/user) with a token
// for the test account, as mcp-blocks.mjs does, and taken away again after.
import { execFileSync } from 'node:child_process';
import { SHOTS, runBoard } from './harness.mjs';

const APP_CONTAINER = process.env.E2E_APP_CONTAINER ?? 'zyrenn-app-1';
const RUN = Date.now().toString().slice(-6);
const TOKEN_NAME = `e2e er-sql ${RUN}`;

const DIAGRAM = `erDiagram
  USER ||--o{ NOTE : writes
  NOTE }o--o{ TAG : "is tagged"
  USER {
    int id PK
    string email UK
  }
  NOTE {
    int id PK
    int user_id FK
    string title
  }
  TAG {
    int id PK
    string label
  }`;

const tinker = (php) =>
    execFileSync(
        'docker',
        ['exec', APP_CONTAINER, 'php', 'artisan', 'tinker', '--execute', php],
        { encoding: 'utf8' },
    )
        .trim()
        .split('\n')
        .pop();

await runBoard(
    '/notes',
    async ({ page, check, afterwards }) => {
        const base = new URL(page.url()).origin;
        const token = tinker(
            `echo App\\Models\\User::where('email', 'test@example.com')->first()->createToken('${TOKEN_NAME}', ['mcp'])->plainTextToken;`,
        );
        afterwards(() =>
            tinker(
                `App\\Models\\User::where('email', 'test@example.com')->first()->tokens()->where('name', '${TOKEN_NAME}')->delete();`,
            ),
        );

        const response = await fetch(`${base}/mcp/user`, {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${token}`,
                'Content-Type': 'application/json',
                Accept: 'application/json, text/event-stream',
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: 1,
                method: 'tools/call',
                params: {
                    name: 'create-note',
                    arguments: {
                        title: `ER to SQL ${RUN}`,
                        markdown: `Schema:\n\n\`\`\`mermaid\n${DIAGRAM}\n\`\`\`\n\nThe end.`,
                    },
                },
            }),
        });
        const ref = (await response.json()).result.structuredContent.ref_id;
        afterwards(() =>
            tinker(
                `App\\Models\\Note\\Note::where('ref_id', '${ref}')->first()?->delete();`,
            ),
        );

        await page
            .context()
            .grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.goto(`${base}/notes/${ref}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.mermaid-svg svg', { timeout: 15000 });

        const sqlBlocks = () =>
            page
                .locator('.code-block-wrapper')
                .filter({ has: page.locator('select', { hasText: 'SQL' }) })
                .locator('pre code')
                .allInnerTexts();

        const menu = async (item) => {
            await page.locator('[data-test="mermaid-sql"]').click();
            if (item) await page.locator(`[data-test="${item}"]`).click();
        };

        // ------------------------------------------------ Insert it below
        check(
            'an erDiagram offers SQL',
            await page.locator('[data-test="mermaid-sql"]').isVisible(),
        );
        await menu();
        await page.getByRole('menuitemradio', { name: 'PostgreSQL' }).click();
        check(
            'the menu says it will insert, with nothing below yet',
            await page
                .locator('[data-test="mermaid-sql-insert"]')
                .innerText()
                .then((text) => text.includes('Insert SQL below')),
        );
        await page.locator('[data-test="mermaid-sql-insert"]').click();
        await page.waitForTimeout(500);

        let blocks = await sqlBlocks();
        check(
            'a SQL block lands under the diagram',
            blocks.length === 1 &&
                blocks[0].includes('(PostgreSQL)') &&
                blocks[0].includes('CREATE TABLE "user"') &&
                blocks[0].includes(
                    'FOREIGN KEY (user_id) REFERENCES "user" (id)',
                ) &&
                blocks[0].includes('CREATE TABLE note_tag'),
            blocks[0]?.split('\n').slice(0, 3).join(' / '),
        );
        const order = await page
            .locator('.ProseMirror > *')
            .evaluateAll((nodes) =>
                nodes.map((node) =>
                    node.classList.contains('mermaid-block')
                        ? 'diagram'
                        : node.classList.contains('code-block-wrapper')
                          ? 'sql'
                          : node.textContent.trim().slice(0, 10),
                ),
            );
        check(
            'right after it, before what followed',
            order.join(',') === 'Schema:,diagram,sql,The end.',
            order.join(','),
        );
        await page.screenshot({ path: `${SHOTS}/er-sql.png`, fullPage: true });

        // ---------------------------- Again, in MySQL: rewritten, not stacked
        await menu();
        await page.getByRole('menuitemradio', { name: 'MySQL' }).click();
        check(
            'with SQL below, the menu offers to update it',
            await page
                .locator('[data-test="mermaid-sql-insert"]')
                .innerText()
                .then((text) => text.includes('Update SQL below')),
        );
        await page.screenshot({ path: `${SHOTS}/er-sql-menu.png` });
        await page.locator('[data-test="mermaid-sql-insert"]').click();
        await page.waitForTimeout(500);

        blocks = await sqlBlocks();
        check(
            'the same block now holds MySQL',
            blocks.length === 1 &&
                blocks[0].includes('(MySQL)') &&
                blocks[0].includes('CREATE TABLE `user`') &&
                blocks[0].includes('AUTO_INCREMENT'),
            `${blocks.length} block(s)`,
        );

        // ---------------------------------------------------------- Copy it
        await menu('mermaid-sql-copy');
        await page.waitForTimeout(300);
        const copied = await page.evaluate(() =>
            navigator.clipboard.readText(),
        );
        check(
            'Copy SQL puts the script on the clipboard',
            copied.startsWith('-- Generated from a Mermaid erDiagram (MySQL)'),
            copied.split('\n')[0],
        );

        // --------------------------------------- Saved, as the note's own
        await page.waitForTimeout(1500);
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('.mermaid-svg svg', { timeout: 15000 });
        blocks = await sqlBlocks();
        check(
            'and it is still there after a reload',
            blocks.length === 1 && blocks[0].includes('(MySQL)'),
        );

        // ----------------------------- Diagrams of other kinds offer nothing
        check(
            'only an erDiagram has the SQL menu',
            (await page.locator('[data-test="mermaid-sql"]').count()) === 1,
        );
    },
    { canvas: false },
);
