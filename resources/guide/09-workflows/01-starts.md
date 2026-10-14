# What starts a workflow

The first step of a workflow says when it runs. Choose it when the workflow is made; change it from the step's settings.

## Run by hand

Runs when **Run** is pressed. Give it **Test data**, such as `{"customer": "Ada", "total": 120}`: the run starts with that, as if it had come in. Every workflow can be run this way while it is being made, whatever starts it once it is switched on.

## On a schedule

Runs at a time: every hour, weekdays at 9:00, the first of the month. Write when as a cron line, such as `0 9 * * 1-5` for 9:00 on weekdays, and choose the **Time zone** it is read in.

## Messages

| Start             | Runs when                          | Set up with              |
| ----------------- | ---------------------------------- | ------------------------ |
| Chat message      | Someone writes in a chat room here | The room                 |
| Telegram message  | Someone writes to your bot         | The bot's connection     |
| LINE message      | Someone writes to your channel     | The channel's connection |
| WhatsApp message  | Someone writes to your number      | The number's connection  |
| Instagram message | A message comes to your account    | The account's connection |
| Instagram comment | Someone comments on a post         | The account's connection |
| Threads reply     | Someone replies on your profile    | The profile's connection |

Telegram and LINE can also take messages only from groups, or only those starting with words of your own, such as `/order`.

**When messages come quickly** chooses what happens when one person writes several at once: each starts a run at once; one at a time, in order; or only the latest, which stops the runs still going for that chat.

## Webhook

Runs when another service calls the workflow's own address. Its settings show the address to give that service, and can check that a call really came from it with a signing secret.

## When run by another workflow

Runs when another workflow hands it work, with **For each**: once for each item of a list. See Steps.

:::callout 💡
Nothing but Run starts a workflow until it is **published** and **switched on**. See Publish and switch on.
:::
