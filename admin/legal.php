<?php
require_once 'guard.php';
require_once '../config.php';
require_once '../lang.php';
require_once '../legal_lib.php';

swiftLegalEnsure($pdo);
if (empty($_SESSION['legal_csrf'])) {
    $_SESSION['legal_csrf'] = bin2hex(random_bytes(16));
}
$notice = '';
$error = '';

$slug = (string)($_GET['p'] ?? $_POST['slug'] ?? 'terms');
if (!isset(SWIFT_LEGAL_PAGES[$slug])) {
    $slug = 'terms';
}
$lang = (string)($_GET['l'] ?? $_POST['lang'] ?? 'en');
if (!isset(SWIFT_LANGS[$lang])) {
    $lang = 'en';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['legal_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Security check failed. Please reload the page and try again.';
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = (string)($_POST['body'] ?? '');
        if (mb_strlen($title) > 160) {
            $error = 'The title is too long (up to 160 characters).';
        } elseif (strlen($body) > 500000) {
            $error = 'The text is too long.';
        } else {
            swiftLegalSave($pdo, $slug, $lang, $title, $body);
            $notice = trim($body) === '' ? 'Saved. The text is empty, so the page shows "not published yet" (or the English text).' : 'Saved. The page is live.';
        }
    }
}

$row = swiftLegalGet($pdo, $slug, $lang) ?: ['title' => '', 'body' => '', 'updated_at' => null];
$filled = [];
foreach (SWIFT_LEGAL_PAGES as $s => $n) {
    foreach (SWIFT_LANGS as $c => $l) {
        $r = swiftLegalGet($pdo, $s, $c);
        $filled[$s][$c] = $r && trim((string)$r['body']) !== '';
    }
}
?>
<?php require_once dirname(__DIR__) . '/lang.php'; swiftLangStart(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Legal Pages - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css?v=3">
    <style>
        .container { max-width: 960px; margin: 24px auto; padding: 0 18px; }
        .form-input { width: 100%; box-sizing: border-box; background: #0d1117; color: #fff; border: 1px solid #30363d; border-radius: 4px; padding: 9px 11px; font-size: 14px; }
        textarea.form-input { min-height: 420px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.55; resize: vertical; }
        label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 4px; }
        .pill-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .pill { padding: 6px 14px; border: 1px solid #30363d; border-radius: 999px; color: #9aa7b5; text-decoration: none; font-size: 13px; }
        .pill.on { color: var(--neon, #39ff14); border-color: var(--neon, #39ff14); }
        .pill small { opacity: .7; margin-left: 4px; }
        .hint { font-size: 12px; color: #6b7785; line-height: 1.7; margin-top: 8px; }
        .hint code { background: #0d1117; padding: 1px 5px; border-radius: 3px; color: #9aa7b5; }
    </style>
</head>
<body>
    <div id="header">
        <div class="logo">Swift<span>Panel</span> <small style="font-size:11px;color:#e74c3c;">ADMIN CONTROL</small></div>
        <div class="user-info">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> | <a href="login.php?logout=1">Logout</a></div>
    </div>
    <?php $adminNavActive = 'legal'; include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="box">
            <div class="box-header">Legal Pages</div>
            <div class="box-content">
                <div class="pill-row">
                    <?php foreach (SWIFT_LEGAL_PAGES as $s => $n): foreach (SWIFT_LANGS as $c => $l): ?>
                        <a class="pill<?php echo ($s === $slug && $c === $lang) ? ' on' : ''; ?>" href="?p=<?php echo $s; ?>&amp;l=<?php echo $c; ?>"><?php echo htmlspecialchars($n . ' - ' . $l); ?><small><?php echo $filled[$s][$c] ? '✓' : '(empty)'; ?></small></a>
                    <?php endforeach; endforeach; ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['legal_csrf']); ?>">
                    <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug); ?>">
                    <input type="hidden" name="lang" value="<?php echo htmlspecialchars($lang); ?>">
                    <div style="margin-bottom:14px;">
                        <label>Page title (empty = the default title)</label>
                        <input type="text" name="title" class="form-input" maxlength="160" value="<?php echo htmlspecialchars($row['title']); ?>" placeholder="<?php echo htmlspecialchars(SWIFT_LEGAL_PAGES[$slug]); ?>">
                    </div>
                    <div style="margin-bottom:14px;">
                        <label>Text</label>
                        <textarea name="body" class="form-input" spellcheck="true"><?php echo htmlspecialchars((string)$row['body']); ?></textarea>
                        <div class="hint">
                            Formatting: <code># Heading</code>, <code>## Subheading</code>, <code>- list item</code>, <code>**bold**</code>, a blank line starts a new paragraph.
                            Web addresses and e-mail addresses become links automatically.
                            Each language has its own text; visitors see the one matching the flag they chose, or the English text when theirs is empty.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-green">Save</button>
                    <a class="btn" href="../<?php echo $slug; ?>.php?lang=<?php echo $lang; ?>" target="_blank" rel="noopener">View page</a>
                    <?php if (!empty($row['updated_at'])): ?><span class="hint" style="margin-left:10px;">Last saved: <?php echo htmlspecialchars($row['updated_at']); ?></span><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
