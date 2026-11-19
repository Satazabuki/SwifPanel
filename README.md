# SwiftPanel – Game Server Hosting Panel (Springs Docker Edition)

SwiftPanel is a web control panel for selling and running game servers.
Clients order a server, an admin approves it onto a node, and the server is
installed and run in a Docker container on that node. Everything is managed from
the browser: ordering, power controls, console, file manager, support tickets
and node monitoring.

Supported games: **Counter-Strike 1.6 / 2**, **Minecraft** (Paper), **Garry's Mod**, **Team Fortress 2**,
**Left 4 Dead 2**, **Rust**, **Valheim**, **Project Zomboid**, **7 Days to Die** and **Palworld**.

---

## Contents

- [Features](#features)
- [Architecture](#architecture)
- [Project structure](#project-structure)
- [Requirements](#requirements)
- [Installing the panel](#installing-the-panel)
- [Adding a node](#adding-a-node)
- [Games](#games)
- [Payments (PayPal)](#payments-paypal)
- [Invoices, renewals and reminders](#invoices-renewals-and-reminders)
- [Mods and plugins](#mods-and-plugins)
- [Live console](#live-console)
- [Database](#database)
- [How it works](#how-it-works)
- [Admin area](#admin-area)
- [Security notes and known issues](#security-notes-and-known-issues)
- [Backups and deployment](#backups-and-deployment)
- [Troubleshooting](#troubleshooting)

---

## Features

**Client portal**

- Order a server from the **Order Server** tab in the top menu (`dashboard.php?page=order`): pick a game card,
  then name, slots (options adapt to the game) and location; get an email confirmation.
- Pay for an order with PayPal (billing period 1/3/6/12 months with optional discounts); unpaid orders show
  a **Pay now** button.
- Cancel pending orders; track order and server status.
- Live status badge per server (Checking / Installing / Online / Offline).
- Start, stop and restart from the dashboard.
- Console page with live log and command box (`server.php`).
- File manager: browse, edit, create, upload, extract `.zip`, delete (`files.php`).
- Support tickets and profile page.

**Admin area (`/admin`)**

- Add and monitor nodes with live CPU, RAM, disk, network and online status.
- Approve orders onto a chosen node (ports and install paths are allocated
  automatically).
- Create servers manually, manage all client servers, users, tickets.
- SMTP configuration with a test-email button.
- Payments page: PayPal settings, per-game prices, transactions, refunds, offline payments.

**Nodes (Springs)**

- One-line installer that sets up Docker and the Springs agent.
- One container per game server, with memory limits.
- Minecraft: newest stable Paper build, Java 25 container.
- Steam games (GMod, TF2, L4D2, Rust, Valheim, Zomboid, 7 Days to Die, Palworld) install with SteamCMD.

---

## Architecture

```
┌──────────────┐        ┌────────────────────────────┐   HTTP + Bearer token   ┌───────────────────────┐
│ Browser      │──────► │ SwiftPanel (PHP + MySQL)   │ ──────────────────────► │ Node: Springs agent   │
│ client/admin │  HTTPS │ dashboard.php, files.php,  │   (default port 8085)   │ Python daemon         │
└──────────────┘        │ admin/*, ServerManager.php │                         │  └─ Docker containers │
                        └────────────────────────────┘                         │     (one per server)  │
                                                                               └───────────────────────┘
```

The panel never logs in to nodes over SSH. It calls the agent's REST API, using
the node's IP, API port and token stored in the `boxes` table. Full API
reference: [`docs/SPRINGS.md`](docs/SPRINGS.md).

---

## Project structure

```
/                           web root (panel)
├── auth.php                session start / login check for client pages
├── config.php              database settings, creates $pdo (written by the installer)
├── install/                web installer (install.php); delete this folder after installing
├── mailer.php              SMTP mail helpers (sendSmtpMail, wrapPanelMail)
├── port_helper.php         allocateFreePort() for new servers
├── games.php               game catalog (slots, RAM, maps, ports) + port allocator for multi-port games
├── ServerManager.php       PHP client for the Springs agent API
├── index.php               public home page: game cards, guest order form (creates the account) and client login
├── dashboard.php           client dashboard: orders, servers, power actions
├── server.php              client console / server management page
├── files.php               client file manager
├── tickets.php             client support tickets
├── profile.php             client profile
├── node_stats_ajax.php     JSON telemetry endpoint used by admin/nodes.php
├── ping_nodes.php          latency from each node location to the visitor (order forms)
├── payments/               PayPal payments
│   ├── payments_lib.php    PayPal client, prices, payment rules (shared by the payment pages)
│   ├── paypal_checkout.php creates the PayPal order and redirects the client to PayPal
│   ├── paypal_return.php   captures the payment after the client approves it
│   ├── paypal_cancel.php   landing page when the client cancels at PayPal
│   ├── paypal_webhook.php  verified PayPal webhook (payments, refunds)
│   ├── invoices_lib.php    invoices, renewals, expiry alerts, reminder schedule
│   └── cron_reminders.php  daily job: renewal invoices + reminder e-mails
├── mods/                   mods & plugins
│   ├── mods_lib.php        catalog, validation, signed links, install / remove
│   ├── index.php           client page: browse and install on a server
│   ├── admin.php           admin page: manage the catalog (add, upload, hide, delete)
│   ├── download.php        serves uploaded files to nodes (signed, time-limited links)
│   └── files/              uploaded mod files (random names, direct access blocked)
├── springs_install.php     node installer + agent source (served as a script)
├── assets/style.css        shared stylesheet
├── vendor/                 phpseclib, paragonie (legacy from the SSH era)
├── .well-known/            Let's Encrypt (ACME) validation
├── docs/SPRINGS.md         agent / API reference
└── admin/
    ├── index.php           redirects to nodes.php or login.php
    ├── login.php           admin login (?logout=1 to log out)
    ├── guard.php           admin-only session check
    ├── nodes.php           add / monitor / delete nodes
    ├── orders.php          approve or cancel orders
    ├── client_servers.php  client servers (?all=1 for everything)
    ├── servers.php         all servers
    ├── create_server.php   create a server manually
    ├── manage_server.php   per-server power, console, commands
    ├── users.php           users and own password
    ├── tickets.php         support tickets
    ├── smtp.php            SMTP settings + test email
    └── payments.php        PayPal settings, game prices, transactions, refunds
```

Client login/registration pages also live in the web root.

---

## Requirements

**Panel server**

- PHP 7.4+ (8.x recommended) with `pdo_mysql`, `curl`, `json`, `mbstring`.
- MySQL or MariaDB (the code uses `FIELD()`).
- Apache or Nginx with HTTPS.
- Outbound access from PHP to every node's API port.

**Nodes**

- Ubuntu/Debian with systemd, root access, `python3`, `curl`.
- Public IP for game ports; API port reachable from the panel server.
- Details: [`docs/SPRINGS.md`](docs/SPRINGS.md).

---

## Installing the panel

### Option A: web installer (recommended)

1. **Upload** the complete project to your web root (including the `install/` folder).
2. **Create an empty database and a user** for it or use a MySQL user that may create databases.
3. Open **`https://YOUR-PANEL/install/install.php`**. The installer:
   - checks PHP (7.4+), the extensions (`pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`, optional `zip`), folder permissions and that the panel files are present;
   - lets you enter the database host, name, user and password and **test the connection** (optionally creates the database);
   - asks for the first **administrator** (user name, e-mail, password of at least 10 characters);
   - creates the core tables, the admin account and `config.php` (permissions 640). If the folder is not writable it shows the
     `config.php` text so you can save it by hand.
4. Press **Delete the install folder** on the last page (or delete `install/` yourself). The installer is also locked by `install/install.lock`.
5. Log in at **`/admin/`**, open **SMTP / Email** and send a test e-mail, then **add your first node** (next section).
6. Add the cron jobs and nginx rules listed on the installer's last page (see also DEPLOY.txt).

The installer never overwrites anything: it will not run when `config.php` exists or when the database already contains users.
Existing installations do not need it.

### Option B: manual

1. **Upload** the project to your web root.
2. **Create a database and user**, then create `config.php` with the constants `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` and a `$pdo`
   connection (UTF-8, exceptions on). The installer writes exactly such a file; copy it from there if in doubt.
3. **Create the tables** (see [Database](#database)); the installer's `schemaStatements()` function in `install/install.php` holds the
   current definitions of the core tables.
4. **Create the first admin.** Generate a hash and insert the user (adjust column
   names to your schema):

   ```bash
   php -r 'echo password_hash("CHOOSE_A_STRONG_PASSWORD", PASSWORD_DEFAULT), "\n";'
   ```

   ```sql
   INSERT INTO users (username, password_hash, email, role, email_verified)
   VALUES ('admin', '<hash from above>', 'you@example.com', 'admin', 1);
   ```

5. **Open `/admin/`**, log in, then go to **SMTP / Email** and configure mail
   (use *Send Test Email* to verify).
6. **Add your first node** (next section).

> If the `users` table is empty, `create_server.php` creates a default admin
> account with the password `admin123`. Never leave that in place: create your
> own admin first, or change the password immediately.

---

## Adding a node

1. In **Admin → Remote Nodes → Connect New Springs Node**, enter the node's name,
   IP, API port (default `8085`) and token. The page shows an install command.
2. Run that command as `root` on the node:

   ```bash
   curl -fsSL "https://YOUR-PANEL/springs_install.php?port=8085&token=YOUR_TOKEN" | bash
   ```

3. Within a few seconds the Nodes page shows the node **Online** with its CPU,
   RAM, disk and network figures.

The token must match `boxes.ssh_password` exactly. The installer installs Docker,
writes the agent, pulls the game images and starts the `springs` service. To
update a node later, run the same command again; running servers are not
stopped. See [`docs/SPRINGS.md`](docs/SPRINGS.md).

---

## Games

Eleven games are supported. Each server runs in its own Docker container with a memory limit,
on the node's host network.

| Game | Slug | Default RAM | Ports used (game port = **P**) | Notes |
|------|------|-------------|--------------------------------|-------|
| Counter-Strike 1.6 (ReHLDS engine) | `cs16` | 2 GB | P (UDP/TCP) | |
| Counter-Strike 2 | `cs2` | 4 GB | P (UDP/TCP), SourceTV `40000 + (P mod 10000)` | Steam app 730; about 35-60 GB of disk **per server**; runs in the Steam Runtime image |
| Minecraft (Paper) | `minecraft` | 2 GB | P (TCP) | Java 25 container, newest stable Paper |
| Garry's Mod | `gmod` | 2 GB | P (UDP/TCP) | Steam app 4020 |
| Team Fortress 2 | `tf2` | 2 GB | P (UDP/TCP) | Steam app 232250 |
| Left 4 Dead 2 | `l4d2` | 2 GB | P (UDP/TCP) | Steam app 222860, see the note below |
| Rust | `rust` | 8 GB | P game (UDP), P+1 query (UDP), P+2 RCON (TCP), P+3 Rust+ app (TCP) | Steam app 258550 |
| Valheim | `valheim` | 4 GB | P, P+1, P+2 (UDP) | Steam app 896660, fixed 10 players |
| Project Zomboid | `zomboid` | 6 GB | P, P+1 (UDP), P+2, P+3 (UDP, Steam) | Steam app 380870 |
| 7 Days to Die | `7dtd` | 8 GB | P (TCP/UDP), P+1, P+2 (UDP) | Steam app 294420; telnet and web dashboard are disabled |
| Palworld | `palworld` | 16 GB | P (UDP) | Steam app 2394010; memory use grows over time, schedule restarts |

The Source games (`gmod`, `tf2`, `l4d2`) take their port from the node's **Assigned Game Ports**
like the CS games. The other new games allocate from their own ranges so that multi-port blocks never
collide with other servers:

| Game | Port range reserved by the panel | Open on the node firewall |
|------|----------------------------------|---------------------------|
| `rust` | 28015-28508 | UDP + TCP |
| `valheim` | 2456-2748 | UDP |
| `zomboid` | 16261-16554 | UDP |
| `7dtd` | 26900-26993 | TCP + UDP |
| `palworld` | 8211-8501 | UDP |

Example: `ufw allow 28015:28508/udp && ufw allow 28015:28508/tcp`. Source games also use
`30000 + (P mod 10000)` (client port) and `40000 + (P mod 10000)` (SourceTV) internally.

### Per-server settings (`springs.env`)

Installing one of the newer games creates `springs.env` in the server's root folder. Clients and
admins edit it in the file manager (quick-config button **springs.env (settings)**) and restart the
server. Passwords are generated randomly per server.

| Setting | Games | Meaning |
|---------|-------|---------|
| `SERVER_NAME` | all | Overrides the name from the panel |
| `GSLT` | cs2, gmod, tf2, l4d2 | Steam Game Server Login Token, needed for the public server list |
| `GAME_MODE`, `GAME_TYPE`, `VAC_ENABLED`, `RCON_ENABLED`, `RCON_PASSWORD`, `SERVER_PASSWORD` | cs2 | Mode/type (competitive 1/0, casual 0/0, wingman 2/0, deathmatch 2/1, arms race 0/1), VAC, external RCON |
| `GAMEMODE`, `TICKRATE`, `WORKSHOP_COLLECTION` | gmod | Gamemode, tickrate, workshop collection |
| `RCON_PASSWORD`, `WORLD_SIZE`, `WORLD_SEED` | rust | RCON listens on P+2 |
| `SERVER_PASSWORD`, `WORLD_NAME`, `PUBLIC`, `CROSSPLAY` | valheim | Password must have at least 5 characters |
| `ADMIN_PASSWORD`, `SERVER_PASSWORD` | zomboid, palworld | Admin and join passwords |
| `SERVER_PASSWORD`, `GAME_NAME` | 7dtd | Join password, save name |
| `EXTRA_ARGS` | all | Extra launch arguments (advanced) |

Values are never run through a shell, so special characters are safe.

### Notes and caveats

- **Left 4 Dead 2:** Valve may require a Steam account to download the server files. If the install
  log ends with `FAILED: srcds_run is missing`, the anonymous download was refused.
- **Counter-Strike 2** needs about 35-60 GB of free disk space per server. The agent refuses a new CS2
  install with a clear message when less than 40 GB is free. Servers created by older versions of the
  panel never downloaded the game files: open the server in the admin area and press **Reinstall files**.
- **Reinstall files** (admin, server page) stops the server and runs the install again for any game. Files
  you added (maps, plugins, saves) are kept; edited stock game files may be reset.
- **First install** of a game on a node pulls Docker images and downloads several GB; it can take a
  long time. Progress is in `install.log` (shown in the console).
- **Graceful stop:** Rust, TF2, GMod, L4D2, Zomboid and Minecraft get a console `quit`/`stop`;
  Valheim gets SIGINT; 7 Days to Die and Palworld get SIGTERM, each with a grace period before a
  forced stop. Restarting waits for a slow stop to finish before starting again.
- **Project Zomboid** settings are written to `Zomboid/Server/servertest.ini` on each start
  (public name, slots, ports); other keys are preserved.
- These games were added from the Pterodactyl egg definitions and tested with Docker mocked; do a
  first real install of each game you sell and check its `install.log` and console.

### Adding another game

1. Add an entry to `swiftGames()` in `games.php` (labels, slots, RAM, map, ports, binary, and the icon text,
   tagline and colors for the home page card). The home page, client dashboard and admin forms pick it up
   automatically.
2. Add a matching entry to `GAMES` in `springs_install.php` (Steam app ID, image, binary, stop mode)
   and a launch branch in `build_game_command()`.
3. Re-run the installer on every node.

## Payments (PayPal)

Clients pay for new orders with PayPal. Payments are optional: until you switch them on, and for any
game whose price is 0, orders work exactly as before (the admin approves them for free).

**Set up (Admin → Payments)**

1. At `developer.paypal.com` create a REST app (**Sandbox** first) and paste its *Client ID* and *Secret*.
2. Add a webhook to that app with the URL shown on the Payments page (`https://YOUR-PANEL/payments/paypal_webhook.php`),
   subscribe it to `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED` and
   `PAYMENT.CAPTURE.REVERSED`, and paste the *Webhook ID*.
3. Pick the currency, tick **enable**, press **Test PayPal Connection**.
4. Enter the **price per slot per month** for each game, and optional discounts for 3, 6 and 12 months.
5. Place a test order with a PayPal sandbox buyer account. Switch the mode to **Live** only afterwards.

**How an order is paid**

1. The client chooses a game, slots and a billing period (1, 3, 6 or 12 months). The page shows the total
   (price x slots x months, minus the period discount). The server recomputes the price itself; nothing is
   trusted from the browser.
2. On submit the order is saved as *Unpaid* and the client is sent to PayPal (`paypal_checkout.php`).
3. After paying, PayPal returns the client to `paypal_return.php`, which captures the payment, checks that
   the amount and currency match, marks the order *Paid* and e-mails a receipt. A cancelled payment leaves
   the order *Unpaid*; the client can pay later with **Pay now** in the Client Area.
4. `paypal_webhook.php` is the safety net (a closed browser tab, delayed payments, refunds made in PayPal).
   Every webhook is verified with PayPal and processed once.
5. The admin can only **approve** a paid order (or a free one). The Payments page lists all transactions,
   can **refund** a PayPal payment, and can mark an order paid for an offline payment (bank transfer).

Guests ordering on the home page are sent to PayPal right after their account is created; the order link
is protected by a secret token, so no login is needed to pay.

**Limits to know**

- A payment covers the chosen period; renewing is a new invoice that the client pays (nothing is charged
  automatically). Expired servers are **not stopped or suspended automatically**: the clients and you are
  reminded, and you decide what to do with them.
- Refunding a payment does not stop or delete a server that was already created.
- A partial refund made inside PayPal marks the whole payment as refunded in the panel.
- The PayPal secret is stored in plaintext in the `payment_settings` table, like the SMTP password.
- PayPal only accepts its supported currencies; the list on the page is limited to those.

## Invoices, renewals and reminders

Every order creates an **invoice** (`INV-000123`), and every PayPal payment pays an invoice. Clients see all
of them in the **Invoices** tab of the Client Area (menu next to *Order Server*): unpaid, overdue and paid,
with a **Pay now** button on every open one.

**Server expiry and renewals**

- A server created from a paid order expires after the period that was paid, counted from the day the admin
  approves the order. Free servers (price 0) never expire.
- *N* days before a server expires (default 7, Admin → Payments) a **renewal invoice** is created
  automatically. The Servers list shows the expiry date and a **Renew** button; the Invoices tab lets the
  client pick another period (1, 3, 6 or 12 months, with the discounts) and pay.
- Paying a renewal extends the server from its current expiry date (or from today when it has already
  expired). Paying twice (return page and webhook) never extends twice.
- On the admin **server page** you can set or remove the expiry date and the renewal period by hand (this is
  how you give existing servers an expiry), and create a renewal invoice.

**Reminders**

- *On screen, always live:* a banner on every Client Area page for invoices that are due soon or overdue and
  servers that expire soon or have expired, plus a red counter on the Invoices tab.
- *By e-mail, sent by a daily job:* a server that expires in 7, 3 and 1 days (days are configurable), and
  after it expired (day 0, +3, +7); an unpaid order invoice 7/3/1 days before it is due, on the due date and
  1, 4 and 8 days after. The server e-mails include the renewal invoice and a **Pay now** link. Every
  reminder is sent once; if the mail server is down it is retried on the next run.

**Set up the daily job** (Admin → Payments shows the exact command and URL with your key):

```bash
# command line, once a day (aaPanel: Cron -> Shell script)
php /www/wwwroot/gp.marinkovic.biz/payments/cron_reminders.php

# or by URL (aaPanel: Cron -> Visit URL)
https://YOUR-PANEL/payments/cron_reminders.php?key=CRON_KEY
```

Also fill in the **Site URL** on the Payments page; without it the e-mails cannot contain a Pay now link.
Orders created before invoices existed get an invoice automatically (the old pay links keep working).

## Mods and plugins

Clients can install mods and plugins on their own servers with one click, from a catalog **you** curate.
Everything new lives in the `/mods/` folder.

**For you (Admin → Mods)**

1. Open **Admin → Mods** and add items: choose the game, a name and description, then where the file comes
   from: a **download link** (the node fetches it directly) or an **upload** (kept in `/mods/files/`, max
   100 MB, handed to the node through a signed link that works for 15 minutes).
2. Say what it is: a **single file** (saved under the name you give, e.g. `EssentialsX.jar`) or an **archive**
   (`.zip`, `.tar.gz` ... unpacked). Pick the **folder inside the server** it goes to; the form suggests the
   usual folders per game (for example `plugins` for Minecraft, `cstrike/addons/amxmodx/plugins` for CS 1.6,
   `oxide/plugins` for Rust, `BepInEx/plugins` for Valheim).
3. Optionally add a SHA-256 checksum (the node verifies it; uploads get one automatically) and a note that is
   shown after installing ("add the plugin to plugins.ini").
4. **Hide** an item to take it out of the catalog without deleting it. Servers that already have an item keep
   it and can still remove it, even after you deleted it from the catalog.
5. If you use uploads, fill in the **panel address** the nodes can reach (Admin → Mods → Download settings)
   and block direct access to the uploads folder (below).

**For clients (Mods & Plugins in the menu, or the Mods button on a server)**

Pick a server, search or filter by category, press **Install**. The node downloads the file into the server
folder. **Update** reinstalls over the old files, **Remove** deletes exactly the files the item added (not the
client's own files). The client then restarts the server to load it. Everything is visible in the File Manager.

**What is protected**

- Clients never type a URL or a path: they can only install items from your catalog on **their own** servers,
  of the **same game**. Server still installing, hidden items and other games' items are refused.
- The node refuses anything but public `http(s)` addresses (no internal network or cloud metadata address,
  also not through redirects), caps size and time, verifies the checksum, and checks an archive completely
  (no `..`, absolute paths or links) before it writes a single file.
- Uploaded files get random names and are only served through `download.php` with a signed link that expires
  (it cannot be forged, extended or reused for another item).
- At most 12 installs per minute per client.

**Block direct access to `/mods/files/`.** Apache does it with the included `.htaccess`. On nginx (aaPanel) add
this to the site's configuration (Website → Configuration file):

```nginx
location ^~ /mods/files/ { deny all; }
```

The nodes download through `/mods/download.php`, which is not affected. Also check that PHP allows uploads of
that size (`upload_max_filesize` and `post_max_size`; the admin page shows the current values).

**Limits to know**

- Installing is synchronous: the page waits (up to about a minute) while the node downloads; the limit is
  250 MB per item. Very large mods can simply be added as a download link and installed from the File Manager.
- Updating overwrites files; files an older version had and the new one does not are left behind.
- An archive that overwrites an existing file (for example a config) replaces it, and removing the item does
  not restore the old version.
- Items with more than 5,000 files cannot be removed automatically (the client is told to use the File Manager).
- The catalog starts **empty**: you decide what clients may install.

## Live console

The console on the server page (`server.php`; admins reach it through the **Live Console** button on the admin
server page) is a real-time terminal.

**How it is live.** The browser keeps one connection open. The agent on the node follows the game's log
(`docker logs -f`) and the panel forwards every new line the moment it appears (typically within a few
milliseconds), so nothing is polled and the full log is never re-sent. The first thing a viewer gets is the
last 200 lines, then only new ones. The terminal keeps the newest 3,000 lines, has Auto-Scroll and Clear,
shows LIVE / RECONNECTING / DISCONNECTED, reconnects by itself, and keeps following the server across a stop and
a start. Start, Stop and Restart work without reloading the page (and without losing the log).

**How commands reach the game.** The panel asks the node, and the node tells the truth about what happened.
Errors (server offline, node unreachable, wrong token, ...) are shown in red under the command.

| Game | Command delivery | What you see |
|------|------------------|--------------|
| Minecraft, Garry's Mod, TF2, L4D2, CS2, Project Zomboid, 7 Days to Die | the game's console input (stdin) | the answer appears in the log |
| Counter-Strike 1.6 | the real console: HLDS runs inside a pseudo-terminal (like `screen` or Pterodactyl) and commands are typed into it | everything it prints, including **Metamod / AMX Mod X** output, appears in the live log |
| Rust | WebRCON (the RCON password from `springs.env`) | the answer in green |
| Valheim, Palworld | no console exists | the panel explains this instead of pretending |

Notes:

- Commands are sent as plain text on one line; nothing is interpreted by a shell, so quotes, `$`, `;` and
  similar characters are safe. Up to 1,000 characters.
- CS 1.6 runs inside a pseudo-terminal (HLDS is a terminal program: without one its output is buffered and console
  input is unreliable), CS2 starts with `-console`, and Rust replies over WebRCON. CS 1.6 servers started by an
  earlier version have no terminal; the panel reaches them through RCON, which cannot return plugin output
  (Metamod, AMX Mod X). The console tells you when that is the case. Servers that are already running keep their
  old start parameters: **restart them once** after updating the node.
- The history (Up / Down arrows) is kept per server in the browser.
- If a **plugin command** (`meta ...`, `amx_...`, `sm ...`, Oxide/Carbon commands) prints nothing, the console says
  so after a moment: a command that does not exist prints nothing, so it usually means the plugin framework is not
  loaded. The terminal's own echo of the typed line is hidden (you already see `> command`).
- **Diagnose** (CS 1.6 servers) checks `cstrike/liblist.gam` (is Metamod the game library? is the file well-formed:
  no BOM, no Windows line endings, final line break, a single `gamedll_linux` line?), that the Metamod library exists,
  is a 32-bit Linux build **and that every library it depends on exists in the game image**, that the original
  `dlls/cs.so` is present, `addons/metamod/plugins.ini` (each listed plugin file, AMX Mod X included) and the boot log
  (start-up lines of Metamod, or a failed game-library load), then prints what to fix.
- **Update ReHLDS** (CS 1.6 servers, server stopped) first switches the game files to the **Steam legacy build** (SteamCMD `-beta steam_legacy`; ReHLDS cannot run on the current build and fails with `engine_i486.so: undefined symbol: SteamGameServer_Init` / `Unable to load engine`), then downloads the newest ReHLDS release (`rehlds-bin-<version>.zip` from
  github.com/rehlds/ReHLDS) and puts its `bin/linux32/*` files, including the engine `engine_i486.so`, into the server
  folder. A copy of the original engine is kept once as `engine_i486.so.springs-orig`. **New CS 1.6 installs get ReHLDS
  automatically**; if the download fails the install still finishes on the classic engine and says so in the install log.
  Diagnose reports which engine runs. A SteamCMD *validate* or "Reinstall files" brings the classic engine back, so
  press Update ReHLDS again (a fresh install does it by itself). ReHLDS needs a CPU with SSE3, and plugins that patch
  engine memory (for example Orpheu) do not work with it. ReGameDLL_CS, Metamod-r, ReAPI and Reunion are separate
  add-ons and are not installed by this.
- **Reinstall on the dashboard**: every server row has a red **Reinstall** button (CSRF-protected, asks for confirmation). For CS 1.6 it stops the server and runs the full reinstall below (two confirmations, everything deleted); for the other games it stops the server and downloads the game files again (added files are kept). The status switches to INSTALLING and back to OFFLINE when the node writes `[Springs] Complete!` to `install.log`.
- **Full reinstall (ReHLDS)** (CS 1.6 servers, server stopped, two confirmations) deletes EVERYTHING in the server folder and installs CS 1.6 again from scratch: Steam legacy game files, then ReHLDS. Use it when a server is in an unknown state. Configs, plugins and maps are gone afterwards.
- **Repair liblist.gam** (CS 1.6 servers) rewrites `liblist.gam` cleanly (a backup `liblist.gam.springs-backup` is
  kept once) and picks a game library that can really be loaded: Metamod when it is a valid 32-bit build whose
  dependencies are available, otherwise the stock library, so a server that no longer starts works again. It tells
  you why Metamod was not used. Restart the server afterwards.

**Requirements and tuning**

- **Update the nodes** (rerun the installer). A node with the old agent still works: the panel detects it and
  falls back to polling, but commands from older agents are not verified and Rust commands cannot work.
- Each open console holds one PHP worker (about 100 seconds, then the browser reconnects). PHP-FPM needs enough
  workers (`pm.max_children`) for the number of consoles open at the same time.
- Behind nginx the response must not be buffered. The panel sends `X-Accel-Buffering: no`, which is enough
  for the default setup. If the console updates only in bursts, also add `fastcgi_buffering off;` for this site.
- Only a single browser tab per server is needed; extra tabs each use a worker.

## Database

The web installer creates the core tables (`users`, `boxes`, `servers`, `orders`, `tickets`, `ticket_replies`, `smtp_settings`); the pages create
the rest (payments, mods, statistics, backups, legal pages, 2FA) the first time they are used. The list below is derived from the queries in
the code, for reference or a manual setup.

| Table | Columns used by the code |
|-------|--------------------------|
| `users` | `id`, `username`, `password_hash`, `email`, `role` (`admin` / `client`), `email_verified`, `verify_token`, `created_at` |
| `boxes` (nodes) | `id`, `name`, `ip_address`, `ssh_port` (**agent API port**), `ssh_user`, `ssh_password` (**agent API token**), `location`, plus the port-range and extra-IP fields managed on the Nodes page |
| `servers` | `id`, `user_id`, `box_id`, `game` (`cs16` / `cs2` / `minecraft`), `name`, `ip_address`, `port`, `max_players`, `start_map_or_world`, `ram_mb`, `install_path`, `screen_name`, `status` (`installing` / `online` / `offline`) |
| `orders` | `id`, `user_id`, `game`, `location`, `server_name`, `slots`, `ram_mb`, `status` (`Pending` / `Approved` / `Cancelled`), `server_id`, `created_at` |
| `tickets` | `id`, `user_id`, `server_id`, `subject`, `status` (`Open` / `Answered` / `Closed`), `updated_at` |
| `ticket_replies` | `id`, `ticket_id`, `user_id`, `is_admin`, `message` |
| `smtp_settings` | one row with `id = 1`: `enabled`, `host`, `port`, `encryption`, `username`, `password`, `from_email`, `from_name` |
| `payment_settings` | one row with `id = 1`: `enabled`, `mode`, `client_id`, `client_secret`, `webhook_id`, `currency`, `brand_name`, `site_url`, `discount_3/6/12` |
| `game_prices` | `game`, `price_per_slot` (per month) |
| `payments` | `order_id`, `user_id`, `provider` (`paypal`/`manual`), `paypal_order_id`, `capture_id`, `amount`, `currency`, `status` (Created, Pending, Completed, Failed, Cancelled, Refunded), `payer_email`, `note` |
| `payment_events` | processed PayPal webhook event ids (prevents double processing) |
| `invoices` | `user_id`, `order_id`, `server_id`, `type` (New/Renewal), `description`, `amount`, `currency`, `period_months`, `status` (Unpaid, Paid, Cancelled, Refunded), `due_date`, `pay_token`, `paid_at` |
| `reminder_log` | which reminder was already e-mailed (kind, ref_id, stage), so nothing is sent twice |
| `servers` (added columns) | `expires_at` (NULL = never expires), `renew_months` |
| `mods` | the catalog: `game`, `name`, `category`, `version`, `author`, `description`, `source_type` (url/upload), `source_url`, `stored_name`, `sha256`, `install_kind` (file/archive), `target_dir`, `install_name`, `post_note`, `enabled` |
| `server_mods` | what is installed on which server, with the list of files it added (used by Remove) |
| `mods_settings` | signing key for download links and the panel address nodes use |
| `orders` (added columns) | `period_months`, `amount`, `currency`, `payment_status` (Free, Unpaid, Paid, Refunded), `pay_token`, `paid_at` |

Note: on a database whose `orders` table was created by an older version of the panel (without a
`location` column), add it: `ALTER TABLE orders ADD location VARCHAR(64) NULL;`. New installs create
it automatically.

---

## How it works

### Ordering and provisioning

1. **Client orders** on the dashboard: game, location, server name, slots
   (10–64). RAM is set automatically (2048 MB, or 4096 MB for CS2). The order is
   `Pending` and a confirmation email is sent.
2. **Admin approves** in *Orders* and picks a node. The panel then:
   - allocates a free game port on that node (`allocateFreePort`),
   - sets the install path `/var/lib/springs/volumes/<user>/<game>_<port>`,
   - sets the container name `<game>_<port>_<5 random hex>`,
   - inserts the server with status `installing`,
   - calls the agent's `/api/servers/install`,
   - marks the order `Approved` and emails the client.
3. **Installation** runs on the node in the background (SteamCMD for CS, a Paper
   download for Minecraft) and writes `install.log`.
4. **The dashboard polls** the server status. When the log shows
   `[Springs] Complete!` or `[Springs] Ready!`, the server becomes `offline`
   (installed, ready to start). Once running it shows `online`.
5. **Start / stop / restart** call the agent; a restart is stop, one second
   pause, start.

### Console and files

- The console page streams the log live and sends commands through the agent
  (stdin, RCON or WebRCON depending on the game): see [Live console](#live-console).
- The file manager works only inside the server's own folder; the agent
  enforces this (see [`docs/SPRINGS.md`](docs/SPRINGS.md)).

### Monitoring

`admin/nodes.php` requests `node_stats_ajax.php` for every node every 5 seconds.
That script uses `ServerManager::getSystemStats()` (`GET /api/stats` on the
node) and returns `{"status":"ok","stats":{...}}`, or an `offline`/`error`
status.

### Defaults per game

Start map or world, slots, RAM, default port and the expected binary of every game are defined in
`games.php` (see [Games](#games)). The admin *Create Server* page fills them in when you pick a game.

---

## Admin area

| Page | Purpose |
|------|---------|
| **Remote Nodes** (`nodes.php`) | Connect and delete nodes; generated install command; live telemetry; update location, port ranges and IPs |
| **Client Servers** (`client_servers.php`) | Servers of all clients (`?all=1` for every server) |
| **Create Server** (`create_server.php`) | Create a server manually for a user and node |
| **Users** (`users.php`) | Create `admin` or `client` users, delete users, verify an email manually, change your own password |
| **Orders** (`orders.php`) | Approve (choose a node) or cancel pending orders |
| **Support Tickets** (`tickets.php`) | Reply to tickets and set them `Open` / `Answered` / `Closed` |
| **SMTP / Email** (`smtp.php`) | Mail server settings and a test-email form |
| **Payments** (`payments.php`) | PayPal credentials and test, game prices and discounts, renewal/reminder settings and the cron command, invoices (mark paid / cancel), transactions, refunds |
| **Mods** (`mods/admin.php`) | the mods & plugins catalog: add items by link or upload, choose the target folder, hide or delete them |

Admin pages are protected by `guard.php`: not logged in goes to
`/admin/login.php`, and a non-admin goes to `/dashboard.php`.

---

- **Nodes** (`admin/nodes.php`): every node is a card with live CPU, memory, disk and network, and its game-port usage.
  Click a card to edit it: name, location, primary IP, daemon port and token; additional game IPs as a tag editor
  (validated, and refused when servers on the node still use an address you remove); the game-port pool with presets,
  a live port map (free / used / extra ports of multi-port games), free counts and the next free port; the install
  command; and delete (only for empty nodes). The pool accepts ports and ranges between 1024 and 65535, at most 500.
  All changes are checked on the server too and protected by a security token.

## Security notes and known issues

- **Passwords** are stored with `password_hash`. The SMTP password and the node
  tokens are stored **in plaintext** in the database; protect database access and
  backups accordingly.
- **Panel-to-node traffic is plain HTTP.** Restrict each node's API port to the
  panel server's IP with a firewall (the installer opens it to everyone).
- **No CSRF protection** on the POST forms, and some actions (for example
  deleting a user) work through plain GET links. Add CSRF tokens.
- **No rate limiting** on the admin login.
- **Default admin** is created with `admin123` if the users table is empty
  (see [Installing the panel](#installing-the-panel)).
- **Rotate secrets** that were ever pasted into chats, tickets or screenshots
  (node tokens, database and SMTP passwords). Change a node's token in both the
  node's `SPRINGS_TOKEN` and `boxes.ssh_password`.
- Payments: prices are computed on the server, the PayPal amount and currency are verified on capture,
  webhooks are verified with PayPal and processed once, and payment links carry a secret token. Admin
  payment actions use a CSRF token.
- `springs.env` holds the server's RCON, admin and join passwords. Anyone with file-manager access to the
  server can read them (that is intended); Rust's RCON port (P+2) is reachable from the internet, so keep
  the generated password.
- `/api/servers/wipe` on the agent deletes any validated path outside a short
  blocklist; restricting it to `/var/lib/springs/volumes` is recommended.
- `vendor/phpseclib` is left over from the earlier SSH-based design and is no
  longer used by `ServerManager.php`.

---

## Home page and game icons

`index.php` is the public home page (hero with client login, game cards with category tabs and "from" prices, order dialog, feature overview). The order dialog keeps what was typed if an order is rejected and reopens itself with the error shown. The in-panel "Order Server" page uses the same icons.

Game cards are banner tiles: a picture fills the card, with the game name and "From X / mo" over a dark fade, in the panel's colours (the same cards are used on the in-panel "Order Server" page). Put one picture per game into `assets/games/` named after the game (`cs16.jpg`, `cs2.jpg`, `minecraft.jpg`, `gmod.jpg`, `tf2.jpg`, `l4d2.jpg`, `rust.jpg`, `valheim.jpg`, `zomboid.jpg`, `7dtd.jpg`, `palworld.jpg`; `.webp`, `.png` and `.svg` also work; 16:9, at least 640x360). A game without a file shows a coloured banner with a large pictogram, so nothing breaks while you collect the artwork. Use official key art only where the publisher allows it (press kits and brand guidelines). The mapping lives in `game_icons.php`; a new game added to `games.php` only needs its slug as the file name.

## Footer and legal pages

Every client page ends with a footer linking to `terms.php` (Terms of Service) and `privacy.php` (Privacy Policy). Write the texts in **Admin > Legal Pages**: one text per page and language (English, Serbian); visitors see the language of the flag they chose, or the English text when theirs is empty, and "not published yet" while both are empty. Light formatting: `# Heading`, `## Subheading`, `- list`, `**bold**`; addresses become links; HTML is never executed. The table `legal_pages` is created automatically. The texts are yours to write (the panel ships none, and this is not legal advice).

## Languages (flag switcher)

The panel is written in English and translated while the page is sent (`lang.php`). A flag in the top menu switches between English and Serbian (Latin); the choice is stored in the cookie `swift_lang`, and `?lang=sr` / `?lang=en` works on any page. Dictionaries live in `lang/sr/*.php` (`'English text' => 'Prevod'`, `%s` for variable parts, `@js:` keys for raw JavaScript pieces). Use `t('text')` in PHP for new texts that are sent as JSON or e-mail. Not translated: e-mails sent by the server (they stay English), text typed by users, game names and console output.

## Profile: e-mail change and two-factor authentication

`profile.php` (clients) and `admin/account.php` (administrators, menu item "My Account") hold the account page: e-mail address, password and two-factor authentication.

**E-mail change.** The user enters the new address and the current password. When SMTP is set up (Admin > SMTP / Email), a confirmation link is sent to the NEW address and a notice to the old one; the address only changes after the link (`confirm_email.php`, valid 24 hours, single use, stored hashed) is opened. Without SMTP the password check alone confirms the change. Addresses already used by another account are refused.

**Two-factor authentication (TOTP).** Works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden and similar apps. Setup: scan the QR code (drawn in the browser by `assets/qrcode.js`, nothing is sent to an outside service) or type the setup key, then enter one code. 8 one-time recovery codes are shown once and stored hashed. Sign-in then has a second step (`login_2fa.php`) for both the client login and `admin/login.php`: 5 wrong codes end the attempt, 10 in a row lock the account for 15 minutes, and a code cannot be used twice. Turning 2FA off, or creating new recovery codes, needs the password and a current code.

Database: the new columns (`users.totp_*`, `users.pending_email*`) and the table `user_recovery_codes` are created automatically. The TOTP secret is stored in the database as is (as other panel secrets are), so keep database access and backups protected. If an administrator loses both phone and recovery codes, clear it in the database: `UPDATE users SET totp_enabled=0, totp_secret=NULL WHERE username='NAME';`

## Server statistics (client dashboard > Stats)

Every game server on the dashboard whose game can be queried gets a **Stats** button. The page shows peak and average players, uptime, CPU and RAM, a players-over-time chart (24 hours, 7 days or 30 days), a live player list, a weekday-by-hour heat map of busy times, and for map-based games (CS 1.6, CS2, GMod, TF2, L4D2) the map popularity, a map rotation timeline and a per-map table. Rust, Valheim, 7 Days to Die and Minecraft show everything except maps. Other games show a short "not available" notice.

How it works: the nodes ask the running game servers directly (Steam A2S query for Source/GoldSrc games; Server List Ping for Minecraft) and report player counts, the map and container CPU/RAM. The panel stores one sample per server per run in the `server_stats` table (created automatically, kept for 30 days) and the page draws charts from those samples. Nothing is recorded while a server is stopped, other than "offline" for the uptime figure; if a node cannot be reached the chart simply shows a gap.

Setup (once): update the nodes (re-run the node installer, no server restart needed) and add a cron job that runs every minute (every 2 minutes is fine too):

    * * * * * php /www/wwwroot/gp.marinkovic.biz/stats/collect.php >/dev/null 2>&1

Charts fill in as samples arrive. The `stats/` folder is closed to the web (`.htaccess`); on nginx add `location ^~ /stats/ { deny all; }`. Game servers must answer queries on their game port (Rust and Valheim: game port + 1, as the agent already sets up). The firewall rule for the node only has to allow the panel to reach port 8085; queries run on the node itself.

## Database backups (Admin > Backups)

- **Create backup**: one click makes a gzip-compressed `.sql` dump of the panel database in `backups/files/`. It uses
  `mysqldump` when the server has it (also saves routines, triggers and events), otherwise a built-in PHP dumper
  (tables, data, views; binary data is stored as hex). The password never appears on a command line. Every file is read
  back and checked before it is kept, and a failed `mysqldump` (for example the MySQL 8 "PROCESS privilege" error)
  falls back to the PHP dumper automatically.
- **Download / Delete** in the list. Downloads go through the admin page only (admin login required, strict file names).
  The folder is blocked from the web by `backups/files/.htaccess`; on **nginx / aaPanel add**
  `location ^~ /backups/files/ { deny all; }` to the site configuration and make `backups/files/` writable by the web
  server (user `www`).
- **Automatic backups**: tick the box, choose how many to keep (manual backups are never deleted automatically) and add
  the cron job shown on the page, e.g. daily: `php /www/wwwroot/gp.marinkovic.biz/backups/cron_backup.php`
  (command line only). The page shows the result of the last automatic run.
- Restore: download the file and import it (phpMyAdmin, or `gunzip -c FILE.sql.gz | mysql -u USER -p DATABASE`).
  There is deliberately no one-click restore. Backups hold all users' data and password hashes: keep them private and
  copy them off the server too, a backup on the same disk does not protect against a lost disk.
- Files: `admin/backups.php`, `backups/backup_lib.php`, `backups/cron_backup.php`, `backups/files/`. Table
  `backup_settings` is created automatically.

## Backups and deployment

- Before changing anything, back up the web root and the database:

  ```bash
  mysqldump -u DB_USER -p DB_NAME > swiftpanel-$(date +%F).sql
  tar czf swiftpanel-files-$(date +%F).tgz /path/to/webroot
  ```

- After uploading a PHP file, check it is the file you meant and that it parses:

  ```bash
  head -n 3 file.php     # should start with <?php
  php -l file.php        # should say: No syntax errors detected
  ```

  A file saved under the wrong name (for example the node installer saved as
  `ServerManager.php`) breaks every page that loads it, and such errors are easy
  to miss in the browser.

---

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Nodes page shows no telemetry or "Offline" | Open `node_stats_ajax.php?id=<node id>` as admin: it must return JSON starting with `{"status":"ok"`. Anything else (bash text, PHP error, login page) is the problem. Then test the node directly: `curl -H "Authorization: Bearer $TOKEN" http://NODE_IP:8085/api/stats` |
| Output starts with `#!/usr/bin/env bash` | A PHP file was overwritten with `springs_install.php`. Find it with `grep -l "Springs install aborted" *.php` (only `springs_install.php` should match) and restore the original |
| `401 Unauthorized` from a node | Token mismatch between `boxes.ssh_password` and the node's `SPRINGS_TOKEN` |
| Order approved but server stays "Installing" | Read `install.log` in the server's folder on the node; for Minecraft it says `FAILED: ...` if the Paper download failed |
| Minecraft will not start | See the Java 25 and entrypoint notes in [`docs/SPRINGS.md`](docs/SPRINGS.md) |
| Placing an order fails on an older database | Add the `location` column to `orders` (see [Database](#database)) |
| CS2 server was created earlier but never starts / console says files are not installed | Older versions never downloaded CS2. Use **Reinstall files** on the admin server page, then check `install.log` |
| Steam game stays on "Installing" or the install log says `FAILED` | Read `install.log` in the server folder. The first install downloads several GB. For Left 4 Dead 2 Valve may refuse the anonymous download |
| Start does nothing / `START FAILED` in the console | The console shows the reason (files not installed, invalid value, Valheim password shorter than 5 characters). Check `springs.env` |
| Players cannot see or join a new game server | Open the game's port range on the node firewall (see [Games](#games)); Source games also need a GSLT token for the public list |
| File manager shows an error | The node agent must be the current version (it provides `/api/files/*`); rerun the installer on the node |
| Location dropdown shows no ping, or an odd value | `ping_nodes.php` asks the node to measure the round trip to the visitor (lowest of 4 ICMP probes, TCP fallback when ping is blocked). Test it: `curl -s https://YOUR-PANEL/ping_nodes.php`. Results are cached for 2 minutes per visitor. Behind a public reverse proxy, add `define('TRUSTED_PROXY_IPS', ['proxy.ip.here']);` to `config.php` so the real visitor IP is used (Cloudflare and proxies on the same host are detected automatically) |
| PayPal says "credentials invalid" on the test | Client ID and Secret must come from the same app and the same mode (sandbox keys only work in Sandbox mode) |
| Client paid but the order still shows Unpaid | Check the transaction on the Payments page. A *Pending* PayPal payment is released by the webhook; make sure the webhook URL is public (HTTPS) and the Webhook ID is saved |
| Clients are not asked to pay | Payments are off, the credentials are empty, or the game's price is 0 |
| No reminder e-mails arrive | The daily job is not set up (see *Invoices, renewals and reminders*), SMTP is not configured, or the client has no valid e-mail. Run `php payments/cron_reminders.php` by hand: it prints what it did |
| Reminder e-mails have no Pay now link | Set the Site URL on the Payments page |
| CS 1.6: `meta version`, `amx_...` or other plugin commands print nothing (the log only shows the command, or `Rcon from ...`) | Press **Diagnose** on the console. Usually Metamod is not loaded (`liblist.gam` must say `gamedll_linux "addons/metamod/metamod_i386.so"`). If the log shows `Rcon from ...`, the server was started before the terminal fix: update the node and restart it once |
| CS 1.6 fails with `engine_i486.so: undefined symbol: SteamGameServer_Init`, `Unable to load engine, image is corrupt` | ReHLDS was installed over the current Steam build. Update the node, stop the server and press **Update ReHLDS** (it installs the Steam legacy game files first), then start the server |
| CS 1.6 does not start after enabling Metamod: `LoadLibrary failed on <garbage>: ... cannot open shared object file: No such file or directory`, `Couldn't get DLL API from <garbage>` | The engine prints garbage instead of the file name for every failed library load, so it says nothing by itself. The cause is a missing file or a missing library the file depends on. Press **Diagnose** to see which (it checks the dependencies and the format of `liblist.gam`), then **Repair liblist.gam** to get the server running again (it falls back to the stock library if Metamod cannot be loaded) |
| Console shows a command in blue but the game does nothing | The error (if any) is shown in red under it. Old servers must be restarted once after updating the node; Valheim and Palworld have no console |
| Console says it is running an old agent / Rust commands fail | Rerun the installer command on that node |
| Console updates in bursts or only every ~minute | The web server buffers the stream: add `fastcgi_buffering off;` to the site's nginx config |
| Console shows RECONNECTING... all the time | The panel cannot reach the node (firewall on port 8085?) or PHP has no free workers |
| Install says the node runs an old agent / "Endpoint not found" | The node was not updated: run the installer command on that node again |
| Pressing Install reloads the page and nothing happens | Fixed in the current version (the button used to disable itself before sending); make sure `mods/index.php` is the latest file |
| Install says "Refusing to download from a non-public address" | The link points to an internal/private address; use a public URL |
| Install of an uploaded item says "panel address is not configured" or the node cannot download it | Set the panel address under Admin → Mods → Download settings (it must be reachable from the node) and make sure the node can open `https://YOUR-PANEL/mods/download.php` |
| Download says "does not match its checksum" | The file at the link changed; update the checksum in the catalog item (or clear it) |
| Uploads fail | PHP `upload_max_filesize` / `post_max_size` too small, or `/mods/files/` is not writable by the web server |
| An existing server shows no expiry | Servers created before invoices never expire until you set a date on the admin server page |
| No emails sent | Admin → SMTP / Email → *Send Test Email* shows the exact SMTP error |

Agent-side problems (service, Docker, containers, logs): see
[`docs/SPRINGS.md`](docs/SPRINGS.md#troubleshooting).

## Server backups

Clients open **Backups** on the dashboard to create a backup now, download, restore or delete backups, and to schedule automatic backups
(every 6/12 hours, daily or weekly, at a chosen UTC hour, keeping N scheduled backups). Archives (.tar.gz) are made on the game node by the
Springs agent and stored in `/var/lib/springs/backups/<server>/`, so reinstalling or restoring a server does not delete them.

Admin > **Server Backups** controls the number of backups: a global default (5, 0 = unlimited), an optional per-server override, the shortest
schedule interval, whether scheduling is allowed, and a per-server "disabled" switch. The panel decides the limit and the agent enforces it.
Scheduled runs come from `sbackups/run_scheduled.php` (cron every 5 minutes). See DEPLOY.txt item 16.
