# Setting ZyrenN up on another machine

Three parts: **run the app** (once per machine), optionally **put it behind
Traefik with HTTPS**, then **connect an AI client** (once per client, by
whichever method suits).

## 1. Run the app

You need Docker with Compose v2 and git. Nothing else is installed on the host.

```sh
git clone <repo-url> zyrenn && cd zyrenn
./scripts/setup.sh            # dev: Vite with hot reload
./scripts/setup.sh --build    # server: built assets, APP_DEBUG=false
```

On Windows, the same script for PowerShell, with the same options:

```powershell
.\scripts\setup.ps1           # or -Build, or -Traefik zyrenn.example.com
# blocked by the execution policy? powershell -ExecutionPolicy Bypass -File scripts\setup.ps1
```

WSL2 is faster: clone into the Linux filesystem (`~`, not `/mnt/c`) and run
`./scripts/setup.sh` there. With the project on `C:\`, every file the
containers read crosses Docker Desktop's file sharing, and Vite never hears that
a file changed.

The script copies `.env.example` to `.env`, fills in your UID/GID and the
secrets (`REVERB_*`, `COLLAB_SECRET`), installs dependencies, generates
`APP_KEY`, migrates and starts everything. Run it again any time; it never
overwrites a value you have set.

Prefer to do it by hand? Those are exactly these steps:

```sh
cp .env.example .env                         # then set UID/GID (`id -u`, `id -g`),
                                             # REVERB_APP_ID/KEY/SECRET, COLLAB_SECRET
docker compose build app
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app npm install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d postgres app
docker compose exec app php artisan migrate --force
docker compose up -d
```

Open <http://localhost:8001> (`APP_PORT` in `.env`; change it if the port is
taken, along with `VITE_PORT` and `FORWARD_DB_PORT`). Register an account, or
seed the test one: `docker compose exec app php artisan db:seed`
(`test@example.com` / `password`).

**Port conflicts.** The stack publishes three host ports: `APP_PORT` (8001),
`VITE_PORT` (5173) and `FORWARD_DB_PORT` (5434). On a first run `setup.sh`
checks them and, for any already in use, picks the next free one and says so.
To choose your own, edit them in `.env` and apply with `docker compose up -d`.
If you changed `APP_PORT` after setup, also update `APP_URL` and
`VITE_REVERB_PORT` in `.env` to match, and use the new port in any MCP URL.
Find what holds a port with `ss -ltnp | grep :8001`. An error such as
"port is already allocated" from `docker compose up` means exactly this.

If it does not come up: `docker compose ps`, then `docker compose logs app`.

## 2. Serve it over HTTPS with Traefik (optional, for a server)

Do this when the app should be reachable by name, from other machines, with a
real certificate. It is also what makes MCP over HTTP safe off localhost: the
bearer token travels in the clear on plain `http://`, so anything beyond
`localhost` should use `https://`.

Prerequisites: a hostname whose DNS points at this machine, and ports 80 and 443
reachable from the internet (Let's Encrypt checks over port 80).

1. **Traefik.** If this machine already runs Traefik on an external network
   named `traefik`, with a `websecure` entrypoint and a certificate resolver as
   the default for it, skip to step 2. Otherwise start the one in this repo:

   ```sh
   cd docs/traefik
   cp .env.example .env          # set ACME_EMAIL
   docker network create traefik
   docker compose up -d
   cd ../..
   ```

2. **ZyrenN, behind it:**

   ```sh
   ./scripts/setup.sh --traefik zyrenn.example.com
   ```

   This builds the assets, turns debug off, creates the `traefik` network if
   missing, and sets `COMPOSE_FILE`, `APP_HOST`, `VITE_HOST` and
   `APP_URL=https://zyrenn.example.com` in `.env`. (By hand: set those four,
   keep `VITE_HOST` one label under the same parent domain, then
   `docker compose run --rm app npm run build` and `docker compose up -d`.)

3. Open `https://zyrenn.example.com`. The first certificate takes a few seconds;
   if it never arrives, `docker compose -f docs/traefik/docker-compose.yml logs traefik`.

Nothing else needs configuring. Websockets and live editing pick `wss://` from
the page's own address, `X-Forwarded-Proto` is passed through, and proxies are
trusted, so there is no mixed content and no CORS. The overlay routes only the
nginx front, so the app, database and Reverb stay unreachable from outside.

Then use `https://zyrenn.example.com/mcp/user` (or `/mcp/global`) as the
endpoint in section 3. Hosted clients that connect from the cloud, such as
claude.ai's custom connectors, need exactly this: a public HTTPS URL.

**Local HTTPS, no public DNS.** Use a name that resolves on your machines
(an `/etc/hosts` line, or local DNS) such as `zyrenn.test`, and give Traefik a
certificate it does not need Let's Encrypt for. Make one with
[mkcert](https://github.com/FiloSottile/mkcert) (`mkcert -install`, then
`mkcert zyrenn.test`), mount the pair into the Traefik container, and point a
file-provider `tls.certificates` entry at it
([Traefik docs](https://doc.traefik.io/traefik/https/tls/#user-defined)). Every
client machine must also trust the mkcert root CA, or its MCP client will reject
the certificate; for that case, plain HTTP on a trusted LAN is simpler.

Keep the admin endpoint (`/mcp/global`) off the public internet if you can:
put it behind a VPN, or leave `MCP_GLOBAL_TOKEN` empty so it answers 404.

## 3. Connect an AI client

Per-client steps (Claude Code and Desktop, Cursor, VS Code, Windsurf, Antigravity,
Gemini CLI, Codex, Zed, Cline, and a generic recipe) are in
[MCP-CLIENTS.md](MCP-CLIENTS.md). This section covers the four ways to reach the
servers; that page covers how each client is told about them.

There are two servers and two ways to reach each.

|                | personal: `zyrenn`                | admin: `zyrenn-admin`                  |
| -------------- | --------------------------------- | -------------------------------------- |
| sees           | one user's content                | every user's (pass `user_id` per call) |
| credential     | token from Settings → MCP         | `MCP_GLOBAL_TOKEN` in `.env`           |
| HTTP endpoint  | `<app url>/mcp/user`              | `<app url>/mcp/global`                 |
| stdio command  | `mcp:start zyrenn` (`MCP_TOKEN`)  | `mcp:start zyrenn-admin`               |

Use **HTTP** from any machine that can reach the app (another laptop, the LAN).
Use **stdio** only on the machine running the Docker stack, from the project
folder. `<app url>` is `http://localhost:8001` locally, `https://<your host>` behind
Traefik, or the host/IP you serve it on. Check it with `docker compose port web 8080`.

### Method A: personal, over HTTP (recommended)

1. Sign in, open **Settings → MCP**, name a token (e.g. "Claude Code on laptop")
   and create it. It is shown once; copy it.
2. In the client:

   ```sh
   claude mcp add --transport http zyrenn http://<host>:8001/mcp/user \
     --header "Authorization: Bearer <token>"
   ```

   Or in any client's JSON config (`.mcp.json`, Claude Desktop's
   `claude_desktop_config.json`, Cursor's `mcp.json`):

   ```json
   { "mcpServers": { "zyrenn": {
       "type": "http",
       "url": "http://<host>:8001/mcp/user",
       "headers": { "Authorization": "Bearer <token>" }
   } } }
   ```

   A client that only speaks stdio (older Claude Desktop) can bridge with
   `npx mcp-remote http://<host>:8001/mcp/user --header "Authorization: Bearer <token>"`.
3. Check: ask the client to "list my notes", or run `claude mcp list`.

Revoke a token in the same settings page; it stops working at once.

### Method B: personal, over stdio

Same machine as Docker only. Make a token as in A.1, then from anywhere:

```sh
claude mcp add zyrenn -e MCP_TOKEN=<token> -- \
  docker compose -f /absolute/path/to/zyrenn/docker-compose.yml \
  exec -T -e MCP_TOKEN app php artisan mcp:start zyrenn
```

Or as JSON:

```json
{ "mcpServers": { "zyrenn": {
    "type": "stdio",
    "command": "docker",
    "args": ["compose", "-f", "/absolute/path/to/zyrenn/docker-compose.yml",
             "exec", "-T", "-e", "MCP_TOKEN", "app", "php", "artisan", "mcp:start", "zyrenn"],
    "env": { "MCP_TOKEN": "<token>" }
} } }
```

The stack must be up (`docker compose up -d`).

### Method C: admin, over HTTP

For operators who need every user's content. Treat the token like a root password.

1. Generate one and put it in `.env`:
   ```sh
   echo "MCP_GLOBAL_TOKEN=$(openssl rand -hex 32)" # paste over the empty line
   docker compose restart app
   ```
   While it is empty the endpoint answers 404, which is how it stays off.
2. Add it:
   ```sh
   claude mcp add --transport http zyrenn-admin http://<host>:8001/mcp/global \
     --header "Authorization: Bearer <MCP_GLOBAL_TOKEN>"
   ```
3. Check: ask it to "list users", then pass one of the ids as `user_id`.

Do not expose this endpoint on the internet; keep it to localhost or a trusted LAN/VPN.

### Method D: admin, over stdio

Needs no token (it runs inside the container, as the operator). The repo's
`.mcp.json` is git-ignored because its path is per machine; create it in the
project folder:

```json
{ "mcpServers": { "zyrenn-admin": {
    "type": "stdio",
    "command": "docker",
    "args": ["compose", "exec", "-T", "app", "php", "artisan", "mcp:start", "zyrenn-admin"]
} } }
```

Claude Code picks it up when started in that folder. From elsewhere, add
`"-f", "/absolute/path/to/zyrenn/docker-compose.yml"` after `"compose"`.

## 4. Troubleshooting

| symptom                                   | cause                                                                 |
| ----------------------------------------- | --------------------------------------------------------------------- |
| 401 from `/mcp/user`                      | token mistyped or revoked; make a new one                             |
| 404 from `/mcp/global`                    | `MCP_GLOBAL_TOKEN` is empty, or `app` was not restarted after setting |
| 401 from `/mcp/global`                    | header is not exactly `Bearer <MCP_GLOBAL_TOKEN>`                     |
| stdio: "Set MCP_TOKEN to a valid token"   | `MCP_TOKEN` not passed (`-e MCP_TOKEN` is needed in the docker args)  |
| stdio: "no configuration file provided"   | not in the project folder, and no `-f` given                          |
| connection refused over HTTP              | wrong host/port, or a firewall; `docker compose port web 8080`        |
| "port is already allocated"               | another process has `APP_PORT`/`VITE_PORT`/`FORWARD_DB_PORT`; see Port conflicts |
| permission errors writing `storage/`      | `UID`/`GID` in `.env` are not yours; fix, then `docker compose build app` |
| Traefik: 404 or bad gateway               | `APP_HOST` does not match the URL you opened, or `web` is not on the `traefik` network (`docker network inspect traefik`) |
| Traefik: browser shows a default/invalid cert | DNS not pointing here, or port 80 blocked, so Let's Encrypt failed; read the traefik logs |
| mixed-content warnings                    | `APP_URL` still `http://`; set it to the `https://` URL and `docker compose up -d` |
| live co-editing not working               | `COLLAB_SECRET` empty; set it and `docker compose up -d collab app`   |
