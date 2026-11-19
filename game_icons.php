<?php
/**
 * Game icons for the public pages.
 *
 * 1. If assets/games/<slug>.jpg|webp|png|svg exists (slug = cs16, cs2, minecraft, gmod, tf2, l4d2, rust, valheim, zomboid, 7dtd, palworld),
 *    that picture is used: as the banner of the game card (16:9 works best) and cropped to a square for the small icons.
 * 2. Otherwise a neutral pictogram on the game's colour tile is drawn, so the page never shows a broken image.
 */

function swiftGameIconFile(string $slug): ?string
{
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', $slug)) {
        return null;
    }
    foreach (['svg', 'png', 'webp', 'jpg', 'jpeg'] as $ext) {
        $f = __DIR__ . '/assets/games/' . $slug . '.' . $ext;
        if (is_file($f)) {
            return 'assets/games/' . $slug . '.' . $ext . '?v=' . filemtime($f);
        }
    }
    return null;
}

// slug => [category, pictogram]; the pictograms are simple generic shapes drawn for this panel (24x24, stroke only)
function swiftGameGlyph(string $slug): array
{
    static $glyphs = null;
    if ($glyphs === null) {
        $cross = '<circle cx="12" cy="12" r="7"/><path d="M12 2.5v5M12 16.5v5M2.5 12h5M16.5 12h5"/><circle cx="12" cy="12" r="1" fill="currentColor"/>';
        $cube = '<path d="M12 2.5 20 7v10l-8 4.5L4 17V7z"/><path d="M4 7l8 4.5L20 7M12 11.5v10"/>';
        $shield = '<path d="M12 3 4.5 6v5.5c0 4.5 3.2 8 7.5 9.5 4.3-1.5 7.5-5 7.5-9.5V6z"/><path d="M12 8v8M8.5 11.5h7"/>';
        $flame = '<path d="M12 3c1 3.5 5 5.5 5 10a5 5 0 0 1-10 0c0-2 1-3.2 2-4.5.3 1.2 1 2 2 2.3-.5-2.8-.3-5 1-7.8z"/>';
        $skull = '<path d="M12 3a7 7 0 0 0-7 7c0 2.4 1.2 4 2.5 5V19h2.5v-2h4v2h2.5v-4c1.3-1 2.5-2.6 2.5-5a7 7 0 0 0-7-7z"/><circle cx="9" cy="11" r="1.3"/><circle cx="15" cy="11" r="1.3"/>';
        $paw = '<path d="M12 12.5c-2.6 0-5 2.2-5 4.3 0 1.6 1.4 2.2 2.8 2.2 1 0 1.5-.4 2.2-.4s1.2.4 2.2.4c1.4 0 2.8-.6 2.8-2.2 0-2.1-2.4-4.3-5-4.3z"/><circle cx="6.5" cy="10" r="1.7"/><circle cx="10" cy="6.5" r="1.7"/><circle cx="14" cy="6.5" r="1.7"/><circle cx="17.5" cy="10" r="1.7"/>';
        $blocks = '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4z"/><path d="m15.5 13.5 3.5 3.5-3.5 3.5-3.5-3.5z"/>';
        $glyphs = [
            'cs16' => ['shooters', $cross], 'cs2' => ['shooters', $cross], 'tf2' => ['shooters', $shield], 'l4d2' => ['shooters', $skull],
            'minecraft' => ['sandbox', $cube], 'gmod' => ['sandbox', $blocks],
            'rust' => ['survival', $flame], 'valheim' => ['survival', $shield], 'zomboid' => ['survival', $skull],
            '7dtd' => ['survival', $flame], 'palworld' => ['survival', $paw],
        ];
    }
    return $glyphs[$slug] ?? ['other', '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>'];
}

const SWIFT_GAME_CATEGORIES = ['shooters' => 'Shooters', 'sandbox' => 'Sandbox', 'survival' => 'Survival'];

function swiftGameIcon(string $slug, array $info): string
{
    $file = swiftGameIconFile($slug);
    if ($file) {
        return '<span class="gi gi-img"><img src="' . htmlspecialchars($file, ENT_QUOTES) . '" alt="" loading="lazy"></span>';
    }
    $c = $info['color'] ?? ['#2ecc71', '#1e8449'];
    $c0 = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$c[0]) ? $c[0] : '#2ecc71';
    $c1 = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$c[1]) ? $c[1] : '#1e8449';
    [, $svg] = swiftGameGlyph($slug);
    return '<span class="gi" style="background:linear-gradient(135deg,' . $c0 . ',' . $c1 . ');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg></span>';
}

function swiftGameIconCss(): string
{
    return '.gi{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:16px;color:#fff;flex:none;box-shadow:inset 0 0 0 1px rgba(255,255,255,.14),0 6px 16px rgba(0,0,0,.35);overflow:hidden}'
        . '.gi svg{width:30px;height:30px;filter:drop-shadow(0 1px 2px rgba(0,0,0,.35))}'
        . '.gi-img{background:#0d1218;box-shadow:inset 0 0 0 1px #263041,0 6px 16px rgba(0,0,0,.35)}'
        . '.gi-img img{width:100%;height:100%;object-fit:cover;display:block}';
}

/** Background of a banner card: the game's picture when assets/games has one, else a colour gradient with a large faint pictogram. */
function swiftGameBanner(string $slug, array $info): string
{
    $file = swiftGameIconFile($slug);
    if ($file) {
        return '<span class="gbg"><img src="' . htmlspecialchars($file, ENT_QUOTES) . '" alt="" loading="lazy"></span>';
    }
    $c = $info['color'] ?? ['#2ecc71', '#1e8449'];
    $c0 = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$c[0]) ? $c[0] : '#2ecc71';
    $c1 = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$c[1]) ? $c[1] : '#1e8449';
    [, $svg] = swiftGameGlyph($slug);
    return '<span class="gbg gbg-gen" style="background:radial-gradient(120% 140% at 85% 0%,' . $c0 . ' 0%,' . $c1 . ' 55%,#0a0e13 100%);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg></span>';
}

function swiftGameBannerCss(): string
{
    return '.gcard{position:relative;display:block;aspect-ratio:16/9;border-radius:14px;overflow:hidden;cursor:pointer;background:#0d1218;border:1px solid var(--border-subtle,#1f2937);transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease;isolation:isolate}'
        . '.gcard:hover,.gcard:focus-visible,.gcard.selected{transform:translateY(-3px);border-color:var(--border-glow,rgba(57,255,20,.35));box-shadow:0 14px 34px rgba(0,0,0,.45),0 0 0 1px rgba(57,255,20,.12);outline:none}'
        . '.gcard .gbg{position:absolute;inset:0;z-index:-2;transition:transform .35s ease}'
        . '.gcard:hover .gbg{transform:scale(1.05)}'
        . '.gcard .gbg img{width:100%;height:100%;object-fit:cover;display:block}'
        . '.gcard .gbg-gen svg{position:absolute;right:-6%;top:-10%;width:74%;height:auto;color:rgba(255,255,255,.16)}'
        . '.gcard::before{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(to top,rgba(7,10,15,.94) 0%,rgba(7,10,15,.62) 38%,rgba(7,10,15,.12) 78%,rgba(7,10,15,.22) 100%)}'
        . '.gcard .gtext{position:absolute;left:16px;right:16px;bottom:14px}'
        . '.gcard .gname{font-size:16px;font-weight:700;color:#fff;line-height:1.2;text-shadow:0 1px 8px rgba(0,0,0,.6)}'
        . '.gcard .gprice{margin-top:4px;font-size:12.5px;color:#cbd5e1;text-shadow:0 1px 6px rgba(0,0,0,.6)}'
        . '.gcard .gprice strong{color:var(--neon,#39ff14);font-weight:700}'
        . '.gcard .gtag{position:absolute;left:16px;top:12px;font-size:11px;color:#e2e8f0;background:rgba(7,10,15,.55);border:1px solid rgba(255,255,255,.14);border-radius:99px;padding:3px 9px;backdrop-filter:blur(4px);max-width:calc(100% - 90px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        . '.gcard .gcta{position:absolute;right:14px;bottom:14px;background:var(--neon,#39ff14);color:#04120a;font-size:12px;font-weight:700;border-radius:8px;padding:6px 12px;opacity:0;transform:translateY(6px);transition:all .18s ease}'
        . '.gcard:hover .gcta,.gcard:focus-visible .gcta,.gcard.selected .gcta{opacity:1;transform:none}'
        . '@media (hover:none){.gcard .gcta{opacity:1;transform:none}}';
}
