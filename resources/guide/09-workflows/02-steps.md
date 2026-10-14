# Steps

Add the next step from the **+** on a step, and pick it from the list, grouped by what it works with. Each step does one thing; each call to a service is a step of its own, so a run shows which call did what.

## Flow

| Step             | Does                                                                            |
| ---------------- | ------------------------------------------------------------------------------- |
| If               | Goes one of two ways, on a formula such as `trigger.total > 100`                |
| Switch           | Goes down the first of several named ways whose formula is true, or "otherwise" |
| Merge            | Brings ways back together: once all have come, or with the first                |
| For each         | Runs another workflow once for each item of a list, a few at a time             |
| Delay            | Waits: minutes, hours, or days                                                  |
| Ask for approval | Asks someone, and waits for a yes or a no                                       |

## Data

| Step     | Does                                                           |
| -------- | -------------------------------------------------------------- |
| Set data | Gives out values of your own, worked out from what came before |
| Run code | Runs JavaScript you write. See Run code                        |
| Log      | Writes a line in the run's log                                 |

## In the app

| Group  | Steps                                                                    |
| ------ | ------------------------------------------------------------------------ |
| Chat   | Write in a room, Read a room, Find or make a room                        |
| Tables | Find rows, Add a row, Change a row                                       |
| Drive  | Save to Drive, Find files, Read a file, Make a link to a file            |
| AI     | Ask AI: a model of your own or a hosted one, with pictures if it can see |

## Outside

| Group                        | Steps                                                            |
| ---------------------------- | ---------------------------------------------------------------- |
| Web                          | HTTP request; search the web with SerpApi or Tavily              |
| Telegram                     | Send, send a file, get a file, and the rest of what a bot can do |
| LINE                         | Reply, push, who wrote, get a file, rich menus                   |
| WhatsApp                     | Send, and get a file                                             |
| LinkedIn                     | Post: words, a link, pictures, a video, a document or a poll     |
| Facebook, Instagram, Threads | Post, and answer comments                                        |

A step that talks to a service uses a **connection**: that service's key, saved once. See Settings and AI clients.

## When a step fails

**If it fails**, in a step's settings, chooses: stop the run, or carry on and pass on what went wrong, for the steps after it to deal with.
