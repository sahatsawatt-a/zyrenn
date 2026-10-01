# Connecting an AI client

Any client that speaks MCP can use ZyrenN. This page has the exact steps for
each common one. Read [SETUP.md](SETUP.md) first if the app is not running yet.

Every client needs the same two things, whatever it calls them:

|              | personal (`zyrenn`)                       | admin (`zyrenn-admin`)                    |
| ------------ | ----------------------------------------- | ----------------------------------------- |
| **URL**      | `<app url>/mcp/user`                      | `<app url>/mcp/global`                    |
| **Header**   | `Authorization: Bearer <your token>`      | `Authorization: Bearer <MCP_GLOBAL_TOKEN>` |
| **Token**    | Settings → MCP in the app, shown once     | `MCP_GLOBAL_TOKEN` in `.env`              |

`<app url>` is `http://localhost:8001`, or `https://<your host>` behind Traefik.
The examples below use the personal server; for admin, swap the name, URL and
token. Use `https://` for anything off `localhost`: the token is sent in clear
on plain HTTP.

## First, test it without any client

This proves the URL and token are right, so a client that fails afterwards is a
client problem, not a ZyrenN one:

```sh
curl -s -X POST <app url>/mcp/user \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

You should get JSON naming `"serverInfo":{"name":"Zyrenn (personal)"…}`.
A `401`, or a redirect to the login page, means the token is wrong or revoked.

## Which client speaks what

| client                     | remote URL + header | stdio | notes                                           |
| -------------------------- | :-----------------: | :---: | ----------------------------------------------- |
| Claude Code                | yes                 | yes   | `claude mcp add`                                |
| Claude Desktop             | via bridge          | yes   | config file; remote needs `mcp-remote`          |
| claude.ai / Claude mobile  | no                  | no    | custom connectors cannot send a bearer header   |
| Cursor                     | yes                 | yes   | `url` + `headers`                               |
| VS Code (Copilot)          | yes                 | yes   | key is `servers`, not `mcpServers`              |
| Windsurf                   | yes                 | yes   | `serverUrl`                                     |
| Google Antigravity         | yes                 | yes   | `serverUrl`                                     |
| Gemini CLI                 | yes                 | yes   | `httpUrl`                                       |
| Codex CLI (OpenAI)         | yes                 | yes   | TOML; token from an environment variable        |
| Zed                        | yes                 | yes   | `context_servers`                               |
| Cline                      | yes                 | yes   | `type: streamableHttp`                          |
| anything else              | try the generic form below                      |

## Claude Code

```sh
claude mcp add --transport http zyrenn <app url>/mcp/user \
  --header "Authorization: Bearer <token>"
claude mcp list                  # zyrenn should show as connected
```

Add `--scope user` to have it in every project, or `--scope project` to write a
shared `.mcp.json`. **Do not commit a token**; for a shared file use
`"Authorization": "Bearer ${ZYRENN_TOKEN}"` and export that variable. In a
session, `/mcp` shows status. stdio form: see Method B in [SETUP.md](SETUP.md).

## Claude Desktop

Settings → Developer → Edit Config opens `claude_desktop_config.json`. It
launches stdio servers, so reach a remote ZyrenN through the `mcp-remote`
bridge (needs Node.js):

```json
{
  "mcpServers": {
    "zyrenn": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "<app url>/mcp/user",
               "--header", "Authorization:${ZYRENN_AUTH}"],
      "env": { "ZYRENN_AUTH": "Bearer <token>" }
    }
  }
}
```

(The header is written `Name:value` with the secret in `env`, because Desktop
on Windows mangles spaces inside `args`.) On the machine running Docker you can
skip the bridge and use the stdio form from Method B in [SETUP.md](SETUP.md).
Restart Claude Desktop after editing. Add `http://` URLs to `mcp-remote` only
with `--allow-http`.

## claude.ai, Claude mobile, and other hosted connectors

Custom connectors there take a URL and optionally OAuth; they have no field for
a bearer header, and ZyrenN authenticates with one. They can also only reach a
public HTTPS address. So they cannot connect today. Use Claude Code or Desktop.

## Cursor

`~/.cursor/mcp.json` (all projects) or `.cursor/mcp.json` (one project), or
Settings → MCP → Add:

```json
{
  "mcpServers": {
    "zyrenn": {
      "url": "<app url>/mcp/user",
      "headers": { "Authorization": "Bearer ${env:ZYRENN_TOKEN}" }
    }
  }
}
```

Cursor expands `${env:NAME}`, so the token can stay out of the file. Check the
toggle next to the server in Settings → MCP turns green.

## VS Code (GitHub Copilot, agent mode)

`.vscode/mcp.json` in a project, or "MCP: Open User Configuration" for all.
The top-level key is **`servers`**:

```json
{
  "inputs": [
    { "type": "promptString", "id": "zyrenn-token", "description": "ZyrenN token", "password": true }
  ],
  "servers": {
    "zyrenn": {
      "type": "http",
      "url": "<app url>/mcp/user",
      "headers": { "Authorization": "Bearer ${input:zyrenn-token}" }
    }
  }
}
```

VS Code asks for the token once and stores it, so nothing secret is in the
file. Start the server from the "Start" lens above it, then pick the tools in
the Chat panel's tools menu.

## Windsurf

`~/.codeium/windsurf/mcp_config.json`, or the MCP icon in the Cascade panel →
Configure. Remote servers use **`serverUrl`**:

```json
{
  "mcpServers": {
    "zyrenn": {
      "serverUrl": "<app url>/mcp/user",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

Press Refresh in the MCP panel after saving.

## Google Antigravity

Open the agent panel's **⋯ → MCP Servers → Manage MCP Servers → View raw
config**, which opens the right file for your version (it has been
`~/.gemini/config/mcp_config.json` globally and `.agents/mcp_config.json` per
workspace). Remote servers need **`serverUrl`**; `url` and `httpUrl` are not
accepted:

```json
{
  "mcpServers": {
    "zyrenn": {
      "serverUrl": "<app url>/mcp/user",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

Save, then Refresh in Manage MCP Servers; the tools list under `zyrenn`.

## Gemini CLI

```sh
gemini mcp add --transport http zyrenn <app url>/mcp/user \
  --header "Authorization: Bearer <token>"
```

or edit `~/.gemini/settings.json` (all projects) / `.gemini/settings.json`:

```json
{
  "mcpServers": {
    "zyrenn": {
      "httpUrl": "<app url>/mcp/user",
      "headers": { "Authorization": "Bearer $ZYRENN_TOKEN" }
    }
  }
}
```

`/mcp` inside Gemini CLI lists servers and their tools.

## Codex CLI

`~/.codex/config.toml`. The token is read from an environment variable, never
written in the file:

```toml
[mcp_servers.zyrenn]
url = "<app url>/mcp/user"
bearer_token_env_var = "ZYRENN_TOKEN"
```

```sh
export ZYRENN_TOKEN=<token>      # in your shell profile
codex mcp list
```

## Zed

Settings (`zed: open settings`):

```json
{
  "context_servers": {
    "zyrenn": {
      "url": "<app url>/mcp/user",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

## Cline

MCP Servers → Configure → "Configure MCP Servers" opens
`cline_mcp_settings.json`:

```json
{
  "mcpServers": {
    "zyrenn": {
      "type": "streamableHttp",
      "url": "<app url>/mcp/user",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

## Any other client

1. If it can add a **remote / HTTP / streamable-HTTP** server with custom
   headers, give it the URL and the `Authorization` header from the first table.
   The name of the URL field varies (`url`, `serverUrl`, `httpUrl`); the client's
   docs say which. SSE-only clients will not work: ZyrenN speaks streamable HTTP.
2. If it only launches **stdio** commands, either bridge the remote server:

   ```sh
   npx -y mcp-remote <app url>/mcp/user --header "Authorization:Bearer <token>"
   ```

   or, on the machine running Docker, run the server directly (needs the stack
   up, with `MCP_TOKEN` set to the personal token):

   ```sh
   docker compose -f /absolute/path/to/zyrenn/docker-compose.yml \
     exec -T -e MCP_TOKEN app php artisan mcp:start zyrenn
   ```
3. Prove the token with the `curl` test at the top before debugging the client.

## What a client should be able to do

Once connected, ask it: "list my notes", "list my boards", "list my tables" and
"list my projects". Each runs a read-only tool. If those work, the rest do.
Tools take a `project` argument to work in a shared project instead of your own
content; viewers can read but not change it.

## When it does not work

| symptom                                 | check                                                          |
| --------------------------------------- | -------------------------------------------------------------- |
| 401 / "unauthenticated"                 | token typed wrong, has a trailing space, or was revoked        |
| redirect to a login page                | the client sent no `Authorization` header (wrong field name)   |
| 404 on `/mcp/global`                    | `MCP_GLOBAL_TOKEN` is empty or `app` was not restarted         |
| connected, but no tools                 | restart/refresh the client; some only load tools at startup    |
| 429                                     | rate limit is 120 requests a minute per token                  |
| certificate error                       | self-signed or mkcert cert the client machine does not trust   |
| works in `curl`, not in the client      | wrong field name for that client (see its section above)       |
| remote works, stdio does not            | run the stdio command by hand; it prints the real error        |
