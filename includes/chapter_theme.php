<?php
/**
 * Chapter color schemes for the member area.
 *
 * A signed-in member's row (MemberSession::current()) has a `chapter` key such as 'amu', 'jamia',
 * 'photographers' or 'generic'. This file turns that key into:
 *   - a set of CSS variables that override the DCW blue/crimson tokens in assets/css/engage.css, and
 *   - the chapter's logo (assets/chapters/<key>.svg).
 *
 * 'generic' (the DCW seal), an unknown key, or no key at all means "no theme": the page keeps the
 * default DCW look.
 *
 * The one place to edit is engage_chapter_themes() below.
 */

/**
 * Chapter color schemes: the ONE place to edit.
 *
 *   primary    main brand color (buttons, hero band, links, focus rings). Required.
 *              If white text on it would be hard to read, it is darkened automatically.
 *   accent     optional second color (Membership banner, sign-out, highlights). Leave out to keep DCW crimson.
 *   logo_zoom  optional. How far the logo is enlarged inside the round top-bar badge to crop away white
 *              margin (1 = no crop; the default is 1.12, set in engage.css).
 *
 * !! The colors below are PLACEHOLDERS. Replace each with the real brand color of that chapter's logo.
 */
function engage_chapter_themes(): array {
    return [
        'jamia'         => ['primary' => '#1F7A4D'],
        'amu'           => ['primary' => '#0E5A8A'],
        'photographers' => ['primary' => '#9A4A12'],
        // 'amu' => ['primary' => '#0E5A8A', 'accent' => '#B8860B', 'logo_zoom' => 1.0],
    ];
}

/** The member's chapter key, lower-cased and checked, or '' when there is none. Never trust it as a path until it passes this. */
function engage_chapter_key(?array $member): string {
    $k = strtolower(trim((string) ($member['chapter'] ?? '')));
    return preg_match('/^[a-z0-9_-]{1,40}\z/', $k) ? $k : '';
}

/** '#RRGGBB' -> [r, g, b], or null if it is not a valid 6-digit hex color. */
function engage_hex_rgb(string $h): ?array {
    if (!preg_match('/^#?([0-9a-f]{6})\z/i', trim($h), $m)) return null;
    return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
}

/** [r, g, b] -> '#RRGGBB' (each channel is clamped to 0-255). */
function engage_rgb_hex(array $c): string {
    return sprintf('#%02X%02X%02X', ...array_map(fn($v) => max(0, min(255, (int) round($v))), $c));
}

/** Mix color $a toward color $b by $t (0 = all $a, 1 = all $b). */
function engage_mix(array $a, array $b, float $t): array {
    return [
        $a[0] + ($b[0] - $a[0]) * $t,
        $a[1] + ($b[1] - $a[1]) * $t,
        $a[2] + ($b[2] - $a[2]) * $t,
    ];
}

/** WCAG relative luminance of an [r, g, b] color (0 = black, 1 = white). */
function engage_lum(array $c): float {
    [$r, $g, $b] = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, $c);
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/** WCAG contrast ratio of white text on the given [r, g, b] background (1 = none, 21 = black). */
function engage_white_contrast(array $c): float {
    return 1.05 / (engage_lum($c) + 0.05);
}

/**
 * engage.css puts white text on the primary and accent colors (buttons, tabs, hero band), and uses the
 * same colors as link text on white. Darken the color in small steps until white-on-color reaches
 * WCAG AA (4.5:1), so a pale brand color can never make text unreadable.
 */
function engage_ensure_white_contrast(array $c): array {
    for ($i = 0; $i < 40 && engage_white_contrast($c) < 4.5; $i++) {
        $c = engage_mix($c, [0, 0, 0], 0.06);
    }
    return $c;
}

/**
 * The light end of the hero gradient. White text (the heading and the lead) sits on the whole band, so
 * this end must stay readable too. Start with a 25% mix toward white and step back toward the primary
 * until white-on-color reaches 3.5:1 (the primary itself is already at 4.5:1 or better).
 */
function engage_hero_end(array $p): array {
    $white = [255, 255, 255];
    for ($t = 0.25; $t > 0.001; $t -= 0.05) {
        $c = engage_mix($p, $white, $t);
        if (engage_white_contrast($c) >= 3.5) return $c;
    }
    return $p;
}

/**
 * CSS variables for this member's chapter, e.g. ['--primary' => '#1F7A4D', ...].
 * Returns [] when there is no theme. Use this directly (not the var() names) for PDFs: Dompdf and mPDF
 * generally ignore CSS variables, so print the hex values straight into the PDF's inline CSS.
 */
function engage_chapter_theme_vars(?array $member): array {
    $key    = engage_chapter_key($member);
    $themes = engage_chapter_themes();
    if ($key === '' || !isset($themes[$key])) return [];

    $t = $themes[$key];
    $p = engage_hex_rgb((string) ($t['primary'] ?? ''));
    if ($p === null) return [];
    $p     = engage_ensure_white_contrast($p);
    $black = [0, 0, 0];
    $white = [255, 255, 255];

    $v = [
        '--primary'       => engage_rgb_hex($p),
        '--primary-dark'  => engage_rgb_hex(engage_mix($p, $black, .30)),
        '--primary-light' => engage_rgb_hex(engage_mix($p, $white, .15)),
        '--primary-tint'  => engage_rgb_hex(engage_mix($p, $white, .92)),
        '--hero-end'      => engage_rgb_hex(engage_hero_end($p)),   // light end of the hero gradient, kept readable under white text
    ];

    if (!empty($t['accent']) && ($a = engage_hex_rgb((string) $t['accent'])) !== null) {
        $a = engage_ensure_white_contrast($a);
        $v += [
            '--accent'       => engage_rgb_hex($a),
            '--accent-dark'  => engage_rgb_hex(engage_mix($a, $black, .25)),
            '--accent-light' => engage_rgb_hex(engage_mix($a, $white, .12)),
            '--accent-tint'  => engage_rgb_hex(engage_mix($a, $white, .92)),
        ];
    }

    if (isset($t['logo_zoom']) && is_numeric($t['logo_zoom'])) {
        $z = max(1.0, min(2.0, (float) $t['logo_zoom']));
        $v['--logo-zoom'] = rtrim(rtrim(number_format($z, 2, '.', ''), '0'), '.');
    }

    return $v;
}

/** Turns a variables array (from engage_chapter_theme_vars()) into one CSS rule, or '' when it is empty. Safe to print: every value is generated, not user text. */
function engage_theme_vars_css(array $vars): string {
    if (!$vars) return '';
    $css = '';
    foreach ($vars as $name => $value) $css .= $name . ':' . $value . ';';
    return ':root{' . $css . '}';
}

/** The theme as one CSS rule (':root{--primary:#...;...}'), or '' for no theme. */
function engage_chapter_theme_css(?array $member): string {
    return engage_theme_vars_css(engage_chapter_theme_vars($member));
}

/* ----------------------------------------------------------------------
   Owner preview: let owners see the member pages as each chapter sees them.
   Used by the owner-only page views/admin/chapter-themes.php (/admin/chapter-themes). Nothing here reads
   or shows real member data: the preview uses a made-up sample member.
   ---------------------------------------------------------------------- */

/** Chapter keys an owner can preview: 'generic' (the DCW default) first, then every key in engage_chapter_themes(). */
function engage_chapter_choices(): array {
    return array_merge(['generic'], array_keys(engage_chapter_themes()));
}

/** The chapter picked in the preview (?chapter=amu), checked against the known list. Falls back to 'generic'. */
function engage_preview_chapter_key(): string {
    $k = strtolower(trim((string) ($_GET['chapter'] ?? '')));
    return in_array($k, engage_chapter_choices(), true) ? $k : 'generic';
}

/** A made-up member row for the preview, in the chapter chosen. */
function engage_preview_member(string $chapter): array {
    return ['member_id' => 'DCW-PREVIEW', 'full_name' => 'Sample Member', 'chapter' => $chapter];
}

/** Human label for a chapter key: 'amu' -> 'AMU', 'photographers' -> 'Photographers', 'generic' -> 'DCW (default)'. */
function engage_chapter_label(string $key): string {
    if ($key === 'generic') return 'DCW (default)';
    return strlen($key) <= 4 ? strtoupper($key) : ucfirst($key);
}
