# ZyrenN

A personal workspace: notes, boards, and a private Drive behind them.

- **Notes** — a rich editor (Tiptap) filed in folders, saved as you type.
  Markdown in and out, with callouts, tables, code, Mermaid diagrams and KaTeX.
- **Boards** — an endless canvas (Konva): sticky notes, shapes, flowchart
  symbols, connectors that stay joined to what they link, pictures, formulae,
  and 16:9 frames that play as slides.
- **Drive** — the files behind both. A picture on a board or in a note lives
  here and is served only to its owner.
- **MCP** — an AI client can read and write all three. See [Agent access](#agent-access).

## Running ZyrenN locally

Everything runs in Docker; nothing but Docker is needed on the host.

```sh
docker compose up -d                     # postgres, app, nginx, reverb, vite
docker compose logs -f app
docker compose exec app php artisan ...
docker compose exec app php artisan test
docker compose down                      # add -v to drop the database too
```

|          |                                                                 |
| -------- | --------------------------------------------------------------- |
| app      | <http://localhost:8001>                                         |
| vite     | <http://localhost:5173> (dev profile, HMR)                      |
| postgres | `localhost:5434`, database and user `zyrenn`, password `secret` |

Those ports were free when this project was scaffolded; they are recorded in
`.env` as `APP_PORT`, `VITE_PORT` and `FORWARD_DB_PORT`. If you lose track of
which port the app is on, ask Docker rather than reading `.env`:

```sh
docker compose port web 8080
```

### Assets

`COMPOSE_PROFILES=dev` in `.env` runs Vite for HMR. While it runs it writes an
empty `public/hot`, which makes Laravel emit relative asset URLs, and `web`
proxies the dev server on the page's own origin. So `http://localhost:8001`,
this machine's LAN IP and a hostname in front of it all serve the same HTML and
all get HMR, with no dev-server address baked in.

Vite refuses a Host header it does not recognise, so name any host that fronts
it: `APP_HOST`, `VITE_HOST`, or `VITE_ALLOWED_HOSTS` (comma separated) in
`.env`. Bare IP addresses need no entry.

Set `VITE_DEV_ORIGIN` to an absolute URL to skip the proxy and have the browser
talk to the dev server directly — that address then becomes the only one that
works.

To serve the built bundle instead — the mode to deploy — build first, then drop
the profile:

```sh
docker compose exec app npm run build
# remove COMPOSE_PROFILES=dev from .env, then
docker compose up -d
```

Set `APP_DEBUG=false` before exposing the app to anyone else: with it on, any
500 renders a stack trace including config values.

## Tests

```sh
docker compose exec app php artisan test   # the PHP suite
docker compose exec app composer ci:check  # what CI runs: format, lint, types, tests
npm run test:e2e                           # the board, in a real browser
```

`composer ci:check` is the one to run before pushing. It fails on lint
**warnings**, not only errors, which is what CI checks too.

The browser suites in `tests/e2e` drive the running app with Playwright and
system Chrome, because a canvas can type-check perfectly and still refuse to
draw. They need the app up and the database seeded (`php artisan db:seed`
creates `test@example.com` / `password`, which they sign in as):

```sh
npm run test:e2e                     # watch it happen
HEADED=0 npm run test:e2e            # quietly
HEADED=0 node tests/e2e/board.mjs    # one suite
```

`SLOWMO`, `APP_URL`, `E2E_EMAIL` and `E2E_PASSWORD` override the defaults.
Every suite shares `tests/e2e/harness.mjs`, which signs in and hands over the
helpers for reading the canvas back; a suite holds only what is particular to
it.

## Agent access

Two MCP servers, both over HTTP and stdio:

- **`zyrenn`** acts as one person. Make a token in Settings → MCP; every tool
  sees only that user's content.
- **`zyrenn-admin`** reaches every user's content with `MCP_GLOBAL_TOKEN`, and
  takes a `user_id` on each call.

Notes are read and written as Markdown, boards as a list of items, and files
through the Drive. A picture has to be in the Drive before a note or a board
can show it — the tools say so, and hand back the line or URL to use.

```sh
docker compose exec app php artisan mcp:start zyrenn   # stdio, reads MCP_TOKEN
```

## How the code is laid out

Ordinary Laravel and Inertia, with two parts worth a map.

**The board** (`resources/js/components/Board/`) is one flat list of items in
paint order, which keeps z-order, undo and hit-testing simple.

|                                                                             |                                                                            |
| --------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `items.ts`                                                                  | what an item is, and what each kind starts as                              |
| `geometry.ts`, `connectors.ts`, `guides.ts`                                 | boxes and anchors, the path a line takes, the ruler things line up against |
| `useBoard.ts`                                                               | the items, the selection, and undo as whole-board snapshots                |
| `useDrawing.ts`                                                             | everything the pointer does: drawing, dragging, snapping, the marquee      |
| `useConnectorEnds.ts`                                                       | the dots a line can hold on to, and dropping an end on one                 |
| `useLabelEditor.ts`, `useFormulae.ts`, `usePictures.ts`, `usePresenting.ts` | writing on things, KaTeX, pictures, playing the frames                     |
| `BoardCanvas.vue`                                                           | the page: panels, the stage, and what is bound to what                     |
| `BoardItem.vue`, `BoardOverlay.vue`                                         | what one thing looks like, and what is drawn over the board                |

**The board's server side** keeps the canvas's own JSON. `app/Support/BoardItems.php`
translates between that and the short form MCP clients write, with
`Board/BoardLayout.php` placing anything sent without coordinates and
`Board/BoardPins.php` deciding where a connector's ends sit.

## Three things that will catch you out

**The tests run on SQLite, the app runs on Postgres.** `phpunit.xml` sets
`DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, and Pest reads it. A green
suite therefore says nothing about Postgres-specific behaviour — JSON
operators, `ILIKE`, transactional DDL, stricter type coercion, sequences. CI
migrates against a real Postgres, so migrations are covered; queries are not.
Point `phpunit.xml` at a `pgsql` connection if the app leans on any of that.

**A cookie the server reads must be exempt from encryption.**
`bootstrap/app.php` has:

```php
$middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
```

`HandleAppearance` reads the `appearance` cookie server-side so Blade can paint
the theme before first render, with no flash. Any cookie you add for that same
purpose — a second appearance axis, a locale, a density setting — has to go on
that `except` list too. Leave it off and the middleware receives `null`: no
error, no warning, just the default, silently.

**Autosave sends a document, not a form.** The same file exempts `PATCH`
requests to notes and boards from trimming and from turning empty strings into
null. A note's text nodes keep their edge spaces, and a board's shapes keep the
empty strings that mean "nothing written here" — turned into nulls, the canvas
had no label to draw and would not open the board at all. Any other route that
saves a document whole belongs on that list.
