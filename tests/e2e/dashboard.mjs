// The dashboards: one's own and a project's -- what is in each, the to-dos
// still open in its notes, its members, and the sidebar and project switcher
// keeping to the dashboard. Makes a project shared with E2E_MEMBER
// (e2e-member@example.com) and a note over MCP, and takes both away again.
import { runBoard, makeNote, shareProject, deleteProject, SHOTS } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

await runBoard('/dashboard', async (ctx) => {
    const { page, check, afterwards } = ctx;
    await makeNote(ctx, `Dash todos ${RUN}`, `- [ ] Personal task ${RUN}\n- [x] Done task ${RUN}\n`);
    await page.goto(page.url(), { waitUntil: 'networkidle' });
    const todos = await page.locator('[data-test="todos"]').innerText();
    check('own to-do listed (Postgres)', todos.includes(`Personal task ${RUN}`) && !todos.includes(`Done task ${RUN}`), todos.slice(0, 120).replace(/\n/g, ' | '));

    const { project, base } = await shareProject(page, `Dash ${RUN}`, process.env.E2E_MEMBER ?? 'e2e-member@example.com');
    afterwards(() => deleteProject(page, base, project));

    await page.goto(`${base}/p/${project}`, { waitUntil: 'networkidle' });
    check('project opens on its dashboard', page.url().endsWith(`/p/${project}/dashboard`), page.url());
    const members = await page.locator('[data-test="members"]').innerText();
    check('members listed', members.includes('(you)') && /Editor/.test(members), members.replace(/\n/g, ' | '));
    const sidebarDash = await page.locator('[data-sidebar="menu-button"]', { hasText: 'Dashboard' }).getAttribute('href');
    check('sidebar dashboard link follows project', (sidebarDash ?? '').endsWith(`/p/${project}/dashboard`), sidebarDash);

    await page.locator('[data-test="new-note"]').click();
    await page.waitForURL(/\/notes\/\w+$/);
    await page.waitForSelector('.ProseMirror');
    await page.waitForTimeout(800);
    await page.locator('.ProseMirror').click();
    await page.keyboard.type(`[ ] Project task ${RUN}`);
    await page.waitForTimeout(3000); // autosave / collab store

    await page.goto(`${base}/p/${project}/dashboard`, { waitUntil: 'networkidle' });
    const ptodos = await page.locator('[data-test="todos"]').innerText();
    check('project to-do listed', ptodos.includes(`Project task ${RUN}`), ptodos.slice(0, 120).replace(/\n/g, ' | '));
    check('new note in recent activity', (await page.locator('[data-test="recent"] a').count()) === 1);
    await page.screenshot({ path: `${SHOTS}/dash-project.png`, fullPage: true });

    await page.locator('[data-test="project-switcher"]').click();
    await page.getByRole('menuitem', { name: /Personal/ }).click();
    await page.waitForTimeout(1000);
    check('switcher keeps to the dashboard', new URL(page.url()).pathname === '/dashboard', page.url());
    const proj = await page.locator('[data-test="projects"]').innerText();
    check('project in own list', proj.includes(`Dash ${RUN}`), proj.replace(/\n/g, ' | '));
}, { canvas: false });
