# Springs Node Agent – Reference (`springs_install.php`)

`springs_install.php` is the node installer for **SwiftPanel**. When a node runs
the command it generates, the node becomes a game-server host: it installs
Docker, writes the **Springs** agent (a small Python HTTP daemon) and registers
it as a systemd service. The panel then manages game servers on that node over
a token-protected REST API.

Supported games: `cs16`, `cs2`, `minecraft` (Paper), `gmod`, `tf2`, `l4d2`, `rust`, `valheim`,
`zomboid`, `7dtd` and `palworld`. The panel-side details (ports, RAM, settings) are in the main
[README](../README.md#games).

```
Admin browser ──► SwiftPanel (PHP) ──HTTP + Bearer token──► Springs agent (node:8085)
                                                               │
                                                               └─► Docker containers (one per game server)
```

---

## Contents

- [Requirements](#requirements)
- [Install a node](#install-a-node)
- [What the installer does](#what-the-installer-does)
- [Panel integration](#panel-integration)
- [Game servers](#game-servers)
- [Agent API reference](#agent-api-reference)
- [Security notes](#security-notes)
- [Updating a node](#updating-a-node)
- [Troubleshooting](#troubleshooting)
- [Uninstall](#uninstall)

---

## Requirements

**On the panel server**

- PHP web server hosting `springs_install.php` in the web root (same folder as
  `dashboard.php`), reachable over HTTPS.
- The `boxes` table with `ip_address`, `ssh_port` (holds the agent's API port)
  and `ssh_password` (holds the agent's API token).

**On every node**

- Ubuntu or Debian with **systemd**, run as **root**.
- `python3` (3.8+; developed against 3.12) and `curl`.
- Outbound internet access (Docker, container images, PaperMC downloads).
- The API port (default **8085**) reachable from the panel server.

---

## Install a node

Run as `root` on the node:

```bash
curl -fsSL "https://YOUR-PANEL/springs_install.php?port=8085&token=YOUR_TOKEN" | bash
```

| Parameter | Required | Rules |
|-----------|----------|-------|
| `port`    | no (default `8085`) | Integer 1-65535 |
| `token`   | **yes** | Only `0-9`, `a-f`, `A-F`, `_` and `-` |

The token must be **identical** to the node's `boxes.ssh_password` value in the
panel database, otherwise the panel gets `401 Unauthorized`. The Add Node page
in the admin area generates this command for you.

If the token or port is missing or invalid, the script prints an error and
`exit 1`s without touching the node:

```
Springs install aborted: missing or invalid token/port (token may only contain 0-9, a-f, A-F, _ and -).
```

> Opening `springs_install.php` in a browser without parameters shows exactly
> that message. This is expected, not a fault.

---

## What the installer does

| Step | Action |
|------|--------|
| 1/4 | Installs Docker Engine via `https://get.docker.com` if `docker` is missing, then enables the service. |
| 2/4 | Creates `/opt/springs` and `/var/lib/springs/volumes` and writes the agent to `/opt/springs/springs.py`. |
| 3/4 | Pulls `ghcr.io/pterodactyl/games:source` (CS games) and `ghcr.io/ptero-eggs/yolks:java_25` (Minecraft). A failed Java pull only prints a warning. |
| 4/4 | Writes `/etc/systemd/system/springs.service` (token and port as environment variables), opens the port with `iptables` / `ufw`, and enables + restarts the service. |

**Files on the node**

| Path | Purpose |
|------|---------|
| `/opt/springs/springs.py` | The agent |
| `/etc/systemd/system/springs.service` | Service unit (`SPRINGS_PORT`, `SPRINGS_TOKEN`) |
| `/var/lib/springs/volumes/` | Default parent folder for server data |

The installer is **idempotent**: running it again overwrites the agent and
service file and restarts only the agent. Running game containers are not
stopped.

---

## Panel integration

| Panel piece | Role |
|-------------|------|
| `admin/nodes.php` | Adds nodes, generates the install command, polls telemetry every 5 s |
| `node_stats_ajax.php` | Called by the Nodes page; returns `{"status":"ok","stats":{...}}` |
| `ServerManager.php` | PHP client for the agent (start, stop, status, console, stats, install, wipe) |
| `files.php` | Client file manager, uses the `/api/files/*` endpoints |
| `boxes` table | `ip_address`, `ssh_port` (= API port), `ssh_password` (= API token) |

---

## Game servers

Each game server runs in its own Docker container named
`springs_<screen_name>`, with `--network host`, a `--memory=<ram_mb>m` limit and
the server's `install_path` mounted at `/data`.

| Game | Install | Runtime |
|------|---------|---------|
| `cs16` | SteamCMD (`ghcr.io/ptero-eggs/steamcmd:debian`, app 90 `-beta steam_legacy`, mod `cstrike`), then the ReHLDS engine from the newest GitHub release (non-fatal when it fails) | `hlds_run` in `ghcr.io/pterodactyl/games:source` |
| `cs2` | SteamCMD (app 730), refused when under 40 GB free | `cs2 -dedicated` in `ghcr.io/ptero-eggs/steamcmd:sniper` (Steam Runtime); `-ip/-port/-tv_port`; settings in `springs.env` |
| `minecraft` | Newest **stable** Paper build from PaperMC's downloads API (`fill.papermc.io/v3`), validated as a real jar, `eula=true` written | `java -Xms512M -Xmx<ram>M -jar server.jar nogui` in `ghcr.io/ptero-eggs/yolks:java_25` with `--entrypoint=` |

Notes:

- **Steam games** (`cs2`, `gmod`, `tf2`, `l4d2`, `rust`, `valheim`, `zomboid`, `7dtd`, `palworld`) are installed
  with SteamCMD inside `ghcr.io/ptero-eggs/installers:debian` (the same approach as the Pterodactyl eggs)
  and run from a Pterodactyl runtime image (`games:source`, `games:rust`, `games:valheim` or
  `steamcmd:debian`) as root, with `HOME=/data` so saves and `.steam/sdk*` live in the server folder.
  Each container is labelled `springs.game=<slug>` so the agent knows how to stop it.
- Per-server settings for these games live in `<install_path>/springs.env` (created on install).
  The agent parses it itself (it is never sourced by a shell) and shell-quotes every value.
- **Minecraft 26.1 and newer needs Java 25.** The container image provides it;
  the node's own Java is not used.
- The Java image's built-in entrypoint ignores a custom command, so the agent
  clears it with `--entrypoint=`.
- The Paper download runs in the background. Progress and errors go to
  `<install_path>/install.log` (`Ready!` on success, `FAILED: ...` otherwise).

---

## Agent API reference

All requests need the header `Authorization: Bearer <token>`. Bodies are JSON.
A wrong or missing token returns `401 {"error":"Unauthorized"}`.

### Node

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/api/stats` | none | `{"status":"ok","stats":{"cpu","ram_used","ram_total","disk_used","disk_total","net_rx_kbps","net_tx_kbps"}}` (RAM in MB, disk in GB) |
| POST | `/api/ping` | `{"ip"}` (public IPs only) | `{"ms": <int or null>, "method": "icmp" \| "tcp" \| null}`. `ms` is the lowest of 4 ICMP probes; when ICMP is blocked a TCP handshake to port 443/80 is timed instead (a reset counts as an answer). `null` = the visitor cannot be reached |

### Servers

| Endpoint | Body | Response |
|----------|------|----------|
| `POST /api/servers/install` | `game`, `install_path`, `screen_name` | `{"status":"installing"}` (runs in the background) |
| `POST /api/servers/start` | `game`, `install_path`, `screen_name`, `ip_address`, `port`, `max_players`, `start_map_or_world`, `ram_mb`, optional `server_name` | `{"status":"started","output":"<container id>"}`; `409` when the game files are not installed yet, `400` for invalid values (the reason is also appended to `install.log`) |
| `POST /api/servers/stop` | `screen_name` | `{"status":"stopped"}`. Graceful per game: console `quit`/`stop` (Minecraft, Rust, Source games, Zomboid), SIGINT (Valheim), SIGTERM (7 Days to Die, Palworld), then a forced stop after the grace period (10-25 s) |
| `POST /api/servers/status` | `screen_name` | `{"running": true\|false}` |
| `POST /api/servers/console` | `screen_name`, `install_path`, `lines` (max 2,000) | `{"log":"..."}` (`docker logs`, falls back to `install.log`) |
| `POST /api/servers/logs/stream` | `screen_name`, `install_path`, `tail` (default 200) | live stream, see below |
| `POST /api/servers/diagnose` | `screen_name`, `install_path`, `game` | `{"ok":true,"findings":[{"level":"ok\|warn\|error\|info","text":"..."}]}` (CS 1.6: Metamod / AMX Mod X checks incl. `liblist.gam` format and library dependencies via `ldd` in the game image; other games: an info line) |
| `POST /api/servers/rehlds` | `screen_name`, `install_path`, `game` (cs16 only), `action` = `status` (default), `install` or `reinstall` (same job but deletes the whole server folder first) | `{"ok":true,"installed":bool,"version":"3.x.y.z","replaced":bool,"job":{"state":"running\|done\|error","log":[...],"error":""}\|null}`; `install` starts a background job: SteamCMD app 90 `-beta steam_legacy validate` (ReHLDS needs the pre-anniversary build), then downloads the newest `rehlds-bin-*.zip` (GitHub API, size/time capped, public addresses only), validates it (`bin/linux32/engine_i486.so`, 32-bit ELF, safe paths) and moves each `bin/linux32` file into the server root atomically. Refused while the container runs. Marker file `.springs-rehlds` (version + engine sha256), original engine kept once as `engine_i486.so.springs-orig` |
| `POST /api/servers/query` | `game`, `screen_name`, `ip_address`, `port`, optional `resources` | `{ok, supported, running, online, name, map, players, max, bots, player_list:[{name,score,time}], version (Minecraft), cpu, mem_mb}`. `supported:false` for games without a query protocol (anything but cs16, cs2, gmod, tf2, l4d2, rust, valheim, 7dtd, minecraft). Queries the game on the node itself (A2S_INFO/A2S_PLAYER with challenge and split-packet handling, GoldSource and Source; Minecraft Server List Ping). Rust, Valheim and 7DTD are queried on game port + 1. |
| `POST /api/servers/query_many` | `servers:[{key, game, screen_name, ip_address, port}]` (max 300), `resources` | `{ok, results:{key: <same object>}}`, queried in parallel; used by `stats/collect.php`. |
| `POST /api/servers/repair` | `screen_name`, `install_path`, `game` (cs16 only) | `{"ok":true,"using":"metamod\|stock","value":"...","changes":[...],"reasons":[...]}`: rewrites `cstrike/liblist.gam` (LF, ASCII, one `gamedll_linux` line, backup once as `liblist.gam.springs-backup`) |
| `POST /api/servers/command` | `screen_name`, `command`, optional `game` | `{"ok":true,"via":"stdin"\|"rcon","reply":"...","status":"sent"}` or HTTP 400 `{"ok":false,"error":"..."}` |
| `POST /api/servers/wipe` | `install_path` | `{"status":"wiped"}` (deletes the folder) |

### File manager

Every call takes `install_path` (the server's root) and `rel_path` (relative to
it). Responses are `{"ok":true, ...}` or `{"ok":false,"error":"..."}`.

| Endpoint | Extra body | Response |
|----------|------------|----------|
| `POST /api/files/list` | none | `{"ok":true,"items":[{"name","is_dir","size","mtime"}]}` (folders first) |
| `POST /api/files/read` | none | `{"ok":true,"content":"..."}` |
| `POST /api/files/write` | `content`, optional `create_only` | `{"ok":true}` (`create_only` refuses to overwrite) |
| `POST /api/files/mkdir` | none | `{"ok":true}` |
| `POST /api/files/upload` | `content_b64` | `{"ok":true}` |
| `POST /api/files/extract` | none (`rel_path` is a `.zip`) | `{"ok":true}` (extracts next to the zip) |
| `POST /api/files/delete` | none | `{"ok":true}` |

**Limits:** editor reads up to 2 MB (binary files refused), writes up to 8 MB,
uploads up to 200 MB, unzips up to 2 GB / 50,000 files, listings up to 5,000
entries, request bodies up to 300 MB.

### Console

**`/api/servers/command`** delivers one line (line breaks are folded to spaces, 1,000 characters at most) and
reports what happened:

| Game | Delivery |
|------|----------|
| `rust` | WebRCON on `127.0.0.1:<rcon.port>`; port and password are read from the container's own start command. Replies are returned in `reply`. |
| `cs16` | HLDS runs inside a pseudo-terminal (`script -qfc 'stty -echo; exec ./hlds_run ...'`, plain `exec` when `script` is missing), so commands are typed into the real console and every plugin's output goes to the log. Servers started by an older agent (PID 1 is not `script`) are reached through GoldSrc RCON over UDP (`+rcon_password`, random at start) and the answer carries a `note` when RCON returns no text |
| `valheim`, `palworld` | refused with an explanation (no console input exists) |
| everything else | stdin of the game process: `docker exec -i` pipes the text into `/proc/1/fd/0`, after checking that stdin is not `/dev/null`. The text never goes through a shell. |

Errors: server offline, stdin closed ("restart it"), RCON not configured / refused, command empty or too long.
Containers are started with `docker run -i`; CS 1.6 and CS2 also with `-console`, and CS 1.6 carries the label `springs.game=cs16` (its stop sends `quit` through the console first).

**`/api/servers/logs/stream`** answers with `application/x-ndjson` (one JSON object per line, connection kept
open): `{"t":"snapshot","log":"...","running":bool}` first (the last `tail` lines, or the install log / last log
when the server is not running; then the stream ends), then `{"t":"lines","text":"..."}` for new output (ANSI
colour codes removed; a prompt without a newline is delivered after 0.35 s), `{"t":"ping"}` every 10 s, and
`{"t":"status","running":false}` when the container stops. The agent stops following as soon as the client
closes the connection and after 15 minutes at the latest. The panel (`server.php`) turns these events into
Server-Sent Events and polls the old endpoints when a node's agent answers 404.

### Mods and plugins

The panel (never a client) tells the node what to download. Both calls take `install_path`.

| Endpoint | Body | Response |
|----------|------|----------|
| `POST /api/mods/install` | `url`, `kind` (`file` or `archive`), `target` (folder inside the server, `""` = root), `filename` (for `file`), optional `sha256`, optional `max_mb` (default and maximum 250) | `{"ok":true,"size","sha256","files":[...],"file_count"}` |
| `POST /api/mods/remove` | `paths` (relative file paths, at most 5,000) | `{"ok":true,"removed":N}` |

How it behaves:

- **Download:** `http`/`https` only. Every hop, redirects included, must resolve to a **public** address
  (loopback, private, link-local and the cloud metadata address are refused) and the node connects to the
  address it just checked. 45 second limit, 250 MB limit, no content encodings.
- **Integrity:** with `sha256` the download is compared before anything is written; a mismatch writes nothing.
- **`file`:** saved as `target/filename` (name limited to letters, digits, `. _ -` and spaces), mode 0644.
- **`archive`:** zip or tar(.gz/.bz2/.xz). The whole archive is checked first: absolute paths, `..`, symbolic
  or hard links and device files refuse the whole install; at most 20,000 files and 1.5 GB unpacked. Executable
  bits are kept. Nothing outside the server folder can be written.
- **Folders** are created only after everything checked out, so a refused install leaves nothing behind.
- **`remove`:** deletes only the listed files (absolute or `..` entries are ignored), then removes folders the
  mod left empty. It never removes the server root.
- Calls for the same server are serialised. Errors come back as HTTP 400 `{"ok":false,"error":"..."}`.

---

## Security notes

- **The token is the only credential.** Anyone who has it can control every
  server on the node. Treat it like a root password, keep it out of chat logs,
  screenshots and tickets, and rotate it by updating **both** `SPRINGS_TOKEN` on
  the node and `boxes.ssh_password` in the panel.
- **Traffic is plain HTTP.** Restrict the API port to the panel server's IP
  (firewall or a private network). The installer opens the port to everyone
  (`iptables -I INPUT ... ACCEPT` / `ufw allow`); tighten that rule after
  installing, e.g. `ufw allow from <PANEL_IP> to any port 8085 proto tcp`.
- **The install command contains the token in a URL**, so it can appear in web
  server logs and shell history of the node.
- **Input validation:** `screen_name` must match `^[A-Za-z0-9_.-]{1,80}$`;
  `install_path` must be absolute, use only `A-Za-z0-9_./-` and contain no `..`;
  CS `ip_address` must be a valid IP and the map name `^[A-Za-z0-9_.-]{1,64}$`.
- **File API confinement:** every path is resolved with `realpath` and must stay
  inside the server's folder, so `../` traversal and symlink escapes are
  refused. Zips are checked before extraction (zip-slip), and system folders
  (`/`, `/etc`, `/home`, `/var`, `/var/lib/springs/volumes`, ...) cannot be used
  as a server root.
- **Known limitation:** `/api/servers/wipe` deletes any validated path that is
  not on a short blocklist. Restricting it to `/var/lib/springs/volumes` is
  recommended.

---

## Updating a node

Re-run the install command with the node's **existing** token:

```bash
curl -fsSL "https://YOUR-PANEL/springs_install.php?port=8085&token=NODE_TOKEN" | bash
```

Get the token from the panel database:

```sql
SELECT id, name, ip_address, ssh_port, ssh_password FROM boxes;
```

Only the agent restarts; game containers keep running.

---

## Troubleshooting

**Service state and logs (on the node)**

```bash
systemctl status springs --no-pager
journalctl -u springs -n 50 --no-pager
ss -tlnp | grep 8085
```

**Test the API (from the panel server)**

```bash
curl -i -m 8 -H "Authorization: Bearer $TOKEN" http://NODE_IP:8085/api/stats
```

| Symptom | Likely cause / fix |
|---------|--------------------|
| `401 Unauthorized` | Token on the node differs from `boxes.ssh_password`. Compare `systemctl cat springs \| grep SPRINGS_` with the database, or re-run the installer with the database token. |
| Connection refused / timeout | Service not running, wrong port, or firewall blocking the panel's IP. |
| Nodes page shows no telemetry | `node_stats_ajax.php` is not returning JSON. Open it directly while logged in as admin; any PHP error or stray output before the `{` breaks the page silently. |
| Steam game install ends with `FAILED: <file> is missing` | SteamCMD did not download the server (no internet, no disk space, or Valve requires a login for that app - seen with Left 4 Dead 2). Read the SteamCMD output above that line in `install.log` |
| Minecraft will not start: "requires Java 25" | The container is using an older Java image. Make sure the agent uses `ghcr.io/ptero-eggs/yolks:java_25` with `--entrypoint=`. |
| Minecraft container exits immediately after printing the Java version | The image's entrypoint is swallowing the command (missing `--entrypoint=`). |
| `server.jar` is tiny / "not a valid jar" | The download returned a web page. Check `<install_path>/install.log`; the current agent validates the file and logs `FAILED: ...`. |
| File manager shows "Server folder does not exist yet" | The install has not finished creating the folder. Check `install.log`. |
| `400 Invalid screen_name` / `Invalid install_path` | A value contains characters outside the allowed set (see Security notes). |

**Container inspection**

```bash
docker ps -a --format '{{.Names}}\t{{.Status}}'
docker logs springs_<screen_name> --tail 50
```

---

## Uninstall

```bash
systemctl disable --now springs
rm -f /etc/systemd/system/springs.service
systemctl daemon-reload
rm -rf /opt/springs
# Game data is kept in /var/lib/springs/volumes; remove it only if you no longer need it.
# Containers: docker ps -a --filter name=springs_ ; docker rm -f <name>
```

## Backup endpoints (POST, Bearer token; JSON body with `screen_name`, `install_path`)

- `/api/backups/list` -> `{ok, items:[{id,kind,size,ts,running}], total_size, disk_free, job}`
- `/api/backups/create` (`kind` manual|auto, `max_count`) -> starts an async job; refuses at the limit or when disk space is below 1 GiB
- `/api/backups/delete` (`id`), `/api/backups/restore` (`id`; container must be stopped; archive is verified first), `/api/backups/purge` (all of a server)
- GET `/api/backups/download?screen_name=&id=` streams the archive
Files: `bk_<UTC YYYYmmdd-HHMMSS>_<manual|auto>.tar.gz` in `/var/lib/springs/backups/<screen_name>/` (override with env SPRINGS_BACKUP_DIR).
