# Workflows

A workflow is a row of steps that runs on its own. The first step says **when** it starts; each step after it does one thing with what came before.

<video src="/images/guide/tour-workflow.webm" title="Show me: Start a workflow"></video>

## Make one

1. Press **New workflow**, or **From a template** for one already joined up.
2. Choose what starts it: by hand, on a schedule, a chat message, a webhook, another workflow, or a message on Telegram, LINE, WhatsApp, Instagram or Threads.
3. Add the next step from the **+** on a step. A step can read what the steps before it gave out.
4. Press **Run** to try it. The run opens step by step, each with what went in beside what came out.
5. **Publish** it and **switch it on**, and it runs by itself from then on.

![A workflow of four steps: run by hand, an If, and a step down each of its two ways](/images/guide/workflows-steps.png)

## Start from a template

**From a template** gives you a workflow already joined up, ready for your connections:

- AI answers on Telegram, or on LINE
- Telegram or LINE chats kept in a chat room here, both ways
- Files sent on Telegram or LINE, kept in the Drive
- A daily summary sent to Telegram
- A rich menu for a LINE channel

| To learn about                          | Read                                                  |
| --------------------------------------- | ----------------------------------------------------- |
| Schedules, messages and webhooks        | [What starts a workflow](/guide/workflows/starts)     |
| Every kind of step                      | [Steps](/guide/workflows/steps)                       |
| `{{ trigger.text }}` and test data      | [Using what came before](/guide/workflows/using-data) |
| Reading a run, and when one fails       | [Runs](/guide/workflows/runs)                         |
| Drafts, versions and switching on       | [Publish and switch on](/guide/workflows/publish)     |
| Files from messages, and into the Drive | [Files in a workflow](/guide/workflows/files)         |
| JavaScript in a step                    | [Run code](/guide/workflows/run-code)                 |

:::callout 💡
A step that talks to an outside service, such as a Telegram bot or an AI model, needs a **connection**: that service's key, saved once under **Settings**, then **Connections**.
:::
