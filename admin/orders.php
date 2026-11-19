<?php
require_once 'guard.php';
require_once '../config.php';
require_once '../mailer.php';
require_once '../port_helper.php';
require_once '../games.php';
require_once '../payments/payments_lib.php';

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

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oid = (int)($_POST['order_id'] ?? 0);

    if (isset($_POST['cancel_order']) && $oid > 0) {
        $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ? AND status = 'Pending'")->execute([$oid]);
        swiftPayCancelOrderInvoices($pdo, $oid);
        $payState = $pdo->prepare("SELECT payment_status FROM orders WHERE id = ?");
        $payState->execute([$oid]);
        $notice = "Order #{$oid} has been cancelled." . ($payState->fetchColumn() === 'Paid' ? ' It was already paid - refund it on the Payments page if needed.' : '');
    } elseif (isset($_POST['approve_order']) && $oid > 0) {
        $boxId = (int)($_POST['box_id'] ?? 0);
        $oStmt = $pdo->prepare("SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ? AND o.status = 'Pending'");
        $oStmt->execute([$oid]);
        $order = $oStmt->fetch();

        $bStmt = $pdo->prepare("SELECT * FROM boxes WHERE id = ?");
        $bStmt->execute([$boxId]);
        $box = $bStmt->fetch();

        if (!$order || !$box) {
            $error = 'Invalid order or target node selected.';
        } elseif (($order['payment_status'] ?? 'Free') === 'Unpaid') {
            $error = "Order #{$oid} has not been paid yet. Wait for the PayPal payment, or record an offline payment on the Payments page.";
        } elseif (($order['payment_status'] ?? 'Free') === 'Refunded') {
            $error = "The payment for order #{$oid} was refunded, so it cannot be approved. Cancel the order instead.";
        } elseif (($port = swiftAllocatePort($pdo, $box, $order['game'])) <= 0) {
            $error = 'No free game port is available on this node for ' . swiftGameLabel($order['game']) . '.';
        } else {

            $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $order['username']) ?: 'client';
            $installPath = "/var/lib/springs/volumes/{$safeUser}/{$order['game']}_{$port}";
            $screenName = "{$order['game']}_{$port}_" . substr(md5(uniqid('', true)), 0, 5);
            $defaultMap = swiftDefaultMap($order['game']);

            $ins = $pdo->prepare("INSERT INTO servers (user_id, box_id, game, name, ip_address, port, max_players, start_map_or_world, ram_mb, install_path, screen_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'installing')");
            $ins->execute([
                (int)$order['user_id'],
                $boxId,
                $order['game'],
                $order['server_name'],
                $box['ip_address'],
                $port,
                (int)$order['slots'],
                $defaultMap,
                (int)$order['ram_mb'],
                $installPath,
                $screenName
            ]);
            $newServerId = (int)$pdo->lastInsertId();
            if (($order['payment_status'] ?? 'Free') === 'Paid' && (int)($order['period_months'] ?? 0) > 0) {
                // The paid period starts when the server is created.
                $pdo->prepare("UPDATE servers SET expires_at = DATE_ADD(NOW(), INTERVAL ? MONTH), renew_months = ? WHERE id = ?")
                    ->execute([(int)$order['period_months'], (int)$order['period_months'], $newServerId]);
            }

            $apiPort = (int)($box['ssh_port'] ?: 8085);
            $ch = curl_init("http://{$box['ip_address']}:{$apiPort}/api/servers/install");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'game' => $order['game'],
                    'install_path' => $installPath,
                    'screen_name' => $screenName
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $box['ssh_password']],
                CURLOPT_TIMEOUT => 8
            ]);
            curl_exec($ch);
            curl_close($ch);

            $pdo->prepare("UPDATE orders SET status = 'Approved', server_id = ? WHERE id = ?")->execute([$newServerId, $oid]);
            if (filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $aHtml = wrapPanelMail("Your Game Server is Active!", "<p>Hello <strong>" . htmlspecialchars($order['username']) . "</strong>,</p><p>Your order <strong>#{$oid}</strong> has been approved and your server is being installed now:</p><ul><li><strong>Server Name:</strong> " . htmlspecialchars($order['server_name']) . "</li><li><strong>Game:</strong> " . strtoupper(htmlspecialchars($order['game'])) . "</li><li><strong>Connect IP:Port:</strong> <code>{$box['ip_address']}:{$port}</code></li><li><strong>Slots:</strong> {$order['slots']}</li></ul><p><a href='https://marinkovic.biz/dashboard.php' style='display:inline-block;padding:10px 18px;background:#2ecc71;color:#000;font-weight:bold;text-decoration:none;border-radius:5px;'>Open Game Panel</a></p>");
                sendSmtpMail($pdo, $order['email'], "Server Activated: " . $order['server_name'], $aHtml);
            }
            $notice = "Order #{$oid} approved! Server #{$newServerId} created on {$box['ip_address']}:{$port} and installation started.";
        }
    }
}

$boxes = $pdo->query("SELECT id, name, ip_address FROM boxes ORDER BY id ASC")->fetchAll();
$orders = $pdo->query("SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id ORDER BY FIELD(o.status,'Pending','Approved','Cancelled'), o.id DESC")->fetchAll();
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Orders - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .o-table { width: 100%; border-collapse: collapse; }
        .o-table th, .o-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #262c3a; font-size: 13px; }
        .o-table th { color: #94a3b8; background: #141820; }
        .node-select { background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 5px 8px; font-size: 12px; }
        .badge-pending { background: rgba(243,156,18,0.2); color: #f39c12; }
        .badge-approved { background: rgba(46,204,113,0.2); color: #2ecc71; }
        .badge-cancelled { background: rgba(231,76,60,0.2); color: #e74c3c; }
        .badge-paid { background: rgba(46,204,113,0.2); color: #2ecc71; }
        .badge-unpaid { background: rgba(243,156,18,0.2); color: #f39c12; }
        .badge-refunded { background: rgba(149,165,166,0.2); color: #95a5a6; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'orders'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="box">
            <div class="box-header">Game Server Orders</div>
            <div class="box-content" style="padding:0;">
                <?php if (empty($orders)): ?>
                    <p style="padding:20px;margin:0;color:#94a3b8;">No server orders have been submitted yet.</p>
                <?php else: ?>
                    <table class="o-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Client</th>
                                <th>Game</th>
                                <th>Server Name</th>
                                <th>Slots / RAM</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Approve / Cancel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o):
                                $cls = $o['status'] === 'Approved' ? 'badge-approved' : ($o['status'] === 'Cancelled' ? 'badge-cancelled' : 'badge-pending');
                            ?>
                                <tr>
                                    <td>#<?php echo (int)$o['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($o['username']); ?></strong><br><small style="color:#94a3b8;"><?php echo htmlspecialchars($o['email']); ?></small></td>
                                    <td><strong><?php echo htmlspecialchars(swiftGameLabel($o['game'])); ?><br><small style="color:#2ecc71;"><?php echo htmlspecialchars($o['location'] ?? 'Any'); ?></small></strong></td>
                                    <td><?php echo htmlspecialchars($o['server_name']); ?></td>
                                    <td><?php echo (int)$o['slots']; ?> Slots / <?php echo (int)$o['ram_mb']; ?> MB</td>
                                    <td><?php $ps = $o['payment_status'] ?? 'Free'; if ($ps === 'Free'): ?><span style="color:#94a3b8;">&mdash;</span><?php else: ?><span class="badge badge-<?php echo strtolower(htmlspecialchars($ps)); ?>"><?php echo strtoupper(htmlspecialchars($ps)); ?></span><br><small style="color:#94a3b8;"><?php echo htmlspecialchars(swiftPayMoney($o['amount'], $o['currency'])); ?> / <?php echo (int)$o['period_months']; ?> mo</small><?php endif; ?></td>
                                    <td><span class="badge <?php echo $cls; ?>"><?php echo strtoupper(htmlspecialchars($o['status'])); ?></span></td>
                                    <td><?php echo htmlspecialchars($o['created_at']); ?></td>
                                    <td>
                                        <?php if ($o['status'] === 'Pending'): ?>
                                            <form method="POST" style="display:flex;gap:6px;align-items:center;margin:0;flex-wrap:wrap;">
                                                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                                <select name="box_id" class="node-select" required>
                                                    <?php foreach ($boxes as $b): ?>
                                                        <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars((!empty($b['location']) ? $b['location'] . ' - ' : '') . $b['name'] . ' (' . $b['ip_address'] . ')'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="approve_order" value="1" class="btn btn-green" style="padding:4px 10px;font-size:12px;"<?php echo in_array(($o['payment_status'] ?? 'Free'), ['Unpaid', 'Refunded'], true) ? ' disabled title="Payment required"' : ''; ?>>Approve</button>
                                                <button type="submit" name="cancel_order" value="1" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Cancel</button>
                                            </form>
                                        <?php elseif ($o['status'] === 'Approved' && !empty($o['server_id'])): ?>
                                            <a href="manage_server.php?id=<?php echo (int)$o['server_id']; ?>" class="btn" style="padding:4px 10px;font-size:12px;">Manage Server #<?php echo (int)$o['server_id']; ?></a>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:12px;">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
