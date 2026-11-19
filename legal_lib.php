<?php
/**
 * Legal pages (Terms of Service, Privacy Policy) and the footer shown on client pages.
 * Texts are written by the administrator in Admin > Legal pages, one text per language.
 */

const SWIFT_LEGAL_PAGES = ['terms' => 'Terms of Service', 'privacy' => 'Privacy Policy'];

function swiftLegalEnsure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS legal_pages (
        slug VARCHAR(20) NOT NULL,
        lang VARCHAR(8) NOT NULL,
        title VARCHAR(160) NOT NULL DEFAULT '',
        body MEDIUMTEXT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (slug, lang)
    )");
}

/** @return array{title:string,body:string,updated_at:?string}|null */
function swiftLegalGet(PDO $pdo, string $slug, string $lang): ?array
{
    swiftLegalEnsure($pdo);
    $st = $pdo->prepare("SELECT title, body, updated_at FROM legal_pages WHERE slug = ? AND lang = ?");
    $st->execute([$slug, $lang]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function swiftLegalSave(PDO $pdo, string $slug, string $lang, string $title, string $body): void
{
    swiftLegalEnsure($pdo);
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("SELECT 1 FROM legal_pages WHERE slug = ? AND lang = ?");
    $st->execute([$slug, $lang]);
    if ($st->fetchColumn()) {
        $pdo->prepare("UPDATE legal_pages SET title = ?, body = ?, updated_at = ? WHERE slug = ? AND lang = ?")->execute([$title, $body, $now, $slug, $lang]);
    } else {
        $pdo->prepare("INSERT INTO legal_pages (slug, lang, title, body, updated_at) VALUES (?, ?, ?, ?, ?)")->execute([$slug, $lang, $title, $body, $now]);
    }
}

/** Plain text with light formatting -> safe HTML. "# Heading", "## Subheading", "- list item", blank line = new paragraph, web addresses become links. */
function swiftLegalRender(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $inline = function (string $s): string {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('~\*\*(.+?)\*\*~u', '<strong>$1</strong>', $s);
        $s = preg_replace('~\b(https?://[^\s<]+[^\s<.,;:!?)])~u', '<a href="$1" rel="noopener noreferrer" target="_blank">$1</a>', $s);
        $s = preg_replace('~\b([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b~', '<a href="mailto:$1">$1</a>', $s);
        return $s;
    };
    $out = '';
    $list = false;
    $para = [];
    $flush = function () use (&$out, &$para, $inline) {
        if ($para) {
            $out .= '<p>' . implode('<br>', array_map($inline, $para)) . '</p>';
            $para = [];
        }
    };
    foreach (explode("\n", $text) as $line) {
        $t = rtrim($line);
        if (preg_match('/^\s*-\s+(.*)$/u', $t, $m)) {
            $flush();
            if (!$list) {
                $out .= '<ul>';
                $list = true;
            }
            $out .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if ($list) {
            $out .= '</ul>';
            $list = false;
        }
        if (preg_match('/^(#{1,2})\s+(.*)$/u', $t, $m)) {
            $flush();
            $tag = strlen($m[1]) === 1 ? 'h2' : 'h3';
            $out .= "<$tag>" . $inline($m[2]) . "</$tag>";
        } elseif (trim($t) === '') {
            $flush();
        } else {
            $para[] = $t;
        }
    }
    $flush();
    if ($list) {
        $out .= '</ul>';
    }
    return $out;
}

/** URL path of the panel root ("" when it is the web root), so footer links also work from /mods or /admin. */
function swiftLegalBase(): string
{
    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $here = realpath(__DIR__);
    if ($root && $here && strpos($here, $root) === 0) {
        return rtrim(str_replace('\\', '/', substr($here, strlen($root))), '/');
    }
    return '';
}

/** The footer for client pages (call right before </body>). */
function swiftFooterHtml(): string
{
    $b = htmlspecialchars(swiftLegalBase(), ENT_QUOTES);
    return '<style>.sp-footer{margin:40px auto 0;padding:22px 18px 28px;border-top:1px solid var(--border-subtle,#1f2937);text-align:center;font-size:13px;color:var(--text-muted,#6b7785);line-height:1.8}'
        . '.sp-footer a{color:var(--text-secondary,#9aa7b5);text-decoration:none;margin:0 10px}.sp-footer a:hover{color:var(--neon,#39ff14)}.sp-footer .sep{opacity:.4}</style>'
        . '<footer class="sp-footer"><span>&copy; ' . date('Y') . ' SwiftPanel</span><span class="sep"> &middot; </span>'
        . '<a href="' . $b . '/terms.php">Terms of Service</a><span class="sep">&middot;</span>'
        . '<a href="' . $b . '/privacy.php">Privacy Policy</a></footer>'
        // keeps the footer at the bottom of the window when a page is shorter than the window
        . '<script>(function(){var f=document.querySelector("footer.sp-footer");if(!f)return;var busy=false;'
        . 'function fit(){if(busy)return;busy=true;f.style.marginTop="40px";'
        . 'var r=f.getBoundingClientRect();var gap=window.innerHeight-(r.bottom+window.pageYOffset);'
        . 'if(gap>0)f.style.marginTop=(40+gap)+"px";busy=false;}'
        . 'fit();window.addEventListener("load",fit);window.addEventListener("resize",fit);'
        . 'if(window.ResizeObserver){var t=0;new ResizeObserver(function(){clearTimeout(t);t=setTimeout(fit,30);}).observe(document.body);}'
        . 'else{setInterval(fit,800);}})();</script>';
}

/** Shows one public legal page (used by terms.php and privacy.php). */
function swiftLegalShowPage(string $slug): void
{
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/lang.php';
    $names = SWIFT_LEGAL_PAGES;
    if (!isset($names[$slug])) {
        http_response_code(404);
        exit;
    }
    $lang = swiftLang();
    $row = swiftLegalGet($pdo, $slug, $lang);
    $fallback = false;
    if (!$row || trim((string)$row['body']) === '') {
        $row = swiftLegalGet($pdo, $slug, 'en');
        $fallback = $lang !== 'en';
    }
    $has = $row && trim((string)$row['body']) !== '';
    $title = ($has && trim($row['title']) !== '') ? $row['title'] : $names[$slug];
    $b = htmlspecialchars(swiftLegalBase(), ENT_QUOTES);
    swiftLangStart();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - SwiftPanel</title>
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/style.css?v=3">
    <style>
        .legal{max-width:820px;margin:34px auto;padding:0 18px}
        .legal .box-content{line-height:1.75;color:var(--text-secondary,#b6c0cb);font-size:15px}
        .legal h2{color:#fff;font-size:20px;margin:26px 0 8px}.legal h3{color:#fff;font-size:16px;margin:20px 0 6px}
        .legal p{margin:0 0 12px}.legal ul{margin:0 0 12px;padding-left:22px}.legal a{color:var(--neon,#39ff14)}
        .legal .meta{font-size:12px;color:var(--text-muted,#6b7785);margin-top:22px}
        .legal .tabs{display:flex;gap:10px;margin-bottom:14px;font-size:13px}.legal .tabs a{color:var(--text-secondary,#9aa7b5);text-decoration:none;padding:5px 12px;border:1px solid var(--border-subtle,#1f2937);border-radius:999px}
        .legal .tabs a.on{color:var(--neon,#39ff14);border-color:var(--neon,#39ff14)}
    </style>
</head>
<body>
    <div id="header">
        <div class="logo"><a href="<?php echo $b; ?>/index.php" style="color:inherit;text-decoration:none">Swift<span>Panel</span></a></div>
        <div class="user-info"><a href="<?php echo $b; ?>/index.php">Home</a></div>
    </div>
    <div class="legal">
        <div class="tabs">
            <?php foreach ($names as $s => $n): ?><a href="<?php echo $b . '/' . $s; ?>.php" class="<?php echo $s === $slug ? 'on' : ''; ?>"><?php echo htmlspecialchars($n); ?></a><?php endforeach; ?>
        </div>
        <div class="box">
            <div class="box-header"><?php echo htmlspecialchars($title); ?></div>
            <div class="box-content">
                <?php if ($has): ?>
                    <?php if ($fallback): ?><p><em>This page is not available in your language yet; the English version is shown.</em></p><?php endif; ?>
                    <?php echo swiftLegalRender((string)$row['body']); ?>
                    <?php if (!empty($row['updated_at'])): ?><div class="meta">Last updated: <?php echo htmlspecialchars(date('M j, Y', strtotime($row['updated_at']))); ?></div><?php endif; ?>
                <?php else: ?>
                    <p>This page has not been published yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php echo swiftFooterHtml(); ?>
</body>
</html>
<?php
}
