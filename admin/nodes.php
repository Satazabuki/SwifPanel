<?php require_once __DIR__ . '/guard.php'; ?>
<?php
require_once '../auth.php';
require_once '../config.php';
require_once '../port_helper.php';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_server"], $_POST["server_id"])) {
    $delId = (int)$_POST["server_id"];
    $wipeFiles = !empty($_POST["wipe_files"]);
    $sStmt = $pdo->prepare("SELECT s.*, b.ip_address AS node_ip, b.ssh_port AS api_port, b.ssh_password AS api_token FROM servers s JOIN boxes b ON s.box_id = b.id WHERE s.id = ?");
    $sStmt->execute([$delId]);
    $delSrv = $sStmt->fetch();
    if ($delSrv) {
        $port = (int)($delSrv["api_port"] ?: 8085);
        $baseUrl = "http://{$delSrv['node_ip']}:{$port}";
        $headers = ["Content-Type: application/json", "Authorization: Bearer " . $delSrv["api_token"]];
        $payload = json_encode(["id" => $delSrv["id"], "game" => $delSrv["game"], "install_path" => $delSrv["install_path"]]);

        foreach (["/api/servers/stop"] + ($wipeFiles ? ["/api/servers/wipe"] : []) as $ep) {
            $ch = curl_init($baseUrl . $ep);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            curl_close($ch);
        }
        $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$delId]);
        $notice = "Server #{$delId} ({$delSrv['name']}) deleted" . ($wipeFiles ? " and volume files wiped." : ".");
    }
}


if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_node'])) {
    $name = trim($_POST['name']);
    $location = trim($_POST['location'] ?? 'Europe') ?: 'Europe';
    $ip = trim($_POST['ip_address']);
    $extraIps = trim($_POST['extra_ips'] ?? '');
    $port = (int)($_POST['ssh_port'] ?: 8080);
    $token = $_POST['ssh_password'];

    if ($name && $ip && $token) {
        $stmt = $pdo->prepare("INSERT INTO boxes (name, location, ip_address, extra_ips, ssh_port, ssh_user, ssh_password) VALUES (?, ?, ?, ?, ?, 'springs', ?)");
        $stmt->execute([$name, $location, $ip, $extraIps, $port, $token]);
        $host = htmlspecialchars($_SERVER['HTTP_HOST']);
        $cmdUrl = 'curl -fsSL "https://' . $host . '/springs_install.php?port=' . $port . '&token=' . urlencode($token) . '" | bash';
        $notice = "Springs node '{$name}' added! Run this on the node:<br><code style='display:inline-block;margin-top:6px;user-select:all;'>{$cmdUrl}</code>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_location'])) {
    $boxId = (int)$_POST['box_id'];
    $newLoc = trim($_POST['location'] ?? 'Europe') ?: 'Europe';
    $pdo->prepare("UPDATE boxes SET location = ? WHERE id = ?")->execute([$newLoc, $boxId]);
    $notice = "Server location updated for node #{$boxId}.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ports'])) {
    $boxId = (int)$_POST['box_id'];
    $newPorts = trim($_POST['assigned_ports'] ?? '');
    $pdo->prepare("UPDATE boxes SET assigned_ports = ? WHERE id = ?")->execute([$newPorts, $boxId]);
    $notice = "Assigned game ports updated for node #{$boxId}.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ips'])) {
    $boxId = (int)$_POST['box_id'];
    $newExtras = trim($_POST['extra_ips'] ?? '');
    $pdo->prepare("UPDATE boxes SET extra_ips = ? WHERE id = ?")->execute([$newExtras, $boxId]);
    $notice = "Additional game IPs updated for node #{$boxId}.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_node'])) {
    $boxId = (int)$_POST['box_id'];
    $pdo->prepare("DELETE FROM boxes WHERE id = ?")->execute([$boxId]);
    $notice = "Springs node #{$boxId} deleted.";
}

$nodes = $pdo->query("SELECT * FROM boxes ORDER BY id ASC")->fetchAll();
$generatedToken = isset($_POST['ssh_password']) && $_POST['ssh_password'] !== '' ? trim($_POST['ssh_password']) : bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Springs Nodes - SwiftPanel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">
            Logged in as <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
            | <a href="login.php?logout=1">Logout</a>
        </div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="nodes.php" class="active">Remote Nodes</a></li>
            <li><a href="client_servers.php">Client Servers</a></li>
            <li><a href="create_server.php">Create Server</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="smtp.php">SMTP / Email</a></li>
        </ul>
    </div>

    <div id="wrapper">
        <div id="sidebar">
            <div class="box">
                <div class="box-header">Navigation</div>
                <div class="box-content">
                    <ul class="sidebar-menu">
                        
                        <li><a href="create_server.php">&raquo; Deploy New Server</a></li>
                        <li><a href="nodes.php">&raquo; Manage Springs Nodes</a></li>
            <li><a href="client_servers.php">Client Servers</a></li>
                        <li><a href="users.php">&raquo; Manage Users</a></li>
                    </ul>
                </div>
            </div>
            <div class="box">
                <div class="box-header">Springs Info</div>
                <div class="box-content">
                    <p style="font-size: 11.5px; color: var(--text-secondary);">
                        <strong>Springs</strong> runs on each remote Docker host over HTTP REST (default port <code>8080</code>) and isolates every game server inside its own container.
                    </p>
                </div>
            </div>
        </div>

        <div id="content">
            <?php if ($notice): ?>
                <div class="alert-info"><strong>System Message:</strong> <?php echo $notice; ?></div>
            <?php endif; ?>

            <div class="box">
                <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Springs Docker Nodes &amp; Live Telemetry</span>
                    <span style="font-size:11px;color:var(--neon);font-weight:normal;">Auto-updating every 5s</span>
                </div>
                <div class="box-content">
                    <?php if (empty($nodes)): ?>
                        <p style="color:var(--text-secondary);margin:0;">No Springs nodes configured yet. Connect a node below.</p>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:16px;">
                            <?php foreach ($nodes as $node): $nid = (int)$node['id']; ?>
                            <div id="telemetry-card-<?php echo $nid; ?>" style="background:var(--bg-input);border:1px solid var(--border-subtle);border-radius:8px;padding:16px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-subtle);padding-bottom:10px;margin-bottom:14px;">
                                    <div>
                                        <strong style="font-size:15px;color:var(--text-primary);">Node: <?php echo htmlspecialchars($node['name']); ?></strong> <span class="badge" style="margin-left:6px;background:rgba(46,204,113,0.15);color:#2ecc71;"><?php echo htmlspecialchars($node['location'] ?: 'Europe'); ?></span>
                                        <code style="margin-left:8px;"><?php echo htmlspecialchars($node['ip_address'] . ':' . $node['ssh_port']); ?></code>
                                    </div>
                                    <span id="node-status-<?php echo $nid; ?>" class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8;">Querying...</span>
                                </div>

                                <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:22px;align-items:start;">
                                    <div>
                                        <div style="margin-bottom:10px;">
                                            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                                                <span style="color:var(--text-secondary);">CPU Load</span>
                                                <span id="cpu-text-<?php echo $nid; ?>" style="color:var(--neon);font-weight:600;">--%</span>
                                            </div>
                                            <div style="width:100%;height:8px;background:#1e293b;border-radius:999px;overflow:hidden;">
                                                <div id="cpu-bar-<?php echo $nid; ?>" style="width:0%;height:100%;background:var(--neon);box-shadow:0 0 8px var(--neon);transition:width 0.4s ease;"></div>
                                            </div>
                                        </div>

                                        <div style="margin-bottom:10px;">
                                            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                                                <span style="color:var(--text-secondary);">Memory (RAM)</span>
                                                <span id="ram-text-<?php echo $nid; ?>" style="color:var(--neon);font-weight:600;">-- / -- MB</span>
                                            </div>
                                            <div style="width:100%;height:8px;background:#1e293b;border-radius:999px;overflow:hidden;">
                                                <div id="ram-bar-<?php echo $nid; ?>" style="width:0%;height:100%;background:var(--neon);box-shadow:0 0 8px var(--neon);transition:width 0.4s ease;"></div>
                                            </div>
                                        </div>

                                        <div style="margin-bottom:12px;">
                                            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                                                <span style="color:var(--text-secondary);">Disk Storage (/)</span>
                                                <span id="disk-text-<?php echo $nid; ?>" style="color:var(--neon);font-weight:600;">-- / -- GB</span>
                                            </div>
                                            <div style="width:100%;height:8px;background:#1e293b;border-radius:999px;overflow:hidden;">
                                                <div id="disk-bar-<?php echo $nid; ?>" style="width:0%;height:100%;background:var(--neon);box-shadow:0 0 8px var(--neon);transition:width 0.4s ease;"></div>
                                            </div>
                                        </div>

                                        <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1px solid var(--border-subtle);font-size:12px;">
                                            <span style="color:var(--text-secondary);">Network I/O:</span>
                                            <span id="net-text-<?php echo $nid; ?>" style="font-family:monospace;color:var(--text-primary);">&darr; -- KB/s &nbsp;|&nbsp; &uarr; -- KB/s</span>
                                        </div>
                                    </div>

                                    <div style="display:flex;flex-direction:column;gap:10px;">
                                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;">
                                            <span style="color:var(--text-secondary);">Secret Token:</span>
                                            <code><?php echo htmlspecialchars(substr($node['ssh_password'], 0, 12)); ?>••••••••</code>
                                        </div>

                                        <form method="POST" style="display:flex;flex-direction:column;gap:4px;margin:0;">
                                            <input type="hidden" name="box_id" value="<?php echo $nid; ?>">
                                            </form>
                                        <form method="POST" style="display:flex;flex-direction:column;gap:4px;margin:0;">
                                            <input type="hidden" name="box_id" value="<?php echo $nid; ?>">
                                            <label style="font-size:11.5px;color:var(--text-secondary);">Server Location</label>
                                            <div style="display:flex;gap:6px;">
                                                <input type="text" name="location" value="<?php echo htmlspecialchars($node['location'] ?? 'Europe'); ?>" placeholder="e.g. Germany (Frankfurt)" style="flex:1;padding:5px 8px;background:#0d1117;border:1px solid var(--border-subtle);border-radius:5px;color:var(--text-primary);font-size:12px;">
                                                <button type="submit" name="update_location" value="1" class="btn btn-blue" style="padding:5px 10px;font-size:12px;">Save</button>
                                            </div>
                                        </form>
                                        <form method="POST" style="display:flex;flex-direction:column;gap:4px;margin:0;"><input type="hidden" name="box_id" value="<?php echo $nid; ?>"><label style="font-size:11.5px;color:var(--text-secondary);">Additional Game IPs</label>
                                            <div style="display:flex;gap:6px;">
                                                <input type="text" name="extra_ips" value="<?php echo htmlspecialchars($node['extra_ips'] ?? ''); ?>" placeholder="e.g. 1.2.3.4, 1.2.3.5" style="flex:1;padding:5px 8px;background:#0d1117;border:1px solid var(--border-subtle);border-radius:5px;color:var(--text-primary);font-size:12px;">
                                                <button type="submit" name="update_ips" value="1" class="btn btn-blue" style="padding:5px 10px;font-size:12px;">Save</button>
                                            </div>
                                        </form>

                                        <form method="POST" style="display:flex;flex-direction:column;gap:4px;margin:0;">
                                            <input type="hidden" name="box_id" value="<?php echo $nid; ?>">
                                            <label style="font-size:11.5px;color:var(--text-secondary);">Assigned Game Ports</label>
                                            <div style="display:flex;gap:6px;">
                                                <input type="text" name="assigned_ports" value="<?php echo htmlspecialchars($node['assigned_ports'] ?? '27015-27060, 25565-25585'); ?>" placeholder="27015-27060, 25565-25585" style="flex:1;padding:5px 8px;background:#0d1117;border:1px solid var(--border-subtle);border-radius:5px;color:var(--text-primary);font-size:12px;">
                                                <button type="submit" name="update_ports" value="1" class="btn btn-blue" style="padding:5px 10px;font-size:12px;">Save</button>
                                            </div>
                                            <?php
                                                $pListStmt = $pdo->prepare("SELECT port, name, game FROM servers WHERE box_id = ? ORDER BY port ASC");
                                                $pListStmt->execute([$nid]);
                                                $assignedRows = $pListStmt->fetchAll();
                                                $poolAll = parsePortPool($node['assigned_ports'] ?? '', 'all');
                                                $usedNums = array_map(fn($r) => (int)$r['port'], $assignedRows);
                                                $freeCount = count(array_diff($poolAll, $usedNums));
                                            ?>
                                            <div style="margin-top:4px;font-size:11.5px;color:var(--text-secondary);display:flex;flex-direction:column;gap:4px;">
                                                <div>
                                                    <span>Used Ports (<?php echo count($assignedRows); ?>)</span> &bull;
                                                    <span style="color:var(--neon);">Free in Pool: <?php echo $freeCount; ?></span>
                                                </div>
                                                <?php if (empty($assignedRows)): ?>
                                                    <span style="color:#64748b;">No ports currently in use on this node.</span>
                                                <?php else: ?>
                                                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                                                        <?php foreach ($assignedRows as $ar): ?>
                                                            <code style="font-size:11px;padding:2px 6px;background:#0d1117;border:1px solid #30363d;border-radius:4px;" title="<?php echo htmlspecialchars($ar['name'] . ' (' . strtoupper($ar['game']) . ')'); ?>">
                                                                <?php echo (int)$ar['port']; ?> (<?php echo htmlspecialchars($ar['name']); ?>)
                                                            </code>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </form>

                                        <form method="POST" style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px;padding-top:8px;border-top:1px solid var(--border-subtle);">
                                            <input type="hidden" name="box_id" value="<?php echo $nid; ?>">
                                            <button type="button" class="btn btn-green" style="padding:5px 12px;font-size:12px;" onclick="copyNodeCmd(<?php echo (int)$node['ssh_port']; ?>, '<?php echo htmlspecialchars($node['ssh_password'], ENT_QUOTES); ?>', this)">Install Cmd</button>
                                            <button type="submit" name="delete_node" value="1" class="btn btn-red" style="padding:5px 12px;font-size:12px;" onclick="return confirm('Delete this Springs node?');">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="control-grid">
                <div class="box" style="margin-bottom:0;">
                    <div class="box-header">Connect New Springs Node</div>
                    <div class="box-content">
                        <form method="POST">
                            <input type="hidden" name="add_node" value="1">
                            <div class="form-row">
                                <label>Node Display Name:</label>
                                <input type="text" name="name" placeholder="EU-Frankfurt-01" required>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label style="display:block;font-size:12px;color:var(--text-secondary);margin-bottom:4px;">Server Location</label>
                                <input type="text" name="location" value="Germany (Frankfurt)" placeholder="e.g. Germany (Frankfurt), Serbia (Belgrade)" style="width:100%;box-sizing:border-box;padding:7px 9px;background:var(--bg-input);border:1px solid var(--border-subtle);border-radius:5px;color:var(--text-primary);font-size:12.5px;">
                            </div>
                            <div class="form-row">
                                <label>Primary Node IP Address:</label>
                                <input type="text" name="ip_address" placeholder="185.191.124.120" required>
                            </div>
                            <div class="form-row">
                                <label>Additional Game IPs (optional, comma-separated):</label>
                                <input type="text" name="extra_ips" placeholder="185.191.124.121, 185.191.124.122">
                            </div>
                            <div class="form-row">
                                <label>Springs Daemon Port:</label>
                                <input type="text" name="ssh_port" value="8085" required>
                            </div>
                            <div class="form-row">
                                <label>Springs Secret Bearer Token:</label>
                                <input type="text" name="ssh_password" id="tokenInput" value="<?php echo htmlspecialchars($generatedToken); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-green">Add Springs Node</button>
                        </form>
                    </div>
                </div>

                <div class="box" style="margin-bottom:0;">
                    <div class="box-header">Deploy Springs Daemon on a Fresh Node</div>
                    <div class="box-content">
                        <p style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;">
                            Run this as <code>root</code> on a fresh Ubuntu/Debian node to install Docker and configure <strong>Springs</strong> with the token on the left:
                        </p>
                        <textarea id="installSnippet" readonly style="width:100%;height:110px;background:#06080b;color:var(--neon);border:1px solid #1a2e22;border-radius:6px;padding:10px;font-family:monospace;font-size:11.5px;resize:none;">curl -fsSL "https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?>/springs_install.php?port=8085&token=<?php echo htmlspecialchars($generatedToken); ?>" | bash</textarea>
                        <button type="button" class="btn btn-blue" style="margin-top:8px;" onclick="navigator.clipboard.writeText(document.getElementById('installSnippet').value); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy Setup Snippet', 2000);">Copy Setup Snippet</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="footer">Powered by Standalone SwiftPanel &bull; Springs Docker Engine</div>

    <script>
    const nodeIds = <?php echo json_encode(array_map(fn($n) => (int)$n['id'], $nodes)); ?>;
    const tokenInput = document.getElementById("tokenInput");
    const installSnippet = document.getElementById("installSnippet");

    if (tokenInput && installSnippet) {
        tokenInput.addEventListener("input", () => {
            const pVal = document.querySelector('input[name="ssh_port"]')?.value || 8085; installSnippet.value = `curl -fsSL "https://${window.location.host}/springs_install.php?port=${pVal}&token=${tokenInput.value}" | bash`;
        });
    }

    function formatSpeed(kbps) {
        return kbps >= 1024 ? (kbps / 1024).toFixed(2) + " MB/s" : kbps.toFixed(1) + " KB/s";
    }

    async function pollNodeStats(id) {
        try {
            const res = await fetch("../node_stats_ajax.php?id=" + id);
            const data = await res.json();
            const badge = document.getElementById("node-status-" + id);
            if (data.status === "ok" && data.stats) {
                const s = data.stats;
                badge.className = "badge badge-online";
                badge.textContent = "Online";
                document.getElementById("cpu-text-" + id).textContent = s.cpu.toFixed(1) + "%";
                document.getElementById("cpu-bar-" + id).style.width = Math.min(100, s.cpu) + "%";
                const ramPct = Math.min(100, (s.ram_used / Math.max(1, s.ram_total)) * 100);
                document.getElementById("ram-text-" + id).textContent = s.ram_used + " / " + s.ram_total + " MB (" + ramPct.toFixed(0) + "%)";
                document.getElementById("ram-bar-" + id).style.width = ramPct + "%";
                const diskPct = Math.min(100, (s.disk_used / Math.max(0.1, s.disk_total)) * 100);
                document.getElementById("disk-text-" + id).textContent = s.disk_used + " / " + s.disk_total + " GB (" + diskPct.toFixed(0) + "%)";
                document.getElementById("disk-bar-" + id).style.width = diskPct + "%";
                document.getElementById("net-text-" + id).innerHTML = "&darr; " + formatSpeed(s.net_rx_kbps) + " &nbsp;|&nbsp; &uarr; " + formatSpeed(s.net_tx_kbps);
            } else {
                badge.className = "badge badge-offline";
                badge.textContent = "Offline";
            }
        } catch (e) {}
    }

    function copyNodeCmd(port, token, btn) {
        const cmd = `curl -fsSL "https://${window.location.host}/springs_install.php?port=${port}&token=${token}" | bash`;
        navigator.clipboard.writeText(cmd);
        const orig = btn.textContent;
        btn.textContent = "Copied!";
        setTimeout(() => btn.textContent = orig, 2000);
    }

    document.addEventListener("DOMContentLoaded", () => {
        nodeIds.forEach(id => pollNodeStats(id));
        setInterval(() => nodeIds.forEach(id => pollNodeStats(id)), 5000);
    });
    </script>
</body>
</html>
