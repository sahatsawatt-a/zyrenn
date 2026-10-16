# Code map

Where each part of Zyrenn lives. The parts and their codes (CMP-n, CAP-n) are
the product tree kept in the app itself: project **ZyrenN**, Product page (or
`get-product` over MCP). Read the tree for what a part does and what a change
to it reaches; read this for where its code is.

Each feature keeps the same shape front and back:

| Layer                      | Where                                                                    |
| -------------------------- | ------------------------------------------------------------------------ |
| Routes                     | `routes/features/{feature}.php`, required from `routes/web.php`          |
| Controllers                | `app/Http/Controllers/{Feature}/`                                        |
| Models                     | `app/Models/{Feature}/`                                                  |
| Logic                      | `app/Support/{Feature}/`                                                 |
| MCP tools                  | `app/Mcp/Tools/{Feature}/`, base class `app/Mcp/Tools/{Feature}Tool.php` |
| Pages (Inertia)            | `resources/js/pages/{feature}/`                                          |
| Components and composables | `resources/js/features/{feature}/`                                       |
| PHP tests                  | `tests/Feature/{Feature}*Test.php`, `tests/Unit/`                        |
| Browser suites             | `tests/e2e/{feature}*.mjs` (rules: `tests/README.md`)                    |

## Features

| Part                       | Backend                                                                                                                                                                             | Frontend                                                                                    |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Notes (CMP-1)              | `Http/Controllers/Note`, `Models/Note`, `Support/Note` (blocks, PDF, Markdown export, page links)                                                                                   | `pages/notes`, `features/notes` (editor, nodes, diagram blocks: `plot/`, `timing/`, `lib/`) |
| Boards (CMP-2)             | `Http/Controllers/Board`, `Models/Board`, `Support/Board` (items, parts, labels, paths, shapes, styles, decks, render)                                                              | `pages/boards`, `features/boards` -- see below                                              |
| Tables (CMP-5)             | `Http/Controllers/Table`, `Models/Table`, `Support/Table`                                                                                                                           | `pages/tables`, `features/tables`                                                           |
| Maps, Trips (CMP-6, 7)     | `Http/Controllers/Map`, `Models/Map`, `Support/Maps`                                                                                                                                | `pages/maps`, `pages/trips`, `features/maps`                                                |
| Drive (CMP-8)              | `Http/Controllers/Drive`, `Models/Drive`                                                                                                                                            | `pages/drive`, `features/drive`, `components/media`, `components/photo`                     |
| Projects (CMP-9)           | `ProjectController`, `ProjectMemberController`, `Models/Project`, `Models/Membership`                                                                                               | `pages/projects`                                                                            |
| Chats, Inbox (CMP-10, 37)  | `Http/Controllers/Chat`, `Models/Chat`, `Support/Chat`                                                                                                                              | `pages/chats` (Index, Show, Groups, Inbox), `features/chat`                                 |
| Workflows (CMP-11)         | `Http/Controllers/Workflow`, `Models/Workflow`, `Support/Workflow` (Engine, NodeTypes, ServiceKinds, `Steps/`, `Inbound/`)                                                          | `pages/workflows`, `features/workflow`                                                      |
| Connections (CMP-16)       | `Connection*Controller`, `Models/Connection`, `Support/Connections` (incl. the Telegram and LINE clients)                                                                           | `features/connections`                                                                      |
| Dashboard (CMP-17)         | `DashboardController`                                                                                                                                                               | `pages/dashboard`, `features/dashboard`                                                     |
| Agent access, MCP (CMP-18) | `Mcp/Servers` (both servers build from `Toolset` and `Instructions`), `Mcp/Tools`                                                                                                   | --                                                                                          |
| Account (CMP-19)           | `Http/Controllers/Settings`                                                                                                                                                         | `pages/settings`, `pages/auth`, `features/account`                                          |
| Tickets (CMP-38)           | `Http/Controllers/Ticket`, `Models/Ticket`                                                                                                                                          | `pages/tickets`, `features/tickets`                                                         |
| AI agents (CMP-39)         | `Http/Controllers/Agent`, `Models/Agent`, `Support/Agents`                                                                                                                          | `pages/agents`, `features/agents`                                                           |
| Knowledge (CMP-40)         | `Http/Controllers/Knowledge`, `Models/Knowledge`, `Support/Knowledge`, `Jobs/ReadKnowledge`                                                                                         | `pages/knowledge`                                                                           |
| Product (CMP-41)           | `Http/Controllers/Product`, `Models/Product`, `Support/Product`                                                                                                                     | `pages/product`, `features/product`                                                         |
| Content planner (CMP-42)   | -- (a demo, through workflows)                                                                                                                                                      | `pages/demo/Planner.vue`, `features/planner`                                                |
| Browser checks (CMP-44)    | `Http/Controllers/Browser`, `Models/Browser`, `Support/Browser`, `Jobs/RunBrowserChecks` (its own `checks` queue and `checks-worker`, so a long demo never holds up workflow steps) | `pages/checks`, `pages/admin` (test runs), `features/browser`                               |
| Guide (CMP-45)             | `GuideController`, `Support/Guide`                                                                                                                                                  | `pages/guide`, `features/browser/tours.ts`                                                  |
| Demos and labs             | `Http/Controllers/Demo`                                                                                                                                                             | `pages/demo`, `features/labs`                                                               |

### Boards, inside

`resources/js/features/boards/`:

| Folder       | What is in it                                                                                                                                                                                                                                                                                                                                                      |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `model/`     | What an item is: items, geometry, layers, rows, shapes, styles, templates, connectors, guides                                                                                                                                                                                                                                                                      |
| `drawing/`   | How items are painted wherever a board is drawn (editor, render, note embeds): BoardView and BoardNode; BoardItem, which picks how each item is drawn; `kinds/` with a component for each bigger kind (frame, picture, video, symbol, library shape, connector, label); `itemLook.ts` for colours, edges and effects; labels, paint, blur, pictures, videos, theme |
| `canvas/`    | The editor: BoardCanvas and its bar, zoom, others' pointers, layers and shape panels; `use*` for camera, gestures, shortcuts, drawing, sync, panels, placing, the frame menu, resize handles (`useTransformer`) and drawing only what is on screen (`useOnScreen`)                                                                                                 |
| `inspector/` | The side panel: InspectorPanel picks the sections that apply; each section is its own component (item actions, part, colours, label, picture, geometry, board, look, connector, structure), with the pickers and icons they use                                                                                                                                    |
| `deck/`      | Slides: the deck's look, layouts, presenting (`useShow`, `usePresenting`), the present bar and notes (`PresentControls`), the presenter window                                                                                                                                                                                                                     |
| `pen/`       | The vector pen and SVG path data                                                                                                                                                                                                                                                                                                                                   |
| `circuit/`   | Circuits: symbols, layout, solving, logic, and `useBoardCircuit` for a circuit live on a board                                                                                                                                                                                                                                                                     |
| `lib/`       | JSON shared with PHP (shapes, styles, icons, templates, deck)                                                                                                                                                                                                                                                                                                      |

### Chats and workflows, inside

| Folder               | What is in it                                                                                                                                                                                                                                                                                                                                          |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `features/chat/`     | `conversation.ts` (a room's messages as turns, days and albums), `types.ts`; `composables/` for a room live (`useRoomLive`), scrolling, older messages, sending, faces; `components/` for the room's header, each message and its parts, the composer and what can be attached, the inbox desk, and the dialogs                                        |
| `features/workflow/` | `canvas.ts`, `steps.ts` (each step's icon and colour), `types.ts`; `composables/` for the editor's space, its canvas, the step palette, switching on and off, step samples and saving (`useWorkflowSave` on the shared save); `components/editor/` for the canvas, the step panels, save status, problems, the published badge and the conflict dialog |

## Shared parts (CMP-20)

Built once, used from several features. Change one with its "impact" in the
product tree in front of you.

| Part                                                  | Where                                                                                                                                     |
| ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Folders and filing (CMP-21)                           | `FolderController`, `Http/Controllers/Concerns/BrowsesFolders`, `Support/Folders.php`, `components/folders`                               |
| Ownership and roles (CMP-22)                          | `Models/Owner.php`, `Models/Concerns/{BelongsToOwner,OwnsContent}`, `Policies/ContentPolicy`, `Http/Controllers/Concerns/ActsForOwner`    |
| Presence, live updates (CMP-23, 24)                   | `Support/Live/Live.php`, `app/Events`, `composables/usePresence.ts`, `lib/live.ts`                                                        |
| Co-editing (CMP-25)                                   | `collab/` (Hocuspocus + Yjs), `Support/Live/Collab.php`                                                                                   |
| Saved with a check for someone else's change (CMP-46) | `Models/Concerns/KeepsRevisions`, `Http/Controllers/Concerns/SavesRevisions`, `composables/useRevisionedSave.ts` (trips, workflow drafts) |
| Formula engine (CMP-26)                               | `Support/Formula`                                                                                                                         |
| Media picker and viewer, photo editor (CMP-27, 28)    | `components/media`, `components/photo`, `lib/photo.ts`                                                                                    |
| Place search and map (CMP-29)                         | `Support/Maps`, `components/map`, `lib/maps.ts`                                                                                           |
| Mermaid diagrams (CMP-30)                             | `lib/mermaid.ts`, `lib/erTidy.ts`                                                                                                         |
| Print, PDF and pictures (CMP-31)                      | `Support/Chrome/Chrome.php` (the only way to the `chrome` container, `docker/chrome`), `lib/printReady.ts`                                |
| Markdown (CMP-32)                                     | `Support/Markdown`                                                                                                                        |
| AI client (CMP-33)                                    | `Support/Ai`                                                                                                                              |
| Safe outside requests (CMP-34)                        | `Support/Outside` (RemoteDownload, HostAddress)                                                                                           |
| Row values (CMP-35)                                   | `Support/Table/RowValues.php`                                                                                                             |
| Activity record (CMP-36)                              | `Support/Audit`, `features/audit`                                                                                                         |

## Kept in both PHP and TypeScript

The server works these out to check what an MCP client sends, the canvas to
draw it; each pair must agree. Data they share is JSON both read; cases they
are both tested on are in `tests/fixtures`.

| What                            | PHP                                                | TypeScript                                                                               |
| ------------------------------- | -------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Label fitting, rich text        | `Support/Board/LabelFit.php`                       | `features/boards/drawing/labels.ts` (shared cases: `tests/fixtures/rich-text-runs.json`) |
| Table and class rows            | `Support/Board/BoardRows.php`                      | `features/boards/model/rows.ts`                                                          |
| Layers                          | `Support/Board/BoardParts.php`                     | `features/boards/model/layers.ts`                                                        |
| SVG paths                       | `Support/Board/PathData.php`                       | `features/boards/pen/pathData.ts`                                                        |
| Shapes, styles, templates, deck | `Support/Board/{Shapes,Styles,Templates,Deck}.php` | `features/boards/model/*`, `deck/deck.ts`; data in `features/boards/lib/*.json`          |
| Note blocks with ids            | `Support/Note/NoteBlocks.php`                      | `features/notes/lib/note-blocks.json`, `collab/prosemirror.mjs`                          |

## Adding something

- **A workflow step:** its definition in `Support/Workflow/NodeTypes.php` (or
  `ServiceKinds.php` for a service's), naming the class that runs it in
  `runs`; the class in `Support/Workflow/Steps`. A step's icon and colour for
  its group: `features/workflow/steps.ts`.
- **An MCP tool:** the class in `Mcp/Tools/{Feature}/`, listed once in
  `Mcp/Servers/Toolset.php`; anything clients must know goes in
  `Mcp/Servers/Instructions.php`.
- **Something the headless browser does:** a route in `docker/chrome/server.mjs`,
  asked through `Support/Chrome/Chrome.php`.
- **A call to Telegram or LINE:** through `Support/Connections/{Telegram,Line}.php`.
