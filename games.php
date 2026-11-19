<?php
// games.php - single source of truth for the games SwiftPanel can sell.
//
// To add a game:
//   1. add an entry to swiftGames() below, and
//   2. add the matching entry to GAMES in the Springs agent (springs_install.php).
//
// Keys per game:
//   label / long_label  display names
//   slots               choices offered on the order form; slots_default is preselected
//   slots_min/slots_max server-side clamp for submitted values
//   ram                 default RAM (MB) for a new order
//   map / map_label     default start map or world name stored in servers.start_map_or_world
//   port                default game port (create-server form)
//   block               number of CONSECUTIVE ports the server uses (game port + following ports)
//   alloc               'legacy' = use allocateFreePort() from port_helper.php (node "Assigned Game Ports")
//                       'range'  = allocate from range[start, step, count] below
//   binary              file that must exist after the install (shown in the admin sidebar)
//   configs             quick-config links for files.php: [label, folder, file]
//   hint                short note shown on the order / create-server forms
//   short/tagline/color icon text, subtitle (HTML entities allowed) and gradient for the public home page cards

function swiftGames()
{
    return [
        'cs16' => [
            'short' => 'CS 1.6', 'tagline' => '1000 FPS &bull; ReHLDS engine', 'color' => ['#f39c12', '#d35400'],
            'label' => 'Counter-Strike 1.6', 'long_label' => 'Counter-Strike 1.6 (ReHLDS)',
            'slots' => [12, 16, 24, 32], 'slots_default' => 32, 'slots_min' => 10, 'slots_max' => 64,
            'ram' => 2048, 'map' => 'de_dust2', 'map_label' => 'Map', 'port' => 27015, 'block' => 1,
            'alloc' => 'legacy', 'binary' => './hlds_run', 'configs' => [], 'hint' => '',
        ],
        'cs2' => [
            'short' => 'CS2', 'tagline' => 'Sub-Tick &bull; Dedicated Linux', 'color' => ['#e67e22', '#2c3e50'],
            'label' => 'Counter-Strike 2', 'long_label' => 'Counter-Strike 2 (Dedicated)',
            'slots' => [12, 16, 24, 32], 'slots_default' => 32, 'slots_min' => 10, 'slots_max' => 64,
            'ram' => 4096, 'map' => 'de_dust2', 'map_label' => 'Map', 'port' => 27015, 'block' => 1,
            'alloc' => 'legacy', 'binary' => './game/bin/linuxsteamrt64/cs2',
            'configs' => [
                ['server.cfg', 'game/csgo/cfg', 'game/csgo/cfg/server.cfg'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'The server files are about 35-60 GB per server. A Steam GSLT token (set in springs.env) is needed to appear in the public server list.',
        ],
        'minecraft' => [
            'short' => 'MC', 'tagline' => 'PaperMC &bull; Java 25 NVMe', 'color' => ['#27ae60', '#1e8449'],
            'label' => 'Minecraft PaperMC', 'long_label' => 'Minecraft (Java / Paper / Spigot)',
            'slots' => [12, 16, 24, 32], 'slots_default' => 32, 'slots_min' => 10, 'slots_max' => 64,
            'ram' => 2048, 'map' => 'world', 'map_label' => 'World', 'port' => 25565, 'block' => 1,
            'alloc' => 'legacy', 'binary' => 'server.jar', 'configs' => [], 'hint' => '',
        ],
        'gmod' => [
            'short' => 'GMOD', 'tagline' => 'Sandbox &bull; DarkRP &bull; TTT', 'color' => ['#3498db', '#1f5f8b'],
            'label' => "Garry's Mod", 'long_label' => "Garry's Mod (Source)",
            'slots' => [8, 16, 24, 32, 64], 'slots_default' => 16, 'slots_min' => 2, 'slots_max' => 128,
            'ram' => 2048, 'map' => 'gm_flatgrass', 'map_label' => 'Map', 'port' => 27015, 'block' => 1,
            'alloc' => 'legacy', 'binary' => './srcds_run',
            'configs' => [
                ['server.cfg', 'garrysmod/cfg', 'garrysmod/cfg/server.cfg'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'A Steam GSLT token (set in springs.env) is needed to appear in the public server list.',
        ],
        'tf2' => [
            'short' => 'TF2', 'tagline' => 'Classic team-based FPS', 'color' => ['#e74c3c', '#922b21'],
            'label' => 'Team Fortress 2', 'long_label' => 'Team Fortress 2 (Source)',
            'slots' => [12, 16, 24, 32], 'slots_default' => 24, 'slots_min' => 2, 'slots_max' => 100,
            'ram' => 2048, 'map' => 'ctf_2fort', 'map_label' => 'Map', 'port' => 27015, 'block' => 1,
            'alloc' => 'legacy', 'binary' => './srcds_run',
            'configs' => [
                ['server.cfg', 'tf/cfg', 'tf/cfg/server.cfg'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'A Steam GSLT token (set in springs.env) is needed to appear in the public server list.',
        ],
        'l4d2' => [
            'short' => 'L4D2', 'tagline' => 'Co-op zombie survival', 'color' => ['#8e44ad', '#4a235a'],
            'label' => 'Left 4 Dead 2', 'long_label' => 'Left 4 Dead 2 (Source)',
            'slots' => [4, 8], 'slots_default' => 4, 'slots_min' => 2, 'slots_max' => 8,
            'ram' => 2048, 'map' => 'c1m1_hotel', 'map_label' => 'Map', 'port' => 27015, 'block' => 1,
            'alloc' => 'legacy', 'binary' => './srcds_run',
            'configs' => [
                ['server.cfg', 'left4dead2/cfg', 'left4dead2/cfg/server.cfg'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'Co-op and Versus, up to 8 players.',
        ],
        'rust' => [
            'short' => 'RUST', 'tagline' => 'Survival &bull; Procedural maps', 'color' => ['#cd412b', '#5b2a1d'],
            'label' => 'Rust', 'long_label' => 'Rust (Dedicated)',
            'slots' => [25, 50, 100, 150], 'slots_default' => 50, 'slots_min' => 5, 'slots_max' => 500,
            'ram' => 8192, 'map' => 'Procedural Map', 'map_label' => 'Level', 'port' => 28015, 'block' => 4,
            'alloc' => 'range', 'range' => ['start' => 28015, 'step' => 10, 'count' => 50], 'binary' => './RustDedicated',
            'configs' => [
                ['server.cfg', 'server/rust/cfg', 'server/rust/cfg/server.cfg'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'Needs about 8 GB RAM. Uses 4 consecutive ports (game, query, RCON, Rust+). RCON password is in springs.env.',
        ],
        'valheim' => [
            'short' => 'VALH', 'tagline' => 'Viking co-op &bull; up to 10 players', 'color' => ['#5dade2', '#1b4f72'],
            'label' => 'Valheim', 'long_label' => 'Valheim (Dedicated)',
            'slots' => [10], 'slots_default' => 10, 'slots_min' => 10, 'slots_max' => 10,
            'ram' => 4096, 'map' => 'Dedicated', 'map_label' => 'World', 'port' => 2456, 'block' => 3,
            'alloc' => 'range', 'range' => ['start' => 2456, 'step' => 10, 'count' => 30], 'binary' => 'valheim_server.x86_64',
            'configs' => [
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'Up to 10 players. Uses 3 consecutive ports. The join password is in springs.env.',
        ],
        'zomboid' => [
            'short' => 'ZOMB', 'tagline' => 'Zombie survival sandbox', 'color' => ['#7d8f3a', '#2c3b12'],
            'label' => 'Project Zomboid', 'long_label' => 'Project Zomboid (Dedicated)',
            'slots' => [8, 16, 32, 64], 'slots_default' => 16, 'slots_min' => 2, 'slots_max' => 100,
            'ram' => 6144, 'map' => 'servertest', 'map_label' => 'Server name', 'port' => 16261, 'block' => 4,
            'alloc' => 'range', 'range' => ['start' => 16261, 'step' => 10, 'count' => 30], 'binary' => 'ProjectZomboid64',
            'configs' => [
                ['servertest.ini', 'Zomboid/Server', 'Zomboid/Server/servertest.ini'],
                ['SandboxVars.lua', 'Zomboid/Server', 'Zomboid/Server/servertest_SandboxVars.lua'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'Uses 4 consecutive UDP ports. Admin and join passwords are in springs.env.',
        ],
        '7dtd' => [
            'short' => '7DTD', 'tagline' => 'Survival horror &bull; Crafting', 'color' => ['#af601a', '#4a2c0a'],
            'label' => '7 Days to Die', 'long_label' => '7 Days to Die (Dedicated)',
            'slots' => [4, 8, 16, 32], 'slots_default' => 8, 'slots_min' => 2, 'slots_max' => 64,
            'ram' => 8192, 'map' => 'Navezgane', 'map_label' => 'World', 'port' => 26900, 'block' => 4,
            'alloc' => 'range', 'range' => ['start' => 26900, 'step' => 10, 'count' => 10], 'binary' => '7DaysToDieServer.x86_64',
            'configs' => [
                ['serverconfig.xml', '', 'serverconfig.xml'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'Needs about 8 GB RAM. The first start generates the world and can take several minutes.',
        ],
        'palworld' => [
            'short' => 'PAL', 'tagline' => 'Open-world creature survival', 'color' => ['#16a085', '#0b5345'],
            'label' => 'Palworld', 'long_label' => 'Palworld (Dedicated)',
            'slots' => [8, 16, 24, 32], 'slots_default' => 16, 'slots_min' => 1, 'slots_max' => 32,
            'ram' => 16384, 'map' => 'default', 'map_label' => 'World', 'port' => 8211, 'block' => 1,
            'alloc' => 'range', 'range' => ['start' => 8211, 'step' => 10, 'count' => 30], 'binary' => 'Pal/Binaries/Linux/PalServer-Linux-Shipping',
            'configs' => [
                ['PalWorldSettings.ini', 'Pal/Saved/Config/LinuxServer', 'Pal/Saved/Config/LinuxServer/PalWorldSettings.ini'],
                ['springs.env (settings)', '', 'springs.env'],
            ],
            'hint' => 'Needs 16 GB RAM or more (memory use grows over time; schedule regular restarts).',
        ],
    ];
}

function swiftGame($slug)
{
    $all = swiftGames();
    return isset($all[$slug]) ? $all[$slug] : null;
}

function swiftGameLabel($slug)
{
    $g = swiftGame($slug);
    return $g ? $g['label'] : strtoupper((string)$slug);
}

function swiftDefaultMap($slug)
{
    $g = swiftGame($slug);
    return $g ? $g['map'] : 'de_dust2';
}

function swiftPortBlock($slug)
{
    $g = swiftGame($slug);
    return $g ? max(1, (int)$g['block']) : 1;
}

function swiftClampSlots($slug, $value)
{
    $g = swiftGame($slug);
    if (!$g) {
        return max(10, min(64, (int)$value));
    }
    return max((int)$g['slots_min'], min((int)$g['slots_max'], (int)$value));
}

// Data for the order / create-server form JavaScript.
function swiftGamesForJs()
{
    $out = [];
    foreach (swiftGames() as $slug => $g) {
        $out[$slug] = [
            'label' => $g['label'], 'slots' => $g['slots'], 'slots_default' => $g['slots_default'],
            'ram' => $g['ram'], 'map' => $g['map'], 'map_label' => $g['map_label'],
            'port' => $g['port'], 'block' => $g['block'], 'binary' => $g['binary'], 'hint' => $g['hint'],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Port allocation
// ---------------------------------------------------------------------------

// Every port taken on a node, including the extra consecutive ports of multi-port games.
function swiftUsedPorts(PDO $pdo, $boxId)
{
    $used = [];
    $st = $pdo->prepare("SELECT port, game FROM servers WHERE box_id = ?");
    $st->execute([(int)$boxId]);
    foreach ($st->fetchAll() as $row) {
        $p = (int)$row['port'];
        $b = swiftPortBlock((string)$row['game']);
        for ($i = 0; $i < $b; $i++) {
            $used[$p + $i] = true;
        }
    }
    return $used;
}

// True when $port .. $port+block-1 is free on the node for this game.
function swiftPortBlockFree(PDO $pdo, array $box, $game, $port)
{
    $port = (int)$port;
    $b = swiftPortBlock($game);
    if ($port < 1024 || $port + $b - 1 > 65535) {
        return false;
    }
    $used = swiftUsedPorts($pdo, (int)$box['id']);
    for ($i = 0; $i < $b; $i++) {
        if (isset($used[$port + $i])) {
            return false;
        }
    }
    return true;
}

// Pick a game port for a new server. Returns 0 when nothing is free.
// 'legacy' games keep using the node's "Assigned Game Ports" via port_helper.php.
function swiftAllocatePort(PDO $pdo, array $box, $game)
{
    $g = swiftGame($game);
    if (!$g || ($g['alloc'] ?? 'legacy') === 'legacy') {
        return function_exists('allocateFreePort') ? (int)allocateFreePort($pdo, $box, $game) : 0;
    }
    $r = $g['range'];
    $used = swiftUsedPorts($pdo, (int)$box['id']);
    $b = swiftPortBlock($game);
    for ($i = 0; $i < (int)$r['count']; $i++) {
        $p = (int)$r['start'] + $i * (int)$r['step'];
        $free = true;
        for ($k = 0; $k < $b; $k++) {
            if (isset($used[$p + $k])) {
                $free = false;
                break;
            }
        }
        if ($free) {
            return $p;
        }
    }
    return 0;
}
