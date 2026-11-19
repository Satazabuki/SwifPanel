<?php
/**
 * The admin menu, shared by every admin page so no page can miss a link.
 * Set $adminNavActive (account, sbackups, legal, nodes, client_servers, create_server, users, orders, tickets, smtp, payments, backups, mods)
 * before including. Pages outside /admin may set $adminNavRoot to the site root URL path (default '..').
 */
$__navRoot = rtrim((string)($adminNavRoot ?? '..'), '/');
$__navActive = (string)($adminNavActive ?? '');
$__navItems = [
    'nodes'          => ['admin/nodes.php', 'Remote Nodes'],
    'client_servers' => ['admin/client_servers.php', 'Client Servers'],
    'create_server'  => ['admin/create_server.php', 'Create Server'],
    'users'          => ['admin/users.php', 'Users'],
    'orders'         => ['admin/orders.php', 'Orders'],
    'tickets'        => ['admin/tickets.php', 'Support Tickets'],
    'smtp'           => ['admin/smtp.php', 'SMTP / Email'],
    'payments'       => ['admin/payments.php', 'Payments'],
    'backups'        => ['admin/backups.php', 'Backups'],
    'sbackups'       => ['admin/server_backups.php', 'Server Backups'],
    'mods'           => ['mods/admin.php', 'Mods'],
    'legal'          => ['admin/legal.php', 'Legal Pages'],
    'account'        => ['admin/account.php', 'My Account'],
];
?>
    <div id="navbar">
        <ul>
<?php foreach ($__navItems as $__k => $__it): ?>
            <li><a href="<?php echo htmlspecialchars($__navRoot . '/' . $__it[0]); ?>"<?php echo $__k === $__navActive ? ' class="active"' : ''; ?>><?php echo htmlspecialchars($__it[1]); ?></a></li>
<?php endforeach; ?>
        </ul>
    </div>
