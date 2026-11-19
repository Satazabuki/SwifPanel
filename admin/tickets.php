<?php
require_once 'guard.php';
require_once '../config.php';

$adminId = (int)$_SESSION['user_id'];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['admin_reply'])) {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if ($tid > 0 && $message !== '') {
            $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?, ?, 1, ?)")->execute([$tid, $adminId, $message]);
            $pdo->prepare("UPDATE tickets SET status = 'Answered', updated_at = NOW() WHERE id = ?")->execute([$tid]);
            $notice = 'Staff reply sent.';
        }
    } elseif (isset($_POST['toggle_status'])) {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['Open', 'Answered', 'Closed'], true) ? $_POST['new_status'] : 'Closed';
        $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$newStatus, $tid]);
        $notice = "Ticket #{$tid} marked as {$newStatus}.";
    }
}

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$activeTicket = null;
$replies = [];
if ($viewId > 0) {
    $tStmt = $pdo->prepare("SELECT t.*, u.username AS client_name, s.name AS server_name, s.ip_address, s.port FROM tickets t JOIN users u ON t.user_id = u.id LEFT JOIN servers s ON t.server_id = s.id WHERE t.id = ?");
    $tStmt->execute([$viewId]);
    $activeTicket = $tStmt->fetch();
    if ($activeTicket) {
        $rStmt = $pdo->prepare("SELECT r.*, u.username FROM ticket_replies r JOIN users u ON r.user_id = u.id WHERE r.ticket_id = ? ORDER BY r.id ASC");
        $rStmt->execute([$viewId]);
        $replies = $rStmt->fetchAll();
    }
}
$tickets = $pdo->query("SELECT t.*, u.username AS client_name, s.name AS server_name FROM tickets t JOIN users u ON t.user_id = u.id LEFT JOIN servers s ON t.server_id = s.id ORDER BY FIELD(t.status,'Open','Answered','Closed'), t.updated_at DESC")->fetchAll();
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Support Tickets - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 1180px; margin: 24px auto; padding: 0 18px; }
        .t-table { width: 100%; border-collapse: collapse; }
        .t-table th, .t-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #262c3a; font-size: 13px; }
        .t-table th { color: #94a3b8; background: #141820; }
        .msg-card { background: #141820; border: 1px solid #262c3a; border-radius: 6px; padding: 14px 16px; margin-bottom: 12px; }
        .msg-card.staff { border-left: 3px solid #e74c3c; background: #1c1518; }
        .msg-meta { display: flex; justify-content: space-between; font-size: 12px; color: #94a3b8; margin-bottom: 8px; }
        .msg-body { white-space: pre-wrap; font-size: 13px; line-height: 1.5; color: #e6edf3; }
        .form-input { width: 100%; box-sizing: border-box; background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 9px 11px; font-size: 13px; }
        .badge-open { background: rgba(231,76,60,0.2); color: #e74c3c; }
        .badge-answered { background: rgba(46,204,113,0.2); color: #2ecc71; }
        .badge-closed { background: rgba(149,165,166,0.2); color: #95a5a6; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'tickets'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($activeTicket): ?>
            <div class="box">
                <div class="box-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Ticket #<?php echo (int)$activeTicket['id']; ?>: <strong><?php echo htmlspecialchars($activeTicket['subject']); ?></strong> (Client: <?php echo htmlspecialchars($activeTicket['client_name']); ?>)</span>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <form method="POST" style="margin:0;"><input type="hidden" name="ticket_id" value="<?php echo (int)$activeTicket['id']; ?>"><input type="hidden" name="new_status" value="<?php echo $activeTicket['status'] !== 'Closed' ? 'Closed' : 'Open'; ?>"><button type="submit" name="toggle_status" value="1" class="btn <?php echo $activeTicket['status'] !== 'Closed' ? 'btn-red' : 'btn-green'; ?>" style="padding:4px 10px;font-size:12px;"><?php echo $activeTicket['status'] !== 'Closed' ? 'Close Ticket' : 'Reopen Ticket'; ?></button></form>
                        <a href="tickets.php" class="btn" style="padding:4px 10px;font-size:12px;">&larr; All Tickets</a>
                    </div>
                </div>
                <div class="box-content">
                    <?php foreach ($replies as $r): ?>
                        <div class="msg-card <?php echo !empty($r['is_admin']) ? 'staff' : ''; ?>">
                            <div class="msg-meta"><span><strong><?php echo htmlspecialchars($r['username']); ?></strong> <?php echo !empty($r['is_admin']) ? '<span class="badge badge-offline" style="margin-left:6px;">ADMIN STAFF</span>' : '<span class="badge" style="margin-left:6px;">CLIENT</span>'; ?></span><span><?php echo htmlspecialchars($r['created_at']); ?></span></div>
                            <div class="msg-body"><?php echo htmlspecialchars($r['message']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <form method="POST" style="margin-top:16px;"><input type="hidden" name="ticket_id" value="<?php echo (int)$activeTicket['id']; ?>"><textarea name="message" rows="4" class="form-input" placeholder="Write response..." required></textarea><button type="submit" name="admin_reply" value="1" class="btn btn-green" style="margin-top:10px;">Send Staff Reply</button></form>
                </div>
            </div>
        <?php else: ?>
            <div class="box">
                <div class="box-header">All Client Support Tickets</div>
                <div class="box-content" style="padding:0;">
                    <table class="t-table">
                        <thead><tr><th>ID</th><th>Client</th><th>Subject</th><th>Server</th><th>Priority</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($tickets as $t): $cls = $t['status'] === 'Answered' ? 'badge-answered' : ($t['status'] === 'Closed' ? 'badge-closed' : 'badge-open'); ?>
                                <tr><td>#<?php echo (int)$t['id']; ?></td><td><strong><?php echo htmlspecialchars($t['client_name']); ?></strong></td><td><?php echo htmlspecialchars($t['subject']); ?></td><td><?php echo htmlspecialchars($t['server_name'] ?: 'General'); ?></td><td><?php echo htmlspecialchars($t['priority']); ?></td><td><span class="badge <?php echo $cls; ?>"><?php echo strtoupper(htmlspecialchars($t['status'])); ?></span></td><td><?php echo htmlspecialchars($t['updated_at']); ?></td><td><a href="tickets.php?view=<?php echo (int)$t['id']; ?>" class="btn btn-green" style="padding:4px 10px;font-size:12px;">Reply / Manage</a></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
