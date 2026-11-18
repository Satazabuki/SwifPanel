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
import os, re, json, time, shutil, subprocess, shlex, threading, ipaddress, base64, zipfile, urllib.request
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

    def do_GET(self):
        if not self.check_auth():
            return self.send_json(401, {"error": "Unauthorized"})
        if self.path == "/api/stats":
            return self.send_json(200, {"status": "ok", "stats": get_stats()})
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
            run_cmd(f"docker rm -f {cname} >/dev/null 2>&1 || true")

            if game == "cs16":
                cmd = f"nohup bash -c 'echo \"[Springs] Installing CS 1.6...\" > {log_file} && docker run --rm --user 0:0 -e HOME=/root -v {path}:/data -v /usr/games:/usr/games -v /usr/lib/games:/usr/lib/games ghcr.io/ptero-eggs/steamcmd:debian /bin/bash -c \"mkdir -p /root/.local/share/Steam/steamapps/common /root/Steam/steamapps/common && ln -sfn /data /root/.local/share/Steam/steamapps/common/Half-Life && ln -sfn /data /root/Steam/steamapps/common/Half-Life && /usr/games/steamcmd +force_install_dir /data +login anonymous +app_set_config 90 mod cstrike +app_update 90 validate +quit && /usr/games/steamcmd +force_install_dir /data +login anonymous +app_set_config 90 mod cstrike +app_update 90 validate +quit && if [ -d /data/steamapps/common/Half-Life ]; then cp -rn /data/steamapps/common/Half-Life/* /data/; fi && chmod +x /data/hlds_run /data/hlds_linux 2>/dev/null || true\" >> {log_file} 2>&1 && echo \"[Springs] Complete!\" >> {log_file}' </dev/null >/dev/null 2>&1 &"
            elif game == "cs2":
                cmd = f"nohup bash -c 'echo \"[Springs] Installing CS2...\" > {log_file} && docker run --rm -v {path}:/data ghcr.io/pterodactyl/games:source /home/container/steamcmd/steamcmd.sh +force_install_dir /data +login anonymous +app_update 730 validate +quit >> {log_file} 2>&1 && echo \"[Springs] Complete!\" >> {log_file}' </dev/null >/dev/null 2>&1 &"
            elif game == "minecraft":
                threading.Thread(target=install_paper, args=(path, log_file), daemon=True).start()
                return self.send_json(200, {"status": "installing"})
            else:
                return self.send_json(400, {"error": "Unsupported game"})
            run_cmd(cmd)
            return self.send_json(200, {"status": "installing"})

        elif self.path == "/api/servers/start":
            cname = f"springs_{body.get('screen_name')}"
            game = body.get("game")
            path = body.get("install_path")
            ip = body.get("ip_address")
            port = int(body.get("port"))
            slots = int(body.get("max_players"))
            start_map = body.get("start_map_or_world")
            ram = int(body.get("ram_mb", 1024))

            if game in ("cs16", "cs2"):
                try:
                    ipaddress.ip_address(str(ip))
                except ValueError:
                    return self.send_json(400, {"error": "Invalid ip_address"})
                if not re.match(r"^[A-Za-z0-9_.-]{1,64}$", str(start_map)):
                    return self.send_json(400, {"error": "Invalid start map"})

            run_cmd(f"docker rm -f {cname} >/dev/null 2>&1 || true")
            if game == "cs16":
                dcmd = f"docker run -d -i --user 0:0 --name {cname} --network host --memory={ram}m -v {path}:/data -w /data --entrypoint /bin/bash ghcr.io/pterodactyl/games:source -c \"echo 10 > /data/steam_appid.txt && mkdir -p ~/.steam/sdk32 && ln -sf /data/steamclient.so ~/.steam/sdk32/steamclient.so && export LD_LIBRARY_PATH=.:\\$LD_LIBRARY_PATH && exec ./hlds_run -game cstrike +ip {ip} +port {port} +maxplayers {slots} +map {start_map}\""
            elif game == "cs2":
                dcmd = f"docker run -d -i --user 0:0 --name {cname} --network host --memory={ram}m -v {path}:/data -w /data --entrypoint /bin/bash ghcr.io/pterodactyl/games:source -c \"export LD_LIBRARY_PATH=./game/bin/linuxsteamrt64:\\$LD_LIBRARY_PATH && exec ./game/bin/linuxsteamrt64/cs2 -dedicated +ip {ip} +port {port} +maxplayers {slots} +map {start_map}\""
            elif game == "minecraft":
                dcmd = f"docker run -d -i --name {cname} --network host --memory={ram}m -v {path}:/data -w /data --entrypoint= {JAVA_IMAGE} java -Xms512M -Xmx{ram}M -jar server.jar nogui"
            else:
                return self.send_json(400, {"error": "Unsupported game"})
            code, out = run_cmd(dcmd)
            return self.send_json(200, {"status": "started", "output": out})

        elif self.path == "/api/servers/stop":
            cname = f"springs_{body.get('screen_name')}"
            run_cmd(f"docker stop -t 5 {cname} >/dev/null 2>&1 && docker rm -f {cname} >/dev/null 2>&1")
            return self.send_json(200, {"status": "stopped"})

        elif self.path == "/api/servers/status":
            cname = f"springs_{body.get('screen_name')}"
            _, out = run_cmd(f"docker inspect -f '{{{{.State.Running}}}}' {cname} 2>/dev/null")
            return self.send_json(200, {"running": out == "true"})

        elif self.path == "/api/servers/console":
            cname = f"springs_{body.get('screen_name')}"
            path = body.get("install_path")
            lines = int(body.get("lines", 35))
            code, out = run_cmd(f"docker logs --tail {lines} {cname} 2>&1")
            if code != 0:
                log_file = os.path.join(path, "install.log")
                if os.path.exists(log_file):
                    _, out = run_cmd(f"tail -n {lines} {log_file}")
                    out = "[Springs Installer]\n" + out
                else:
                    out = "[Springs] Container offline. Click Start Server to boot."
            return self.send_json(200, {"log": out})

        elif self.path == "/api/servers/command":
            cname = f"springs_{body.get('screen_name')}"
            cmd_str = str(body.get("command", ""))
            inner = "echo " + shlex.quote(cmd_str) + " > /proc/1/fd/0"
            run_cmd(f"docker exec {cname} bash -c {shlex.quote(inner)} 2>/dev/null || true")
            return self.send_json(200, {"status": "sent"})

        elif self.path == "/api/ping":
            target = str(body.get("ip", "")).strip()
            if not re.match(r"^[0-9a-fA-F.:]+$", target):
                return self.send_json(400, {"error": "Invalid IP"})
            _, out = run_cmd(f"ping -c 2 -W 1 -i 0.2 {target} 2>/dev/null || true")
            m = re.search(r"rtt [^=]+= [0-9.]+/([0-9.]+)/", out)
            ms = max(1, round(float(m.group(1)))) if m else None
            return self.send_json(200, {"ms": ms})

        elif self.path == "/api/servers/wipe":
            path = body.get("install_path", "").rstrip("/")
            if len(path) > 6 and path not in ["/home", "/root", "/opt", "/var", "/usr", "/etc"]:
                shutil.rmtree(path, ignore_errors=True)
            return self.send_json(200, {"status": "wiped"})

        elif self.path.startswith("/api/files/"):
            return self.handle_files(body)

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