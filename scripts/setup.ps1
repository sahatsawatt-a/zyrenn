# First-run setup for a fresh clone on Windows: scripts/setup.sh for PowerShell,
# step for step. Safe to run again: it only fills in what is missing and never
# overwrites a value you have set.
#
#   .\scripts\setup.ps1                                # dev: Vite with HMR
#   .\scripts\setup.ps1 -Build                         # serve the built bundle (no Vite), for a server
#   .\scripts\setup.ps1 -Traefik zyrenn.example.com    # behind Traefik over HTTPS (implies -Build)
#
# Blocked by the execution policy? powershell -ExecutionPolicy Bypass -File scripts\setup.ps1
#
# WSL2, with the clone in the Linux filesystem and scripts/setup.sh, is faster:
# with the project on C:\ every file the containers read crosses Docker
# Desktop's file sharing, and Vite never hears that a file changed.
#
# Keep this file ASCII: Windows PowerShell 5.1 reads a script without a BOM as
# ANSI, and some UTF-8 punctuation decodes to characters it parses as quotes.
param(
    [switch]$Build,
    [string]$Traefik = ''
)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$doBuild = $Build.IsPresent -or [bool]$Traefik

# Windows PowerShell turns a native command's redirected stderr into a
# terminating error under 'Stop', so docker is run through these.

# Run docker quietly and say whether it succeeded.
function Test-Docker {
    $saved = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { & docker @args *> $null } finally { $ErrorActionPreference = $saved }
    return $LASTEXITCODE -eq 0
}
# Run docker and return what it printed, or '' when it failed.
function Get-DockerOutput {
    $saved = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $out = & docker @args 2> $null } finally { $ErrorActionPreference = $saved }
    if ($LASTEXITCODE -ne 0) { return '' }
    return ($out -join "`n").Trim()
}
# Run docker and stop here if it fails.
function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw "'docker $args' failed (exit $LASTEXITCODE)" }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker Desktop is required: https://docs.docker.com/desktop/setup/install/windows-install/'
}
if (-not (Test-Docker compose version)) { throw "Docker Compose v2 is required ('docker compose')." }

if (-not (Test-Path .env)) { Copy-Item .env.example .env; Write-Host '- created .env' }

# .env is edited in memory and written once, with LF endings and no BOM: a BOM
# would become part of the first key's name when Laravel reads it.
$envPath = Join-Path (Get-Location) '.env'
$lines = [System.Collections.Generic.List[string]]([IO.File]::ReadAllLines($envPath))

function Get-EnvValue($key) {
    foreach ($line in $lines) { if ($line -match "^$key=(.*)$") { return $Matches[1] } }
    return $null
}
# Set KEY=value, replacing its line (or, with -OrExample, a commented-out
# example of it) or appending one.
function Set-EnvValue($key, $value, [switch]$OrExample) {
    $pattern = if ($OrExample) { "^#? ?$key=" } else { "^$key=" }
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) { $lines[$i] = "$key=$value"; return }
    }
    $lines.Add("$key=$value")
}
# Set KEY=value only while KEY is empty or absent.
function Add-EnvValue($key, $value) {
    if ([string]::IsNullOrEmpty((Get-EnvValue $key))) { Set-EnvValue $key $value; Write-Host "- set $key" }
}
function New-Secret {
    $bytes = New-Object byte[] 16
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

# UID and GID stay at 1000 from .env.example: Docker Desktop shares files on
# C:\ with every user in the containers, so there is no host user to match.

# Host ports: if something else already listens on one, move to the next free
# port. Skipped while this project's own stack is up, since it holds them itself.
function Test-PortInUse([int]$port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try { return $client.ConnectAsync('127.0.0.1', $port).Wait(300) } catch { return $false } finally { $client.Dispose() }
}
if (-not (Get-DockerOutput compose ps -q)) {
    foreach ($key in 'APP_PORT', 'VITE_PORT', 'FORWARD_DB_PORT') {
        $value = Get-EnvValue $key
        if (-not $value) { continue }
        $wanted = [int]$value
        $port = $wanted
        while (Test-PortInUse $port) { $port++ }
        if ($port -ne $wanted) {
            Set-EnvValue $key $port
            Write-Host "- port $wanted is taken, using $key=$port"
            # The browser reaches the app and the websocket on APP_PORT
            if ($key -eq 'APP_PORT') {
                if ((Get-EnvValue APP_URL) -match '^http://localhost:') { Set-EnvValue APP_URL "http://localhost:$port" }
                if ($null -ne (Get-EnvValue VITE_REVERB_PORT)) { Set-EnvValue VITE_REVERB_PORT $port }
            }
        }
    }
}

Add-EnvValue REVERB_APP_ID (Get-Random -Minimum 100000 -Maximum 1000000)
Add-EnvValue REVERB_APP_KEY (New-Secret)
Add-EnvValue REVERB_APP_SECRET (New-Secret)
Add-EnvValue COLLAB_SECRET ((New-Secret) + (New-Secret))

if ($doBuild) {
    Set-EnvValue COMPOSE_PROFILES ''
    Set-EnvValue APP_DEBUG 'false'
}

if ($Traefik) {
    if (-not (Test-Docker network inspect traefik)) {
        Write-Host "- creating the external 'traefik' network"
        Invoke-Docker network create traefik | Out-Null
    }
    # The overlay wants a vite host even when Vite is off: one label, same parent domain
    $label = $Traefik.Split('.')[0]
    $parent = if ($Traefik.Contains('.')) { $Traefik.Substring($Traefik.IndexOf('.') + 1) } else { $Traefik }
    # Compose splits COMPOSE_FILE on ';' on Windows, not ':'
    Set-EnvValue COMPOSE_FILE 'docker-compose.yml;docker-compose.traefik.yml' -OrExample
    Set-EnvValue APP_HOST $Traefik -OrExample
    Set-EnvValue VITE_HOST "$label-vite.$parent" -OrExample
    Set-EnvValue APP_URL "https://$Traefik" -OrExample
    Write-Host "- Traefik: https://$Traefik (needs Traefik running; see docs/SETUP.md)"
}

[IO.File]::WriteAllText($envPath, ($lines -join "`n") + "`n", (New-Object System.Text.UTF8Encoding $false))

Write-Host '- building the image'
Invoke-Docker compose build app

Write-Host '- installing PHP and JS dependencies'
Invoke-Docker compose run --rm --no-deps app composer install --no-interaction
Invoke-Docker compose run --rm --no-deps app npm install

if ((Get-EnvValue APP_KEY) -notmatch '^base64:') {
    Invoke-Docker compose run --rm --no-deps app php artisan key:generate --force
}

Write-Host '- starting the stack'
if ($doBuild) { Invoke-Docker compose run --rm --no-deps app npm run build }
Invoke-Docker compose up -d --wait postgres app

Invoke-Docker compose exec app php artisan migrate --force
Invoke-Docker compose up -d

$port = Get-EnvValue APP_PORT
if (-not $port) { $port = 8000 }
$url = "http://localhost:$port"
if ($Traefik) { $url = "https://$Traefik" }
Write-Host @"

Done. ZyrenN is at $url

Next: register an account there (or 'docker compose exec app php artisan db:seed'
for test@example.com / password), then follow docs/SETUP.md to connect an AI client.
"@
