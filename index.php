<?php
session_start();
require_once 'config.php';
require_once 'mailer.php';
require_once __DIR__ . '/twofa_lib.php';
require_once 'games.php';
require_once __DIR__ . '/game_icons.php';
require_once 'payments/payments_lib.php';
initMailSchema($pdo);
twofaEnsureSchema($pdo);

if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? 'client') === 'client') {
    header("Location: dashboard.php");
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    game VARCHAR(32) NOT NULL,
    location VARCHAR(64) NULL,
    server_name VARCHAR(120) NOT NULL,
    slots INT DEFAULT 32,
    ram_mb INT DEFAULT 2048,
    status ENUM('Pending','Approved','Cancelled') DEFAULT 'Pending',
    server_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
swiftPayEnsureSchema($pdo);
$payPricing = swiftPayPricingForJs($pdo);

$cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$pcol = in_array('password_hash', $cols, true) ? 'password_hash' : (in_array('pass', $cols, true) ? 'pass' : 'password');

$nodeLocations = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(location,''),'Europe') AS loc FROM boxes ORDER BY loc ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($nodeLocations)) $nodeLocations = ['Europe'];
$error = '';
$notice = '';
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
        $vLink = "https://gp.marinkovic.biz/verify.php?token=" . $newTok;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['do_login'])) {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        $st = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1");
        $st->execute([$user, $user]);
        $row = $st->fetch();
        if ($row && password_verify($pass, ($row[$pcol] ?? ''))) {
            if (twofaIsEnabled($row)) {
                // password is right, but this account uses two-factor authentication: ask for the code before logging in
                twofaStartChallenge($row, (($row['role'] ?? 'client') === 'admin') ? 'admin/nodes.php' : 'dashboard.php');
                header('Location: login_2fa.php');
                exit;
            }
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
        $orderLoc = trim($_POST['location'] ?? ($nodeLocations[0] ?? 'Europe'));
        $srvName = trim($_POST['server_name'] ?? '');
        $slots = swiftClampSlots($game, (int)($_POST['slots'] ?? 32));
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($srvName === '' || $username === '' || $password === '') {
            $error = 'Please fill in Server Name, Username, and Password to place an order.';
        } elseif (!swiftGame($game)) {
            $error = 'Please choose a valid game.';
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
                $ram = (int)swiftGame($game)['ram'];
                $oIns = $pdo->prepare("INSERT INTO orders (user_id, game, location, server_name, slots, ram_mb, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
                $oIns->execute([$uid, $game, $orderLoc, $srvName, $slots, $ram]);
                $oid = (int)$pdo->lastInsertId();
                $pay = swiftPayAttachToOrder($pdo, $oid, $game, $slots, (int)($_POST['period'] ?? 1));
                if ($pay['required']) {
                    header('Location: ' . swiftPayCheckoutPath($pay['invoice_id'], $pay['token']));
                    exit;
                }
                $uMail = $uRow['email'] ?? $email;
                if (filter_var($uMail, FILTER_VALIDATE_EMAIL)) {
                    $oHtml = wrapPanelMail("Order #{$oid} Received", "<p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p><p>We received your server order:</p><ul><li><strong>Game:</strong> " . htmlspecialchars(swiftGameLabel($game)) . "</li><li><strong>Server Name:</strong> " . htmlspecialchars($srvName) . "</li><li><strong>Slots:</strong> {$slots}</li></ul><p>You will receive another email as soon as an administrator approves and activates your server.</p>");
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
<?php require_once __DIR__ . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SwiftPanel - Game Server Hosting &amp; Client Portal</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        <?php echo swiftGameIconCss(); ?>
        html { scroll-behavior: smooth; }
        body { background: radial-gradient(1100px 520px at 78% -8%, rgba(57,255,20,.07), transparent 60%), radial-gradient(900px 480px at 0% 0%, rgba(56,189,248,.06), transparent 55%), var(--bg-main); }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
        .top { position: sticky; top: 0; z-index: 30; background: rgba(9,12,16,.82); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border-subtle); }
        .top .wrap { display: flex; align-items: center; gap: 22px; height: 62px; }
        .brand { font-size: 20px; font-weight: 800; color: #fff; text-decoration: none; letter-spacing: -.01em; }
        .brand span { color: var(--neon); }
        .top nav { display: flex; gap: 4px; margin-left: 10px; }
        .top nav a { color: var(--text-secondary); text-decoration: none; font-size: 14px; padding: 8px 12px; border-radius: 8px; }
        .top nav a:hover { color: #fff; background: #131a23; }
        .top .right { margin-left: auto; display: flex; gap: 10px; align-items: center; }
        .top .admin { color: var(--text-muted); font-size: 12px; text-decoration: none; }
        .top .admin:hover { color: var(--text-secondary); }
        @media (max-width: 760px) { .top nav { display: none; } }

        .hero { padding: 54px 0 34px; }
        .hero-grid { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(300px, 400px); gap: 40px; align-items: center; }
        @media (max-width: 900px) { .hero-grid { grid-template-columns: 1fr; gap: 28px; } .hero { padding-top: 32px; } }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: var(--neon); background: var(--neon-dim); border: 1px solid var(--border-glow); border-radius: 99px; padding: 5px 12px; margin-bottom: 18px; }
        .hero h1 { margin: 0 0 14px; font-size: clamp(30px, 4.6vw, 48px); line-height: 1.08; letter-spacing: -.025em; color: #fff; }
        .hero h1 em { font-style: normal; color: var(--neon); }
        .hero p.lead { margin: 0 0 24px; font-size: 16px; line-height: 1.6; color: var(--text-secondary); max-width: 560px; }
        .cta { display: flex; flex-wrap: wrap; gap: 10px; }
        .btn-lg { padding: 12px 22px; font-size: 14px; border-radius: 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-ghost { background: #121821; color: #fff; border: 1px solid var(--border-subtle); }
        .btn-ghost:hover { border-color: #2d3a4d; background: #161e29; }
        .gstrip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 26px; }
        .gstrip .gi { width: 38px; height: 38px; border-radius: 11px; }
        .gstrip .gi svg { width: 21px; height: 21px; }

        .login { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 16px; padding: 24px; box-shadow: 0 18px 50px rgba(0,0,0,.35); }
        .login h2 { margin: 0 0 4px; font-size: 18px; color: #fff; }
        .login .sub { margin: 0 0 18px; font-size: 13px; color: var(--text-secondary); }
        .lbl { display: block; font-size: 12px; color: var(--text-secondary); margin: 0 0 5px; }
        .form-input { width: 100%; box-sizing: border-box; background: var(--bg-input); color: #fff; border: 1px solid var(--border-subtle); border-radius: 9px; padding: 11px 12px; font-size: 14px; margin-bottom: 12px; }
        .form-input:focus { outline: none; border-color: var(--neon); box-shadow: 0 0 0 3px var(--neon-dim); }
        select.form-input { appearance: auto; }
        .login .go { width: 100%; padding: 12px; font-size: 14px; border-radius: 10px; margin-top: 2px; }
        .login .foot { margin: 14px 0 0; font-size: 12px; color: var(--text-muted); text-align: center; line-height: 1.5; }
        .alerts { margin-bottom: 18px; }
        .verify-box { margin-bottom: 14px; padding: 12px 14px; background: #161b22; border: 1px solid #f39c12; border-radius: 10px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .verify-box .form-input { margin: 0; max-width: 240px; }

        .section { padding: 34px 0; }
        .section h2.title { margin: 0 0 6px; font-size: 26px; color: #fff; letter-spacing: -.015em; }
        .section .intro { margin: 0 0 22px; color: var(--text-secondary); font-size: 15px; }
        .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
        .tab { background: #111720; color: var(--text-secondary); border: 1px solid var(--border-subtle); border-radius: 99px; padding: 7px 15px; font-size: 13px; cursor: pointer; }
        .tab:hover { color: #fff; border-color: #2d3a4d; }
        .tab.on { background: var(--neon-dim); color: var(--neon); border-color: var(--border-glow); font-weight: 600; }
        <?php echo swiftGameBannerCss(); ?>
        .games-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        .gcard.hide { display: none; }

        .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; }
        .feat { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 14px; padding: 18px; }
        .feat .ic { width: 38px; height: 38px; border-radius: 11px; background: var(--neon-dim); border: 1px solid var(--border-glow); color: var(--neon); display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
        .feat .ic svg { width: 20px; height: 20px; }
        .feat b { display: block; color: #fff; font-size: 15px; margin-bottom: 4px; }
        .feat span { color: var(--text-secondary); font-size: 13px; line-height: 1.5; }
        .footer { margin-top: 30px; border-top: 1px solid var(--border-subtle); padding: 22px 0 34px; color: var(--text-muted); font-size: 12px; display: flex; flex-wrap: wrap; gap: 8px 20px; justify-content: space-between; }
        .footer a { color: var(--text-secondary); text-decoration: none; }

        /* order dialog */
        .ovl { position: fixed; inset: 0; background: rgba(3,6,10,.72); backdrop-filter: blur(4px); z-index: 60; display: none; align-items: flex-start; justify-content: center; padding: 28px 14px; overflow-y: auto; }
        .ovl.open { display: flex; }
        .dlg { width: 100%; max-width: 640px; background: var(--bg-card); border: 1px solid var(--border-glow); border-radius: 18px; padding: 24px; box-shadow: 0 30px 80px rgba(0,0,0,.6); margin: auto; }
        .dlg-head { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
        .dlg-head .t { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; }
        .dlg-head .n { font-size: 20px; font-weight: 700; color: #fff; }
        .dlg-head .x { margin-left: auto; background: #121821; border: 1px solid var(--border-subtle); color: var(--text-secondary); width: 34px; height: 34px; border-radius: 10px; font-size: 18px; cursor: pointer; line-height: 1; }
        .dlg-head .x:hover { color: #fff; border-color: #2d3a4d; }
        .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        @media (max-width: 620px) { .g2, .g3 { grid-template-columns: 1fr; gap: 0; } }
        .hint { font-size: 12px; color: var(--text-secondary); margin: -4px 0 12px; min-height: 16px; }
        .total { font-size: 15px; font-weight: 700; color: var(--neon); margin: 0 0 12px; }
        .dlg .submit { width: 100%; padding: 13px; font-size: 14px; border-radius: 10px; margin-top: 4px; }
        .note { font-size: 12px; color: var(--text-muted); margin-top: 10px; text-align: center; }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="brand" href="index.php">Swift<span>Panel</span></a>
            <nav>
                <a href="#games">Games</a>
                <a href="#features">Features</a>
                <a href="#login">Client login</a>
            </nav>
            <div class="right"><a class="admin" href="admin/login.php">Admin Control</a></div>
        </div>
    </header>

    <main class="wrap">
        <section class="hero">
            <div class="alerts">
                <?php if (!empty($unverifiedUser) || isset($_GET['unverified'])): ?>
                    <form method="POST" class="verify-box">
                        <input type="text" name="resend_user" value="<?php echo htmlspecialchars($unverifiedUser); ?>" placeholder="Username or Email" class="form-input" required>
                        <button type="submit" name="resend_verification" value="1" class="btn btn-blue" style="padding:8px 14px;font-size:12px;">Resend Verification Email</button>
                    </form>
                <?php endif; ?>
                <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
            </div>

            <div class="hero-grid">
                <div>
                    <span class="eyebrow">&#9679; Game server hosting</span>
                    <h1>Your game server, <em>managed</em> from one clean panel.</h1>
                    <p class="lead">Pick a game, choose your slots and location, and run everything from the client area: live console, file manager, mods and plugins, and player statistics.</p>
                    <div class="cta">
                        <a class="btn btn-green btn-lg" href="#games">Choose a game</a>
                        <a class="btn btn-ghost btn-lg" href="#login">Client login</a>
                    </div>
                    <div class="gstrip" aria-hidden="true">
                        <?php foreach (swiftGames() as $gSlug => $gInfo): ?><span data-game="<?php echo htmlspecialchars($gSlug); ?>"><?php echo swiftGameIcon($gSlug, $gInfo); ?></span><?php endforeach; ?>
                    </div>
                </div>

                <div class="login" id="login">
                    <h2>Client area login</h2>
                    <p class="sub">Sign in to manage your servers.</p>
                    <form method="POST">
                        <label class="lbl" for="lg-user">Username or Email</label>
                        <input type="text" id="lg-user" name="username" class="form-input" placeholder="Enter username" required autocomplete="username">
                        <label class="lbl" for="lg-pass">Password</label>
                        <input type="password" id="lg-pass" name="password" class="form-input" placeholder="Enter password" required autocomplete="current-password">
                        <button type="submit" name="do_login" value="1" class="btn btn-green go">Login to Game Panel</button>
                    </form>
                    <p class="foot">No account yet? Choose a game below to order, and your account is created automatically.</p>
                </div>
            </div>
        </section>

        <section class="section" id="games">
            <h2 class="title">Order a game server</h2>
            <p class="intro">Click a game to configure and order it.</p>
            <div class="tabs" id="tabs">
                <button type="button" class="tab on" data-cat="all">All games</button>
                <?php
                $usedCats = [];
                foreach (swiftGames() as $gSlug => $gInfo) { $usedCats[swiftGameGlyph($gSlug)[0]] = true; }
                foreach (SWIFT_GAME_CATEGORIES as $ck => $cl): if (isset($usedCats[$ck])): ?>
                    <button type="button" class="tab" data-cat="<?php echo htmlspecialchars($ck); ?>"><?php echo htmlspecialchars($cl); ?></button>
                <?php endif; endforeach; ?>
            </div>
            <div class="games-grid">
                <?php $gjs = swiftGamesForJs(); foreach (swiftGames() as $gSlug => $gInfo):
                    $perSlot = (float)($payPricing['prices'][$gSlug] ?? 0);
                    $minSlots = (int)($gjs[$gSlug]['slots'][0] ?? 0);
                    $from = ($payPricing['enabled'] && $perSlot > 0 && $minSlots > 0) ? $perSlot * $minSlots : 0;
                    $maxSlots = (int)(end($gjs[$gSlug]['slots']) ?: 0);
                ?>
                    <div class="gcard game-card" tabindex="0" role="button" data-game="<?php echo htmlspecialchars($gSlug); ?>" data-cat="<?php echo htmlspecialchars(swiftGameGlyph($gSlug)[0]); ?>" data-label="<?php echo htmlspecialchars($gInfo['label']); ?>" aria-label="Order <?php echo htmlspecialchars($gInfo['label']); ?>" onclick="selectGame(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();selectGame(this);}">
                        <?php echo swiftGameBanner($gSlug, $gInfo); ?>
                        <span class="gtag"><?php echo strip_tags(html_entity_decode((string)$gInfo['tagline'])); ?></span>
                        <div class="gtext">
                            <div class="gname"><?php echo htmlspecialchars($gInfo['label']); ?></div>
                            <div class="gprice"><?php echo $from > 0 ? 'From <strong>' . htmlspecialchars($payPricing['zero_decimal'] ? number_format(round($from)) : number_format($from, 2)) . ' ' . htmlspecialchars($payPricing['currency']) . '</strong> / mo' : 'Up to ' . $maxSlots . ' slots'; ?></div>
                        </div>
                        <span class="gcta">Order &rarr;</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section" id="features">
            <h2 class="title">Everything in the client area</h2>
            <p class="intro">Included with every server.</p>
            <div class="features">
                <div class="feat"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 4 4-4 4M12 17h7"/><rect x="2.5" y="3.5" width="19" height="17" rx="3"/></svg></div><b>Live console</b><span>Watch the log in real time and send commands, with start, stop and restart buttons.</span></div>
                <div class="feat"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></div><b>File manager</b><span>Upload, edit and organise your server files from the browser.</span></div>
                <div class="feat"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 19h16"/></svg></div><b>Mods &amp; plugins</b><span>Install plugins and maps with one click from the panel.</span></div>
                <div class="feat"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></div><b>Statistics</b><span>Players online, busy hours and map rotation charts for your server.</span></div>
                <div class="feat"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></div><b>Secure sign-in</b><span>Protect your account with two-factor authentication.</span></div>
            </div>
        </section>

        <footer class="footer">
            <span>&copy; <?php echo date('Y'); ?> SwiftPanel</span>
            <span><a href="#games">Games</a> &middot; <a href="#login">Client login</a> &middot; <a href="admin/login.php">Admin</a></span>
        </footer>
    </main>

    <!-- order dialog -->
    <div class="ovl" id="order-panel" aria-hidden="true">
        <div class="dlg" role="dialog" aria-modal="true" aria-labelledby="selected-game-label">
            <div class="dlg-head">
                <span id="dlg-icon"></span>
                <div><div class="t">Configure &amp; order</div><div class="n" id="selected-game-label">Counter-Strike 1.6</div></div>
                <button type="button" class="x" onclick="closeOrder()" aria-label="Close">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="game" id="order-game-input" value="<?php echo htmlspecialchars((isset($_POST['game']) && is_string($_POST['game']) && swiftGame($_POST['game'])) ? $_POST['game'] : 'cs16'); ?>">
                <div class="g2">
                    <div>
                        <label class="lbl">Server Hostname</label>
                        <input type="text" name="server_name" class="form-input" placeholder="e.g. Balkan Public #1" value="<?php echo htmlspecialchars(isset($_POST['do_order']) ? (string)($_POST['server_name'] ?? '') : ''); ?>" required>
                    </div>
                    <div>
                        <label class="lbl">Player Slots</label>
                        <select name="slots" id="order-slots" class="form-input">
                            <option value="12">12 Slots</option>
                            <option value="16">16 Slots</option>
                            <option value="24">24 Slots</option>
                            <option value="32" selected>32 Slots</option>
                        </select>
                    </div>
                </div>
                <div id="order-hint" class="hint"></div>
                <?php if ($payPricing['enabled']): ?>
                <label class="lbl">Billing Period</label>
                <select name="period" id="order-period" class="form-input">
                    <?php foreach (swiftPayPeriods() as $pm): ?>
                        <option value="<?php echo (int)$pm; ?>"><?php echo $pm === 1 ? '1 month' : $pm . ' months'; ?><?php echo $payPricing['discounts'][$pm] > 0 ? ' (save ' . rtrim(rtrim(number_format($payPricing['discounts'][$pm], 2, '.', ''), '0'), '.') . '%)' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="order-price" class="total"></div>
                <?php endif; ?>
                <div><label class="lbl">Server Location</label><select name="location" class="form-input"><?php foreach ($nodeLocations as $nl): ?><option value="<?php echo htmlspecialchars($nl); ?>"><?php echo htmlspecialchars($nl); ?></option><?php endforeach; ?></select><div id="location-ping-bar" style="display:flex;flex-wrap:wrap;gap:6px;margin:-4px 0 12px;"><span style="font-size:11px;color:#94a3b8;">Testing ping to locations...</span></div></div>
                <div class="g3">
                    <div>
                        <label class="lbl">Client Username</label>
                        <input type="text" name="username" class="form-input" placeholder="Choose or existing user" value="<?php echo htmlspecialchars(isset($_POST['do_order']) ? (string)($_POST['username'] ?? '') : ''); ?>" required>
                    </div>
                    <div>
                        <label class="lbl">Email Address</label>
                        <input type="email" name="email" class="form-input" placeholder="you@example.com" value="<?php echo htmlspecialchars(isset($_POST['do_order']) ? (string)($_POST['email'] ?? '') : ''); ?>">
                    </div>
                    <div>
                        <label class="lbl">Account Password</label>
                        <input type="password" name="password" class="form-input" placeholder="Password" required>
                    </div>
                </div>
                <button type="submit" id="order-submit" name="do_order" value="1" class="btn btn-green submit">Submit Server Order</button>
                <div class="note">Already have an account? Enter your username and password and the server is added to it.</div>
            </form>
        </div>
    </div>
    <script>
        var SWIFT_GAMES = <?php echo json_encode(swiftGamesForJs(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function fillSlots(slug) {
            var g = SWIFT_GAMES[slug];
            var sel = document.getElementById('order-slots');
            var hint = document.getElementById('order-hint');
            if (!g || !sel) return;
            sel.innerHTML = '';
            g.slots.forEach(function (n) {
                var o = document.createElement('option');
                o.value = n;
                o.textContent = n + ' Slots';
                if (n === g.slots_default) o.selected = true;
                sel.appendChild(o);
            });
            if (hint) hint.textContent = g.hint || '';
            if (typeof updatePrice === 'function') updatePrice();
        }
        var SWIFT_PRICING = <?php echo json_encode($payPricing, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function relabelSlots(per, disc, months) {
            var sel = document.getElementById('order-slots');
            if (!sel) return;
            Array.prototype.forEach.call(sel.options, function (o) {
                var n = parseInt(o.value, 10);
                var label = n + ' Slots';
                if (per > 0) {
                    var t = Math.round(per * n * months * (100 - disc) + 0.0000001) / 100;
                    if (SWIFT_PRICING.zero_decimal) t = Math.round(t);
                    label += ' \u2014 ' + (SWIFT_PRICING.zero_decimal ? String(t) : t.toFixed(2)) + ' ' + SWIFT_PRICING.currency + (months > 1 ? ' / ' + months + ' mo' : ' / mo');
                }
                o.textContent = label;
            });
        }

        function updatePrice() {
            var box = document.getElementById('order-price');
            var btn = document.getElementById('order-submit');
            if (!SWIFT_PRICING.enabled || !box) return;
            var game = document.getElementById('order-game-input').value;
            var slots = parseInt(document.getElementById('order-slots').value, 10) || 0;
            var months = parseInt(document.getElementById('order-period').value, 10) || 1;
            var per = SWIFT_PRICING.prices[game] || 0;
            var disc = SWIFT_PRICING.discounts[months] || 0;
            relabelSlots(per, disc, months);
            var total = Math.round(per * slots * months * (100 - disc) + 0.0000001) / 100;
            if (SWIFT_PRICING.zero_decimal) total = Math.round(total);
            if (total > 0) {
                box.textContent = 'Total: ' + (SWIFT_PRICING.zero_decimal ? String(total) : total.toFixed(2)) + ' ' + SWIFT_PRICING.currency + (months > 1 ? ' for ' + months + ' months' : ' per month');
                if (btn) btn.textContent = 'Continue to PayPal';
            } else {
                box.textContent = 'No payment required for this plan.';
                if (btn) btn.textContent = 'Submit Server Order';
            }
        }
        (function () {
            var sl = document.getElementById('order-slots');
            var pe = document.getElementById('order-period');
            if (sl) sl.addEventListener('change', updatePrice);
            if (pe) pe.addEventListener('change', updatePrice);
        })();
        var ICONS = {};
        document.querySelectorAll('.gstrip [data-game]').forEach(function (w) { var i = w.querySelector('.gi'); if (i) ICONS[w.dataset.game] = i.outerHTML; });
        function openOrderFor(slug, label) {
            document.getElementById('order-game-input').value = slug;
            document.getElementById('selected-game-label').textContent = label;
            document.getElementById('dlg-icon').innerHTML = ICONS[slug] || '';
            fillSlots(slug);
            var p = document.getElementById('order-panel');
            p.classList.add('open'); p.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            var f = p.querySelector('input[name=server_name]'); if (f) setTimeout(function () { f.focus(); }, 60);
        }
        function selectGame(card) { openOrderFor(card.dataset.game, card.dataset.label); }
        function closeOrder() {
            var p = document.getElementById('order-panel');
            p.classList.remove('open'); p.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
        document.getElementById('order-panel').addEventListener('mousedown', function (e) { if (e.target === this) closeOrder(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeOrder(); });
        document.querySelectorAll('#tabs .tab').forEach(function (t) {
            t.addEventListener('click', function () {
                document.querySelectorAll('#tabs .tab').forEach(function (x) { x.classList.toggle('on', x === t); });
                document.querySelectorAll('.game-card').forEach(function (c) { c.classList.toggle('hide', t.dataset.cat !== 'all' && c.dataset.cat !== t.dataset.cat); });
            });
        });
        fillSlots(document.getElementById('order-game-input').value);
<?php if (!empty($_POST['do_order']) && !empty($error)): ?>
        (function () { var g = document.getElementById('order-game-input').value, c = document.querySelector('.game-card[data-game="' + g + '"]'); if (c) openOrderFor(g, c.dataset.label); })();
<?php endif; ?>
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
        const fastest = valid.length ? valid[0].location : null;

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
<?php require_once __DIR__ . '/legal_lib.php'; echo swiftFooterHtml(); ?>
</body>
</html>
