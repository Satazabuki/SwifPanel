<?php
require_once 'auth.php';
require_once 'config.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    server_id INT NULL,
    subject VARCHAR(255) NOT NULL,
    priority ENUM('Low','Medium','High') DEFAULT 'Medium',
    status ENUM('Open','Answered','Closed') DEFAULT 'Open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$userId = (int)$_SESSION['user_id'];
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_ticket'])) {
        $subject = trim($_POST['subject'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $_POST['priority'] : 'Medium';
        $serverId = !empty($_POST['server_id']) ? (int)$_POST['server_id'] : null;
        $message = trim($_POST['message'] ?? '');
        if ($subject === '' || $message === '') {
            $error = 'Please enter both a subject and a message.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO tickets (user_id, server_id, subject, priority, status) VALUES (?, ?, ?, ?, 'Open')");
            $stmt->execute([$userId, $serverId, $subject, $priority]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?, ?, 0, ?)")->execute([$newId, $userId, $message]);
            header("Location: tickets.php?view=" . $newId);
            exit;
        }
    } elseif (isset($_POST['reply_ticket'])) {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $chk = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ?");
        $chk->execute([$tid, $userId]);
        if ($chk->fetch() && $message !== '') {
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?, ?, 0, ?)")->execute([$tid, $userId, $message]);
            $pdo->prepare("UPDATE tickets SET status = 'Open', updated_at = NOW() WHERE id = ?")->execute([$tid]);
            $notice = 'Reply posted.';
        }
    } elseif (isset($_POST['close_ticket'])) {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $pdo->prepare("UPDATE tickets SET status = 'Closed' WHERE id = ? AND user_id = ?")->execute([$tid, $userId]);
        $notice = 'Ticket closed.';
    }
}

$srvStmt = $pdo->prepare("SELECT id, name, ip_address, port FROM servers WHERE user_id = ? ORDER BY name ASC");
$srvStmt->execute([$userId]);
$myServers = $srvStmt->fetchAll();

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$activeTicket = null;
$replies = [];
if ($viewId > 0) {
    $tStmt = $pdo->prepare("SELECT t.*, s.name AS server_name, s.ip_address, s.port FROM tickets t LEFT JOIN servers s ON t.server_id = s.id WHERE t.id = ? AND t.user_id = ?");
    $tStmt->execute([$viewId, $userId]);
    $activeTicket = $tStmt->fetch();
    if ($activeTicket) {
        $rStmt = $pdo->prepare("SELECT r.*, u.username FROM ticket_replies r JOIN users u ON r.user_id = u.id WHERE r.ticket_id = ? ORDER BY r.id ASC");
        $rStmt->execute([$viewId]);
        $replies = $rStmt->fetchAll();
    }
}

$listStmt = $pdo->prepare("SELECT t.*, s.name AS server_name FROM tickets t LEFT JOIN servers s ON t.server_id = s.id WHERE t.user_id = ? ORDER BY t.updated_at DESC");
$listStmt->execute([$userId]);
$tickets = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Support Tickets - SwiftPanel</title>
    <link rel="stylesheet" href="assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .t-table { width: 100%; border-collapse: collapse; }
        .t-table th, .t-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #262c3a; font-size: 13px; }
        .t-table th { color: #94a3b8; background: #141820; }
        .msg-card { background: #141820; border: 1px solid #262c3a; border-radius: 6px; padding: 14px 16px; margin-bottom: 12px; }
        .msg-card.staff { border-left: 3px solid #2ecc71; background: #121b18; }
        .msg-meta { display: flex; justify-content: space-between; font-size: 12px; color: #94a3b8; margin-bottom: 8px; }
        .msg-body { white-space: pre-wrap; font-size: 13px; line-height: 1.5; color: #e6edf3; }
        .form-input { width: 100%; box-sizing: border-box; background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 9px 11px; font-size: 13px; }
        .badge-open { background: rgba(52,152,219,0.2); color: #3498db; }
        .badge-answered { background: rgba(46,204,113,0.2); color: #2ecc71; }
        .badge-closed { background: rgba(149,165,166,0.2); color: #95a5a6; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#aaa;">Springs Docker Edition</small></div>
        <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'client'); ?></strong> | <a href="logout.php">Logout</a></div>
    </div>
    <div id="navbar">
        <ul>
            <li><a href="dashboard.php">Game Servers</a></li>
            <li><a href="tickets.php" class="active">Support Tickets</a></li>
            <li><a href="profile.php">My Profile</a></li>
        </ul>
    </div>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($activeTicket): ?>
            <div class="box">
                <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Ticket #<?php echo (int)$activeTicket['id']; ?>: <strong><?php echo htmlspecialchars($activeTicket['subject']); ?></strong></span>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <?php if ($activeTicket['status'] !== 'Closed'): ?>
                            <form method="POST" style="margin:0;"><input type="hidden" name="ticket_id" value="<?php echo (int)$activeTicket['id']; ?>"><button type="submit" name="close_ticket" value="1" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Close Ticket</button></form>
                        <?php endif; ?>
                        <a href="tickets.php" class="btn" style="padding:4px 10px;font-size:12px;">&larr; All Tickets</a>
                    </div>
                </div>
                <div class="box-content">
                    <div style="margin-bottom:14px;font-size:12px;color:#94a3b8;">Status: <strong><?php echo htmlspecialchars($activeTicket['status']); ?></strong> | Priority: <strong><?php echo htmlspecialchars($activeTicket['priority']); ?></strong></div>
                    <?php foreach ($replies as $r): ?>
                        <div class="msg-card <?php echo !empty($r['is_admin']) ? 'staff' : ''; ?>">
                            <div class="msg-meta"><span><strong><?php echo htmlspecialchars($r['username']); ?></strong> <?php echo !empty($r['is_admin']) ? '<span class="badge badge-online" style="margin-left:6px;">STAFF</span>' : ''; ?></span><span><?php echo htmlspecialchars($r['created_at']); ?></span></div>
                            <div class="msg-body"><?php echo htmlspecialchars($r['message']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($activeTicket['status'] !== 'Closed'): ?>
                        <form method="POST" style="margin-top:16px;"><input type="hidden" name="ticket_id" value="<?php echo (int)$activeTicket['id']; ?>"><textarea name="message" rows="4" class="form-input" placeholder="Write your message..." required></textarea><button type="submit" name="reply_ticket" value="1" class="btn btn-green" style="margin-top:10px;">Send Reply</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="box" style="margin-bottom:20px;">
                <div class="box-header">Open a New Support Ticket</div>
                <div class="box-content">
                    <form method="POST">
                        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                            <div><label style="display:block;margin-bottom:5px;font-size:12px;color:#94a3b8;">Subject</label><input type="text" name="subject" class="form-input" required></div>
                            <div><label style="display:block;margin-bottom:5px;font-size:12px;color:#94a3b8;">Server</label><select name="server_id" class="form-input"><option value="">-- General --</option><?php foreach ($myServers as $ms): ?><option value="<?php echo (int)$ms['id']; ?>"><?php echo htmlspecialchars($ms['name']); ?></option><?php endforeach; ?></select></div>
                            <div><label style="display:block;margin-bottom:5px;font-size:12px;color:#94a3b8;">Priority</label><select name="priority" class="form-input"><option value="Low">Low</option><option value="Medium" selected>Medium</option><option value="High">High</option></select></div>
                        </div>
                        <div style="margin-bottom:12px;"><textarea name="message" rows="4" class="form-input" placeholder="Describe your issue..." required></textarea></div>
                        <button type="submit" name="create_ticket" value="1" class="btn btn-green">Submit Ticket</button>
                    </form>
                </div>
            </div>
            <div class="box">
                <div class="box-header">My Support Tickets</div>
                <div class="box-content" style="padding:0;">
                    <table class="t-table">
                        <thead><tr><th>ID</th><th>Subject</th><th>Server</th><th>Priority</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($tickets as $t): $cls = $t['status'] === 'Answered' ? 'badge-answered' : ($t['status'] === 'Closed' ? 'badge-closed' : 'badge-open'); ?>
                                <tr><td>#<?php echo (int)$t['id']; ?></td><td><strong><?php echo htmlspecialchars($t['subject']); ?></strong></td><td><?php echo htmlspecialchars($t['server_name'] ?: 'General'); ?></td><td><?php echo htmlspecialchars($t['priority']); ?></td><td><span class="badge <?php echo $cls; ?>"><?php echo strtoupper(htmlspecialchars($t['status'])); ?></span></td><td><?php echo htmlspecialchars($t['updated_at']); ?></td><td><a href="tickets.php?view=<?php echo (int)$t['id']; ?>" class="btn" style="padding:4px 10px;font-size:12px;">View Thread</a></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
