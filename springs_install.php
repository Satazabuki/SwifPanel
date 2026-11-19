<?php
header('Content-Type: text/plain; charset=utf-8');
$rawToken = (string)($_GET['token'] ?? '');
$token = preg_replace('/[^a-fA-F0-9_-]/', '', $rawToken);
$port = (int)($_GET['port'] ?? 8085);
if (!$port) $port = 8085;
if ($token === '' || $token !== $rawToken || $port < 1 || $port > 65535) {
    echo "#!/usr/bin/env bash\n";
    echo "echo 'Springs install aborted: missing or invalid token/port (token may only contain 0-9, a-f, A-F, _ and -).'\n";
    echo "exit 1\n";
    exit;
}
?>
#!/usr/bin/env bash
set -e

echo "[1/4] Installing Docker Engine..."
if ! command -v docker >/dev/null 2>&1; then
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
fi

echo "[2/4] Writing /opt/springs/springs.py..."
mkdir -p /opt/springs /var/lib/springs/volumes
cat << 'PYEOF' > /opt/springs/springs.py
#!/usr/bin/env python3
import os, re, json, time, shutil, subprocess, shlex, threading, ipaddress, base64, zipfile, secrets, socket, urllib.request
import ssl, stat, tarfile, hashlib, tempfile, http.client, select, struct, calendar
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlsplit, urljoin, parse_qs
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

API_TOKEN = os.environ.get("SPRINGS_TOKEN", "<?php echo $token; ?>")
PORT = int(os.environ.get("SPRINGS_PORT", "<?php echo $port; ?>"))


UA = "SwiftPanel/1.0 (https://marinkovic.biz)"
PAPER_API = "https://fill.papermc.io/v3/projects/paper"
JAVA_IMAGE = "ghcr.io/ptero-eggs/yolks:java_25"


def _http_json(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def install_paper(path, log_file):
    """Download the newest STABLE Paper build into <path>/server.jar and validate it."""
    def log(msg, mode="a"):
        with open(log_file, mode) as f:
            f.write(msg + "\n")
    tmp = os.path.join(path, "server.jar.part")
    try:
        log("[Springs] Downloading PaperMC...", "w")
        url = ver = None
        for group in _http_json(PAPER_API)["versions"].values():
            for v in group:
                builds = _http_json(f"{PAPER_API}/versions/{v}/builds")
                stable = [b for b in builds if b.get("channel") == "STABLE"]
                if stable:
                    url = stable[0]["downloads"]["server:default"]["url"]
                    ver = v
                    break
            if url:
                break
        if not url:
            raise RuntimeError("no stable Paper build found")
        log(f"[Springs] Version {ver}")
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        with urllib.request.urlopen(req, timeout=120) as r, open(tmp, "wb") as f:
            shutil.copyfileobj(r, f)
        with open(tmp, "rb") as f:
            magic = f.read(2)
        if magic != b"PK" or os.path.getsize(tmp) < 1000000:
            raise RuntimeError("downloaded file is not a valid jar")
        os.replace(tmp, os.path.join(path, "server.jar"))
        with open(os.path.join(path, "eula.txt"), "w") as f:
            f.write("eula=true\n")
        log("[Springs] Ready!")
    except Exception as e:
        try:
            if os.path.exists(tmp):
                os.remove(tmp)
            log(f"[Springs] FAILED: {e}")
        except Exception:
            pass


def safe_resolve(base_path, rel_path=""):
    base = os.path.realpath(base_path)
    rel = (rel_path or "").lstrip("/")
    target = os.path.realpath(os.path.join(base, rel))
    if target != base and not target.startswith(base + os.sep):
        raise ValueError("Access denied: path outside server volume")
    return base, target

PROTECTED_DIRS = {"/", "/bin", "/boot", "/dev", "/etc", "/home", "/lib", "/lib64", "/opt", "/proc",
                  "/root", "/run", "/sbin", "/srv", "/sys", "/tmp", "/usr", "/var",
                  "/var/lib", "/var/lib/springs", "/var/lib/springs/volumes"}
MAX_EDIT_BYTES = 2 * 1024 * 1024
MAX_WRITE_BYTES = 8 * 1024 * 1024
MAX_UPLOAD_BYTES = 200 * 1024 * 1024
MAX_UNZIP_BYTES = 2 * 1024 ** 3
MAX_UNZIP_FILES = 50000
MAX_LIST_ITEMS = 5000
MAX_BODY_BYTES = 300 * 1024 * 1024


class FileError(Exception):
    pass


def file_target(body):
    """Resolve (server_root, target) for a file-manager request, staying inside the server folder."""
    root = str(body.get("install_path") or "")
    if not root:
        raise FileError("Missing install_path")
    base = os.path.realpath(root)
    if base in PROTECTED_DIRS or base.count("/") < 3:
        raise FileError("Refusing to manage files in a protected directory")
    if not os.path.isdir(base):
        raise FileError("Server folder does not exist yet (the install may still be running)")
    try:
        _, target = safe_resolve(base, str(body.get("rel_path") or ""))
    except ValueError:
        raise FileError("Access denied: path outside server volume")
    return base, target


def run_cmd(cmd):
    p = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    return p.returncode, p.stdout.strip()

# ---------------------------------------------------------------------------
# Data-driven games (SteamCMD based). cs16 / minecraft keep their own code paths below.
# ---------------------------------------------------------------------------
INSTALLER_IMAGE = "ghcr.io/ptero-eggs/installers:debian"
IMG_SOURCE = "ghcr.io/pterodactyl/games:source"
IMG_RUST = "ghcr.io/pterodactyl/games:rust"
IMG_VALHEIM = "ghcr.io/ptero-eggs/games:valheim"
IMG_STEAMCMD = "ghcr.io/ptero-eggs/steamcmd:debian"
IMG_SNIPER = "ghcr.io/ptero-eggs/steamcmd:sniper"   # Steam Runtime image, required by CS2

# kind "srcds" = Source engine (./srcds_run). stop_cmd is written to the console (stdin),
# stop_signal is sent with `docker stop`, stop_grace is the maximum wait in seconds.
GAMES = {
    "cs2":      {"label": "Counter-Strike 2",  "kind": "cs2",   "appid": "730",    "image": IMG_SNIPER,   "check": "game/bin/linuxsteamrt64/cs2",
                 "min_disk_gb": 40, "stop_cmd": "quit", "stop_grace": 15},
    "gmod":     {"label": "Garry's Mod",       "kind": "srcds", "appid": "4020",    "image": IMG_SOURCE,   "check": "srcds_run",
                 "game_dir": "garrysmod", "default_map": "gm_flatgrass", "stop_cmd": "quit", "stop_grace": 15},
    "tf2":      {"label": "Team Fortress 2",   "kind": "srcds", "appid": "232250",  "image": IMG_SOURCE,   "check": "srcds_run",
                 "game_dir": "tf", "default_map": "ctf_2fort", "stop_cmd": "quit", "stop_grace": 15},
    "l4d2":     {"label": "Left 4 Dead 2",     "kind": "srcds", "appid": "222860",  "image": IMG_SOURCE,   "check": "srcds_run",
                 "game_dir": "left4dead2", "default_map": "c1m1_hotel", "stop_cmd": "quit", "stop_grace": 15},
    "rust":     {"label": "Rust",              "kind": "rust",  "appid": "258550",  "image": IMG_RUST,     "check": "RustDedicated",
                 "stop_cmd": "quit", "stop_grace": 20},
    "valheim":  {"label": "Valheim",           "kind": "valheim", "appid": "896660", "image": IMG_VALHEIM, "check": "valheim_server.x86_64",
                 "stop_signal": "SIGINT", "stop_grace": 20},
    "zomboid":  {"label": "Project Zomboid",   "kind": "zomboid", "appid": "380870", "image": IMG_STEAMCMD, "check": "ProjectZomboid64",
                 "stop_cmd": "quit", "stop_grace": 20},
    "7dtd":     {"label": "7 Days to Die",     "kind": "7dtd",  "appid": "294420",  "image": IMG_STEAMCMD, "check": "7DaysToDieServer.x86_64",
                 "stop_signal": "SIGTERM", "stop_grace": 25},
    "palworld": {"label": "Palworld",          "kind": "palworld", "appid": "2394010", "image": IMG_STEAMCMD,
                 "check": "Pal/Binaries/Linux/PalServer-Linux-Shipping", "stop_signal": "SIGTERM", "stop_grace": 25},
}

# Same installer approach as the Pterodactyl game eggs. Runs inside INSTALLER_IMAGE with the
# server folder mounted at /mnt/server. Retries because the first SteamCMD run often only updates itself.
STEAM_INSTALL_SH = r"""
set -u
cd /tmp
mkdir -p /mnt/server/steamcmd /mnt/server/steamapps
curl -sSL -o steamcmd.tar.gz https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz || { echo "[Springs] FAILED: could not download SteamCMD"; exit 1; }
tar -xzf steamcmd.tar.gz -C /mnt/server/steamcmd || { echo "[Springs] FAILED: could not unpack SteamCMD"; exit 1; }
cd /mnt/server/steamcmd
chown -R root:root /mnt
export HOME=/mnt/server
for attempt in 1 2 3; do
  echo "[Springs] SteamCMD attempt $attempt..."
  ./steamcmd.sh +@sSteamCmdForcePlatformType linux +force_install_dir /mnt/server +login anonymous +app_update "$SRCDS_APPID" validate +quit
  [ -e "/mnt/server/$CHECK_FILE" ] && break
done
if [ ! -e "/mnt/server/$CHECK_FILE" ]; then
  echo "[Springs] FAILED: $CHECK_FILE was not downloaded (see SteamCMD output above)"
  exit 1
fi
mkdir -p /mnt/server/.steam/sdk32 /mnt/server/.steam/sdk64
cp -v linux32/steamclient.so /mnt/server/.steam/sdk32/steamclient.so
cp -v linux64/steamclient.so /mnt/server/.steam/sdk64/steamclient.so
exit 0
"""


def _rand(n=10):
    alphabet = "abcdefghjkmnpqrstuvwxyz23456789"
    return "".join(secrets.choice(alphabet) for _ in range(n))


def default_settings(game):
    """(KEY, default, description) rows written to <server folder>/springs.env on install."""
    kind = GAMES[game]["kind"]
    rows = [("SERVER_NAME", "", "Server name. Leave empty to use the name from the panel.")]
    if game == "cs2":
        rows += [("GSLT", "", "Steam Game Server Login Token. Needed to be listed publicly: https://steamcommunity.com/dev/managegameservers"),
                 ("SERVER_PASSWORD", "", "Join password (optional)"),
                 ("RCON_PASSWORD", _rand(12), "RCON password (external RCON tools only)"),
                 ("GAME_MODE", "1", "Game mode. Competitive 1/0, Casual 0/0, Wingman 2/0, Deathmatch 2/1, Arms Race 0/1 (GAME_MODE/GAME_TYPE)"),
                 ("GAME_TYPE", "0", "Game type, see GAME_MODE"),
                 ("VAC_ENABLED", "1", "1 = VAC secure, 0 = insecure mode"),
                 ("RCON_ENABLED", "0", "1 = allow external RCON tools (-usercon)")]
    if game == "gmod":
        rows += [("GAMEMODE", "sandbox", "Gamemode, e.g. sandbox, darkrp, terrortown"),
                 ("TICKRATE", "22", "Server tickrate"),
                 ("WORKSHOP_COLLECTION", "", "Steam Workshop collection ID (optional)")]
    if kind == "srcds":
        rows += [("GSLT", "", "Steam Game Server Login Token. Needed to be listed publicly: https://steamcommunity.com/dev/managegameservers")]
    if game == "rust":
        rows += [("RCON_PASSWORD", _rand(14), "RCON password (RCON listens on the game port + 2)"),
                 ("WORLD_SIZE", "3000", "Procedural map size (1000-6000)"),
                 ("WORLD_SEED", str(secrets.randbelow(999999) + 1), "Procedural map seed")]
    if game == "valheim":
        rows += [("SERVER_PASSWORD", _rand(8), "Join password (at least 5 characters, must not be part of the server name)"),
                 ("WORLD_NAME", "Dedicated", "World save name"),
                 ("PUBLIC", "1", "1 = listed in the server browser, 0 = hidden"),
                 ("CROSSPLAY", "0", "1 = enable crossplay")]
    if game == "zomboid":
        rows += [("ADMIN_PASSWORD", _rand(12), "Password of the 'admin' account (used when the server is first created)"),
                 ("SERVER_PASSWORD", "", "Join password (optional)")]
    if game == "7dtd":
        rows += [("SERVER_PASSWORD", "", "Join password (optional)"),
                 ("GAME_NAME", "MyGame", "Save game name")]
    if game == "palworld":
        rows += [("SERVER_PASSWORD", "", "Join password (optional)"),
                 ("ADMIN_PASSWORD", _rand(12), "Admin password for in-game admin commands")]
    rows += [("EXTRA_ARGS", "", "Extra launch arguments (advanced)")]
    return rows


def seed_settings(game, path):
    f = os.path.join(path, "springs.env")
    if os.path.exists(f):
        return
    lines = ["# Springs server settings - edit the values, then restart the server.",
             "# One KEY=value per line, no quotes. Lines starting with # are ignored.", ""]
    for key, val, desc in default_settings(game):
        lines += ["# " + desc, key + "=" + val, ""]
    with open(f, "w") as fh:
        fh.write("\n".join(lines))


def read_settings(path):
    out = {}
    try:
        with open(os.path.join(path, "springs.env"), encoding="utf-8", errors="replace") as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                m = re.match(r"^([A-Z][A-Z0-9_]{0,31})=(.*)$", line)
                if not m:
                    continue
                v = m.group(2).strip()
                if len(v) >= 2 and v[0] == v[-1] and v[0] in "\"'":
                    v = v[1:-1]
                if len(v) > 200 or re.search(r"[\x00-\x1f]", v):
                    continue
                out[m.group(1)] = v
    except OSError:
        pass
    return out


def ini_set(file, values):
    """Set KEY=value lines in an ini file (create it when missing)."""
    os.makedirs(os.path.dirname(file), exist_ok=True)
    lines = []
    if os.path.exists(file):
        with open(file, encoding="utf-8", errors="replace") as fh:
            lines = fh.read().split("\n")
    seen = set()
    for i, line in enumerate(lines):
        m = re.match(r"^([A-Za-z0-9_]+)=", line)
        if m and m.group(1) in values:
            lines[i] = m.group(1) + "=" + values[m.group(1)]
            seen.add(m.group(1))
    while lines and lines[-1] == "":
        lines.pop()
    for k, v in values.items():
        if k not in seen:
            lines.append(k + "=" + v)
    with open(file, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")


def _clean(v):
    return re.sub(r"[\r\n\x00]", " ", str(v))


def prepare_zomboid(path, body, cfg, name):
    P = int(body.get("port"))
    slots = int(body.get("max_players") or 16)
    ram = int(body.get("ram_mb") or 4096)
    ini_set(os.path.join(path, "Zomboid", "Server", "servertest.ini"), {
        "PublicName": _clean(name), "MaxPlayers": str(slots), "Password": _clean(cfg.get("SERVER_PASSWORD", "")),
        "DefaultPort": str(P), "UDPPort": str(P + 1), "SteamPort1": str(P + 2), "SteamPort2": str(P + 3)})
    jf = os.path.join(path, "ProjectZomboid64.json")
    if os.path.exists(jf):  # keep the JVM heap below the container memory limit
        with open(jf, encoding="utf-8", errors="replace") as fh:
            text = fh.read()
        text = re.sub(r"-Xmx\d+[kKmMgG]", "-Xmx%dm" % max(1024, int(ram * 0.75)), text)
        with open(jf, "w", encoding="utf-8") as fh:
            fh.write(text)


def build_game_command(game, body, cfg):
    """Return the shell command that runs INSIDE the game container (values are shell-quoted)."""
    q = shlex.quote
    spec = GAMES[game]
    kind = spec["kind"]
    P = int(body.get("port"))
    ip = str(body.get("ip_address") or "0.0.0.0")
    slots = int(body.get("max_players") or 16)
    world = str(body.get("start_map_or_world") or "")
    name = (cfg.get("SERVER_NAME") or str(body.get("server_name") or "") or str(body.get("screen_name") or "Springs Server"))[:60]
    pre, args = [], []
    if kind == "srcds":
        args = ["./srcds_run", "-game", spec["game_dir"], "-console", "-port", str(P), "+ip", ip,
                "+clientport", str(30000 + P % 10000), "+tv_port", str(40000 + P % 10000),
                "+map", world or spec["default_map"], "+maxplayers", str(slots), "+hostname", name,
                "-strictportbind", "-norestart"]
        if game == "gmod":
            args += ["+gamemode", cfg.get("GAMEMODE") or "sandbox", "-tickrate", cfg.get("TICKRATE") or "22"]
            if cfg.get("WORKSHOP_COLLECTION"):
                args += ["+host_workshop_collection", cfg["WORKSHOP_COLLECTION"]]
        if cfg.get("GSLT"):
            args += ["+sv_setsteamaccount", cfg["GSLT"]]
    elif kind == "cs2":
        pre = ['export LD_LIBRARY_PATH="$(pwd)/game/bin/linuxsteamrt64:${LD_LIBRARY_PATH:-}"']
        args = ["./game/bin/linuxsteamrt64/cs2", "-dedicated", "-console"]
        if cfg.get("VAC_ENABLED") == "0":
            args.append("-insecure")
        args += ["-ip", ip, "-port", str(P), "-tv_port", str(40000 + P % 10000), "-maxplayers", str(slots)]
        if cfg.get("RCON_ENABLED") == "1":
            args.append("-usercon")
        args += ["+game_mode", cfg.get("GAME_MODE") or "1", "+game_type", cfg.get("GAME_TYPE") or "0",
                 "+map", world or "de_dust2", "+hostname", name]
        if cfg.get("SERVER_PASSWORD"):
            args += ["+sv_password", cfg["SERVER_PASSWORD"]]
        if cfg.get("RCON_PASSWORD"):
            args += ["+rcon_password", cfg["RCON_PASSWORD"]]
        if cfg.get("GSLT"):
            args += ["+sv_setsteamaccount", cfg["GSLT"]]
    elif kind == "rust":
        pre = ['export LD_LIBRARY_PATH="$(pwd)/RustDedicated_Data/Plugins/x86_64:${LD_LIBRARY_PATH:-}"']
        args = ["./RustDedicated", "-batchmode",
                "+server.port", str(P), "+server.queryport", str(P + 1), "+server.identity", "rust",
                "+rcon.ip", "0.0.0.0", "+rcon.port", str(P + 2), "+rcon.web", "true",
                "+rcon.password", cfg.get("RCON_PASSWORD") or _rand(14),
                "+server.hostname", name, "+server.level", world or "Procedural Map",
                "+server.worldsize", cfg.get("WORLD_SIZE") or "3000", "+server.seed", cfg.get("WORLD_SEED") or "1",
                "+server.maxplayers", str(slots), "+app.port", str(P + 3), "+server.saveinterval", "300"]
    elif kind == "valheim":
        pre = ['export LD_LIBRARY_PATH="./linux64:${LD_LIBRARY_PATH:-}"', "export SteamAppId=892970"]
        args = ["./valheim_server.x86_64", "-nographics", "-batchmode", "-name", name, "-port", str(P),
                "-world", cfg.get("WORLD_NAME") or world or "Dedicated",
                "-password", cfg.get("SERVER_PASSWORD") or "",
                "-public", "0" if cfg.get("PUBLIC") == "0" else "1",
                "-saveinterval", "1800", "-backups", "4", "-backupshort", "7200", "-backuplong", "43200"]
        if cfg.get("CROSSPLAY") == "1":
            args.append("-crossplay")
    elif kind == "zomboid":
        pre = ['export PATH="./jre64/bin:$PATH"',
               'export LD_LIBRARY_PATH="./linux64:./natives:.:./jre64/lib/amd64:${LD_LIBRARY_PATH:-}"',
               'export LD_PRELOAD="${LD_PRELOAD:-}:libjsig.so"']
        args = ["./ProjectZomboid64", "-port", str(P), "-udpport", str(P + 1), "-servername", "servertest",
                "-adminpassword", cfg.get("ADMIN_PASSWORD") or _rand(12), "-cachedir=/data/Zomboid"]
    elif kind == "7dtd":
        pre = ['export LD_LIBRARY_PATH=".:${LD_LIBRARY_PATH:-}"']
        args = ["./7DaysToDieServer.x86_64", "-configfile=serverconfig.xml", "-quit", "-batchmode", "-nographics",
                "-dedicated", "-logfile", "/dev/stdout",
                "-ServerPort=" + str(P), "-ServerName=" + name, "-ServerMaxPlayerCount=" + str(slots),
                "-GameWorld=" + (world or "Navezgane"), "-GameName=" + (cfg.get("GAME_NAME") or "MyGame"),
                "-TelnetEnabled=false", "-ControlPanelEnabled=false", "-WebDashboardEnabled=false"]
        if cfg.get("SERVER_PASSWORD"):
            args.append("-ServerPassword=" + cfg["SERVER_PASSWORD"])
    elif kind == "palworld":
        args = ["./Pal/Binaries/Linux/PalServer-Linux-Shipping", "Pal", "-publiclobby", "-useperfthreads",
                "-NoAsyncLoadingThread", "-UseMultithreadForDS", "-port=" + str(P), "-publicport=" + str(P),
                "-servername=" + name, "-players=" + str(slots), "-adminpassword=" + (cfg.get("ADMIN_PASSWORD") or _rand(12))]
        if cfg.get("SERVER_PASSWORD"):
            args.append("-serverpassword=" + cfg["SERVER_PASSWORD"])
    extra = []
    if cfg.get("EXTRA_ARGS"):
        try:
            extra = shlex.split(cfg["EXTRA_ARGS"])
        except ValueError:
            extra = []
    return "; ".join(pre + ["exec " + " ".join(q(a) for a in args + extra)])


def install_steam_game(game, path, log_file):
    spec = GAMES[game]
    try:
        with open(log_file, "w") as lf:
            def w(msg):
                lf.write(msg + "\n")
                lf.flush()
            w(f"[Springs] Installing {spec['label']} (Steam app {spec['appid']})...")
            need = spec.get("min_disk_gb")
            if need and not os.path.exists(os.path.join(path, spec["check"])):
                free_gb = shutil.disk_usage(path).free / 1073741824
                if free_gb < need:
                    w(f"[Springs] FAILED: not enough free disk space on this node ({free_gb:.0f} GB free, about {need} GB needed for {spec['label']}).")
                    return
            w("[Springs] Pulling Docker images (the first install on a node can take a few minutes)...")
            subprocess.run(["docker", "pull", INSTALLER_IMAGE], stdout=lf, stderr=subprocess.STDOUT)
            subprocess.run(["docker", "pull", spec["image"]], stdout=lf, stderr=subprocess.STDOUT)
            w("[Springs] Downloading game files with SteamCMD (this can take a long time)...")
            subprocess.run(["docker", "run", "--rm", "--user", "0:0", "-v", f"{path}:/mnt/server",
                            "-e", f"SRCDS_APPID={spec['appid']}", "-e", f"CHECK_FILE={spec['check']}",
                            "--entrypoint", "bash", INSTALLER_IMAGE, "-c", STEAM_INSTALL_SH],
                           stdout=lf, stderr=subprocess.STDOUT)
            if not os.path.exists(os.path.join(path, spec["check"])):
                w(f"[Springs] FAILED: {spec['check']} is missing after the download.")
                if game == "l4d2":
                    w("[Springs] Note: Valve may require a Steam account to download Left 4 Dead 2 server files.")
                return
            seed_settings(game, path)
            w("[Springs] Settings file: springs.env (edit it in the file manager)")
            w("[Springs] Complete!")
    except Exception as e:
        try:
            with open(log_file, "a") as lf:
                lf.write(f"[Springs] FAILED: {e}\n")
        except Exception:
            pass


_LOCKS = {}
_LOCKS_GUARD = threading.Lock()


def container_lock(name):
    """One lock per container so a restart (stop, then start) cannot overlap a slow, graceful stop."""
    with _LOCKS_GUARD:
        lk = _LOCKS.get(name)
        if lk is None:
            lk = _LOCKS[name] = threading.Lock()
        return lk


def container_running(cname):
    _, out = run_cmd("docker inspect -f '{{.State.Running}}' " + cname + " 2>/dev/null")
    return out == "true"


def graceful_stop(cname):
    _, game = run_cmd("docker inspect -f '{{index .Config.Labels \"springs.game\"}}' " + cname + " 2>/dev/null")
    if game == "minecraft":
        spec = {"stop_cmd": "stop", "stop_grace": 25}
    elif game == "cs16":
        spec = {"stop_cmd": "quit", "stop_grace": 10}
    else:
        spec = GAMES.get(game) or {}
    grace = int(spec.get("stop_grace", 10))
    cmd, sig = spec.get("stop_cmd"), spec.get("stop_signal")
    if cmd and container_running(cname):
        try:
            send_console(cname, game, cmd)
        except Exception:
            pass                     # not accepted: the stop below still works
        deadline = time.time() + grace
        while time.time() < deadline and container_running(cname):
            time.sleep(1)
        grace = 5
    sigopt = f"-s {sig} " if sig else ""
    run_cmd(f"docker stop {sigopt}-t {grace} {cname} >/dev/null 2>&1; docker rm -f {cname} >/dev/null 2>&1")


def measure_rtt(target):
    """Round-trip time from this node to <target> in ms, as (ms, method). Lowest of several probes."""
    fam = "-6" if ":" in target else "-4"
    _, out = run_cmd(f"ping {fam} -c 4 -i 0.2 -W 1 -q {shlex.quote(target)} 2>/dev/null || true")
    m = re.search(r"=\s*([0-9.]+)/([0-9.]+)/([0-9.]+)", out)      # min/avg/max
    if m:
        return max(1, int(round(float(m.group(1))))), "icmp"
    # ICMP is blocked (very common on home routers): time a TCP handshake instead.
    # A reply or a reset both prove a round trip to the client's side of the connection.
    results = []

    def probe(port):
        s = socket.socket(socket.AF_INET6 if ":" in target else socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(1.2)
        t0 = time.perf_counter()
        try:
            s.connect((target, port))
        except (ConnectionRefusedError, ConnectionResetError):
            pass
        except OSError:
            return
        finally:
            s.close()
        results.append((time.perf_counter() - t0) * 1000.0)

    threads = [threading.Thread(target=probe, args=(port,), daemon=True) for port in (443, 80)]
    for t in threads:
        t.start()
    for t in threads:
        t.join(2.0)
    if results:
        return max(1, int(round(min(results)))), "tcp"
    return None, None


# ---------------------------------------------------------------------------
# Mods & plugins: download a catalog file into a server folder.
# The PANEL decides what may be installed (an admin-curated catalog); the node only ever fetches that URL
# and never trusts a client. Downloads must come from public addresses (no internal network), redirects are
# re-checked, size and time are capped, and archives are checked before anything is written.
# ---------------------------------------------------------------------------
MAX_MOD_BYTES = 250 * 1024 * 1024
MAX_MOD_FILES = 20000
MAX_MOD_EXTRACT = 1536 * 1024 * 1024
MOD_DOWNLOAD_TIMEOUT = 45
MOD_LIST_LIMIT = 5000
ALLOW_PRIVATE_DOWNLOADS = os.environ.get("SPRINGS_ALLOW_PRIVATE_DOWNLOADS", "") == "1"   # for tests only


class ModError(Exception):
    pass


def _public_addresses(host, port):
    try:
        infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    except (socket.gaierror, UnicodeError):
        raise ModError("Could not resolve " + str(host))
    addrs = []
    for fam, _t, _p, _c, sa in infos:
        ip = ipaddress.ip_address(sa[0])
        if not ALLOW_PRIVATE_DOWNLOADS and (not ip.is_global or ip.is_multicast or ip.is_reserved):
            raise ModError("Refusing to download from a non-public address")
        addrs.append((fam, sa))
    if not addrs:
        raise ModError("Could not resolve " + str(host))
    return addrs


def fetch_to_file(url, dest, max_bytes):
    """GET url into dest. http(s) only; every hop (redirects too) must resolve to public addresses.
    Returns (size, sha256 hex)."""
    deadline = time.time() + MOD_DOWNLOAD_TIMEOUT
    for _hop in range(6):
        parts = urlsplit(url)
        if parts.scheme not in ("http", "https") or not parts.hostname:
            raise ModError("Only http(s) download links are allowed")
        https = parts.scheme == "https"
        port = parts.port or (443 if https else 80)
        fam, sa = _public_addresses(parts.hostname, port)[0]
        raw = socket.socket(fam, socket.SOCK_STREAM)
        conn = None
        try:
            raw.settimeout(max(1.0, min(15.0, deadline - time.time())))
            raw.connect(sa)
            sock = ssl.create_default_context().wrap_socket(raw, server_hostname=parts.hostname) if https else raw
            conn = (http.client.HTTPSConnection if https else http.client.HTTPConnection)(parts.hostname, port, timeout=30)
            conn.sock = sock      # connect to the address we just verified, not to whatever DNS says next
            path = (parts.path or "/") + ("?" + parts.query if parts.query else "")
            conn.request("GET", path, headers={"User-Agent": UA, "Accept": "*/*", "Accept-Encoding": "identity"})
            resp = conn.getresponse()
            if resp.status in (301, 302, 303, 307, 308):
                loc = resp.getheader("Location")
                if not loc:
                    raise ModError("The download link redirects nowhere")
                url = urljoin(url, loc)
                continue
            if resp.status != 200:
                raise ModError("Download failed: HTTP " + str(resp.status))
            clen = resp.getheader("Content-Length")
            if clen and clen.isdigit() and int(clen) > max_bytes:
                raise ModError("The file is too large")
            h = hashlib.sha256()
            size = 0
            with open(dest, "wb") as out:
                while True:
                    left = deadline - time.time()
                    if left <= 0:
                        raise ModError("The download took too long")
                    try:
                        sock.settimeout(max(1.0, min(15.0, left)))
                    except OSError:
                        pass                      # the connection already closed itself after the last byte
                    try:
                        chunk = resp.read1(65536)   # returns as soon as some data arrived, so a trickling server cannot stall the loop
                    except (socket.timeout, ssl.SSLError):
                        raise ModError("The download took too long")
                    if not chunk:
                        break
                    size += len(chunk)
                    if size > max_bytes:
                        raise ModError("The file is too large")
                    h.update(chunk)
                    out.write(chunk)
            return size, h.hexdigest()
        except (OSError, http.client.HTTPException) as e:
            if isinstance(e, socket.timeout):
                raise ModError("The download took too long")
            raise ModError("Download failed: " + str(e)[:120])
        finally:
            try:
                if conn is not None:
                    conn.close()
                raw.close()
            except OSError:
                pass
    raise ModError("Too many redirects")


def _archive_dest(base, tdir, name):
    """Where an archive member goes; refuses anything that would leave the server folder."""
    n = str(name).replace("\\", "/")
    if n.startswith("/") or re.match(r"^[A-Za-z]:", n):
        raise ModError("Unsafe path in the archive: " + str(name)[:80])
    parts = [p for p in n.split("/") if p not in ("", ".")]
    if any(p == ".." for p in parts):
        raise ModError("Unsafe path in the archive: " + str(name)[:80])
    if not parts:
        return None
    target = os.path.realpath(os.path.join(tdir, *parts))
    if target != base and not target.startswith(base + os.sep):
        raise ModError("Unsafe path in the archive: " + str(name)[:80])
    return target


def _copy_capped(src, dest, state):
    with open(dest, "wb") as out:
        while True:
            chunk = src.read(1024 * 1024)
            if not chunk:
                break
            state["written"] += len(chunk)
            if state["written"] > MAX_MOD_EXTRACT:
                raise ModError("The archive is too large when unpacked")
            out.write(chunk)


def extract_archive(path, base, tdir):
    """Unpack a zip or tar(.gz/.bz2/.xz) into tdir. Everything is validated first; nothing is written when
    a single entry is unsafe. Returns the relative paths of the files that were written."""
    files = []
    state = {"written": 0}
    if zipfile.is_zipfile(path):
        with zipfile.ZipFile(path) as z:
            infos = z.infolist()
            if len(infos) > MAX_MOD_FILES:
                raise ModError("The archive contains too many files")
            if sum(i.file_size for i in infos) > MAX_MOD_EXTRACT:
                raise ModError("The archive is too large when unpacked")
            plan = []
            for i in infos:
                if stat.S_ISLNK((i.external_attr >> 16) & 0xFFFF):
                    raise ModError("The archive contains a symbolic link")
                plan.append((i, _archive_dest(base, tdir, i.filename)))
            for i, dest in plan:
                if dest is None:
                    continue
                if i.is_dir():
                    os.makedirs(dest, exist_ok=True)
                    continue
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                with z.open(i) as src:
                    _copy_capped(src, dest, state)
                if ((i.external_attr >> 16) & 0o111):
                    os.chmod(dest, 0o755)
                files.append(os.path.relpath(dest, base))
        return files
    if tarfile.is_tarfile(path):
        with tarfile.open(path, "r:*") as tf:
            members = tf.getmembers()
            if len(members) > MAX_MOD_FILES:
                raise ModError("The archive contains too many files")
            plan = []
            for m in members:
                if m.issym() or m.islnk() or m.isdev() or m.isfifo():
                    raise ModError("The archive contains a link or special file")
                plan.append((m, _archive_dest(base, tdir, m.name)))
            if sum(m.size for m, _d in plan if m.isfile()) > MAX_MOD_EXTRACT:
                raise ModError("The archive is too large when unpacked")
            for m, dest in plan:
                if dest is None:
                    continue
                if m.isdir():
                    os.makedirs(dest, exist_ok=True)
                    continue
                if not m.isfile():
                    continue
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                src = tf.extractfile(m)
                if src is None:
                    continue
                _copy_capped(src, dest, state)
                if m.mode & 0o111:
                    os.chmod(dest, 0o755)
                files.append(os.path.relpath(dest, base))
        return files
    raise ModError("The downloaded file is not a zip or tar archive")


# ---------------------------------------------------------------------------
# ReHLDS: replaces the stock HLDS engine (engine_i486.so) of a CS 1.6 server
# ---------------------------------------------------------------------------
REHLDS_API = os.environ.get("SPRINGS_REHLDS_API", "https://api.github.com/repos/rehlds/ReHLDS/releases/latest")
REHLDS_MARKER = ".springs-rehlds"
MAX_REHLDS_BYTES = 40 * 1024 * 1024


def _sha256_file(p):
    h = hashlib.sha256()
    with open(p, "rb") as f:
        for chunk in iter(lambda: f.read(1048576), b""):
            h.update(chunk)
    return h.hexdigest()


def _elf32_i386(head):
    return len(head) >= 20 and head[:4] == b"\x7fELF" and head[4] == 1 and head[18:20] == b"\x03\x00"


def rehlds_latest():
    """(version, download url) of the newest ReHLDS release (the rehlds-bin-<version>.zip asset)."""
    try:
        rel = _http_json(REHLDS_API)
    except (OSError, ValueError, http.client.HTTPException) as e:
        raise ModError("Could not look up the latest ReHLDS release: " + str(e)[:100])
    for a in (rel.get("assets") or []):
        name = str(a.get("name") or "")
        if re.fullmatch(r"rehlds-bin-[0-9][0-9A-Za-z._-]*\.zip", name) and a.get("browser_download_url"):
            ver = str(rel.get("tag_name") or name[len("rehlds-bin-"):-4])
            return re.sub(r"[^A-Za-z0-9._-]", "", ver)[:40] or "unknown", str(a["browser_download_url"])
    raise ModError("The latest ReHLDS release has no rehlds-bin zip file")


def rehlds_status(path):
    """Is the ReHLDS engine the one in this server folder right now?"""
    base = os.path.realpath(path)
    eng = os.path.join(base, "engine_i486.so")
    info = {"installed": False, "version": "", "engine_present": os.path.isfile(eng), "replaced": False}
    try:
        with open(os.path.join(base, REHLDS_MARKER)) as f:
            m = json.load(f)
        if info["engine_present"] and m.get("engine_sha256") == _sha256_file(eng):
            info["installed"] = True
            info["version"] = str(m.get("version") or "")
        else:
            info["replaced"] = True      # it was installed once, but the engine file is a different one now (SteamCMD validate, reinstall)
    except (OSError, ValueError, AttributeError):
        pass
    return info


def install_rehlds(path, say=None):
    """Download the newest ReHLDS and put its linux32 files into the server root. The server must not be running.
    Every file is copied next to its target and moved into place, so a half-finished download never leaves a broken engine."""
    say = say or (lambda _m: None)
    base, _t = file_target({"install_path": path})
    if not os.path.isfile(os.path.join(base, "hlds_run")) or not os.path.isdir(os.path.join(base, "cstrike")):
        raise ModError("The CS 1.6 game files are not installed yet")
    say("[Springs] Looking up the latest ReHLDS release...")
    ver, url = rehlds_latest()
    work = tempfile.mkdtemp(prefix=".rehlds-", dir=base)
    try:
        say("[Springs] Downloading ReHLDS " + ver + "...")
        zpath = os.path.join(work, "rehlds.zip")
        fetch_to_file(url, zpath, MAX_REHLDS_BYTES)
        out = os.path.realpath(os.path.join(work, "x"))
        os.makedirs(out)
        try:
            extract_archive(zpath, out, out)
        except (zipfile.BadZipFile, tarfile.TarError, EOFError, OSError) as e:
            raise ModError("The ReHLDS download is damaged: " + str(e)[:100])
        src = None
        for cand in [os.path.join(out, "bin", "linux32")] + [os.path.join(out, d, "bin", "linux32") for d in sorted(os.listdir(out))]:
            if os.path.isdir(cand) and not os.path.islink(cand):
                src = cand
                break
        if not src or not os.path.isfile(os.path.join(src, "engine_i486.so")):
            raise ModError("The ReHLDS download has no bin/linux32/engine_i486.so (the release layout changed?)")
        with open(os.path.join(src, "engine_i486.so"), "rb") as f:
            if not _elf32_i386(f.read(20)):
                raise ModError("The ReHLDS engine in the download is not a 32-bit Linux library")
        orig = os.path.join(base, "engine_i486.so")
        bak = orig + ".springs-orig"
        if os.path.isfile(orig) and not os.path.exists(bak) and not rehlds_status(base)["installed"]:
            shutil.copy2(orig, bak)       # one-time copy of the stock engine, so the change can be undone by hand
        n = 0
        for root, _dirs, files in os.walk(src):
            rel = os.path.relpath(root, src)
            for fn in files:
                s_ = os.path.join(root, fn)
                if os.path.islink(s_):
                    continue
                d_ = os.path.normpath(os.path.join(base, rel, fn))
                if d_ != base and not d_.startswith(base + os.sep):
                    continue
                os.makedirs(os.path.dirname(d_), exist_ok=True)
                tmp = d_ + ".springs-new"
                shutil.copyfile(s_, tmp)
                os.chmod(tmp, 0o755)
                os.replace(tmp, d_)       # the old file is unlinked, not overwritten: safe even if something still maps it
                n += 1
        with open(os.path.join(base, REHLDS_MARKER), "w") as f:
            json.dump({"version": ver, "engine_sha256": _sha256_file(orig), "installed_at": int(time.time())}, f)
        say("[Springs] ReHLDS " + ver + " installed (%d files)." % n)
        return {"version": ver, "files": n}
    finally:
        shutil.rmtree(work, ignore_errors=True)


CS16_BETA = "steam_legacy"      # ReHLDS only works with the pre-anniversary HLDS build (engine <= 8684), which Steam keeps on this branch


def cs16_steamcmd(path, out):
    """SteamCMD app 90 (mod cstrike), legacy branch, validated. Returns the exit code. `out` is an open file for the output."""
    inner = ("mkdir -p /root/.local/share/Steam/steamapps/common /root/Steam/steamapps/common && "
             "ln -sfn /data /root/.local/share/Steam/steamapps/common/Half-Life && ln -sfn /data /root/Steam/steamapps/common/Half-Life && "
             "/usr/games/steamcmd +force_install_dir /data +login anonymous +app_set_config 90 mod cstrike +app_update 90 -beta " + CS16_BETA + " validate +quit && "
             "/usr/games/steamcmd +force_install_dir /data +login anonymous +app_set_config 90 mod cstrike +app_update 90 -beta " + CS16_BETA + " validate +quit && "
             "if [ -d /data/steamapps/common/Half-Life ]; then cp -rn /data/steamapps/common/Half-Life/* /data/; fi && "
             "chmod +x /data/hlds_run /data/hlds_linux 2>/dev/null || true")
    return subprocess.run(["docker", "run", "--rm", "--user", "0:0", "-e", "HOME=/root", "-v", f"{path}:/data",
                           "-v", "/usr/games:/usr/games", "-v", "/usr/lib/games:/usr/lib/games", IMG_STEAMCMD,
                           "/bin/bash", "-c", inner], stdout=out, stderr=subprocess.STDOUT).returncode


def install_cs16(path, log_file):
    """CS 1.6: legacy game files from SteamCMD (app 90, mod cstrike), then the ReHLDS engine on top."""
    try:
        with open(log_file, "w") as lf:
            def w(msg):
                lf.write(msg + "\n")
                lf.flush()
            w("[Springs] Installing CS 1.6 (ReHLDS, Steam legacy build)...")
            rc = cs16_steamcmd(path, lf)
            if rc != 0 or not os.path.isfile(os.path.join(path, "hlds_run")):
                w("[Springs] FAILED: SteamCMD did not install the CS 1.6 files (see the lines above).")
                return
            try:
                install_rehlds(path, w)
            except (ModError, FileError, OSError) as e:
                w("[Springs] WARNING: ReHLDS was not installed (" + str(e) + "). The server runs on the classic HLDS engine; press Update ReHLDS on the server's console page to retry.")
            w("[Springs] Complete!")
    except Exception as e:
        try:
            with open(log_file, "a") as lf:
                lf.write(f"[Springs] FAILED: {e}\n")
        except Exception:
            pass


_REHLDS_JOBS = {}


def rehlds_job_status(path):
    j = _REHLDS_JOBS.get(os.path.realpath(path))
    return {"job": (dict(j, log=list(j["log"][-12:])) if j else None)}


def rehlds_job(path, cname, full=False):
    """Background job behind the Update ReHLDS button: legacy game files first (an old install on the current
    Steam build cannot run ReHLDS), then the ReHLDS engine."""
    key = os.path.realpath(path)
    job = _REHLDS_JOBS[key] = {"state": "running", "log": [], "error": "", "version": ""}

    ilog = os.path.join(key, "install.log")

    def say(m):
        job["log"].append(m)
        if full:
            try:
                with open(ilog, "a") as f:        # the panel dashboard follows install.log for "installing" -> "offline"
                    f.write(m + "\n")
            except OSError:
                pass
    try:
        with container_lock(cname):
            if container_running(cname):
                raise ModError("Stop the server first, then update ReHLDS.")
            if full:
                job["log"].append("[Springs] Full reinstall: deleting everything in the server folder...")
                for name in os.listdir(key):
                    fp = os.path.join(key, name)
                    if os.path.isdir(fp) and not os.path.islink(fp):
                        shutil.rmtree(fp, ignore_errors=True)
                    else:
                        try:
                            os.remove(fp)
                        except OSError:
                            pass
                say("[Springs] Full reinstall: the server folder was cleared.")
                say("[Springs] Installing CS 1.6 (ReHLDS, Steam legacy build) from scratch (this can take several minutes)...")
            else:
                say("[Springs] Switching the game files to the Steam legacy build (this can take a few minutes)...")
            with open(os.path.join(key, "rehlds-update.log"), "w") as lf:
                rc = cs16_steamcmd(key, lf)
            if rc != 0:
                raise ModError("SteamCMD could not install the legacy game files (see rehlds-update.log in the server folder).")
            res = install_rehlds(key, say)
            job["version"] = res["version"]
        say("[Springs] Complete!")
        job["state"] = "done"
    except (ModError, FileError, OSError) as e:
        job["error"] = str(e)
        say("[Springs] FAILED: " + str(e))
        job["state"] = "error"
    except Exception as e:
        job["error"] = "Unexpected error: " + str(e)[:150]
        say("[Springs] FAILED: " + job["error"])
        job["state"] = "error"


# ---------------------------------------------------------------------------
# Statistics: ask a running game server who is playing (Steam A2S for Source/GoldSrc games,
# Server List Ping for Minecraft). Used by the panel's statistics page and its collector.
# ---------------------------------------------------------------------------
A2S_HEAD = b"\xff\xff\xff\xff"
# game -> ("a2s", port offset from the game port) | ("mc", 0). Games not listed have no query protocol we can use.
QUERY_PORTS = {"cs16": ("a2s", 0), "cs2": ("a2s", 0), "gmod": ("a2s", 0), "tf2": ("a2s", 0), "l4d2": ("a2s", 0),
               "rust": ("a2s", 1), "valheim": ("a2s", 1), "7dtd": ("a2s", 1), "minecraft": ("mc", 0)}
MAX_QUERY_ITEMS = 300


def _cstr(b, i):
    j = b.index(b"\x00", i)
    return b[i:j].decode("utf-8", "replace"), j + 1


def _a2s_recv(sock, goldsrc=False):
    """One A2S answer without the 4-byte header. Reassembles split packets (Source and GoldSource layouts)."""
    parts, total = {}, None
    for _ in range(12):
        pkt, _addr = sock.recvfrom(4096)
        if pkt[:4] == A2S_HEAD:
            return pkt[4:]
        if pkt[:4] != b"\xfe\xff\xff\xff" or len(pkt) < 9:
            raise ValueError("unexpected packet")
        if goldsrc:
            num, tot, body = pkt[8] & 0x0F, pkt[8] >> 4, pkt[9:]
        else:
            tot, num, body = pkt[8], pkt[9], pkt[12:]
        total = tot
        parts[num] = body
        if total and len(parts) >= total:
            whole = b"".join(parts[k] for k in sorted(parts))
            if whole[:4] != A2S_HEAD:
                raise ValueError("bad reassembled packet")
            return whole[4:]
    raise ValueError("incomplete split packet")


def a2s_parse_info(d):
    if d[0] == 0x49:                      # Source: header, protocol, name, map, folder, game, app id(2), players, max, bots
        i = 2
        name, i = _cstr(d, i)
        mp, i = _cstr(d, i)
        folder, i = _cstr(d, i)
        game, i = _cstr(d, i)
        i += 2
        return {"name": name, "map": mp, "folder": folder, "game": game, "players": d[i], "max": d[i + 1], "bots": d[i + 2]}
    if d[0] == 0x6D:                      # old GoldSource: header, address, name, map, folder, game, players, max
        _addr, i = _cstr(d, 1)
        name, i = _cstr(d, i)
        mp, i = _cstr(d, i)
        folder, i = _cstr(d, i)
        game, i = _cstr(d, i)
        return {"name": name, "map": mp, "folder": folder, "game": game, "players": d[i], "max": d[i + 1], "bots": 0}
    raise ValueError("not an info answer")


def a2s_parse_players(d):
    out = []
    n = d[1]
    i = 2
    for _ in range(n):
        i += 1                            # index
        name, i = _cstr(d, i)
        score, dur = struct.unpack("<if", d[i:i + 8])
        i += 8
        out.append({"name": name[:40], "score": score, "time": int(dur) if dur == dur and dur >= 0 else 0})
        if len(out) >= 100:
            break
    return out


def a2s_query(ip, port, goldsrc=False, timeout=2.0, players=True):
    """Returns the info dict (plus player_list) or None when the server does not answer."""
    fam = socket.AF_INET6 if ":" in ip else socket.AF_INET
    s = socket.socket(fam, socket.SOCK_DGRAM)
    s.settimeout(timeout)
    addr = (ip, int(port))
    try:
        req = A2S_HEAD + b"TSource Engine Query\x00"
        s.sendto(req, addr)
        d = _a2s_recv(s, goldsrc)
        if d[0] == 0x41:                  # challenge
            s.sendto(req + d[1:5], addr)
            d = _a2s_recv(s, goldsrc)
        info = a2s_parse_info(d)
    except (OSError, ValueError, IndexError, struct.error):
        s.close()
        return None
    info["player_list"] = []
    if players and info["players"]:
        try:
            s.sendto(A2S_HEAD + b"U\xff\xff\xff\xff", addr)
            d = _a2s_recv(s, goldsrc)
            if d[0] == 0x41:
                s.sendto(A2S_HEAD + b"U" + d[1:5], addr)
                d = _a2s_recv(s, goldsrc)
            if d[0] == 0x44:
                info["player_list"] = a2s_parse_players(d)
        except (OSError, ValueError, IndexError, struct.error):
            pass                          # the counts from the info answer are enough
    s.close()
    return info


def _mc_varint(n):
    out = b""
    while True:
        b = n & 0x7F
        n >>= 7
        out += bytes([b | (0x80 if n else 0)])
        if not n:
            return out


def _mc_text(desc):
    if isinstance(desc, str):
        return desc
    if isinstance(desc, dict):
        return str(desc.get("text", "")) + "".join(_mc_text(e) for e in desc.get("extra", []) or [])
    return ""


def mc_query(host, port, timeout=3.0):
    """Minecraft Java 'Server List Ping'. Returns the same shape as a2s_query or None."""
    try:
        sock = socket.create_connection((host, int(port)), timeout)
    except OSError:
        return None
    try:
        sock.settimeout(timeout)

        def need(n):
            buf = b""
            while len(buf) < n:
                c = sock.recv(n - len(buf))
                if not c:
                    raise ValueError("closed")
                buf += c
            return buf

        def read_varint():
            v = 0
            for i in range(5):
                b = need(1)[0]
                v |= (b & 0x7F) << (7 * i)
                if not b & 0x80:
                    return v
            raise ValueError("bad varint")
        hb = host.encode()
        hs = _mc_varint(0) + _mc_varint(765) + _mc_varint(len(hb)) + hb + struct.pack(">H", int(port)) + _mc_varint(1)
        sock.sendall(_mc_varint(len(hs)) + hs + b"\x01\x00")
        ln = read_varint()
        if not 0 < ln <= 262144:
            raise ValueError("bad length")
        body = need(ln)
        i = 0
        pid = 0
        for k in range(5):
            b = body[i]
            i += 1
            pid |= (b & 0x7F) << (7 * k)
            if not b & 0x80:
                break
        sl = 0
        for k in range(5):
            b = body[i]
            i += 1
            sl |= (b & 0x7F) << (7 * k)
            if not b & 0x80:
                break
        if pid != 0:
            raise ValueError("unexpected packet id")
        j = json.loads(body[i:i + sl].decode("utf-8", "replace"))
        pl = j.get("players") or {}
        sample = [{"name": str(x.get("name", ""))[:40], "score": None, "time": None} for x in (pl.get("sample") or [])[:100] if isinstance(x, dict)]
        return {"name": re.sub("\u00a7.", "", _mc_text(j.get("description")))[:80], "map": None, "folder": "", "game": "minecraft",
                "players": int(pl.get("online", 0)), "max": int(pl.get("max", 0)), "bots": 0, "player_list": sample,
                "version": str((j.get("version") or {}).get("name", ""))[:40]}
    except (OSError, ValueError, IndexError, KeyError, TypeError, json.JSONDecodeError):
        return None
    finally:
        sock.close()


def query_one(item):
    """item: game, screen_name, ip_address, port. Returns supported / running / online and the query result."""
    game = str(item.get("game") or "")
    spec = QUERY_PORTS.get(game)
    if not spec:
        return {"supported": False}
    sn = str(item.get("screen_name") or "")
    if not re.match(r"^[A-Za-z0-9_.-]{1,80}$", sn):
        return {"supported": True, "running": False, "online": False, "error": "invalid screen_name"}
    try:
        ip = str(ipaddress.ip_address(str(item.get("ip_address"))))
        port = int(item.get("port"))
    except (ValueError, TypeError):
        return {"supported": True, "running": False, "online": False, "error": "invalid address"}
    if not 1 <= port <= 65535:
        return {"supported": True, "running": False, "online": False, "error": "invalid port"}
    out = {"supported": True, "running": container_running("springs_" + sn), "online": False}
    if not out["running"]:
        return out
    if ip in ("0.0.0.0", "::"):
        ip = "127.0.0.1"
    kind, off = spec
    q = mc_query(ip, port) if kind == "mc" else a2s_query(ip, port + off, goldsrc=(game == "cs16"))
    if q:
        out.update(q)
        out["online"] = True
    return out


def _to_mb(txt):
    m = re.match(r"^\s*([0-9.]+)\s*([A-Za-z]*)", txt or "")
    if not m:
        return None
    mult = {"b": 1 / 1048576, "kib": 1 / 1024, "kb": 1 / 1000 * 0.9537, "mib": 1, "mb": 0.9537, "gib": 1024, "gb": 953.7, "tib": 1048576}
    return int(float(m.group(1)) * mult.get(m.group(2).lower(), 1))


def container_resources(names):
    """{container name: {"cpu": percent of one core, "mem_mb": MB}} for running containers (one docker call)."""
    names = [n for n in names if re.match(r"^springs_[A-Za-z0-9_.-]{1,80}$", n)]
    if not names:
        return {}
    try:
        p = subprocess.run(["docker", "stats", "--no-stream", "--format", "{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}"] + names,
                           stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=20)
    except (OSError, subprocess.TimeoutExpired):
        return {}
    res = {}
    for line in p.stdout.splitlines():
        parts = line.split("|")
        if len(parts) != 3:
            continue
        try:
            res[parts[0]] = {"cpu": round(float(parts[1].strip().rstrip("%")), 1), "mem_mb": _to_mb(parts[2].split("/")[0])}
        except ValueError:
            continue
    return res


def query_many(items, resources=False):
    items = [i for i in items if isinstance(i, dict)][:MAX_QUERY_ITEMS]
    with ThreadPoolExecutor(max_workers=8) as ex:
        results = list(ex.map(query_one, items))
    out = {}
    for it, r in zip(items, results):
        out[str(it.get("key") or it.get("screen_name"))] = r
    if resources:
        res = container_resources(["springs_" + str(it.get("screen_name")) for it, r in zip(items, results) if r.get("running")])
        for it in items:
            rr = res.get("springs_" + str(it.get("screen_name")))
            if rr:
                out[str(it.get("key") or it.get("screen_name"))].update(rr)
    return out


def mods_install(body):
    base, tdir = file_target({"install_path": body.get("install_path"), "rel_path": str(body.get("target") or "")})
    url = str(body.get("url") or "")
    kind = str(body.get("kind") or "")
    sha = str(body.get("sha256") or "").lower()
    if sha and not re.fullmatch(r"[0-9a-f]{64}", sha):
        raise ModError("Invalid checksum")
    try:
        max_bytes = max(1, min(int(body.get("max_mb") or 250), MAX_MOD_BYTES // 1048576)) * 1048576
    except (TypeError, ValueError):
        max_bytes = MAX_MOD_BYTES
    if kind not in ("file", "archive"):
        raise ModError("Unknown install kind")
    name = ""
    if kind == "file":
        name = str(body.get("filename") or "")
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._ -]{0,99}", name):
            raise ModError("Invalid file name")

    tmp_dir = os.path.join(base, ".springs_tmp")
    os.makedirs(tmp_dir, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=tmp_dir, suffix=".part")
    os.close(fd)
    try:
        size, digest = fetch_to_file(url, tmp, max_bytes)
        if sha and digest != sha:
            raise ModError("The downloaded file does not match its checksum")
        if kind == "file":
            dest = _archive_dest(base, tdir, name)
            os.makedirs(tdir, exist_ok=True)      # folders are only created once everything has checked out
            shutil.move(tmp, dest)
            os.chmod(dest, 0o644)
            files = [os.path.relpath(dest, base)]
        else:
            files = extract_archive(tmp, base, tdir)
        return {"ok": True, "size": size, "sha256": digest, "files": files[:MOD_LIST_LIMIT], "file_count": len(files)}
    finally:
        try:
            os.remove(tmp)
        except OSError:
            pass
        try:
            os.rmdir(tmp_dir)
        except OSError:
            pass


def mods_remove(body):
    base, _t = file_target({"install_path": body.get("install_path"), "rel_path": ""})
    paths = body.get("paths")
    if not isinstance(paths, list) or len(paths) > MOD_LIST_LIMIT:
        raise ModError("Invalid file list")
    removed = 0
    dirs = set()
    for rel in paths:
        rel = str(rel)
        if rel.startswith("/") or ".." in rel.replace("\\", "/").split("/"):
            continue                              # the list always holds paths relative to the server root
        try:
            _b, t = safe_resolve(base, rel)
        except ValueError:
            continue
        if t == base or not (os.path.isfile(t) or os.path.islink(t)):
            continue
        try:
            os.remove(t)
        except OSError:
            continue
        removed += 1
        dirs.add(os.path.dirname(t))
    for d in sorted(dirs, key=len, reverse=True):     # tidy up folders the mod left empty (never the server root)
        cur = d
        while cur != base and cur.startswith(base + os.sep):
            try:
                os.rmdir(cur)
            except OSError:
                break
            cur = os.path.dirname(cur)
    return {"ok": True, "removed": removed}


# ---------------------------------------------------------------------------
# Console: send commands and follow the log live.
#  - rust   -> WebRCON (RustDedicated does not read stdin)
#  - cs16   -> GoldSrc RCON over UDP (password chosen at start), stdin as fallback
#  - others -> the game's stdin (containers run with -i), checked before writing
# Every call returns what really happened, so the panel can show errors.
# ---------------------------------------------------------------------------
CONSOLE_MAX_CMD = 1000
STREAM_MAX_SECONDS = 900
NO_CONSOLE = {
    "valheim": "Valheim has no server console, so commands cannot be sent.",
    "palworld": "Palworld has no console input. Use in-game admin commands (/AdminPassword) instead.",
}


class ConsoleError(Exception):
    pass


def strip_ansi(text):
    return re.sub(r"\x1b\[[0-9;?]*[ -/]*[@-~]", "", text).replace("\r", "")


def docker_exec_stdin(cname, script, text, timeout=10):
    """Run `bash -c script` inside the container with `text` on its stdin. Returns (exit code, output)."""
    try:
        p = subprocess.run(["docker", "exec", "-i", cname, "bash", "-c", script], input=text,
                           stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return 124, "Timed out"
    except OSError as e:
        return 127, str(e)
    return p.returncode, p.stdout.strip()


STDIN_SCRIPT = ('if [ "$(readlink /proc/1/fd/0 2>/dev/null)" = "/dev/null" ]; then echo "stdin closed" >&2; exit 3; fi; '
                'cat > /proc/1/fd/0')


def stdin_send(cname, cmd):
    code, out = docker_exec_stdin(cname, STDIN_SCRIPT, cmd + "\n")
    if code == 0:
        return {"ok": True, "via": "stdin", "reply": ""}
    if code == 3:
        raise ConsoleError("This server was started without console input. Restart it to enable the console.")
    low = out.lower()
    if "no such container" in low or "is not running" in low:
        raise ConsoleError("The server is offline. Start it first.")
    if code == 124:
        raise ConsoleError("The server did not accept the command in time.")
    raise ConsoleError(("Could not send the command: " + out[:150]) if out else "Could not send the command.")


def container_exec_args(cname):
    """Arguments of the game process as it was started (parsed from the container's own start command)."""
    _, out = run_cmd("docker inspect -f '{{json .Config.Cmd}}' " + cname + " 2>/dev/null")
    try:
        parts = json.loads(out)
    except ValueError:
        return []
    text = next((c for c in reversed(parts) if isinstance(c, str) and "exec " in c), "")
    if not text:
        return []
    try:
        return shlex.split(text.rsplit("exec ", 1)[1])
    except ValueError:
        return []


def arg_after(tokens, name):
    for i, t in enumerate(tokens[:-1]):
        if t == name:
            return tokens[i + 1]
    return ""


def _recv_exact(sock, buf, n):
    while len(buf) < n:
        chunk = sock.recv(65536)
        if not chunk:
            raise ConsoleError("The server closed the RCON connection.")
        buf += chunk
    return buf[:n], buf[n:]


def ws_request(host, port, path, message, timeout=6):
    """Minimal WebSocket client for Rust WebRCON: sends one command, returns its reply text."""
    ident = secrets.randbelow(2 ** 30) + 1
    try:
        sock = socket.create_connection((host, port), timeout=timeout)
    except OSError:
        raise ConsoleError("Rust is not accepting RCON connections yet (it may still be starting).")
    try:
        sock.settimeout(timeout)
        key = base64.b64encode(os.urandom(16)).decode()
        sock.sendall(("GET %s HTTP/1.1\r\nHost: %s:%d\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                      "Sec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n" % (path, host, port, key)).encode())
        head = b""
        while b"\r\n\r\n" not in head:
            chunk = sock.recv(4096)
            if not chunk or len(head) > 16384:
                raise ConsoleError("Rust refused the RCON connection.")
            head += chunk
        head, rest = head.split(b"\r\n\r\n", 1)
        accept = base64.b64encode(hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest())
        if not head.startswith(b"HTTP/1.1 101") or accept not in head:
            raise ConsoleError("Rust refused the RCON connection (wrong RCON password?).")
        payload = json.dumps({"Identifier": ident, "Message": message, "Name": "SwiftPanel"}).encode()
        mask = os.urandom(4)
        n = len(payload)
        hdr = bytes([0x81]) + (bytes([0x80 | n]) if n < 126 else bytes([0x80 | 126]) + n.to_bytes(2, "big") if n < 65536 else bytes([0x80 | 127]) + n.to_bytes(8, "big"))
        sock.sendall(hdr + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))
        buf, deadline, text = rest, time.time() + timeout, b""
        while time.time() < deadline:
            h, buf = _recv_exact(sock, buf, 2)
            fin, op, ln = h[0] & 0x80, h[0] & 0x0F, h[1] & 0x7F
            if ln == 126:
                e, buf = _recv_exact(sock, buf, 2); ln = int.from_bytes(e, "big")
            elif ln == 127:
                e, buf = _recv_exact(sock, buf, 8); ln = int.from_bytes(e, "big")
            if ln > 4 * 1024 * 1024:
                raise ConsoleError("The RCON reply is too large.")
            data, buf = _recv_exact(sock, buf, ln)
            if op == 8:
                break
            if op == 9:
                sock.sendall(bytes([0x8A, 0x80]) + b"\0\0\0\0")
                continue
            text += data
            if not fin:
                continue
            try:
                obj = json.loads(text.decode("utf-8", "replace"))
            except ValueError:
                obj = {}
            text = b""
            if isinstance(obj, dict) and obj.get("Identifier") == ident:
                return str(obj.get("Message", ""))
        return ""
    except socket.timeout:
        return ""
    finally:
        try:
            sock.close()
        except OSError:
            pass


def rust_rcon(cname, cmd):
    tokens = container_exec_args(cname)
    port, pw = arg_after(tokens, "+rcon.port"), arg_after(tokens, "+rcon.password")
    if not port.isdigit() or not pw:
        raise ConsoleError("RCON is not configured for this Rust server. Restart it from the panel.")
    from urllib.parse import quote
    reply = ws_request("127.0.0.1", int(port), "/" + quote(pw, safe=""), cmd)
    return {"ok": True, "via": "rcon", "reply": strip_ansi(reply).strip()}


def goldsrc_rcon(host, port, password, cmd, timeout=2.0):
    """GoldSrc (HLDS) RCON over UDP. Returns the server's reply text."""
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.settimeout(timeout)
        s.sendto(b"\xff\xff\xff\xffchallenge rcon\n", (host, port))
        data, _ = s.recvfrom(4096)
        m = re.search(rb"challenge rcon (\d+)", data)
        if not m:
            raise ConsoleError("The server did not answer the RCON challenge.")
        s.sendto(b"\xff\xff\xff\xffrcon " + m.group(1) + b' "' + password.encode() + b'" ' + cmd.encode("utf-8", "replace") + b"\n", (host, port))
        out = b""
        s.settimeout(timeout)
        while True:
            try:
                data, _ = s.recvfrom(65535)
            except socket.timeout:
                break
            data = data[4:] if data[:4] == b"\xff\xff\xff\xff" else data
            if data[:1] in (b"l", b"n"):
                data = data[1:]
            out += data.rstrip(b"\x00")
            s.settimeout(0.5)
        return out.decode("utf-8", "replace")
    except socket.timeout:
        raise ConsoleError("The server did not answer the RCON request.")
    finally:
        s.close()


def cs16_settings(cname):
    _, out = run_cmd("docker inspect -f '{{json .Config.Cmd}}' " + cname + " 2>/dev/null")
    try:
        text = " ".join(str(x) for x in json.loads(out))
    except ValueError:
        return "", "", ""
    def grab(flag, pattern):
        m = re.search(re.escape(flag) + r"\s+(" + pattern + r")", text)
        return m.group(1) if m else ""
    return grab("+rcon_password", r"[0-9A-Za-z]+"), grab("+port", r"\d+"), grab("+ip", r"[0-9.]+")


def pid1_name(cname):
    _, out = run_cmd(f"docker exec {cname} cat /proc/1/comm 2>/dev/null")
    return out.strip()


def cs16_rcon(cname, cmd):
    pw, port, ip = cs16_settings(cname)
    if not pw or not port.isdigit():
        return None            # started by an older agent: fall back to stdin
    ip = ip or "127.0.0.1"
    if ip in ("0.0.0.0", ""):
        ip = "127.0.0.1"
    try:
        reply = goldsrc_rcon(ip, int(port), pw, cmd)
    except (ConsoleError, OSError):
        return None
    return {"ok": True, "via": "rcon", "reply": strip_ansi(reply).strip()}


def console_game(cname, hint=""):
    _, g = run_cmd("docker inspect -f '{{index .Config.Labels \"springs.game\"}}' " + cname + " 2>/dev/null")
    return g.strip() or str(hint or "")


def send_console(cname, game, cmd):
    """Deliver one console command. Returns {"ok", "via", "reply"} or raises ConsoleError."""
    cmd = re.sub(r"[\r\n]+", " ", str(cmd)).strip()
    if not cmd:
        raise ConsoleError("Type a command first.")
    if len(cmd) > CONSOLE_MAX_CMD:
        raise ConsoleError("The command is too long.")
    if game in NO_CONSOLE:
        raise ConsoleError(NO_CONSOLE[game])
    if not container_running(cname):
        raise ConsoleError("The server is offline. Start it first.")
    if game == "rust":
        return rust_rcon(cname, cmd)
    if game == "cs16" and pid1_name(cname) != "script":
        # started without a terminal (older agent): RCON is the only dependable way in
        r = cs16_rcon(cname, cmd)
        if r:
            if not r["reply"]:
                r["note"] = ("This server was started before console support was improved, so plugin output (Metamod, AMX Mod X) "
                             "may not be shown. Restart the server once to fix that.")
            return r
    return stdin_send(cname, cmd)


def console_snapshot(cname, path, lines):
    """Last log lines of a running container, the installer log, or an offline notice."""
    code, out = run_cmd(f"docker logs --tail {lines} {cname} 2>&1")
    if code != 0:
        log_file = os.path.join(str(path), "install.log")
        if path and os.path.exists(log_file):
            _, out = run_cmd(f"tail -n {lines} {log_file}")
            out = "[Springs Installer]\n" + out
        else:
            out = "[Springs] Container offline. Click Start Server to boot."
    return out


def pump_logs(proc, emit, tail, client=None):
    """Forward the output of `docker logs -f` as events: one snapshot first, then lines as they arrive."""
    fd = proc.stdout.fileno()
    buf, snap, snap_done = b"", [], False
    t0 = last_data = last_out = time.time()
    while True:
        now = time.time()
        if now - t0 > STREAM_MAX_SECONDS:
            return "timeout"
        r, _, _ = select.select([fd] + ([client] if client else []), [], [], 0.2)
        eof = False
        if client in r:                       # the viewer closed the page: stop following right away
            try:
                if not client.recv(4096):
                    return "gone"
            except (BlockingIOError, InterruptedError):
                pass
            except OSError:
                return "gone"
        if fd in r:
            chunk = os.read(fd, 65536)
            if not chunk:
                eof = True
            else:
                buf += chunk
                last_data = time.time()
        now = time.time()
        text = ""
        if b"\n" in buf:
            head, _, buf = buf.rpartition(b"\n")
            text = head.decode("utf-8", "replace") + "\n"
        elif buf and (eof or now - last_data > 0.35):
            text, buf = buf.decode("utf-8", "replace"), b""
        text = strip_ansi(text)
        if not snap_done:
            if text:
                snap.append(text)
            if eof or now - last_data > 0.25 or now - t0 > 1.5:
                emit({"t": "snapshot", "log": "".join(snap), "running": True})
                snap_done, last_out = True, now
        elif text:
            emit({"t": "lines", "text": text})
            last_out = now
        if eof:
            return "ended"
        if now - last_out > 10:
            emit({"t": "ping"})
            last_out = now


# ---------------------------------------------------------------------------
# Diagnostics: why does a plugin command (meta, amx_...) print nothing? (CS 1.6 / Metamod)
# ---------------------------------------------------------------------------
METAMOD_LIBS = ("addons/metamod/metamod_i386.so", "addons/metamod/metamod.so", "addons/metamod/dlls/metamod.so")
STOCK_LIBS = ("dlls/cs.so", "dlls/cs_i386.so")
_BADCH = lambda c: (ord(c) > 126 or ord(c) < 32) and c not in "\r\n\t"


def liblist_lines(raw):
    """liblist.gam bytes -> (clean lines, problems that HLDS's strict parser can trip over)."""
    problems = []
    if raw.startswith(b"\xef\xbb\xbf"):
        problems.append("a UTF-8 byte-order mark at the start")
        raw = raw[3:]
    if b"\r" in raw:
        problems.append("Windows line endings (CRLF)")
    if raw and not raw.endswith(b"\n"):
        problems.append("no line break at the end of the file")
    text = raw.decode("utf-8", "replace")
    if any(_BADCH(c) for c in text):
        problems.append("non-text characters")
        text = "".join(c for c in text if not _BADCH(c))
    lines = [l.rstrip() for l in text.replace("\r\n", "\n").replace("\r", "\n").split("\n")]
    while lines and lines[-1] == "":
        lines.pop()
    return lines, problems


def gamedll_values(lines):
    out = []
    for l in lines:
        m = re.match(r'^\s*gamedll_linux\s+"([^"]*)"\s*$', l, re.I)
        if m:
            out.append(m.group(1).strip())
    return out


def missing_deps(path, rel):
    """Libraries a 32-bit .so needs that the game image does not have (None = could not check)."""
    try:
        p = subprocess.run(["docker", "run", "--rm", "--user", "0:0", "-v", f"{path}:/data", "--entrypoint", "/bin/bash", IMG_SOURCE,
                            "-c", 'ldd "$0" 2>&1', "/data/" + rel], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=60)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if "not a dynamic executable" in p.stdout or (p.returncode != 0 and "not found" not in p.stdout):
        return None
    return re.findall(r"^\s*(\S+)\s+=>\s+not found", p.stdout, re.M)


def elf_problem(head):
    """Why this file cannot be loaded by 32-bit HLDS ('' = fine)."""
    if head[:4] != b"\x7fELF":
        return "is not a Linux library (wrong file, or a Windows .dll renamed)"
    if head[4] == 2 or (len(head) >= 20 and head[18:20] == b"\x3e\x00"):
        return "is a 64-bit build. HLDS is 32-bit and cannot load it: use the i386 (32-bit) build"
    return ""


def diagnose_cs16(cname, path):
    findings = []

    def add(level, text):
        findings.append({"level": level, "text": text})
    base, mod = file_target({"install_path": path, "rel_path": "cstrike"})
    if not os.path.isdir(mod):
        add("error", "The cstrike folder was not found: the game files are not installed (check install.log).")
        return findings

    st = rehlds_status(base)
    if st["installed"]:
        add("ok", "The server runs the ReHLDS engine%s." % ((" " + st["version"]) if st["version"] else ""))
    elif st["replaced"]:
        add("warn", "ReHLDS was installed once, but engine_i486.so is the classic one again (a SteamCMD validate or a reinstall overwrote it). Stop the server and press Update ReHLDS.")
    else:
        add("warn", "This server runs the classic HLDS engine, not ReHLDS. Stop the server and press Update ReHLDS to switch.")

    def inside(rel):
        t = os.path.realpath(os.path.join(mod, str(rel).replace("\\", "/").lstrip("/")))
        return t if (t == base or t.startswith(base + os.sep)) else None

    # --- liblist.gam: which game library does HLDS load, and is the file well-formed? ---
    lib = os.path.join(mod, "liblist.gam")
    dll, values = "", []
    try:
        with open(lib, "rb") as f:
            raw = f.read(65536)
        lines, problems = liblist_lines(raw)
        values = gamedll_values(lines)
        dll = values[0] if values else ""
        if problems:
            add("error", "liblist.gam has %s. HLDS reads this file with a strict parser and may fail to start or print garbage. Press Repair liblist.gam to rewrite it cleanly (a backup is kept)." % ", ".join(problems))
        if len(values) > 1:
            add("warn", "liblist.gam has %d gamedll_linux lines; only one should exist (Repair liblist.gam fixes this)." % len(values))
    except OSError:
        add("error", "cstrike/liblist.gam could not be read.")
    found = [rel for rel in METAMOD_LIBS if os.path.isfile(os.path.join(mod, rel))]
    stock = [rel for rel in STOCK_LIBS if os.path.isfile(os.path.join(mod, rel))]
    if dll and "metamod" in dll.lower():
        add("ok", 'liblist.gam loads Metamod: gamedll_linux "%s"' % dll)
        target = inside(dll)
        if target is None:
            add("error", "The Metamod path in liblist.gam points outside the server folder.")
        elif not os.path.isfile(target):
            add("error", "liblist.gam points to %s but that file does not exist. Upload the Metamod library there%s." % (dll, (" (found at %s)" % found[0]) if found else ""))
        else:
            try:
                with open(target, "rb") as f:
                    head = f.read(20)
            except OSError:
                head = b""
            why = elf_problem(head)
            if why:
                add("error", "%s %s." % (dll, why))
            else:
                add("ok", "The Metamod library is a valid 32-bit Linux library.")
                miss = missing_deps(path, "cstrike/" + os.path.relpath(target, mod).replace(os.sep, "/"))
                if miss:
                    add("error", "The Metamod library needs libraries that this server image does not have: %s. HLDS reports this as 'LoadLibrary failed ... No such file or directory'. "
                                 "Use a Metamod build for this system, or Repair liblist.gam to go back to the stock library." % ", ".join(miss))
                elif miss is None:
                    add("info", "The library dependency check could not be run on this node.")
                else:
                    add("ok", "Every library Metamod depends on is available.")
        if not stock:
            add("error", "The original game library (cstrike/dlls/cs.so) is missing, and Metamod needs it. Reinstall the server files.")
    elif dll:
        hint = (" The Metamod files ARE there (%s), liblist.gam just does not use them." % found[0]) if found else " (No Metamod files were found in cstrike/addons/metamod either: upload Metamod first.)"
        add("error", 'liblist.gam still loads the stock game library ("%s"), so Metamod is NOT loaded and the commands meta / amx_... do not exist.%s '
                     'Repair liblist.gam switches it to Metamod when the library is usable, or set the line to:  gamedll_linux "addons/metamod/metamod_i386.so"  and restart the server.' % (dll, hint))
    elif not any(f_["level"] == "error" for f_ in findings):
        add("error", "liblist.gam has no gamedll_linux line (Repair liblist.gam adds it).")

    # --- plugins.ini: which plugins should Metamod load? ---
    ini = os.path.join(mod, "addons", "metamod", "plugins.ini")
    if "metamod" in dll.lower():
        try:
            with open(ini, "r", errors="replace") as f:
                ilines = [l.strip() for l in f.read(65536).splitlines()]
            active = [l for l in ilines if l and not l.startswith((";", "//", "#"))]
            linux = [l.split(None, 1)[1].strip() for l in active if l.lower().startswith("linux") and len(l.split(None, 1)) == 2]
            if not linux:
                add("warn", "addons/metamod/plugins.ini has no active 'linux ...' lines, so Metamod loads no plugins (AMX Mod X needs: linux addons/amxmodx/dlls/amxmodx_mm_i386.so).")
            else:
                missing = [x for x in linux if not (inside("addons/metamod/" + x) and os.path.isfile(inside("addons/metamod/" + x))) and not (inside(x) and os.path.isfile(inside(x)))]
                add("ok", "plugins.ini lists %d plugin(s)." % len(linux))
                for x in missing:
                    add("error", "plugins.ini lists %s but the file does not exist." % x)
                if not any("amxmodx" in x.lower() for x in linux):
                    add("info", "AMX Mod X is not listed in plugins.ini (amx_ commands need it).")
        except OSError:
            add("warn", "addons/metamod/plugins.ini was not found: Metamod loads no plugins.")

    # --- the boot log: did the last start load the game library? did Metamod say hello? ---
    code, log = run_cmd(f"docker logs --tail 1500 {cname} 2>&1")
    if code != 0:
        add("info", "The server has not run yet, so there is no boot log to check.")
    else:
        if re.search(r"LoadLibrary failed|Couldn't get DLL API", log):
            add("error", "The last start failed while loading the game library ('LoadLibrary failed' / 'Couldn't get DLL API'). The file name printed in that message is garbled by the engine; "
                         "the real cause is a missing file or a missing library the file depends on (see the other findings). Repair liblist.gam switches back to a working library.")
        elif re.search(r"\[META\]|Metamod", log):
            add("ok", "Metamod printed its start-up lines in the boot log: it is loaded.")
        elif dll and "metamod" in dll.lower():
            add("warn", "Metamod is configured but the log contains no Metamod lines. Restart the server and run this check again.")
    if not any(f_["level"] in ("error", "warn") for f_ in findings):
        add("ok", "Everything looks healthy. If a plugin command still prints nothing, check that command's own name and the plugin's log.")
    return findings


def repair_cs16(cname, path):
    """Rewrite cstrike/liblist.gam cleanly and pick a game library that can really be loaded."""
    base, mod = file_target({"install_path": path, "rel_path": "cstrike"})
    lib = os.path.join(mod, "liblist.gam")
    if not os.path.isfile(lib):
        raise FileError("cstrike/liblist.gam was not found: the game files are not installed.")
    with open(lib, "rb") as f:
        raw = f.read(262144)
    lines, problems = liblist_lines(raw)
    changes, reasons = [], []
    bak = lib + ".springs-backup"
    if not os.path.exists(bak):
        shutil.copy2(lib, bak)
        changes.append("Saved the original file as liblist.gam.springs-backup")
    values = gamedll_values(lines)
    configured = next((v.replace("\\", "/") for v in values if "metamod" in v.lower()), "")
    candidates = []
    for rel in ([configured] if configured else []) + list(METAMOD_LIBS):
        if rel and rel not in candidates:
            candidates.append(rel)
    chosen = None
    for rel in candidates:
        t = os.path.realpath(os.path.join(mod, rel.lstrip("/")))
        if not (t == base or t.startswith(base + os.sep)) or not os.path.isfile(t):
            continue
        try:
            with open(t, "rb") as f:
                head = f.read(20)
        except OSError:
            continue
        why = elf_problem(head)
        if why:
            reasons.append("%s %s" % (rel, why))
            continue
        miss = missing_deps(path, "cstrike/" + rel)
        if miss:
            reasons.append("%s needs libraries this server image does not have: %s" % (rel, ", ".join(miss)))
            continue
        chosen = rel
        break
    stock = next((x for x in STOCK_LIBS if os.path.isfile(os.path.join(mod, x))), None)
    if chosen:
        value, using = chosen, "metamod"
    else:
        if not stock:
            raise FileError("Neither a usable Metamod library nor the stock game library (cstrike/dlls/cs.so) was found.")
        value, using = stock, "stock"
        if not any(os.path.isfile(os.path.join(mod, r_)) for r_ in METAMOD_LIBS):
            reasons.append("Metamod was not found in cstrike/addons/metamod")
    out, placed = [], False
    for l in lines:
        if re.match(r"^\s*gamedll_linux\b", l, re.I):
            if not placed:
                out.append('gamedll_linux "%s"' % value)
                placed = True
            continue
        out.append(l)
    if not placed:
        idx = next((i for i, l in enumerate(out) if re.match(r"^\s*gamedll\b", l, re.I)), None)
        (out.insert(idx + 1, 'gamedll_linux "%s"' % value) if idx is not None else out.append('gamedll_linux "%s"' % value))
    tmp = lib + ".tmp"
    with open(tmp, "wb") as f:
        f.write(("\n".join(out) + "\n").encode("ascii", "replace"))
    os.replace(tmp, lib)
    if problems:
        changes.append("Fixed: " + ", ".join(problems))
    if len(values) > 1:
        changes.append("Removed duplicate gamedll_linux lines")
    changes.append('gamedll_linux is now "%s" (%s library)' % (value, "Metamod" if using == "metamod" else "stock"))
    return {"ok": True, "using": using, "value": value, "changes": changes, "reasons": reasons}


# ---------------------------------------------------------------------------------------------- server backups
# Backups are .tar.gz files kept OUTSIDE the server folder (so a reinstall or restore never deletes them):
#   /var/lib/springs/backups/<screen_name>/bk_<UTC date>-<UTC time>_<manual|auto>.tar.gz
BACKUP_ROOT = os.environ.get("SPRINGS_BACKUP_DIR", "/var/lib/springs/backups")
BACKUP_NAME_RE = re.compile(r"^bk_(\d{8})-(\d{6})_(manual|auto)\.tar\.gz$")
BACKUP_MIN_FREE = 1024 ** 3
backup_jobs = {}
backup_jobs_lock = threading.Lock()


class BackupError(Exception):
    pass


def backup_dir(screen):
    screen = str(screen or "")
    if not re.match(r"^[A-Za-z0-9_.-]{1,80}$", screen) or screen in (".", ".."):
        raise BackupError("Invalid server name")
    return os.path.join(BACKUP_ROOT, screen)


def backup_server_root(install_path):
    root = str(install_path or "")
    if not root:
        raise BackupError("Missing install_path")
    base = os.path.realpath(root)
    if base in PROTECTED_DIRS or base.count("/") < 3:
        raise BackupError("Refusing to use a protected directory")
    if not os.path.isdir(base):
        raise BackupError("The server folder does not exist yet (the install may still be running)")
    return base


def backup_list(screen):
    d = backup_dir(screen)
    items, total = [], 0
    job = backup_jobs.get(screen) or {}
    running = job.get("state") == "running"
    if os.path.isdir(d):
        for n in sorted(os.listdir(d)):
            p = os.path.join(d, n)
            if n.endswith(".part"):
                if running:
                    items.append({"id": n[:-5], "kind": "auto" if "_auto" in n else "manual", "size": os.path.getsize(p),
                                  "ts": int(os.path.getmtime(p)), "running": True})
                else:
                    try:
                        os.remove(p)                # leftover of an interrupted backup
                    except OSError:
                        pass
                continue
            m = BACKUP_NAME_RE.match(n)
            if not m or not os.path.isfile(p):
                continue
            try:
                ts = int(calendar.timegm(time.strptime(m.group(1) + m.group(2), "%Y%m%d%H%M%S")))
            except ValueError:
                ts = int(os.path.getmtime(p))
            size = os.path.getsize(p)
            total += size
            items.append({"id": n, "kind": m.group(3), "size": size, "ts": ts, "running": False})
    items.sort(key=lambda i: i["ts"], reverse=True)
    free = 0
    try:
        os.makedirs(BACKUP_ROOT, exist_ok=True)
        free = shutil.disk_usage(BACKUP_ROOT).free
    except OSError:
        pass
    return {"ok": True, "items": items, "total_size": total, "disk_free": free, "job": dict(job) if job else None}


def _backup_job_set(screen, **kw):
    with backup_jobs_lock:
        cur = backup_jobs.get(screen) or {}
        cur.update(kw)
        backup_jobs[screen] = cur


def _backup_worker(screen, base, part, final, max_bytes):
    err = ""
    try:
        cmd = ["nice", "-n", "19", "ionice", "-c", "3", "tar", "--warning=no-file-changed", "-czf", part, "-C", base, "."]
        r = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=4 * 3600)
        if r.returncode not in (0, 1):      # 1 = "a file changed while it was read", normal for a running game
            err = (r.stderr or b"").decode("utf-8", "replace").strip().splitlines()[-1:] or ["tar failed"]
            err = err[0][:200]
        else:
            os.replace(part, final)
            if max_bytes:
                total = backup_list_total(screen)
                if total > max_bytes:
                    os.remove(final)
                    err = "The backup would exceed the storage limit for this server, so it was discarded."
    except subprocess.TimeoutExpired:
        err = "The backup took too long and was stopped."
    except Exception as e:
        err = str(e)[:200]
    if err:
        for p in (part, final):
            try:
                if os.path.exists(p):
                    os.remove(p)
            except OSError:
                pass
        _backup_job_set(screen, state="failed", error=err, finished=int(time.time()))
    else:
        _backup_job_set(screen, state="done", error="", finished=int(time.time()))


def backup_list_total(screen):
    d = backup_dir(screen)
    total = 0
    if os.path.isdir(d):
        for n in os.listdir(d):
            if BACKUP_NAME_RE.match(n):
                total += os.path.getsize(os.path.join(d, n))
    return total


def backup_create(body):
    screen = str(body.get("screen_name") or "")
    d = backup_dir(screen)
    base = backup_server_root(body.get("install_path"))
    kind = "auto" if body.get("kind") == "auto" else "manual"
    if (backup_jobs.get(screen) or {}).get("state") == "running":
        raise BackupError("A backup or restore is already running for this server")
    max_count = int(body.get("max_count") or 0)
    max_bytes = int(body.get("max_bytes") or 0)
    cur = backup_list(screen)
    if max_count and len([i for i in cur["items"] if not i["running"]]) >= max_count:
        raise BackupError("The backup limit for this server is reached. Delete an old backup first.")
    if max_bytes and cur["total_size"] >= max_bytes:
        raise BackupError("The storage limit for backups of this server is reached. Delete an old backup first.")
    os.makedirs(d, exist_ok=True)
    if shutil.disk_usage(d).free < BACKUP_MIN_FREE:
        raise BackupError("The node is low on disk space, the backup was not started")
    name = "bk_%s_%s.tar.gz" % (time.strftime("%Y%m%d-%H%M%S", time.gmtime()), kind)
    final = os.path.join(d, name)
    if os.path.exists(final):
        raise BackupError("A backup with this timestamp already exists, try again in a moment")
    part = final + ".part"
    _backup_job_set(screen, state="running", kind="backup", id=name, started=int(time.time()), error="")
    threading.Thread(target=_backup_worker, args=(screen, base, part, final, max_bytes), daemon=True).start()
    return {"ok": True, "id": name}


def backup_delete(body):
    screen = str(body.get("screen_name") or "")
    d = backup_dir(screen)
    bid = str(body.get("id") or "")
    if not BACKUP_NAME_RE.match(bid):
        raise BackupError("Invalid backup id")
    if (backup_jobs.get(screen) or {}).get("state") == "running":
        raise BackupError("A backup or restore is running, try again when it has finished")
    p = os.path.join(d, bid)
    if not os.path.isfile(p):
        raise BackupError("Backup not found")
    os.remove(p)
    return {"ok": True}


def _restore_worker(screen, base, archive):
    err = ""
    try:
        r = subprocess.run(["tar", "-tzf", archive], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=3600)
        if r.returncode != 0:
            raise BackupError("The backup file is damaged, nothing was changed")
        for n in os.listdir(base):
            p = os.path.join(base, n)
            if os.path.isdir(p) and not os.path.islink(p):
                shutil.rmtree(p, ignore_errors=True)
            else:
                try:
                    os.remove(p)
                except OSError:
                    pass
        r = subprocess.run(["tar", "-xzf", archive, "-C", base, "--no-same-owner" if os.geteuid() != 0 else "--same-owner"],
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=4 * 3600)
        if r.returncode not in (0, 1):
            err = ((r.stderr or b"").decode("utf-8", "replace").strip().splitlines() or ["extract failed"])[-1][:200]
    except BackupError as e:
        err = str(e)
    except Exception as e:
        err = str(e)[:200]
    _backup_job_set(screen, state="failed" if err else "done", error=err, finished=int(time.time()))


def backup_restore(body):
    screen = str(body.get("screen_name") or "")
    d = backup_dir(screen)
    base = backup_server_root(body.get("install_path"))
    bid = str(body.get("id") or "")
    if not BACKUP_NAME_RE.match(bid):
        raise BackupError("Invalid backup id")
    archive = os.path.join(d, bid)
    if not os.path.isfile(archive):
        raise BackupError("Backup not found")
    if (backup_jobs.get(screen) or {}).get("state") == "running":
        raise BackupError("A backup or restore is already running for this server")
    if container_running("springs_" + screen):
        raise BackupError("Stop the server before restoring a backup")
    _backup_job_set(screen, state="running", kind="restore", id=bid, started=int(time.time()), error="")
    threading.Thread(target=_restore_worker, args=(screen, base, archive), daemon=True).start()
    return {"ok": True}


def backup_purge(screen):
    """Removes every backup of a server (used when the server is deleted)."""
    d = backup_dir(screen)
    if os.path.isdir(d):
        shutil.rmtree(d, ignore_errors=True)
    backup_jobs.pop(screen, None)
    return {"ok": True}


def get_stats():
    def read_cpu():
        with open("/proc/stat") as f:
            vals = list(map(float, f.readline().split()[1:8]))
        return sum(vals), vals[3] + vals[4]
    def read_net():
        rx = tx = 0
        with open("/proc/net/dev") as f:
            for line in f.readlines()[2:]:
                if ":" in line:
                    iface, data = line.split(":", 1)
                    if iface.strip() != "lo":
                        cols = data.split()
                        rx += int(cols[0])
                        tx += int(cols[8])
        return rx, tx
    t1, i1 = read_cpu(); rx1, tx1 = read_net()
    time.sleep(0.3)
    t2, i2 = read_cpu(); rx2, tx2 = read_net()
    cpu = round(100.0 * (1.0 - (i2 - i1) / max(t2 - t1, 1)), 1)
    mem = {}
    with open("/proc/meminfo") as f:
        for l in f:
            k, v = l.split(":")
            mem[k] = int(v.split()[0]) // 1024
    ram_tot = mem.get("MemTotal", 1)
    ram_used = ram_tot - mem.get("MemAvailable", mem.get("MemFree", 0))
    disk = shutil.disk_usage("/")
    return {
        "cpu": cpu,
        "ram_used": ram_used,
        "ram_total": ram_tot,
        "disk_used": round(disk.used / 1073741824, 1),
        "disk_total": round(disk.total / 1073741824, 1),
        "net_rx_kbps": round(((rx2 - rx1) / 0.3) / 1024, 1),
        "net_tx_kbps": round(((tx2 - tx1) / 0.3) / 1024, 1)
    }
class SpringsHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        pass

    def send_json(self, code, payload):
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(payload).encode())

    def check_auth(self):
        return self.headers.get("Authorization", "") == f"Bearer {API_TOKEN}"

    def handle_files(self, body):
        action = self.path[len("/api/files/"):]
        try:
            base, target = file_target(body)

            if action == "list":
                if not os.path.isdir(target):
                    raise FileError("Not a directory")
                items = []
                with os.scandir(target) as it:
                    for e in it:
                        try:
                            st = e.stat()
                            is_dir = e.is_dir()
                        except OSError:
                            continue
                        items.append({"name": e.name, "is_dir": is_dir,
                                      "size": 0 if is_dir else st.st_size, "mtime": int(st.st_mtime)})
                        if len(items) >= MAX_LIST_ITEMS:
                            break
                items.sort(key=lambda i: (not i["is_dir"], i["name"].lower()))
                return self.send_json(200, {"ok": True, "items": items})

            if action == "read":
                if not os.path.isfile(target):
                    raise FileError("File not found")
                if os.path.getsize(target) > MAX_EDIT_BYTES:
                    raise FileError("File is too large to edit in the browser (max 2 MB)")
                with open(target, "rb") as f:
                    data = f.read()
                if b"\0" in data:
                    raise FileError("Binary files cannot be edited in the browser")
                return self.send_json(200, {"ok": True, "content": data.decode("utf-8", errors="replace")})

            if action == "write":
                if target == base or os.path.isdir(target):
                    raise FileError("Invalid file path")
                if body.get("create_only") and os.path.exists(target):
                    raise FileError("A file with that name already exists")
                data = str(body.get("content", "")).encode("utf-8")
                if len(data) > MAX_WRITE_BYTES:
                    raise FileError("Content too large (max 8 MB)")
                os.makedirs(os.path.dirname(target), exist_ok=True)
                with open(target, "wb") as f:
                    f.write(data)
                return self.send_json(200, {"ok": True})

            if action == "mkdir":
                if os.path.exists(target) and not os.path.isdir(target):
                    raise FileError("A file with that name already exists")
                os.makedirs(target, exist_ok=True)
                return self.send_json(200, {"ok": True})

            if action == "upload":
                if target == base or os.path.isdir(target):
                    raise FileError("Invalid file path")
                raw = base64.b64decode(str(body.get("content_b64", "")))
                if len(raw) > MAX_UPLOAD_BYTES:
                    raise FileError("Upload too large (max 200 MB)")
                os.makedirs(os.path.dirname(target), exist_ok=True)
                with open(target, "wb") as f:
                    f.write(raw)
                return self.send_json(200, {"ok": True})

            if action == "extract":
                if not os.path.isfile(target) or not zipfile.is_zipfile(target):
                    raise FileError("Not a valid zip file")
                rel_dir = os.path.relpath(os.path.dirname(target), base)
                rel_dir = "" if rel_dir == "." else rel_dir
                with zipfile.ZipFile(target) as z:
                    infos = z.infolist()
                    if len(infos) > MAX_UNZIP_FILES:
                        raise FileError("Zip contains too many files")
                    if sum(i.file_size for i in infos) > MAX_UNZIP_BYTES:
                        raise FileError("Zip is too large when extracted (max 2 GB)")
                    plan = []
                    for info in infos:  # validate every path before writing anything
                        try:
                            plan.append((info, safe_resolve(base, os.path.join(rel_dir, info.filename))[1]))
                        except ValueError:
                            raise FileError("Zip contains an unsafe path: " + info.filename)
                    written = 0
                    for info, dest in plan:
                        if info.is_dir():
                            os.makedirs(dest, exist_ok=True)
                            continue
                        os.makedirs(os.path.dirname(dest), exist_ok=True)
                        with z.open(info) as src_f, open(dest, "wb") as out_f:
                            while True:
                                chunk = src_f.read(1024 * 1024)
                                if not chunk:
                                    break
                                written += len(chunk)
                                if written > MAX_UNZIP_BYTES:
                                    raise FileError("Zip is too large when extracted (max 2 GB)")
                                out_f.write(chunk)
                        if (info.external_attr >> 16) & 0o111:
                            os.chmod(dest, 0o755)
                return self.send_json(200, {"ok": True})

            if action == "delete":
                if target == base:
                    raise FileError("Cannot delete the server root folder")
                if os.path.isdir(target):
                    shutil.rmtree(target)
                elif os.path.exists(target):
                    os.remove(target)
                else:
                    raise FileError("Not found")
                return self.send_json(200, {"ok": True})

            return self.send_json(404, {"ok": False, "error": "Unknown file action"})
        except FileError as e:
            return self.send_json(400, {"ok": False, "error": str(e)})
        except Exception as e:
            return self.send_json(500, {"ok": False, "error": f"File operation failed: {e}"})

    def stream_logs(self, body):
        cname = f"springs_{body.get('screen_name')}"
        try:
            tail = max(1, min(int(body.get("tail", 200)), 2000))
        except (TypeError, ValueError):
            tail = 200
        self.send_response(200)
        self.send_header("Content-Type", "application/x-ndjson")
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()

        def emit(obj):
            self.wfile.write((json.dumps(obj) + "\n").encode())
            self.wfile.flush()
        proc = None
        try:
            if not container_running(cname):
                text = strip_ansi(console_snapshot(cname, body.get("install_path"), tail))
                emit({"t": "snapshot", "log": text + ("\n" if text and not text.endswith("\n") else ""), "running": False})
                return
            proc = subprocess.Popen(["docker", "logs", "--tail", str(tail), "-f", cname], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            if pump_logs(proc, emit, tail, self.connection) == "ended":
                emit({"t": "status", "running": container_running(cname)})
        except (BrokenPipeError, ConnectionResetError, OSError):
            pass                     # the viewer went away
        finally:
            if proc is not None:
                try:
                    proc.kill()
                    proc.wait(timeout=5)
                except (OSError, subprocess.TimeoutExpired):
                    pass

    def handle_mods(self, body):
        try:
            with container_lock("mods:" + str(body.get("install_path"))):
                if self.path == "/api/mods/install":
                    return self.send_json(200, mods_install(body))
                if self.path == "/api/mods/remove":
                    return self.send_json(200, mods_remove(body))
            return self.send_json(404, {"ok": False, "error": "Unknown mods action"})
        except (ModError, FileError) as e:
            return self.send_json(400, {"ok": False, "error": str(e)})
        except Exception as e:
            return self.send_json(500, {"ok": False, "error": "Mod operation failed: " + str(e)[:150]})

    def start_game(self, game, cname, body):
        spec = GAMES[game]
        path = body.get("install_path")
        log_file = os.path.join(str(path), "install.log")

        def fail(code, msg):
            try:
                with open(log_file, "a") as lf:
                    lf.write("[Springs] START FAILED: " + msg + "\n")
            except OSError:
                pass
            return self.send_json(code, {"status": "error", "error": msg})

        try:
            port = int(body.get("port"))
            slots = int(body.get("max_players") or 16)
            ram = int(body.get("ram_mb") or 2048)
        except (TypeError, ValueError):
            return self.send_json(400, {"error": "Invalid port, max_players or ram_mb"})
        if not (1024 <= port <= 65531):
            return self.send_json(400, {"error": "Invalid port"})
        if not (1 <= slots <= 1000) or not (256 <= ram <= 262144):
            return self.send_json(400, {"error": "Invalid max_players or ram_mb"})
        world = str(body.get("start_map_or_world") or "")
        if world and not re.match(r"^[A-Za-z0-9 _.-]{1,64}$", world):
            return self.send_json(400, {"error": "Invalid map or world name"})
        if spec["kind"] in ("srcds", "cs2"):
            try:
                ipaddress.ip_address(str(body.get("ip_address")))
            except ValueError:
                return self.send_json(400, {"error": "Invalid ip_address"})
        if not path or not os.path.isdir(path):
            return self.send_json(400, {"error": "Server folder does not exist"})
        if not os.path.exists(os.path.join(path, spec["check"])):
            return fail(409, "Server files are not installed yet (" + spec["check"] + " is missing). Check install.log")

        seed_settings(game, path)
        cfg = read_settings(path)
        name = (cfg.get("SERVER_NAME") or str(body.get("server_name") or "") or str(body.get("screen_name") or "Springs Server"))[:60]
        if spec["kind"] == "zomboid":
            prepare_zomboid(path, body, cfg, name)
        if spec["kind"] == "valheim" and len(cfg.get("SERVER_PASSWORD", "")) < 5:
            return fail(400, "Valheim needs SERVER_PASSWORD with at least 5 characters in springs.env")

        inner = build_game_command(game, body, cfg)
        run_cmd(f"docker rm -f {cname} >/dev/null 2>&1 || true")
        proc = subprocess.run(
            ["docker", "run", "-d", "-i", "--user", "0:0", "--name", cname, "--network", "host",
             "--memory", f"{ram}m", "-e", "HOME=/data", "-v", f"{path}:/data", "-w", "/data",
             "--label", f"springs.game={game}", "--entrypoint", "/bin/bash", spec["image"], "-c", inner],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        out = proc.stdout.strip()
        if proc.returncode != 0:
            return fail(500, "docker run failed: " + out[-300:])
        return self.send_json(200, {"status": "started", "output": out})

    def start_server(self, body, cname):
        game = body.get("game")
        if game in GAMES:
            return self.start_game(game, cname, body)
        game = body.get("game")
        path = body.get("install_path")
        ip = body.get("ip_address")
        port = int(body.get("port"))
        slots = int(body.get("max_players"))
        start_map = body.get("start_map_or_world")
        ram = int(body.get("ram_mb", 1024))

        if game == "cs16":
            try:
                ipaddress.ip_address(str(ip))
            except ValueError:
                return self.send_json(400, {"error": "Invalid ip_address"})
            if not re.match(r"^[A-Za-z0-9_.-]{1,64}$", str(start_map)):
                return self.send_json(400, {"error": "Invalid start map"})

        run_cmd(f"docker rm -f {cname} >/dev/null 2>&1 || true")
        rcon_pw = secrets.token_hex(8)      # only the agent needs it (read back from the container's start command)
        if game == "cs16":
            # HLDS is a terminal program: without a TTY its output is buffered and console input is unreliable.
            # `script` gives it a pseudo-terminal; commands arrive through the container's stdin pipe (docker -i).
            hl_args = f"-game cstrike +ip {ip} +port {port} +maxplayers {slots} +map {start_map} -console +rcon_password {rcon_pw}"
            dcmd = (f"docker run -d -i --user 0:0 --name {cname} --label springs.game=cs16 --network host --memory={ram}m -v {path}:/data -w /data --entrypoint /bin/bash ghcr.io/pterodactyl/games:source -c "
                    f"\"echo 10 > /data/steam_appid.txt && mkdir -p ~/.steam/sdk32 && ln -sf /data/steamclient.so ~/.steam/sdk32/steamclient.so && export LD_LIBRARY_PATH=.:\\$LD_LIBRARY_PATH && "
                    f"if command -v script >/dev/null 2>&1; then exec script -qfc 'stty -echo; exec ./hlds_run {hl_args}' /dev/null; else exec ./hlds_run {hl_args}; fi\"")
        elif game == "minecraft":
            dcmd = f"docker run -d -i --name {cname} --label springs.game=minecraft --network host --memory={ram}m -v {path}:/data -w /data --entrypoint= {JAVA_IMAGE} java -Xms512M -Xmx{ram}M -jar server.jar nogui"
        else:
            return self.send_json(400, {"error": "Unsupported game"})
        code, out = run_cmd(dcmd)
        return self.send_json(200, {"status": "started", "output": out})

    def handle_backups(self, body):
        action = self.path[len("/api/backups/"):]
        try:
            if action == "list":
                return self.send_json(200, backup_list(str(body.get("screen_name") or "")))
            if action == "create":
                return self.send_json(200, backup_create(body))
            if action == "delete":
                return self.send_json(200, backup_delete(body))
            if action == "restore":
                return self.send_json(200, backup_restore(body))
            if action == "purge":
                return self.send_json(200, backup_purge(str(body.get("screen_name") or "")))
            return self.send_json(404, {"ok": False, "error": "Unknown backup action"})
        except BackupError as e:
            return self.send_json(200, {"ok": False, "error": str(e)})
        except Exception as e:
            return self.send_json(200, {"ok": False, "error": "Backup error: " + str(e)[:200]})

    def stream_backup(self):
        q = parse_qs(urlsplit(self.path).query)
        try:
            d = backup_dir((q.get("screen_name") or [""])[0])
            bid = (q.get("id") or [""])[0]
            if not BACKUP_NAME_RE.match(bid):
                raise BackupError("Invalid backup id")
            p = os.path.join(d, bid)
            if not os.path.isfile(p):
                raise BackupError("Backup not found")
        except BackupError as e:
            return self.send_json(404, {"ok": False, "error": str(e)})
        size = os.path.getsize(p)
        self.send_response(200)
        self.send_header("Content-Type", "application/gzip")
        self.send_header("Content-Length", str(size))
        self.end_headers()
        try:
            with open(p, "rb") as f:
                while True:
                    chunk = f.read(1024 * 1024)
                    if not chunk:
                        break
                    self.wfile.write(chunk)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def do_GET(self):
        if not self.check_auth():
            return self.send_json(401, {"error": "Unauthorized"})
        if self.path == "/api/stats":
            return self.send_json(200, {"status": "ok", "stats": get_stats()})
        if self.path.startswith("/api/backups/download"):
            return self.stream_backup()
        self.send_json(404, {"error": "Not found"})

    def do_POST(self):
        if not self.check_auth():
            return self.send_json(401, {"error": "Unauthorized"})
        length = int(self.headers.get("Content-Length", 0))
        if length > MAX_BODY_BYTES:
            return self.send_json(413, {"error": "Request too large"})
        body = json.loads(self.rfile.read(length) or "{}")

        # Values below end up inside shell commands, so only allow safe characters.
        screen = body.get("screen_name")
        if screen is not None and not re.match(r"^[A-Za-z0-9_.-]{1,80}$", str(screen)):
            return self.send_json(400, {"error": "Invalid screen_name"})
        ipath = body.get("install_path")
        if ipath and (not re.match(r"^/[A-Za-z0-9_./-]+$", str(ipath)) or ".." in str(ipath).split("/")):
            return self.send_json(400, {"error": "Invalid install_path"})

        if self.path == "/api/servers/install":
            game = body.get("game")
            path = body.get("install_path")
            cname = f"springs_{body.get('screen_name')}"
            os.makedirs(path, exist_ok=True)
            log_file = os.path.join(path, "install.log")
            with container_lock(cname):
                run_cmd(f"docker rm -f {cname} >/dev/null 2>&1 || true")

            if game == "cs16":
                threading.Thread(target=install_cs16, args=(path, log_file), daemon=True).start()
                return self.send_json(200, {"status": "installing"})
            elif game == "minecraft":
                threading.Thread(target=install_paper, args=(path, log_file), daemon=True).start()
                return self.send_json(200, {"status": "installing"})
            elif game in GAMES:
                threading.Thread(target=install_steam_game, args=(game, path, log_file), daemon=True).start()
                return self.send_json(200, {"status": "installing"})
            else:
                return self.send_json(400, {"error": "Unsupported game"})
            run_cmd(cmd)
            return self.send_json(200, {"status": "installing"})

        elif self.path == "/api/servers/start":
            cname = f"springs_{body.get('screen_name')}"
            with container_lock(cname):
                return self.start_server(body, cname)

        elif self.path == "/api/servers/stop":
            cname = f"springs_{body.get('screen_name')}"
            with container_lock(cname):
                graceful_stop(cname)
            return self.send_json(200, {"status": "stopped"})

        elif self.path == "/api/servers/status":
            cname = f"springs_{body.get('screen_name')}"
            _, out = run_cmd(f"docker inspect -f '{{{{.State.Running}}}}' {cname} 2>/dev/null")
            return self.send_json(200, {"running": out == "true"})

        elif self.path == "/api/servers/console":
            cname = f"springs_{body.get('screen_name')}"
            try:
                lines = max(1, min(int(body.get("lines", 35)), 2000))
            except (TypeError, ValueError):
                lines = 35
            return self.send_json(200, {"log": console_snapshot(cname, body.get("install_path"), lines)})

        elif self.path == "/api/servers/logs/stream":
            return self.stream_logs(body)

        elif self.path == "/api/servers/repair":
            cname = f"springs_{body.get('screen_name')}"
            try:
                if console_game(cname, body.get("game")) != "cs16":
                    return self.send_json(400, {"ok": False, "error": "There is no automatic repair for this game."})
                return self.send_json(200, repair_cs16(cname, body.get("install_path")))
            except (FileError, OSError) as e:
                return self.send_json(400, {"ok": False, "error": str(e)})

        elif self.path == "/api/servers/rehlds":
            cname = f"springs_{body.get('screen_name')}"
            if body.get("game") != "cs16":
                return self.send_json(400, {"ok": False, "error": "ReHLDS is only for Counter-Strike 1.6."})
            try:
                base, _t = file_target({"install_path": body.get("install_path")})
                cur = _REHLDS_JOBS.get(base)
                if body.get("action") in ("install", "reinstall"):
                    full = body.get("action") == "reinstall"
                    if not (cur and cur["state"] == "running"):
                        if container_running(cname):
                            return self.send_json(400, {"ok": False, "error": "Stop the server first, then update ReHLDS."})
                        if not full and not os.path.isfile(os.path.join(base, "hlds_run")):
                            return self.send_json(400, {"ok": False, "error": "The CS 1.6 game files are not installed yet."})
                        threading.Thread(target=rehlds_job, args=(base, cname, full), daemon=True).start()
                        time.sleep(0.2)
                st = rehlds_status(base)
                st.update(rehlds_job_status(base))
                st["ok"] = True
                return self.send_json(200, st)
            except (ModError, FileError, OSError) as e:
                return self.send_json(400, {"ok": False, "error": str(e)})

        elif self.path.startswith("/api/backups/"):
            return self.handle_backups(body)

        elif self.path == "/api/servers/query":
            body["screen_name"] = body.get("screen_name")
            res = query_one(body)
            if body.get("resources") and res.get("running"):
                res.update(container_resources(["springs_" + str(body.get("screen_name"))]).get("springs_" + str(body.get("screen_name")), {}))
            return self.send_json(200, dict(res, ok=True))

        elif self.path == "/api/servers/query_many":
            items = body.get("servers")
            if not isinstance(items, list):
                return self.send_json(400, {"ok": False, "error": "servers must be a list"})
            return self.send_json(200, {"ok": True, "results": query_many(items, bool(body.get("resources")))})

        elif self.path == "/api/servers/diagnose":
            cname = f"springs_{body.get('screen_name')}"
            try:
                game = console_game(cname, body.get("game"))
                if game != "cs16":
                    return self.send_json(200, {"ok": True, "game": game, "findings": [{"level": "info", "text": "There are no automatic checks for this game yet."}]})
                return self.send_json(200, {"ok": True, "game": game, "findings": diagnose_cs16(cname, body.get("install_path"))})
            except FileError as e:
                return self.send_json(400, {"ok": False, "error": str(e)})

        elif self.path == "/api/servers/command":
            cname = f"springs_{body.get('screen_name')}"
            try:
                game = console_game(cname, body.get("game"))
                result = send_console(cname, game, body.get("command", ""))
            except ConsoleError as e:
                return self.send_json(400, {"ok": False, "status": "error", "error": str(e)})
            except Exception as e:
                return self.send_json(500, {"ok": False, "status": "error", "error": "Console error: " + str(e)[:150]})
            result["status"] = "sent"
            return self.send_json(200, result)

        elif self.path == "/api/ping":
            target = str(body.get("ip", "")).strip()
            try:
                addr = ipaddress.ip_address(target)
            except ValueError:
                return self.send_json(400, {"error": "Invalid IP"})
            if not addr.is_global or addr.is_multicast or addr.is_reserved:
                return self.send_json(400, {"error": "Only public IP addresses can be measured"})
            ms, method = measure_rtt(str(addr))
            return self.send_json(200, {"ms": ms, "method": method})

        elif self.path == "/api/servers/wipe":
            path = body.get("install_path", "").rstrip("/")
            if len(path) > 6 and path not in ["/home", "/root", "/opt", "/var", "/usr", "/etc"]:
                shutil.rmtree(path, ignore_errors=True)
            return self.send_json(200, {"status": "wiped"})

        elif self.path.startswith("/api/files/"):
            return self.handle_files(body)

        elif self.path.startswith("/api/mods/"):
            return self.handle_mods(body)

        self.send_json(404, {"error": "Endpoint not found"})

if __name__ == "__main__":
    print(f"Springs Daemon listening on 0.0.0.0:{PORT}")
    ThreadingHTTPServer(("0.0.0.0", PORT), SpringsHandler).serve_forever()
PYEOF
chmod +x /opt/springs/springs.py

echo "[3/4] Pulling Docker images (CS games + Java 25 for Minecraft)..."
docker pull ghcr.io/pterodactyl/games:source
docker pull ghcr.io/ptero-eggs/yolks:java_25 || echo "WARNING: could not pull the Java 25 image - Minecraft servers will not start until it is available."

echo "[4/4] Configuring & starting springs.service on port <?php echo $port; ?>..."
cat << 'SVCEOF' > /etc/systemd/system/springs.service
[Unit]
Description=Springs Docker Game Node Daemon
After=network.target docker.service
Requires=docker.service

[Service]
Type=simple
Environment="SPRINGS_PORT=<?php echo $port; ?>"
Environment="SPRINGS_TOKEN=<?php echo $token; ?>"
ExecStart=/usr/bin/python3 /opt/springs/springs.py
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
SVCEOF

iptables -I INPUT -p tcp --dport <?php echo $port; ?> -j ACCEPT 2>/dev/null || true
ufw allow <?php echo $port; ?>/tcp 2>/dev/null || true
systemctl daemon-reload
systemctl enable --now springs
systemctl restart springs
echo "Springs successfully installed and running on port <?php echo $port; ?>!"
