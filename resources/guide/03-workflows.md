# Workflows

A workflow is a row of steps that runs on its own. The first step says **when** it starts; each step after it does one thing with what came before.

## Make one

1. Press **New workflow**, or **From a template** for one already joined up.
2. Choose what starts it: by hand, on a schedule, a chat message, a webhook, or a message to a Telegram bot or LINE channel.
3. Add the next step from the **+** on a step. A step can read what the steps before it gave out.
4. Press **Run** to try it. The run opens step by step, each with what went in beside what came out.

![A workflow of four steps: run by hand, an If, and a step down each of its two ways](/images/guide/workflows-steps.png)

:::callout 💡
Nothing starts by itself until the workflow is **published** and **switched on**. Until then it only runs when you press Run.
:::

## What starts a workflow

| Start            | Use it when                                       |
| ---------------- | ------------------------------------------------- |
| Run by hand      | You are trying it out, or want a button to press  |
| On a schedule    | It should happen at a time, like weekdays at 9:00 |
| Chat message     | Someone writes in a chat here                     |
| Webhook          | Another service calls an address                  |
| Telegram message | Someone writes to your bot                        |
| LINE message     | Someone writes to your channel                    |

## Connections

A step that talks to an outside service, such as a Telegram bot or an AI model, needs a **connection**: that service's key, saved once and then chosen in the step.

## When a run fails

Open the run and find the step that failed. Its input is on one side and what it gave out on the other. Put the step right, then run again.

![A run, with one step opened to show its input beside its output](/images/guide/workflows-run.png)
