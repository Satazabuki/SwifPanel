<?php require_once __DIR__ . '/guard.php'; ?>
<?php
require_once '../auth.php';
require_once '../config.php';
require_once '../port_helper.php';
require_once '../ServerManager.php';

$notice = '';
$errorMsg = '';

// Ensure at least one default admin user exists for foreign key mapping
$userCheck = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetch();
if (!$userCheck) {
    $defaultPass = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, email, role) VALUES ('admin', ?, 'admin@localhost', 'admin')")->execute([$defaultPass]);
    $defaultUserId = $pdo->lastInsertId();
} else {
    $defaultUserId = $userCheck['id'];
}
$allUsers = $pdo->query("SELECT id, username, role FROM users ORDER BY username ASC")->fetchAll();

// Handle Create Server Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_server'])) {
    $boxId = (int)$_POST['box_id'];
    $ownerId = (int)($_POST['user_id'] ?? $defaultUserId);
    $game = $_POST['game'];
    $name = trim($_POST['name']);
    $port = (int)$_POST['port'];
    $maxPlayers = (int)$_POST['max_players'];
    $startMap = trim($_POST['start_map_or_world']);
    $ramMb = (int)($_POST['ram_mb'] ?: 2048);
    $installPath = rtrim(trim($_POST['install_path']), '/');

    if ($boxId && $name && $port && $installPath) {
        $boxStmt = $pdo->prepare("SELECT * FROM boxes WHERE id = ?");
        $boxStmt->execute([$boxId]);
        $box = $boxStmt->fetch();

        if (!$box) {
            $errorMsg = "Selected remote node does not exist.";
        } elseif (in_array($port, getUsedPortsOnNode($pdo, $box), true)) {
            $nextFree = allocateFreePort($pdo, $box, $game);
            $errorMsg = "Port {$port} is already assigned or in use on {$box['name']}. Next available port: {$nextFree}.";
        } else {
            $allowedIps = [$box['ip_address']];
            if (!empty($box['extra_ips'])) {
                foreach (preg_split("/[\s,]+/", $box['extra_ips'], -1, PREG_SPLIT_NO_EMPTY) as $eip) {
                    if (filter_var($eip, FILTER_VALIDATE_IP) && !in_array($eip, $allowedIps)) {
                        $allowedIps[] = $eip;
                    }
                }
            }
            $selectedIp = trim($_POST['ip_address'] ?? '');
            $ipAddress = in_array($selectedIp, $allowedIps) ? $selectedIp : $box['ip_address'];
            try {
                $mgr = new ServerManager();
                $mgr->connect($box['ip_address'], $box['ssh_port'], $box['ssh_user'], $box['ssh_password']);
                $screenName = $game . "_" . $port . "_" . substr(md5(uniqid()), 0, 5);
                $mgr->installServer($game, $installPath, $screenName);

                $ins = $pdo->prepare("
                    INSERT INTO servers (user_id, box_id, game, name, ip_address, port, max_players, start_map_or_world, ram_mb, install_path, screen_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$ownerId, $boxId, $game, $name, $ipAddress, $port, $maxPlayers, $startMap, $ramMb, $installPath, $screenName]);
                $notice = "Directory created and background download started on {$box['name']}! Check {$installPath}/install.log before starting.";
            } catch (Exception $e) {
                $errorMsg = "SSH Error: " . $e->getMessage();
            }
        }
    } else {
        $errorMsg = "Please fill in all required fields.";
    }
}

$nodes = $pdo->query("SELECT * FROM boxes ORDER BY id ASC")->fetchAll();
$serverCount = $pdo->query("SELECT COUNT(*) FROM servers")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Game Server - SwiftPanel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
</head>
<body>

    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">
            Logged in as: <strong><?php echo htmlspecialchars($_SESSION["username"] ?? "admin"); ?></strong> | Database: <span style="color: #2ecc71;">Connected</span> | <a href="login.php?logout=1">Logout</a>
        </div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="nodes.php">Remote Nodes</a></li>
            <li><a href="client_servers.php">Client Servers</a></li>
            <li><a href="create_server.php" class="active">Create Server</a></li>
            <li><a href="users.php">Users</a></li>
            <li><a href="orders.php">Orders</a></li>
            <li><a href="tickets.php">Support Tickets</a></li>
            <li><a href="smtp.php">SMTP / Email</a></li>
        </ul>
    </div>

    <div id="wrapper">
        
        <div id="sidebar">
            <div class="box">
                <div class="box-header">Server Navigation</div>
                <div class="box-content">
                    <ul class="sidebar-menu">
                        
                        <li><a href="nodes.php">&raquo; Manage Remote Nodes</a></li>
                        <li><a href="create_server.php">&raquo; Create Game Server</a></li>
                    </ul>
                </div>
            </div>

            <div class="box">
                <div class="box-header">Expected Binaries</div>
                <div class="box-content" style="font-size: 11px;">
                    <p style="margin-bottom: 5px;"><strong>CS 1.6:</strong> <code>./hlds_run</code></p>
                    <p style="margin-bottom: 5px;"><strong>CS2:</strong> <code>./game/bin/linuxsteamrt64/cs2</code></p>
                    <p><strong>Minecraft:</strong> <code>server.jar</code></p>
                </div>
            </div>
        </div>
        <div id="content">
            
            <?php if ($notice): ?>
                <div class="alert-info">
                    <strong>System Message:</strong> <?php echo htmlspecialchars($notice); ?> </div>
            <?php elseif ($errorMsg): ?>
                <div class="alert-info" style="background-color: #f2dede; border-color: #ebccd1; color: #a94442;">
                    <strong>Error:</strong> <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php endif; ?>

            <div class="box">
                <div class="box-header">Create New Game Server</div>
                <div class="box-content">
                    <?php if (empty($nodes)): ?>
                        <p>You must add at least one remote Linux node before creating a game server. <a href="nodes.php"><strong>&raquo; Add Remote Node</strong></a></p>
                    <?php else: ?>
                    <form method="POST">
                        <div class="control-grid">
                            <div>
                                <div class="form-row">
                                    <label>Assign to User (Owner):</label>
                                    <select name="user_id" required>
                                        <?php foreach ($allUsers as $u): ?>
                                            <option value="<?php echo (int)$u['id']; ?>" data-username="<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>">
                                                <?php echo htmlspecialchars($u['username'] . " (" . $u['role'] . ")"); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <label>Remote Linux Node:</label>
                                    <select name="box_id" id="nodeSelect" onchange="updateIpDropdown()" required>
                                        <?php foreach ($nodes as $n): ?>
                                            <option value="<?php echo (int)$n['id']; ?>">
                                                <?php echo htmlspecialchars($n['name'] . ' (' . $n['ip_address'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <label>Game Type:</label>
                                    <select name="game" required>
                                        <option value="cs16">Counter-Strike 1.6 (HLDS / ReHLDS)</option>
                                        <option value="cs2">Counter-Strike 2 (Dedicated)</option>
                                        <option value="minecraft">Minecraft (Java / Paper / Spigot)</option>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <label>Server Display Name:</label>
                                    <input type="text" name="name" placeholder="Public Dust2 24/7" required>
                                </div>

                                <div class="form-row">
                                    <label>Install Directory on Remote Node:</label>
                                    <input type="text" name="install_path" id="installPathInput" value="/var/lib/springs/volumes/<?php echo htmlspecialchars(preg_replace('/[^a-z0-9_-]+/', '_', strtolower($allUsers[0]['username'] ?? 'client'))); ?>/cs16_27015" required>
                                </div>
                            </div>

                            <div>
                                <div class="form-row">
                                    <label>Game IP Address:</label>
                                    <select name="ip_address" id="ipSelect" required></select>
                                </div>

                                <div class="form-row">
                                    <label>Game Port:</label>
                                    <input type="text" name="port" value="27015" required>
                                </div>

                                <div class="form-row">
                                    <label>Max Players (Counter-Strike):</label>
                                    <input type="text" name="max_players" value="32" required>
                                </div>

                                <div class="form-row">
                                    <label>Startup Map (CS) or World Name (MC):</label>
                                    <input type="text" name="start_map_or_world" value="de_dust2" required>
                                </div>

                                <div class="form-row">
                                    <label>RAM Allocation in MB (Minecraft Only):</label>
                                    <input type="text" name="ram_mb" value="2048">
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="create_server" value="1" class="btn btn-green">Create Directory &amp; Install Game Files</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>

    <div id="footer">
        Powered by Standalone SwiftPanel &bull; Direct SSH &amp; Screen Control
    </div>

    <script>
    const nodeIps = <?php
        $map = [];
        foreach ($nodes as $n) {
            $list = [$n['ip_address']];
            if (!empty($n['extra_ips'])) {
                foreach (preg_split("/[\s,]+/", $n['extra_ips'], -1, PREG_SPLIT_NO_EMPTY) as $eip) {
                    if (!in_array($eip, $list)) $list[] = $eip;
                }
            }
            $map[$n['id']] = $list;
        }
        echo json_encode($map);
    ?>;

    function updateIpDropdown() {
        const nodeSelect = document.getElementById("nodeSelect");
        const ipSelect = document.getElementById("ipSelect");
        if (!nodeSelect || !ipSelect) return;
        const ips = nodeIps[nodeSelect.value] || [];
        ipSelect.innerHTML = "";
        ips.forEach(ip => {
            const opt = document.createElement("option");
            opt.value = ip;
            opt.textContent = ip;
            ipSelect.appendChild(opt);
        });
    }
    document.addEventListener("DOMContentLoaded", function() {
        updateIpDropdown();

        const ownerSel = document.querySelector("select[name='user_id']");
        const gameSel  = document.querySelector("select[name='game']");
        const portInp  = document.querySelector("input[name='port']");
        const pathInp  = document.querySelector("input[name='install_path']");
        if (!pathInp) return;

        let manuallyEdited = false;
        pathInp.addEventListener("input", () => { manuallyEdited = true; });

        function updateVolumePath() {
            if (manuallyEdited) return;
            const opt = ownerSel && ownerSel.selectedIndex >= 0 ? ownerSel.options[ownerSel.selectedIndex] : null;
            const rawUser = opt ? opt.textContent.split("(")[0].trim() : "client";
            const cleanUser = rawUser.toLowerCase().replace(/[^a-z0-9_-]+/g, "_").replace(/^_+|_+$/g, "") || "client";
            const game = gameSel ? gameSel.value.trim().toLowerCase() : "cs16";
            const port = portInp && portInp.value.trim() ? portInp.value.trim() : "27015";
            pathInp.value = `/var/lib/springs/volumes/${cleanUser}/${game}_${port}`;
        }

        if (ownerSel) ownerSel.addEventListener("change", updateVolumePath);
        if (gameSel)  gameSel.addEventListener("change", updateVolumePath);
        if (portInp)  portInp.addEventListener("input", updateVolumePath);
        updateVolumePath();
    });
    </script>
</body>
</html>
