// An erDiagram in a note turned into SQL from its toolbar: inserted below it,
// rewritten in place in another dialect, and copied.
//
// The note is made over the app's own MCP endpoint (see makeNote) and taken
// away again after.
import { SHOTS, makeNote, runBoard } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

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

// Lines that bend just short of a box: Mermaid drew their circles off the line
const CROWDED = `erDiagram
  USER ||--o{ NOTE : writes
  USER ||--|| PROFILE : has
  PROJECT |o--o{ NOTE : holds
  USER }o--o{ PROJECT : "is a member of"
  FOLDER |o--o{ FOLDER : contains
  FOLDER |o--o{ NOTE : files
  NOTE }o--o{ TAG : "is tagged"
  USER {
    int id PK
    string email UK
  }
  PROFILE {
    int id PK
  }
  PROJECT {
    uuid id PK
  }
  FOLDER {
    int id PK
    int parent_id FK
  }
  NOTE {
    int id PK
    int user_id FK
  }
  TAG {
    int id PK
  }`;

await runBoard(
    '/notes',
    async (board) => {
        const { page, check } = board;
        const base = new URL(page.url()).origin;
        const ref = await makeNote(
            board,
            `ER to SQL ${RUN}`,
            `Schema:\n\n\`\`\`mermaid\n${DIAGRAM}\n\`\`\`\n\nThe end.`,
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

        // ------------------------------- Every label fits the box it was given
        // (the note's own paragraph style once drew them past it: "writes"
        // came out as "write")
        const overflowing = await page
            .locator('.mermaid-svg svg foreignObject')
            .evaluateAll((boxes) =>
                boxes
                    .filter((box) => {
                        const label = box.firstElementChild;
                        return (
                            label &&
                            label.scrollWidth >
                                Number(box.getAttribute('width')) + 1
                        );
                    })
                    .map((box) => box.textContent),
            );
        check(
            'every label fits inside its box',
            overflowing.length === 0,
            overflowing.join(', ') || 'all fit',
        );
        await page
            .locator('.mermaid-block')
            .screenshot({ path: `${SHOTS}/er-labels.png` });

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

        // ------------------- Markers sit on a straight stretch of their line
        const crowded = await makeNote(
            board,
            `ER markers ${RUN}`,
            `\`\`\`mermaid\n${CROWDED}\n\`\`\``,
        );
        await page.goto(`${base}/notes/${crowded}`, {
            waitUntil: 'networkidle',
        });
        await page.waitForSelector('.mermaid-svg svg', { timeout: 15000 });
        const stretches = await page.evaluate(() =>
            [
                ...document.querySelectorAll(
                    '.mermaid-svg path.relationshipLine[data-points]',
                ),
            ].map((line) => {
                const points = JSON.parse(atob(line.dataset.points));
                const run = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
                const n = points.length;
                return {
                    id: line.dataset.id.replace(/id_entity-|-\d+/g, ''),
                    first: Math.round(run(points[0], points[1])),
                    last: Math.round(run(points[n - 2], points[n - 1])),
                };
            }),
        );
        const cramped = stretches.filter(
            (line) => line.first < 40 || line.last < 40,
        );
        check(
            'every marker has a straight stretch of line to sit on',
            stretches.length === 7 && cramped.length === 0,
            cramped.map((l) => `${l.id} ${l.first}/${l.last}`).join(', ') ||
                `${stretches.length} lines`,
        );
        await page
            .locator('.mermaid-block')
            .screenshot({ path: `${SHOTS}/er-markers.png` });
    },
    { canvas: false },
);
