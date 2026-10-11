# Workflows: running and looking after them

Workflows are steps that run on their own: started by hand, on a schedule, by
a chat message, a webhook, a Telegram bot or a LINE channel. This page is for
whoever runs the app or works on the engine. How to build one is in the app
itself (the step palette and each step's settings).

## What runs where

| Part         | Container          | What it does                                                    |
| ------------ | ------------------ | --------------------------------------------------------------- |
| Web app      | `app` behind `web` | The editor, `/hooks/*` (webhooks), starting runs                |
| Queue worker | `worker`           | Runs each step as a job (`php artisan queue:work`)              |
| Scheduler    | `scheduler`        | Every minute: due schedules, the recovery sweep; daily: pruning |
| Live updates | `reverb`           | The canvas and run panel follow runs as they go                 |

Runs go through the **database queue** (`QUEUE_CONNECTION=database`). A step's
job is written in the same transaction as the state change that made it due,
so a run never loses a step to a crash between the two. No Redis is needed:
one worker finishes about 25 steps a second on the dev machine
(`tests/e2e/workflow-load.mjs`); add workers before reaching for Redis:

```sh
docker compose up -d --scale worker=3 worker
```

After changing PHP code, tell the workers to load it:
`docker compose exec app php artisan queue:restart`.

## Settings (`.env`)

| Setting                      | Default | Meaning                                                                                                                                                                                  |
| ---------------------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WORKFLOWS_PUBLIC_URL`       | empty   | Where Telegram, LINE and other senders reach `/hooks/*` from outside, e.g. an ngrok or Traefik address. Empty: nothing outside can reach the hooks, and Telegram bots are polled instead |
| `WORKFLOWS_KEEP_RUNS_DAYS`   | 30      | Runs that ended are deleted after this, with their steps, logs and sends                                                                                                                 |
| `WORKFLOWS_KEEP_EVENTS_DAYS` | 14      | Records of what came in by webhook (used to spot repeats)                                                                                                                                |
| `WORKFLOWS_KEEP_AUDIT_DAYS`  | 365     | Who published, switched on, ran, or changed a connection                                                                                                                                 |

Runs still going or waiting are never pruned, however old.

## Commands

| Command                       | When         | What                                                                                                                                                                                                |
| ----------------------------- | ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `workflows:recover`           | every minute | Puts right what a dead worker left: steps past their lease are run again, or held for a person when they may already have sent something; wakes waits that were missed; tells owners of stuck steps |
| `workflows:schedules`         | every minute | Starts the runs whose schedule is due                                                                                                                                                               |
| `workflows:telegram-poll`     | when asked   | Fetches Telegram messages for bots set to poll                                                                                                                                                      |
| `workflows:prune`             | daily 03:30  | Deletes what is older than the settings above                                                                                                                                                       |
| `workflows:health --hours=24` | by hand      | Runs and how they ended, run time and wait for a worker (p50/p95), retries, sends that may not have gone out, stuck steps, recent failures                                                          |

The same health numbers are at the top of each Workflows page, for that
person's or project's workflows.

## Alerts

The owner of a workflow is told in a chat of their own, **Workflow alerts**,
when:

- a run that started by itself fails (at most once per workflow every 30
  minutes, so a failing schedule doesn't flood it);
- a step sent something and its worker died before it could tell whether it
  went out: the run waits for someone to say "send it again" or not;
- steps have waited over 10 minutes for a worker -- usually the worker is down.

Runs started by hand are never alerted: the one who pressed Run is watching.
A project's workflow alerts whoever it acts as (who published it), while they
are still in the project.

## Limits

| What                  | Limit                                                                                                    |
| --------------------- | -------------------------------------------------------------------------------------------------------- |
| Steps a run may begin | 1000 (`budget_exceeded` past it)                                                                         |
| Runs setting off runs | 5 deep                                                                                                   |
| One step              | 150 s; its lease 180 s; tried at most 3 times when the failure may pass                                  |
| A step's output       | 256 KB (a file is given out as its Drive ref_id, never its bytes: see Files)                             |
| A delay               | 30 days                                                                                                  |
| Webhooks              | 600 a minute per hook, 3000 a minute per sending address; over that is answered 429, which senders retry |

## When something is down

- **The worker:** runs wait in the queue and carry on when it is back. After
  10 minutes the owners are alerted.
- **The database:** a webhook is answered with an error, so its sender tries
  again; nothing of it is kept half-way, so the retry is not taken for a
  repeat.
- **Reverb:** runs carry on; the editor falls back to asking the server every
  few seconds, and catches up when Reverb is back.
- **A service a step calls** (busy, 429, 5xx): the step waits and tries again,
  honouring `Retry-After`, up to 3 times.

## Shapes a run can take

- **Switch** tries its named ways in order and goes down the first whose
  formula is true, or "otherwise". Each way's name is its dot on the canvas;
  renaming or removing a way lets go of what was joined to it.
- **Merge** brings ways back together: once every way in that was taken has
  come (giving out each by its step's key), or with the first to arrive (the
  rest are let go -- nothing after it runs twice).
- **For each** runs another workflow once per item of a list, a few at a time
  (5 by default, at most 20 and 100 items), as runs of their own started by
  that workflow's "When run by another workflow" trigger. It waits while they
  go -- woken as each ends, and once a minute in case a wake is missed -- and
  gives out each item, how its run went, and what its last step gave. A run
  stopped stops the runs it started. Runs started this way don't alert; the
  step's output says which failed.
- **When messages come quickly** (chat, Telegram and LINE triggers): each
  message runs at once (the default); one at a time per chat, in order (later
  runs are held, queued, until the one before ends); or only the latest per
  chat (a newer message cancels the runs still going for that chat).

## Files

A file in a workflow is always a file in the **Drive** of the workflow's
owner -- a person's own Drive, or the project's -- and goes from step to step
as its `ref_id`. Its bytes never pass through what a step gives out.

- **In.** A Telegram or LINE message carries only the ids of what was sent
  with it, as `trigger.files`; the hook never downloads anything. Each
  service has a step of its own that asks it for the file with the bot's or
  channel's key: **Telegram: get the file** and **LINE: get the file** (the
  first file, unless told which). A step gives out JSON, never bytes, so the
  same step keeps the file in the Drive, in a folder that is made if it
  isn't there. **Save to Drive** does the same for a file at an address on
  the public internet, or for text written in the step. Each gives out the
  file: `ref_id`, `name`, `folder`, `mime`, `kind`, `size`, `url`, and for a
  picture or a video `markdown`, the line that shows it in a note.
- **Around.** **Find files** lists a folder (or the whole Drive) newest
  first, by part of a name and by kind. **Read a file** gives one by ref_id,
  with a text file's first 64 KB as `text`.
- **In a chat.** **Write in a room** takes a file, shown under the message:
  a picture or a video as one, anything else as a link. The room-bridge
  templates ("Telegram / LINE chats in a room, both ways") use it: a message
  that brought a file has it fetched into a folder named for the service and
  shown in the room; one that didn't is written as before.
- **Out.** **Send a file on Telegram** sends one from the Drive: a picture
  or a video is shown as one, anything else goes as a document, with words
  under it if any. **Reply on LINE** and **Push on LINE** take a JPEG or PNG
  as part of their message. **Ask AI** takes up to four pictures for a model
  that can look at them.

Each call a service offers is a step of its own, in that service's group,
rather than a choice on a general step: for LINE, **LINE: who wrote**
(profile), **LINE: get the file** (content), **Reply on LINE** (reply: free,
with the token a message came with, soon after it and once) and **Push on
LINE** (push: at any time, against the monthly allowance). A new call to
Telegram or LINE gets a new step. A workflow published while one "Send on
LINE" step chose between reply and push goes on doing what it was set to.

A message with no file fails a "get the file" step, so a workflow that hears every
message puts an **If** on `len(trigger.files) > 0` before it (the "Files sent
on Telegram / LINE, kept in the Drive" templates do).

| What                                | Limit                                                     |
| ----------------------------------- | --------------------------------------------------------- |
| A file fetched into the Drive       | 100 MB; Telegram hands a bot at most 20 MB                |
| A file sent on Telegram             | 50 MB; shown as a picture up to 10 MB, else as a document |
| A picture sent on LINE              | JPEG or PNG, 10 MB                                        |
| Pictures put to a model             | 4, each 8 MB (JPEG, PNG, WebP, GIF)                       |
| How long LINE keeps what it is sent | a while only: save it in the run the message started      |

LINE is not sent a picture; it is given an address and fetches it. That
address is `/hooks/files/…`, signed, good for an hour and for that one file
-- so sending a picture on LINE needs `WORKFLOWS_PUBLIC_URL`, as LINE's
webhook does. The same picture is given as its own preview (there is nothing
here to shrink it with), and LINE may not show a preview of one over 1 MB
until it is opened.

## From an AI client (MCP)

Reading and running: `list-workflows`, `list-workflow-folders`, `get-workflow`
(steps by key, joins, problems, latest runs, and its `revision`),
`list-workflow-runs`, `get-workflow-run`, `run-workflow` (the draft or what is
published, with data of its own, optionally waiting up to 60 s),
`switch-workflow`.

Building: `list-workflow-steps` (the kinds of step and their settings, and the
connections, rooms, tables and workflows settings may point at, by ref_id),
`create-workflow` (empty, from a template, or built at once),
`update-workflow` and `publish-workflow`, `delete-workflow`. An update names
steps by key and sends only what changes -- remove, change (settings merged; a
renamed key follows into every `node("key")`), add (placed and joined after
another step), unjoin, join, in that order -- and is checked as an editor save
is; any one thing that can't be done changes nothing. Passing the `revision`
from get-workflow refuses a change made over someone else's. An editor with
the workflow open picks the change up.

Making and changing need edit rights in a project; publishing, running and
deleting are kept in the activity record.

## Not done, on purpose

- **Redis:** the database queue keeps up (one worker ~25 steps/s, and the
  queue's own work is small next to a step's); add workers first. Moving to
  Redis would need an outbox table, since a Redis push can't share the
  database transaction.
- **Run Code:** steps are formulas and templates only, until there is a real
  sandbox for code.

## Versions

Publishing freezes the workflow as a version; runs always run the version they
started with, so editing or publishing again never changes a run under way --
a run waiting on a 30-day delay finishes as it was published. Each step also
keeps its own version (`nodeVersion`). When a step's behaviour changes in a way
waiting runs must not see, bump its `version` in `NodeTypes`, keep the old code
under `'type@N'` in `Engine::STEPS`, and set `oldest` once no runs of the old
version can be left. A step from a newer app than the one running (after a
rollback) fails with a plain message rather than running wrongly.

## Tests

- PHP: `tests/Feature/Workflow*Test.php`, `tests/Unit/WorkflowDefinitionTest.php`.
- Browser: `npm run test:e2e` runs every suite (see `tests/e2e/run.mjs`).
- Only when named, since they change the running containers or load them:
  `node tests/e2e/workflow-workers.mjs` (several workers, one killed) and
  `node tests/e2e/workflow-load.mjs` (a burst of webhooks).
