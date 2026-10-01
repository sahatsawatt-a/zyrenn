# ZyrenN

A personal workspace: notes, boards, tables, and a private Drive behind them.

- **Notes** — a rich editor (Tiptap) filed in folders, saved as you type.
  Markdown in and out, with callouts, tables, code, Mermaid diagrams and KaTeX.
- **Boards** — an endless canvas (Konva): sticky notes, shapes, flowchart
  symbols, connectors that stay joined to what they link, pictures, formulae,
  and 16:9 frames that play as slides.
- **Tables** — rows and columns of your own, each column of a kind (text,
  numbers, dates, choices, ratings…), with search, filters, sort and CSV export.
- **Drive** — the files behind the rest. A picture on a board or in a note lives
  here and is served only to its owner.
- **MCP** — an AI client can read and write all of it. See [Agent access](#agent-access).

## Running ZyrenN locally

Everything runs in Docker; nothing but Docker is needed on the host. A fresh
clone needs only `docker compose up -d`: the first one installs everything, in a
few minutes. `./scripts/setup.sh` (`.\scripts\setup.ps1` on Windows) also
matches your UID and picks free ports; [docs/SETUP.md](docs/SETUP.md) has the
steps, HTTPS behind Traefik (`./scripts/setup.sh --traefik <host>`), and one for
each way of connecting an AI client.

```sh
docker compose up -d                     # postgres, app, nginx, reverb, collab, ...
docker compose up -d vite                # Vite with HMR, only while you need it
docker compose logs -f app
docker compose exec app php artisan ...
docker compose exec app php artisan test
docker compose down                      # add -v to drop the database too
```

|          |                                                                 |
| -------- | --------------------------------------------------------------- |
| app      | <http://localhost:8001>                                         |
| vite     | <http://localhost:5173> (on demand, HMR)                        |
| postgres | `localhost:5434`, database and user `zyrenn`, password `secret` |

Those ports were free when this project was scaffolded; they are recorded in
`.env` as `APP_PORT`, `VITE_PORT` and `FORWARD_DB_PORT`. If you lose track of
which port the app is on, ask Docker rather than reading `.env`:

```sh
docker compose port web 8080
```

### Assets

The page is served from the built bundle in `public/build`. The `init` service
(`docker/init.sh`) rebuilds it on `docker compose up` whenever the sources have
changed, so a pull needs nothing more.

Vite runs only when asked for: `docker compose up -d vite` starts it, and
`docker compose stop vite && docker compose up -d` goes back to the bundle,
rebuilt with whatever you changed meanwhile. `COMPOSE_PROFILES=dev` in `.env`
(or `./scripts/setup.sh --dev`) starts it with every `up` instead. While it runs
it writes an empty `public/hot`, which makes Laravel emit relative asset URLs, and `web`
proxies the dev server on the page's own origin. So `http://localhost:8001`,
this machine's LAN IP and a hostname in front of it all serve the same HTML and
all get HMR, with no dev-server address baked in.

Vite refuses a Host header it does not recognise, so name any host that fronts
it: `APP_HOST`, `VITE_HOST`, or `VITE_ALLOWED_HOSTS` (comma separated) in
`.env`. Bare IP addresses need no entry.

Set `VITE_DEV_ORIGIN` to an absolute URL to skip the proxy and have the browser
talk to the dev server directly — that address then becomes the only one that
works.

Keep `APP_DEBUG=false`, the default, before exposing the app to anyone else: with it on, any
500 renders a stack trace including config values.

### Backups

The `backup` service takes one every 6 hours: the database and the files on
disk (Drive files, pictures in notes and boards) together, because a dump alone
restores rows that point at files which are gone. Each one is a folder in
`../zyrenn-backups`, next to the repo rather than in it:

```
auto-20260930-141129/db.dump         # pg_restore
auto-20260930-141129/files.tar.gz    # storage/app/private
```

Folders named `auto-*` older than 14 days are removed after each successful
backup, keeping the newest 3 whatever their age. Anything else in that folder
-- a dump taken by hand before a risky migration, say -- is never touched.
Change the schedule in `.env` with `BACKUP_INTERVAL_HOURS`, `BACKUP_KEEP_DAYS`,
`BACKUP_KEEP_MIN` and `BACKUP_DIR`.

```sh
docker compose exec backup /backup.sh once   # take one now
docker compose logs backup                   # what it has done
```

To restore one, stop the app so nothing writes meanwhile, then put back both
halves:

```sh
B=../zyrenn-backups/auto-20260930-141129
docker compose stop app reverb
docker compose exec -T postgres pg_restore -U zyrenn -d zyrenn --clean --if-exists --no-owner < $B/db.dump
tar -xzf $B/files.tar.gz -C storage/app      # restores storage/app/private
docker compose start app reverb
```

### Chat and your own models

Settings → AI connections says where a chat reaches a model: Ollama, LM Studio,
OpenRouter, OpenAI, Groq, Gemini, or any host that speaks the OpenAI chat API.
Each is tried before it is saved. A key is kept encrypted and used only for
your own chats.

Reaching a model that runs on your machine, from the app's container:

- **`http://localhost:11434`** works as it reads: `localhost` means the machine
  the app runs on, not the container (`App\Support\Chat\HostAddress`). Set
  `CHAT_LOCALHOST_AS` to a name or address to say where that is, or `off` to take
  the word literally.
- **`http://ollama:11434`**, an Ollama in a container of its own, works once the
  app shares its Docker network: add `docker-compose.ollama.yml` to `COMPOSE_FILE`
  in `.env` (and `OLLAMA_NETWORK` if the network is not called `ai`).

## Tests

```sh
docker compose exec app php artisan test   # the PHP suite
docker compose exec app composer ci:check  # what CI runs: format, lint, types, tests
npm run test:e2e                           # boards and tables, in a real browser
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

Notes are read and written as Markdown, boards as a list of items, tables as
columns and rows of values keyed by column label, and files through the Drive. A picture has to be in the Drive before a note or a board
can show it — the tools say so, and hand back the line or URL to use.

```sh
docker compose exec app php artisan mcp:start zyrenn   # stdio, reads MCP_TOKEN
```

Step-by-step setup for each method (personal or admin, HTTP or stdio) is in
[docs/SETUP.md](docs/SETUP.md), and for each client (Claude, Cursor, VS Code,
Windsurf, Antigravity, Gemini CLI, Codex and others) in
[docs/MCP-CLIENTS.md](docs/MCP-CLIENTS.md).

## How the code is laid out

Ordinary Laravel and Inertia, grouped by feature: `Models/Note/`,
`Controllers/Board/`, `components/Table/` and so on. A few parts are worth a map.

**Folders** are the same for every feature. `app/Models/Folder.php` is the tree
each kind's folder model extends, with one `FolderPolicy` for all of them;
`FolderController` makes, renames, moves and deletes them, and the
`BrowsesFolders` trait lists what is in them. On the page, notes, boards and
tables share `components/folders/FolderListPage.vue` and say only what they
are called and what they count. The MCP tools share the same way:
`app/Mcp/Tools/FiledTool.php`, and the list, get and delete tools in `Concerns/`.

**The board** is one flat list of items in paint order, which keeps z-order,
undo and hit-testing simple. Its logic is in `resources/js/composables/board/`,
its components in `resources/js/components/Board/`.

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

**A table** keeps its rows in a database table of its own,
`user_table_<ref_id>`, with one real column per column of the grid;
`table_columns` says what each is. Every schema change and row write goes
through `app/Support/Table/TableStorage.php`, which names columns itself from
their labels, so nothing from a request becomes an identifier. The grid edits
itself first and saves behind (`resources/js/composables/table/`).

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
