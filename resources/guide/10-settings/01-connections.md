# Connections

A connection is another service's key, saved once and then chosen in a workflow's step. Add one under **Settings**, then **Connections**, with **Add connection**.

| Connection                                       | For                                          | Needs                                             |
| ------------------------------------------------ | -------------------------------------------- | ------------------------------------------------- |
| A model of your own                              | Ollama or LM Studio, on your machine         | Its address; no key                               |
| A hosted model                                   | OpenRouter, OpenAI, Groq or Google Gemini    | Your key                                          |
| Telegram                                         | A bot that hears from and answers people     | The bot's token, from BotFather                   |
| LINE                                             | A channel that hears from and answers people | Its channel access token and secret               |
| LinkedIn, Facebook, Instagram, Threads, WhatsApp | Posting and messages                         | Your app's keys, then signing in with the service |
| SerpApi, Tavily                                  | Searching the web from a workflow            | Your key                                          |

**Test** checks that a connection still works. A model connection can name its **usual model**, the one steps start with.

## Webhooks

A Telegram bot is pointed at the app for you when a workflow using it is switched on. A LINE channel shows its webhook address, to paste in the LINE Developers console under Messaging API.

## Yours, or a project's

The connections under your **Settings** are yours alone. A project keeps its own under **Project settings**, so nobody's own key pays for shared work; its owners and editors manage them.

Removing a connection stops the workflows that use it until they are given another.
