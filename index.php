<?php
session_start();
require_once 'config.php';
require_once 'mailer.php';
initMailSchema($pdo);

if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? 'client') === 'client') {
    header("Location: dashboard.php");
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    game VARCHAR(32) NOT NULL,
    server_name VARCHAR(120) NOT NULL,
    slots INT DEFAULT 32,
    ram_mb INT DEFAULT 2048,
    status ENUM('Pending','Approved','Cancelled') DEFAULT 'Pending',
    server_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$pcol = in_array('password_hash', $cols, true) ? 'password_hash' : (in_array('pass', $cols, true) ? 'pass' : 'password');

$nodeLocations = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(location,''),'Europe') AS loc FROM boxes ORDER BY loc ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($nodeLocations)) $nodeLocations = ['Europe'];
$error = '';
$unverifiedUser = '';
if (isset($_GET['unverified'])) {
    $error = "Please verify your email address before accessing the panel.";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_verification'])) {
    $rUser = trim($_POST['resend_user'] ?? '');
    $st = $pdo->prepare("SELECT id, username, email, email_verified FROM users WHERE username = ? OR email = ? LIMIT 1");
    $st->execute([$rUser, $rUser]);
    $u = $st->fetch();
    if ($u && (int)$u['email_verified'] === 0 && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        $newTok = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE users SET verify_token = ? WHERE id = ?")->execute([$newTok, (int)$u['id']]);
        $vLink = "https://marinkovic.biz" . $newTok;
        $vHtml = wrapPanelMail("Verify Your Account", "<p>Hello <strong>" . htmlspecialchars($u['username']) . "</strong>,</p><p>Click below to verify your email address and activate login access:</p><p><a href='{$vLink}' style='display:inline-block;padding:10px 18px;background:#2ecc71;color:#000;font-weight:bold;text-decoration:none;border-radius:5px;'>Verify Email Address</a></p>");
        $mailErr = null;
        if (sendSmtpMail($pdo, $u['email'], "Verify Your SwiftPanel Account", $vHtml, $mailErr)) {
            $notice = "A new verification email has been sent to " . htmlspecialchars($u['email']) . ".";
        } else {
            $error = "Could not send verification email: " . ($mailErr ?: "SMTP error");
        }
    } else {
        $notice = "If that account exists and is unverified, a verification email has been sent.";
    }
}
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['do_login'])) {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        $st = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1");
        $st->execute([$user, $user]);
        $row = $st->fetch();
        if ($row && password_verify($pass, ($row[$pcol] ?? ''))) {
            $_SESSION['user_id'] = (int)$row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'] ?? 'client';
            header("Location: " . (($_SESSION['role'] === 'admin') ? 'admin/nodes.php' : 'dashboard.php'));
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } elseif (isset($_POST['do_order'])) {
        $game = trim($_POST['game'] ?? 'cs16');
        $orderLoc = trim($_POST['location'] ?? ($nodeLocations ?? 'Europe'));
        $srvName = trim($_POST['server_name'] ?? '');
        $slots = max(10, min(64, (int)($_POST['slots'] ?? 32)));
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($srvName === '' || $username === '' || $password === '') {
            $error = 'Please fill in Server Name, Username, and Password to place an order.';
        } else {
            $st = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $st->execute([$username]);
            $uRow = $st->fetch();

            if ($uRow) {
                if (!password_verify($password, ($uRow[$pcol] ?? ''))) {
                    $error = 'Username already exists. Please enter the correct password for that account.';
                } else {
                    $uid = (int)$uRow['id'];
                }
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $vTok = bin2hex(random_bytes(24));
                $clientEmail = $email ?: ($username . '@client.local');
                $ins = $pdo->prepare("INSERT INTO users (username, email, {$pcol}, role, email_verified, verify_token) VALUES (?, ?, ?, 'client', 0, ?)");
                $ins->execute([$username, $clientEmail, $hash, $vTok]);
                $uid = (int)$pdo->lastInsertId();
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $vLink = "https://gp.marinkovic.biz/verify.php?token=" . $vTok;
                    $vHtml = wrapPanelMail("Verify Your Account", "<p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p><p>Thank you for registering! Please click below to verify your email address:</p><p><a href='{$vLink}' style='display:inline-block;padding:10px 18px;background:#2ecc71;color:#000;font-weight:bold;text-decoration:none;border-radius:5px;'>Verify Email Address</a></p>");
                    sendSmtpMail($pdo, $email, "Verify Your SwiftPanel Account", $vHtml);
                }
            }

            if (empty($error) && !empty($uid)) {
                $ram = ($game === 'cs2') ? 4096 : 2048;
                $oIns = $pdo->prepare("INSERT INTO orders (user_id, game, location, server_name, slots, ram_mb, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
                $oIns->execute([$uid, $game, $orderLoc, $srvName, $slots, $ram]);
                $oid = (int)$pdo->lastInsertId();
                $uMail = $uRow['email'] ?? $email;
                if (filter_var($uMail, FILTER_VALIDATE_EMAIL)) {
                    $oHtml = wrapPanelMail("Order #{$oid} Received", "<p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p><p>We received your server order:</p><ul><li><strong>Game:</strong> " . strtoupper(htmlspecialchars($game)) . "</li><li><strong>Server Name:</strong> " . htmlspecialchars($srvName) . "</li><li><strong>Slots:</strong> {$slots}</li></ul><p>You will receive another email as soon as an administrator approves and activates your server.</p>");
                    sendSmtpMail($pdo, $uMail, "Server Order #{$oid} Confirmation", $oHtml);
                }
                $orderModalNotice = "Order #{$oid} submitted! Once an admin approves your order, your server will appear in your Client Area.

Also please check your email for verification link to be able to login inside Client Area.";
                $notice = "Order #{$oid} submitted! Once an admin approves your order, your server will appear in your Client Area.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SwiftPanel - Game Server Hosting &amp; Client Portal</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 28px auto; padding: 0 18px; }
        .hero-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 22px; align-items: start; }
        @media (max-width: 900px) { .hero-grid { grid-template-columns: 1fr; } }
        .games-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        @media (max-width: 680px) { .games-grid { grid-template-columns: 1fr; } }
        .game-card { background: #141820; border: 2px solid #262c3a; border-radius: 8px; padding: 18px 14px; text-align: center; cursor: pointer; transition: all 0.2s ease; }
        .game-card:hover, .game-card.selected { border-color: #2ecc71; background: #16221c; transform: translateY(-2px); }
        .game-icon { width: 64px; height: 64px; margin: 0 auto 12px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 20px; color: #fff; }
        .icon-cs16 { background: linear-gradient(135deg, #f39c12, #d35400); }
        .icon-cs2 { background: linear-gradient(135deg, #e67e22, #2c3e50); }
        .icon-mc { background: linear-gradient(135deg, #27ae60, #1e8449); }
        .game-title { font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 4px; }
        .game-sub { font-size: 12px; color: #94a3b8; margin-bottom: 12px; }
        .form-input { width: 100%; box-sizing: border-box; background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 9px 11px; font-size: 13px; margin-bottom: 10px; }
        .order-panel { margin-top: 18px; background: #141820; border: 1px solid #2ecc71; border-radius: 8px; padding: 18px; display: none; }
        .order-panel.open { display: block; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
        <div class="user-info"><a href="admin/login.php" style="color:#94a3b8;">Admin Control</a></div>
    </div>

    <div class="container">
        <?php if (!empty($unverifiedUser) || isset($_GET['unverified'])): ?>
            <form method="POST" style="margin-bottom:14px;padding:10px 12px;background:#161b22;border:1px solid #f39c12;border-radius:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" name="resend_user" value="<?php echo htmlspecialchars($unverifiedUser); ?>" placeholder="Username or Email" class="form-input" style="max-width:220px;" required>
                <button type="submit" name="resend_verification" value="1" class="btn btn-blue" style="padding:7px 12px;font-size:12px;">Resend Verification Email</button>
            </form>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <div class="hero-grid">
            <div class="box">
                <div class="box-header">Order a Game Server &mdash; Click Any Game Below</div>
                <div class="box-content">
                    <div class="games-grid">
                        <div class="game-card" data-game="cs16" data-label="Counter-Strike 1.6" onclick="selectGame(this)">
                            <div class="game-icon icon-cs16">CS 1.6</div>
                            <div class="game-title">Counter-Strike 1.6</div>
                            <div class="game-sub">1000 FPS &bull; HLDS / ReHLDS</div>
                            <span class="btn btn-green" style="padding:5px 12px;font-size:12px;">Order CS 1.6</span>
                        </div>
                        <div class="game-card" data-game="cs2" data-label="Counter-Strike 2" onclick="selectGame(this)">
                            <div class="game-icon icon-cs2">CS2</div>
                            <div class="game-title">Counter-Strike 2</div>
                            <div class="game-sub">Sub-Tick &bull; Dedicated Linux</div>
                            <span class="btn btn-green" style="padding:5px 12px;font-size:12px;">Order CS2</span>
                        </div>
                        <div class="game-card" data-game="minecraft" data-label="Minecraft PaperMC" onclick="selectGame(this)">
                            <div class="game-icon icon-mc">MC</div>
                            <div class="game-title">Minecraft</div>
                            <div class="game-sub">PaperMC &bull; Java 21 NVMe</div>
                            <span class="btn btn-green" style="padding:5px 12px;font-size:12px;">Order Minecraft</span>
                        </div>
                    </div>

                    <div id="order-panel" class="order-panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                            <strong style="font-size:15px;color:#2ecc71;">Configure &amp; Order: <span id="selected-game-label">Counter-Strike 1.6</span></strong>
                            <button type="button" class="btn" style="padding:3px 9px;font-size:11px;" onclick="closeOrder()">Close</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="game" id="order-game-input" value="cs16">
                            <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;">
                                <div>
                                    <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Server Hostname</label>
                                    <input type="text" name="server_name" class="form-input" placeholder="e.g. Balkan Public #1" required>
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Player Slots</label>
                                    <select name="slots" class="form-input">
                                        <option value="12">12 Slots</option>
                                        <option value="16">16 Slots</option>
                                        <option value="24">24 Slots</option>
                                        <option value="32" selected>32 Slots</option>
                                    </select>
                                </div>
                            </div>
                            <div style="margin-bottom:4px;"><label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Server Location</label><select name="location" class="form-input"><?php foreach ($nodeLocations as $nl): ?><option value="<?php echo htmlspecialchars($nl); ?>"><?php echo htmlspecialchars($nl); ?></option><?php endforeach; ?></select><div id="location-ping-bar" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"><span style="font-size:11px;color:#94a3b8;">Testing ping to locations...</span></div></div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                                <div>
                                    <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Client Username</label>
                                    <input type="text" name="username" class="form-input" placeholder="Choose or existing user" required>
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Email Address</label>
                                    <input type="email" name="email" class="form-input" placeholder="you@example.com">
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Account Password</label>
                                    <input type="password" name="password" class="form-input" placeholder="Password" required>
                                </div>
                            </div>
                            <button type="submit" name="do_order" value="1" class="btn btn-green">Submit Server Order</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="box">
                <div class="box-header">Client Area Login</div>
                <div class="box-content">
                    <form method="POST">
                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Username or Email</label>
                        <input type="text" name="username" class="form-input" placeholder="Enter username" required>
                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Password</label>
                        <input type="password" name="password" class="form-input" placeholder="Enter password" required>
                        <button type="submit" name="do_login" value="1" class="btn btn-green" style="width:100%;margin-top:4px;">Login to Game Panel</button>
                    </form>
                    <p style="margin:12px 0 0;font-size:12px;color:#94a3b8;text-align:center;">
                        Don't have an account? Click a game on the left to order and create your account automatically.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <script>
        function selectGame(card) {
            document.querySelectorAll('.game-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            document.getElementById('order-game-input').value = card.dataset.game;
            document.getElementById('selected-game-label').textContent = card.dataset.label;
            const panel = document.getElementById('order-panel');
            panel.classList.add('open');
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        function closeOrder() {
            document.querySelectorAll('.game-card').forEach(c => c.classList.remove('selected'));
            document.getElementById('order-panel').classList.remove('open');
        }
    </script>
<script>
(async function measureLocationPings() {
    const selects = document.querySelectorAll("select[name=\"location\"]");
    if (!selects.length) return;
    try {
        const res = await fetch("ping_nodes.php");
        const data = await res.json();
        const results = data.locations || [];

        const valid = results.filter(r => r.ms !== null).sort((a, b) => a.ms - b.ms);
        const fastest = valid.length ? valid.location : null;

        selects.forEach(sel => {
            Array.from(sel.options).forEach(opt => {
                const found = results.find(r => r.location === opt.value);
                if (found && found.ms !== null) {
                    opt.textContent = found.location + " — " + found.ms + " ms" + (found.location === fastest ? " (Best Ping)" : "");
                }
            });
            if (fastest) sel.value = fastest;
        });

        const bar = document.getElementById("location-ping-bar");
        if (bar && results.length) {
            bar.innerHTML = results.map(r => {
                const color = r.ms === null ? "#94a3b8" : (r.ms < 60 ? "#2ecc71" : (r.ms < 120 ? "#f39c12" : "#e74c3c"));
                const label = r.ms === null ? "Offline" : r.ms + " ms";
                return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:#0d1117;border:1px solid #30363d;border-radius:999px;font-size:11px;\"><span style=\"width:7px;height:7px;border-radius:50%;background:" + color + ";\"></span><strong>" + r.location + ":</strong> <span style=\"color:" + color + ";font-family:monospace;\">" + label + "</span></span>";
            }).join(" ");
        }
    } catch (e) {}
})();
</script>
<?php if (!empty($orderModalNotice)): ?>
<div id="order-confirm-modal" style="position:fixed;inset:0;background:rgba(0,0,0,0.78);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
    <div style="background:#161b22;border:1px solid #2ecc71;box-shadow:0 0 28px rgba(46,204,113,0.25);border-radius:10px;max-width:460px;width:100%;padding:24px;text-align:center;color:#e2e8f0;">
        <div style="width:48px;height:48px;margin:0 auto 14px;border-radius:50%;background:rgba(46,204,113,0.15);color:#2ecc71;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:bold;">&#10003;</div>
        <h3 style="margin:0 0 12px;color:#2ecc71;font-size:18px;">Order Submitted Successfully</h3>
        <p style="margin:0 0 12px;font-size:13.5px;line-height:1.55;color:#e2e8f0;">
            <?php echo nl2br(htmlspecialchars($orderModalNotice)); ?>
        </p>
        <button type="button" class="btn btn-green" style="margin-top:8px;padding:9px 24px;font-size:13px;font-weight:600;" onclick="document.getElementById('order-confirm-modal').remove();">Got It</button>
    </div>
</div>
<?php endif; ?>
</body>
</html>
