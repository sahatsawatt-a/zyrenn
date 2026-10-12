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

| Setting                      | Default            | Meaning                                                                                                                                                                                                                                                                                    |
| ---------------------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `WORKFLOWS_PUBLIC_URL`       | empty              | Where Telegram, LINE and other senders reach `/hooks/*` from outside, e.g. an ngrok or Traefik address. Empty: nothing outside can reach the hooks, and Telegram bots are polled instead                                                                                                   |
| `NGROK_AUTHTOKEN`            | empty              | With `tunnel` among `COMPOSE_PROFILES`, the stack runs an ngrok tunnel at `WORKFLOWS_PUBLIC_URL` itself, which lets only `/hooks/*` through and comes back after a restart                                                                                                                 |
| `TUNNEL_CARRIES`             | `hooks`            | What that tunnel lets through: only `/hooks/*`, or with `app` the whole app, whose sign-in page is then on the internet. An error asked for at the public address never shows a debug page, whatever `APP_DEBUG` says; with the dev server, name the tunnel's host in `VITE_ALLOWED_HOSTS` |
| `CODE_URL`                   | `http://code:3000` | Where a **Run code** step runs: the `code` container. Only changed to run one elsewhere                                                                                                                                                                                                    |
| `WORKFLOWS_KEEP_RUNS_DAYS`   | 30                 | Runs that ended are deleted after this, with their steps, logs and sends                                                                                                                                                                                                                   |
| `WORKFLOWS_KEEP_EVENTS_DAYS` | 14                 | Records of what came in by webhook (used to spot repeats)                                                                                                                                                                                                                                  |
| `WORKFLOWS_KEEP_AUDIT_DAYS`  | 365                | Who published, switched on, ran, or changed a connection                                                                                                                                                                                                                                   |

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
- **Around.** **Make a link to a file** gives an address that opens one
  file for anyone who has it, for up to a week. **Find files** lists a folder (or the whole Drive) newest
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

A workflow published while one "Send on LINE" step chose between reply and
push goes on doing what it was set to. The rest of what Telegram and LINE
can do is under "Telegram and LINE" below.

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

## Telegram and LINE

Each call a service offers is a step of its own, in that service's group.
The settings a step asks for are that call's own; every one says in the
editor what it is for.

**What starts a run.** The "Telegram message" and "LINE message" triggers
have a "When": a message or a button (the default, and what a trigger set up
before there was a choice does), or one other kind of thing. `trigger.type`
says which it was, and the trigger gives out the parts that kind brings.

| When                                   | Telegram                                             | LINE                                  | Gives out                                                           |
| -------------------------------------- | ---------------------------------------------------- | ------------------------------------- | ------------------------------------------------------------------- |
| A message, or a button                 | `message`, `edited`, `button`                        | `message`, `postback`                 | `trigger.text`, `trigger.files`, `trigger.button`                   |
| A place, a sticker, a contact          | in a message                                         | in a message                          | `trigger.location`, `trigger.sticker`, `trigger.contact` (Telegram) |
| Someone joins or leaves                | `joined`, `left` (and the bot added or put out)      | `memberJoined`, `memberLeft`          | `trigger.member` (Telegram), `trigger.members` (LINE)               |
| The channel added, blocked, in a group | --                                                   | `follow`, `unfollow`, `join`, `leave` | `trigger.sender`, `trigger.chat`                                    |
| A post in a channel                    | `channel_post`                                       | --                                    | as a message                                                        |
| A reaction                             | `reaction` (only where the bot looks after the chat) | --                                    | `trigger.reaction`                                                  |
| A poll answered                        | `poll_answer`                                        | --                                    | `trigger.poll`                                                      |
| An inline question (`@bot …`)          | `inline_query`                                       | --                                    | `trigger.inline`, `trigger.text`                                    |
| A payment                              | `checkout`, then `payment`                           | --                                    | `trigger.checkout`, `trigger.payment`                               |
| A message taken back                   | --                                                   | `unsend`                              | `trigger.unsent`                                                    |

A bot is told of all of these once a workflow of its is published or
switched on again: that is when Telegram is given the list
(`Envelope::TELEGRAM_UPDATES`). A bot registered before only hears messages
and button presses until then.

**Telegram's steps.** Sending: a message (with buttons), a file, an album, a
sticker, a location, a contact, a poll, an invoice; forwarding and copying a
message; making a topic. On a message already there: edit it, change its
buttons, delete, pin, unpin, react. Answering: a button press, an inline
question, a checkout. Showing "typing…". Looking after a chat: ban, lift a
ban, limit what someone may do, set the bot's commands. Asking: about a
chat, about a member, how many members.

**LINE's steps.** Reply, Push, "Send to many" (multicast) and "Send to
everyone" (broadcast) carry the same message: words and a picture, a
sticker, a place, a video, a recording, buttons to choose from, a yes-or-no
question, or a Flex card -- with quick replies under it, and quoting a
message. Also: show loading, mark as read, leave a group. Asking: who
wrote, about a group, how many members, the monthly allowance and how much
of it is used.

**LINE's rich menus.** The panel under a chat is a picture with places to
tap, and each call about one is a step. "Make a rich menu" takes a JPEG or
PNG from the Drive (800 to 2500 wide, at least 250 high, at least 1.45 times
as wide as high, at most 1 MB; LINE's own are 2500 × 1686 and 2500 × 843),
makes the menu the picture's size, lays its buttons out as chosen -- six,
four, three, two, one, or areas written out as LINE takes them -- and puts
the picture on it. A menu and its picture are made together because LINE
shows no menu without one, takes none of another size, and lets neither be
changed: a menu that is to look different is made again. Should the picture
be turned down, the menu made for it is deleted again. A menu is seen by no
one until it is shown: to everyone ("Show a rich menu to everyone", and stop
doing so), to one person, or to up to 500 at once -- a person's own comes
before everyone's, and everyone's before one set in LINE Official Account
Manager. "Name a rich menu" gives one a short name, moved when it is already
in use; wherever a step takes a menu, its id or a short name will do. "LINE:
rich menus" lists those made here, with their short names and which one
everyone sees, and "LINE: someone's rich menu" says which a person was
given. LINE makes or deletes at most 100 menus an hour, and keeps 1000.

A button on a menu does what any button does (below), and one more thing:
`menu:` and another menu's short name turns to that menu, as a tab does.
LINE tells the workflow of it as `trigger.type` "menu", with `trigger.menu`
saying which it turned to -- a trigger set to buttons takes it, one set to
messages does not, so a chat kept in a room is not written in for it. The
"A rich menu for your LINE channel" template makes one, names it "main" and
shows it to everyone, by hand.

**Run code.** For what formulas can't say, a **Run code** step (Data) runs
JavaScript: the body of a function that reads `trigger`, `input` and
`node("key")` as a formula does, and whose `return` is what the step gives
out -- a record as it is, anything else as `value`. `console.log` writes in
the run's log; an error stops the step, saying which line. It may `await`,
but only its own work: there is nothing outside it to reach.

It never runs in the app. The `code` container (`docker/code`) runs it in a
QuickJS interpreter compiled to WebAssembly, which has no network, no files,
no process and no modules; each run has a thread and an interpreter of its
own, 128 MB of memory, and is stopped after the step's time (5 seconds
unless it says, 30 at most). It is handed up to 2 MB to read and may give
back 1 MB. The container itself holds nothing of the app -- no `.env`, no
volumes, no added rights -- and publishes no port.

**Buttons.** Wherever a step takes buttons, each is what it says and what
tapping it does. An address opens -- `link:` before it says it is one, where
a formula gives it. Otherwise it comes back to the workflow,
to a trigger set to buttons, as `trigger.text`: on Telegram anything else
does (at most 64 bytes); on LINE what follows `postback:` does, unseen, and
anything else is said in the chat as the person's own message.

**Payments on Telegram.** "Send an invoice" asks for Stars (XTR) with
nothing more, or for money with a payment provider's token, kept as a
"Webhook secret" connection. Telegram then asks the bot to agree and waits
ten seconds: a workflow whose trigger is set to payments answers with
"Answer a checkout" as its first step, and is started again when it is paid.

**What a room keeps.** A room has a picture and details by name, set by
"Find or make a room" ("Its picture", "Details to keep") and kept with the
room itself -- not with each message. They are written only when something
is different, so a step that runs for every message costs nothing when
nothing changed; a detail that comes to nothing is left as it was. In the
chat, the picture is the room's face (its title, the list of chats, and
beside what the other side writes; pressed, it opens larger), and "About
this chat" opens a panel beside the chat: what a workflow keeps -- shown
and copied, but not changed there, since the workflow would set it back --
and notes of one's own, which whoever may change the room adds and edits. A
workflow reads them as `trigger.room.picture` and
`trigger.room.details.<name>`. The bridge templates keep who an outside chat
is with: their picture (one person's chat only; a group has many faces),
name, id there, language and status.

**Places in a chat.** A message can carry a place -- lat, lng, a title and
an address -- drawn under it as a small map with its pin, the way there, the
app's own map of it, and "Save" to keep it in a list of saved places; the
map opens larger over the chat. "Write in a room" takes one as its "With a
place" (`{{ trigger.location }}` from a Telegram or LINE message that sent
one), and someone in a chat shares one from the attach menu: a saved place,
anywhere searched for, or where they are. A "Chat message" trigger gives it
as `trigger.location`, with `trigger.kind` saying "location"; the bridge
templates send it on as a place (LINE's location message, Telegram's
venue), with any words written beside it.

**Files in a chat.** Someone in one's own chat or a project's can attach a
file to what they write: from their device, when it is kept in a "Chats"
folder of that chat's Drive -- one's own, or the project's -- or one
already in that Drive, chosen there. A "Chat message" trigger gives its
ref_id in `trigger.files`. The room-bridge templates send it on to the
outside chat. A chat between two people has no Drive both can open, so no
files.

**A whole conversation.** The "LINE / Telegram chats in a room, both ways"
templates are the full bridge between an outside chat and a room here:

- _In_, a **Switch** goes by `trigger.kind`, which a message always has
  (text, image, video, audio, file, sticker, location, contact; a tap is a
  button). A picture or a file is fetched and shown in the room; a sticker
  is shown as its picture (LINE's are fetched from LINE's own address for
  them, once each: "Save to Drive" can use a file of the same name already
  there), or just named when that fails; a place is written with a link to
  a map; anything else is said as it was.
- _Out_, what is written in the room goes to the outside chat, with a file
  when one was attached: on Telegram by its own call for a file; on LINE as
  a picture when it is a JPEG or PNG, else as a link made by **Make a link
  to a file** -- an address that opens that one file for a day without
  signing in.
- Who a chat is with is kept with its room, by **Find or make a room**:
  their name and id as details, and their picture as the room's face. LINE
  gives a picture's address ("LINE: who wrote"). Telegram gives a file:
  **Telegram: about a chat** names the chat's photo, **Telegram: get the
  file** keeps it in the Drive under the photo's own id -- told to use the
  one there, so the same photo is fetched once -- and the room is given the
  file's ref_id. A chat that shows a bot no photo keeps the face it had.
- On LINE, what is written in the room is first tried as a reply to the
  last thing the other end wrote, which LINE does not count against the
  month's allowance: a **Reply on LINE** step with `{{ trigger.reply.token }}`
  stands before each **Push on LINE**. The reply goes while its token can be
  used -- for a minute after their message unless the step says another
  time, and once. When it can't, the run carries on down the reply's "not
  sent" way to the push, which sends it as before.
- On LINE, a **Chat message** trigger set to "Someone reads what an outside
  chat sent" marks the message read there, once someone has seen it here.
  LINE allows this only with **Chat switched on** for the channel (LINE
  Official Account Manager, Response settings); without it the step says so
  and the run carries on.
- On LINE, someone who adds the channel as a friend gets their room at once.
- A sticker that moves is shown moving: LINE's by its animated picture
  (`trigger.sticker.url`, an animated PNG, which plays a few times and
  stops, as it does on LINE -- a click in the chat plays it again); Telegram's video stickers play over and over,
  and its drawn ones (.tgs), which nothing here can play, show as their
  still picture (`trigger.sticker.picture`).
- In the room, a sticker is drawn as one -- small, with nothing round it
  ("Write in a room", shown as a sticker) -- and LINE's own emoji are drawn
  where their names stand in the words: `trigger.display` is a message's
  words with each such emoji written as a picture, where `trigger.text` has
  only its name, like "(angel Moon)". A picture in a chat opens larger over
  it when clicked.

LINE keeps no copy of a picture or a video it is sent this way: each phone
fetches it from this app when it shows the message, so its address is good
for 30 days and the app has to stay reachable from outside for that long.

Checked against the real services: a LINE picture and a sticker in, a
picture out to a phone, the read receipt (refused, Chat being off), and a
few read-only questions to both. Everything else has only been run against
faked services.

## LinkedIn

Each call is a step of its own, as the member the connection is signed in
as.

**Post on LinkedIn** posts on the member's own profile: words (at most
3,000 letters), alone or with one thing more -- a link shown as a card, a
picture or several (2 to 20; JPEG, PNG or GIF), a video (MP4, at most
200 MB), a document shown page by page (PDF, Word or PowerPoint; at most
100 MB and 300 pages), or a poll (a question, 2 to 4 answers, open from a
day to two weeks). It is seen by anyone, or by the member's connections
only. It gives out the post's `id` and its address as `url`. LinkedIn has no way
to be told "this is a repeat", so the step is never simply made again; it
does refuse a post whose words are the same as one just made.

**The others.** Repost (with words over it or none), delete a post,
comment, delete a comment, like, take back a like, and "LinkedIn: who I
am". A post is named by the id a step gave out or by its link; a comment by
the `urn` "Comment on LinkedIn" gives.

LinkedIn has two sets of calls, and a step uses the one that has its call.
The older (`/v2`) posts words, a link, a picture or a video, and comments,
likes and deletes. The newer (`/rest`) has several pictures, documents,
polls and reposts, and is asked for by the month it was written against
(`LinkedInApi::VERSION`), which LinkedIn answers to for a year: move it on
before then. Its words are written with a backslash before each mark it
would read as its own -- a bracket, a star, an underscore -- so they stand
for themselves; a `#` before a word stays the hashtag it looks like.

The connection is a LinkedIn app of one's own, and a member signed in
through it:

1. At linkedin.com/developers make an app (LinkedIn asks for a company page
   to tie it to), and on its Products page add **Share on LinkedIn** and
   **Sign In with LinkedIn using OpenID Connect**. Neither is reviewed.
2. Add a LinkedIn connection here with the app's client ID and client
   secret, from its Auth page.
3. The connection then shows the address LinkedIn sends its member back to,
   the same for every connection: add it on the Auth page under "Authorized
   redirect URLs". With `WORKFLOWS_PUBLIC_URL` set it is that address's
   `/hooks/linkedin/signed-in`, which takes nothing in and only passes the
   member on to where they set out (the tunnel has to be up); without, it
   is `/settings/connections/signed-in` where the app is open, which
   LinkedIn wants to be https and which has to be the one listed.
4. **Sign in with LinkedIn**, and say yes there.

A sign-in lasts 60 days, and LinkedIn renews one unasked only for its
marketing partners -- so the connection says until when it is signed in,
and a step after that fails saying so rather than asking LinkedIn. Signing
in again takes one click while still signed in to LinkedIn. A member posts
through an app at most 150 times a day.

Nothing here reads: the sign-in cannot see the member's feed, their past
posts, or who commented, so nothing on LinkedIn can start a workflow.
Posting as a company page needs LinkedIn's Community Management API, which
is reviewed; editing a profile is for developers LinkedIn approves. A post
cannot be put in a group: LinkedIn has a place for one in a post, and
answers an ordinary app that names it with a 500. LinkedIn has no test
site; it lets a developer keep up to five test profiles.

Checked against the real LinkedIn (2026-10-12), each by a step in a
workflow: signing in by the public address; a post of words alone, with a
link, a picture, two pictures, a video, a document and a poll; a comment
and a like, and each taken away again; every post deleted; and "who I am".
A repost of a post seen by connections only was refused (403), which
LinkedIn does for such a post whoever asks; one of a post anyone can see
has not been tried.

## WhatsApp, Facebook, Instagram and Threads

The four are Meta's, set up in one Meta app (developers.facebook.com), and
their calls are written against one Graph API version (`Meta::VERSION`),
which Meta answers to for about two years: move it on before then. Each call
is a step of its own, in its service's group. None has been run against the
real service yet; all of it against faked ones.

**WhatsApp** is a business number, by Meta's Cloud API -- not a person's own
WhatsApp. The connection takes the number's ID and a token (WhatsApp → API
Setup in the Meta app; the token shown there lasts a day, a system user's
lasts) and the app's secret. Meta gives a test number that can write to five
phones for nothing.

- **WhatsApp message** starts a run when the number is written to. The
  connection shows its webhook address and a verify token: both go in the
  Meta app under WhatsApp → Configuration, subscribed to "messages". Meta
  signs what it sends with the app secret; one address takes everything the
  app is told of, so only what came to the connection's own number is taken.
- **Send on WhatsApp** (words, with up to three buttons), **Send a file**
  (a picture, a video or a recording shown as one, anything else as a
  document), **Send a place**, **React**, **Mark as read** (with
  "typing…"), and **WhatsApp: get the file**.
- Words of one's own reach someone only within 24 hours of their last
  message. Before that, or after, only **Send a template** does: a message
  WhatsApp approved for the business, which Meta charges for.

**Facebook** posts as a Page: Facebook lets an app post as a Page, never on
a person's own profile. The connection is the Meta app's ID and secret, with
Facebook Login added; someone who looks after the Page signs in, and the
Page's own token is kept, which does not run out. An account that looks
after several Pages names the one in "Page". Steps: **Post on a Facebook
Page** (words, a link, a picture or several, a video), comment, like, take
back a like, delete, and -- Facebook lets a Page read -- **the Page's
posts**, **a post's comments** and **the Page**. Nothing on Facebook starts
a workflow yet: a schedule that asks for the newest comments does instead.

**Instagram** posts as a professional account (a business's or a creator's;
Instagram gives an app nothing of a personal one), signed in by Instagram's
own login with the Instagram app's ID and secret. Steps: **Post on
Instagram** (a picture, several to swipe through, a reel, a story), comment,
answer a comment, delete a comment, **my posts**, **a post's comments**,
**who I am**. Instagram is not sent a file: it is handed an address and
fetches it, so posting needs `WORKFLOWS_PUBLIC_URL`, and a picture has to be
a JPEG. A video is published once Instagram has worked through it; the step
waits meanwhile, holding no worker, for up to five minutes. An app can
publish and cannot take a post down again.

**Threads** posts as a Threads profile -- anyone's -- signed in with the
Threads app's ID and secret. Steps: **Post on Threads** (words up to 500
letters, a link, a picture, a video, several; told which post it answers,
a reply under it), repost, delete (100 a day), **my posts**, **a post's
replies**, **who I am**. Pictures and videos are fetched from the app, as
on Instagram.

Instagram's and Threads' sign-ins last 60 days and, unlike LinkedIn's, are
made new unasked while they still hold -- when a step uses the connection or
it is tested with ten days or fewer left -- so one in use does not run out.
Every sign-in comes back by the same public address as LinkedIn's
(`/hooks/{service}/signed-in`), which the connection shows to be listed in
the Meta app.

## SerpApi and Tavily

Two ways to search the web from a workflow, each a connection under
**Search** that is a key alone, and each call a step of its own in its
service's group. Testing a connection is free with both, and says what is
left of the key. Nothing here changes anything outside, so a step is simply
run again when its worker dies.

**SerpApi** (serpapi.com) gives what a search engine itself shows, as data.
Its key is at serpapi.com/manage-api-key. A search counts as one against
the plan's month; the same one asked again within the hour is answered from
SerpApi's cache for nothing.

- **Search Google**, **Search Google News**, **Search Google Images**,
  **Search Google Maps** and **Search YouTube** each give out `results`,
  with the parts of each worth having: a web result's `title`, `link` and
  `snippet`; a place's `title`, `address`, `lat`, `lng` and `rating` -- so
  a result as it is can go with a chat message as its place; a picture's
  own address as `image`. Google's also gives `answer`, `knowledge`,
  `questions` and `related`. Google, News and YouTube give `text` too: the
  results as words for a model to read, for **Ask AI**'s prompt.
- **SerpApi: any engine** asks any other one -- Google Shopping, Scholar,
  Trends, Flights, Hotels, Bing, DuckDuckGo, Amazon -- by its name and what
  its page on serpapi.com says it takes, and gives out the answer whole as
  `body`. A step gives out at most 256 KB; SerpApi's `json_restrictor`
  keeps an answer to the parts named.
- **SerpApi: find a place** gives the names a search can be made "from"
  (free, with no key), and **SerpApi: searches left** the plan's month.
- Every search step has **More**: anything else its engine takes, by the
  name SerpApi gives it. What the step's own settings say goes over it.
- A search that finds nothing is not a failure: it gives out no results.

The app's own `SERPAPI_KEY` is another thing: it fills in opening hours on
the Maps demo, and no workflow uses it.

**Tavily** (tavily.com) searches for a model to read: it gives the part of
each page that answers, and reads pages whole. Its key (`tvly-…`) is at
app.tavily.com, and each call spends credits.

- **Search with Tavily** gives out `results` (`title`, `url`, `content`,
  `score`), an `answer` written from them when asked for one, and `text`,
  the results as words for a model. Each page whole is `text` on each
  result, when asked for.
- **Tavily: read pages** reads up to 20 pages by their addresses: `text`
  is one page's words, or every page's each under its address; `results`
  has each apart, and `failed` the ones that could not be read. It fails
  only when none could.
- **Tavily: crawl a site** follows a site's links and reads what it finds;
  **Tavily: list a site's pages** only lists them. Both are told to stop
  after two minutes with what they have, since a step is stopped at 150
  seconds.
- **Tavily: credits used** gives what the key and its plan have spent.
- Pages' text is cut short where together it is more than a step may give
  out (about 100 KB of it), and `cut` says so.
- A call that never reached Tavily is made again; one that was not
  answered in time is not, since it may have been paid for.

Not done: Tavily's research call, which answers later rather than at once,
and SerpApi's archive of past searches. Checked against faked services
only -- neither has been asked for real from a workflow yet.

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
