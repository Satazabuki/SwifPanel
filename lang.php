<?php
/**
 * Multilingual support.
 *
 * - The visitor's language is kept in the cookie "swift_lang" (set by clicking a flag: any page + ?lang=sr).
 * - Pages stay written in English. When a page's HTML is about to be sent, swiftLangStart() (called just before the
 *   <!DOCTYPE>) makes the output pass through a filter that replaces the English texts with the translation from
 *   lang/<code>/*.php (text, placeholder/title/alt attributes, and strings in inline JavaScript).
 *   Only HTML pages are filtered, never the console stream, downloads or AJAX answers.
 * - t('English text') translates a string in PHP code (for JSON answers, e-mails ...).
 * - A text with a variable is listed with %s in its place:  'Order #%s submitted!' => 'Porudžbina #%s je poslata!'
 *
 * Add a language: add it to SWIFT_LANGS, a flag to swiftLangFlag(), and a folder lang/<code>/ with PHP files that
 * return ['English text' => 'Translation', ...].
 */

const SWIFT_LANGS = ['en' => 'English', 'sr' => 'Srpski'];
const SWIFT_LANG_COOKIE = 'swift_lang';

/** Current language code. */
function swiftLang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $c = $_COOKIE[SWIFT_LANG_COOKIE] ?? '';
    if (is_string($c) && isset(SWIFT_LANGS[$c])) {
        return $lang = $c;
    }
    // first visit: Serbian, Croatian and Bosnian browsers get Serbian, everyone else English
    $al = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (preg_match('/^\s*(sr|hr|bs)\b/', $al)) {
        return $lang = 'sr';
    }
    return $lang = 'en';
}

/** Handles ?lang=xx (sets the cookie, then returns to the same page without the parameter). */
function swiftLangBoot(): void
{
    if (!isset($_GET['lang']) || !is_string($_GET['lang']) || headers_sent()) {
        return;
    }
    $code = $_GET['lang'];
    if (isset(SWIFT_LANGS[$code])) {
        setcookie(SWIFT_LANG_COOKIE, $code, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    }
    $q = $_GET;
    unset($q['lang']);
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    header('Location: ' . $path . ($q ? '?' . http_build_query($q) : ''), true, 303);
    exit;
}

/** Dictionary of the current language: ['exact' => [...], 'patterns' => [[regex, translation], ...]] */
function swiftLangDict(): array
{
    static $dict = null;
    if ($dict !== null) {
        return $dict;
    }
    $dict = ['exact' => [], 'patterns' => [], 'js' => []];
    $lang = swiftLang();
    if ($lang === 'en') {
        return $dict;
    }
    foreach (glob(__DIR__ . '/lang/' . $lang . '/*.php') ?: [] as $f) {
        $part = include $f;
        if (!is_array($part)) {
            continue;
        }
        foreach ($part as $k => $v) {
            if (strncmp((string)$k, '@js:', 4) === 0) {          // raw JavaScript piece, matched exactly (spaces included)
                $dict['js'][substr((string)$k, 4)] = (string)$v;
                continue;
            }
            $k = swiftLangNorm((string)$k);
            if (strpos($k, '%s') !== false) {
                $re = '/^' . str_replace('%s', '(.+?)', preg_quote($k, '/')) . '$/su';
                $re = str_replace(preg_quote('%s', '/'), '(.+?)', $re);
                $dict['patterns'][] = [$re, (string)$v];
            } else {
                $dict['exact'][$k] = (string)$v;
            }
        }
    }
    // longer patterns first, so the most specific one wins
    usort($dict['patterns'], fn($a, $b) => strlen($b[0]) <=> strlen($a[0]));
    return $dict;
}

function swiftLangNorm(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Translation of one plain text (already HTML-decoded), or null when there is none. */
function swiftLangLookup(string $text): ?string
{
    $d = swiftLangDict();
    $k = swiftLangNorm($text);
    if ($k === '') {
        return null;
    }
    if (isset($d['exact'][$k])) {
        return $d['exact'][$k];
    }
    $found = swiftLangPattern($d, $k);
    if ($found !== null) {
        return $found;
    }
    if (($log = getenv('SWIFT_LANG_MISSING')) && preg_match('/[A-Za-z]{3}/', $k)) {   // development aid: list what has no translation
        @file_put_contents($log, $k . "\n", FILE_APPEND);
    }
    return null;
}

function swiftLangPattern(array $d, string $k): ?string
{
    foreach ($d['patterns'] as [$re, $tr]) {
        if (preg_match($re, $k, $m)) {
            array_shift($m);
            // captured parts can be translatable themselves (for example a status word)
            foreach ($m as $i => $part) {
                $m[$i] = $d['exact'][$part] ?? $part;
            }
            return vsprintf(str_replace('%s', '%s', $tr), $m);
        }
    }
    return null;
}

/** t('English text', args...) for strings built in PHP code. */
function t(string $s, ...$args): string
{
    $tr = swiftLang() === 'en' ? null : swiftLangLookup($s);
    $out = $tr ?? $s;
    return $args ? vsprintf($out, $args) : $out;
}

// ---------------------------------------------------------------------------------------------- the output filter

function swiftLangStart(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;
    swiftLangBoot();
    ob_start('swiftLangFilter');
}

/** "Nov 18, 2026" -> "18. 11. 2026." (Serbian date order). Other languages can add their own rule here. */
function swiftLangDates(string $s): string
{
    if (swiftLang() !== 'sr') {
        return $s;
    }
    static $mon = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    return preg_replace_callback('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? (\d{1,2}), (\d{4})\b/', fn($m) => (int)$m[2] . '. ' . $mon[strtolower($m[1])] . '. ' . $m[3] . '.', $s);
}

function swiftLangTextNode(string $raw): string
{
    if (trim($raw) === '' || strpos($raw, '<') !== false) {
        return $raw;
    }
    $plain = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $tr = swiftLangLookup($plain);
    if ($tr === null) {
        $d = swiftLangDates($plain);          // text that only contains an English date such as "Nov 18, 2026"
        return $d === $plain ? $raw : htmlspecialchars($d, ENT_NOQUOTES, 'UTF-8');
    }
    $tr = swiftLangDates($tr);
    preg_match('/^(\s*)(.*?)(\s*)$/su', $raw, $m);
    return $m[1] . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8') . $m[3];
}

function swiftLangAttrValue(string $raw): string
{
    $plain = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $tr = swiftLangLookup($plain);
    return $tr === null ? $raw : htmlspecialchars($tr, ENT_QUOTES, 'UTF-8');
}

/** Translates string literals inside JavaScript (a <script> block or an onclick="..." attribute). */
function swiftLangJs(string $js, bool $inAttribute = false): string
{
    return preg_replace_callback('/\'((?:[^\'\\\\\n]|\\\\.)*)\'|"((?:[^"\\\\\n]|\\\\.)*)"/s', function ($m) use ($inAttribute) {
        $quote = $m[1] !== '' && ($m[0][0] === "'") ? "'" : ($m[0][0] === '"' ? '"' : "'");
        $body = $m[0][0] === "'" ? $m[1] : $m[2];
        if ($inAttribute) {
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($body === '' || !preg_match('/[A-Za-z]{2}/', $body)) {
            return $m[0];
        }
        $plain = preg_replace_callback('/\\\\(.)/s', fn($e) => $e[1] === 'n' ? "\n" : $e[1], $body);
        $raw = swiftLangDict()['js'][$plain] ?? null;
        if ($raw !== null) {
            $tr = $raw;
        } else {
            // a piece of HTML built in JavaScript: translate the texts between the tags
            if (strpos($plain, '<') !== false && strpos($plain, '>') !== false) {
                $changed = false;
                $html = preg_replace_callback('/(^|>)([^<>]+)(?=<|$)/u', function ($t) use (&$changed) {
                    $tr = swiftLangLookup($t[2]);
                    if ($tr === null) {
                        return $t[0];
                    }
                    $changed = true;
                    preg_match('/^(\s*).*?(\s*)$/su', $t[2], $ws);
                    return $t[1] . $ws[1] . $tr . $ws[2];
                }, $plain);
                if (!$changed) {
                    return $m[0];
                }
                $esc = str_replace(["\\", $quote, "\n", "\r"], ["\\\\", '\\' . $quote, '\\n', ''], $html);
                if ($inAttribute) {
                    $esc = str_replace('&#039;', "\\'", htmlspecialchars($esc, ENT_QUOTES, 'UTF-8'));
                }
                return $quote . $esc . $quote;
            }
            // a single lower-case word is almost always an identifier, a key or a CSS class: never touch it
            if (!preg_match('/\s/', $plain) && !preg_match('/^[A-Z\x{0100}-\x{FFFF}]/u', $plain)) {
                return $m[0];
            }
            $tr = swiftLangLookup($plain);
            if ($tr === null) {
                return $m[0];
            }
            // keep the whitespace the original had around the text (string pieces that are glued together in JavaScript)
            preg_match('/^(\s*).*?(\s*)$/su', $plain, $ws);
            $tr = $ws[1] . $tr . $ws[2];
        }
        $esc = str_replace(["\\", $quote, "\n", "\r"], ["\\\\", '\\' . $quote, '\\n', ''], $tr);
        if ($inAttribute) {
            $esc = htmlspecialchars($esc, ENT_QUOTES, 'UTF-8');
            $esc = str_replace('&#039;', "\\'", $esc);
        }
        return $quote . $esc . $quote;
    }, $js);
}

function swiftLangFilter(string $html, int $phase = 0): string
{
    // chunks that must not be touched or are handled separately
    $parts = preg_split('~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<textarea\b[^>]*>.*?</textarea>|<pre\b[^>]*>.*?</pre>|<!--.*?-->)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $translate = swiftLang() !== 'en';
    foreach ($parts as $i => $p) {
        if ($i % 2 === 1) {                                   // a protected chunk
            if ($translate && preg_match('~^<script\b([^>]*)>(.*)</script>$~is', $p, $m) && stripos($m[1], 'src=') === false && stripos($m[1], 'json') === false) {
                $parts[$i] = '<script' . $m[1] . '>' . swiftLangJs($m[2]) . '</script>';
            }
            continue;
        }
        if (!$translate) {
            continue;
        }
        $p = preg_replace_callback('/>([^<>]+)(?=<)/u', fn($m) => '>' . swiftLangTextNode($m[1]), $p);
        $p = preg_replace_callback('/\b(placeholder|title|aria-label|alt)="([^"]*)"/', fn($m) => $m[1] . '="' . swiftLangAttrValue($m[2]) . '"', $p);
        $p = preg_replace_callback('/\b(on[a-z]+)="([^"]*)"/', fn($m) => $m[1] . '="' . swiftLangJs($m[2], true) . '"', $p);
        $parts[$i] = $p;
    }
    $out = implode('', $parts);
    $lang = swiftLang();
    $out = preg_replace('/<html lang="[a-z-]*"/', '<html lang="' . $lang . '"', $out, 1);
    return swiftLangInjectSwitcher($out);
}

// ---------------------------------------------------------------------------------------------- flag switcher

function swiftLangFlag(string $code): string
{
    switch ($code) {
        case 'sr':   // civil flag of Serbia: red, blue, white
            return '<svg viewBox="0 0 30 20" aria-hidden="true"><rect width="30" height="20" fill="#fff"/><rect width="30" height="13.34" fill="#0c4076"/><rect width="30" height="6.67" fill="#c6363c"/></svg>';
        default:     // en: Union Jack
            return '<svg viewBox="0 0 60 30" aria-hidden="true"><clipPath id="swl-a"><path d="M0 0v30h60V0z"/></clipPath><clipPath id="swl-b"><path d="M30 15h30v15zv15H0zH0V0zV0h30z"/></clipPath><g clip-path="url(#swl-a)"><path d="M0 0v30h60V0z" fill="#012169"/><path d="M0 0l60 30m0-30L0 30" stroke="#fff" stroke-width="6"/><path d="M0 0l60 30m0-30L0 30" clip-path="url(#swl-b)" stroke="#c8102e" stroke-width="4"/><path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10"/><path d="M30 0v30M0 15h60" stroke="#c8102e" stroke-width="6"/></g></svg>';
    }
}

function swiftLangSwitcherHtml(): string
{
    $cur = swiftLang();
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $q = $_GET;
    unset($q['lang']);
    $h = '<span class="swl" role="group" aria-label="Language">';
    foreach (SWIFT_LANGS as $code => $label) {
        $q2 = $q + ['lang' => $code];
        $h .= '<a href="' . htmlspecialchars($path . '?' . http_build_query($q2), ENT_QUOTES) . '" class="swl-f' . ($code === $cur ? ' on' : '') . '" title="' . htmlspecialchars($label, ENT_QUOTES) . '" hreflang="' . $code . '" lang="' . $code . '"' . ($code === $cur ? ' aria-current="true"' : '') . '>' . swiftLangFlag($code) . '</a>';
    }
    return $h . '</span>';
}

function swiftLangCss(): string
{
    return '<style id="swl-css">'
        . '.swl{display:inline-flex;gap:6px;align-items:center;vertical-align:middle;margin-right:12px}'
        . '.swl a.swl-f{display:inline-block!important;box-sizing:border-box;width:28px!important;height:19px!important;min-width:0!important;padding:0!important;margin:0!important;border:0!important;border-radius:4px;background:none!important;overflow:hidden;opacity:.55;box-shadow:0 0 0 1px rgba(255,255,255,.2);transition:opacity .15s,box-shadow .15s,transform .15s;line-height:0;font-size:0}'
        . '.swl a.swl-f svg{width:100%;height:100%;display:block}'
        . '.swl a.swl-f:hover{opacity:1;transform:translateY(-1px)}'
        . '.swl a.swl-f.on{opacity:1;box-shadow:0 0 0 2px var(--neon,#39ff14)}'
        . '.swl-float{position:fixed;top:12px;right:14px;z-index:9999;background:rgba(10,14,19,.85);padding:7px 10px;border-radius:10px;border:1px solid #1f2937;backdrop-filter:blur(6px)}'
        . '.swl-float .swl{margin:0}'
        . '</style>';
}

function swiftLangInjectSwitcher(string $html): string
{
    if (stripos($html, '</body>') === false || stripos($html, 'id="swl-css"') !== false) {
        return $html;
    }
    $sw = swiftLangSwitcherHtml();
    $n = 0;
    // inside the top menu of the page: the user-info corner, the home page's right corner, or the admin header
    foreach (['<div class="user-info">', '<div class="right">', '<div id="navbar">'] as $anchor) {
        $pos = stripos($html, $anchor);
        if ($pos !== false) {
            $wrap = $anchor === '<div id="navbar">' ? '<div style="float:right;padding:9px 16px 0">' . $sw . '</div>' : $sw;
            $html = substr_replace($html, $anchor . $wrap, $pos, strlen($anchor));
            $n = 1;
            break;
        }
    }
    if (!$n) {
        $html = preg_replace('~</body>~i', '<div class="swl-float">' . $sw . '</div></body>', $html, 1);
    }
    return preg_replace('~</head>~i', swiftLangCss() . '</head>', $html, 1) ?: $html;
}

/** For JSON answers: translates the texts under the keys error / message / note (anything else is left alone). */
function swiftLangJsonData($data)
{
    if (!is_array($data) || swiftLang() === 'en') {
        return $data;
    }
    foreach ($data as $k => $v) {
        if (is_string($v) && in_array($k, ['error', 'message', 'note'], true)) {
            $data[$k] = swiftLangLookup($v) ?? $v;
        } elseif (is_array($v)) {
            $data[$k] = swiftLangJsonData($v);
        }
    }
    return $data;
}
