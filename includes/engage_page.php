<?php
require_once __DIR__ . '/social_meta.php';
require_once __DIR__ . '/chapter_theme.php';

/**
 * Absolute path of a file under the web root (e.g. '/assets/css/engage.css'), or null if it can't be found.
 */
function engage_asset_file(string $path): ?string {
    $candidates = array_filter([
        !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $path : null,
        __DIR__ . '/../public' . $path,
        __DIR__ . '/..' . $path,
    ]);
    foreach ($candidates as $file) {
        if (is_file($file)) return $file;
    }
    return null;
}

/**
 * Versioned URL for a file under the web root, e.g. engage_asset('/assets/css/engage.css').
 * Appends ?v=<last-modified time>, so a changed file gets a new URL and is never served
 * from a stale browser or CDN cache. If the file can't be found, the plain URL is returned.
 */
function engage_asset(string $path): string {
    $file = engage_asset_file($path);
    return $file !== null ? $path . '?v=' . (int) filemtime($file) : $path;
}

/**
 * URL of the logo shown in the top bar (versioned like the CSS).
 *
 * With no argument it is the DCW seal, assets/chapters/generic.svg. Pass a member's chapter key
 * (see engage_chapter_key()) and it is that chapter's logo, assets/chapters/<key>.svg, falling back to the
 * DCW seal when the chapter has no logo file. The key is checked before it is used in a path.
 */
function engage_logo_url(string $chapter = ''): string {
    if ($chapter !== '' && $chapter !== 'generic' && preg_match('/^[a-z0-9_-]{1,40}\z/', $chapter)) {
        $path = '/assets/chapters/' . $chapter . '.svg';
        if (engage_asset_file($path) !== null) return engage_asset($path);
    }
    return engage_asset('/assets/chapters/generic.svg');
}

/**
 * Compact DCW Engage page shell.
 * Usage: engage_header([...]); ...content...; engage_footer();
 *
 * Staff pages (/admin, /finance) automatically get the staff scheme ("Logo Blue, Green & Crimson"): the <body> gets
 * class="admin", assets/css/staff.css is loaded after engage.css, the page is marked noindex, and the brand
 * link goes to the workspace. A staff page that passes no 'tools' also gets the shared staff menu
 * (engage_staff_menu()): chips on wide screens, one hamburger on phones.
 *
 * Member pages that pass 'member' get that member's chapter colors and chapter logo (see
 * includes/chapter_theme.php). Staff pages and pages with no member keep the default DCW look.
 *
 * Owner preview: the owner-only page admin/chapter-themes.php sets $GLOBALS['ENGAGE_THEME_PREVIEW'] = true
 * before calling engage_header(). While that flag is set, a page under /admin is drawn with the MEMBER look
 * (no staff scheme, no staff footer), is marked noindex and has no social preview tags, so the owner sees
 * exactly what a member of the chosen chapter sees. Pass a made-up member (engage_preview_member()), never a real one.
 *
 * Options:
 *   title    <title> text (" - DCW Engage" is added)
 *   heading  big heading in the hero (defaults to title)
 *   kicker   small pill above the heading (not shown on slim pages)
 *   lead     optional sentence under the heading (plain text)
 *   lead_html optional trusted, already-escaped HTML shown under the lead (for example a link)
 *   member   MemberSession::current() row, or null
 *   tools    raw, already-escaped HTML for the top-bar buttons (overrides the member links and the staff menu)
 *   wide     true for wide layouts (finance queue and tables)
 *   home     true for the public home page: a slim hero with a left-aligned heading and lead, no big
 *            logo, no kicker pill, and no breadcrumbs unless 'crumbs' is given
 *   slim     true for any other page that should use the same slim top bar as the home page
 *            with a left-aligned heading and lead, no kicker pill, and the breadcrumb trail kept.
 *            'home' implies it.
 *   compact  true for a shorter band (member pages, and the home page for signed-in members). Implies
 *            'slim', and the breadcrumb trail is off unless 'crumbs' is given.
 *   bare     true for a band with only the top bar: no heading, no lead, no trail (the dashboard).
 *            Implies 'compact'.
 *   flat     true for a band with only the top bar (like 'bare'), but the page starts below the band
 *            with no overlap (the signed-in member's home page). Implies 'slim'.
 *   back     [label, href] a single quiet "← label" link above the heading (replaces the breadcrumb
 *            pills on member pages), e.g. ['Dashboard', '/member/dashboard'].
 *   crumbs   breadcrumb trail, e.g. [['Home','/'],['Support','/support'],['Reimbursement']].
 *            The last item is the current page (no link). A "Back" button pointing to the
 *            nearest linked crumb is added automatically. Defaults to Home > heading
 *            (or no trail at all when 'home', 'compact' or 'bare' is true). Pass [] to hide the trail.
 *   description  social preview text (optional; a default is used)
 *   image        social preview image: a Commons file name or link, any URL, or a path (optional;
 *                the default Commons photo in includes/social_meta.php is used)
 *   image_alt    alt text for the preview image (defaults to the heading)
 *
 * Top bar: the brand sits alone on the left. On the right, a signed-in member gets the avatar menu
 * (engage_member_menu(), which also holds the About DCW links). Everyone else gets whatever 'tools'
 * holds plus a small menu button (engage_guest_menu()) with Track application and About DCW.
 */
function engage_header(array $o) {
    $title    = $o['title'];
    $heading  = $o['heading'] ?? $title;
    $kicker   = $o['kicker'] ?? 'Deoband Community Wikimedia';
    $lead     = $o['lead'] ?? '';
    $leadHtml = (string) ($o['lead_html'] ?? '');
    $member   = $o['member'] ?? null;
    $tools    = $o['tools'] ?? null;

    // Staff pages with no top-bar tools of their own get the shared staff menu.
    if (engage_is_staff_path() && ($tools === null || trim((string) $tools) === '')) {
        $staffMenu = engage_staff_menu();
        if ($staffMenu !== '') $tools = $staffMenu;
    }

    $wide     = !empty($o['wide']);
    $home     = !empty($o['home']);
    $bare     = !empty($o['bare']);
    $flat     = !empty($o['flat']);   // top bar only: no heading, no lead, no trail; the page starts below the band
    $compact  = $bare || !empty($o['compact']);
    $slim     = $home || $compact || $flat || !empty($o['slim']);
    $back     = (isset($o['back']) && is_array($o['back']) && !empty($o['back'][0]) && !empty($o['back'][1])) ? $o['back'] : null;
    $crumbs   = ($bare || $flat) ? [] : ($o['crumbs'] ?? (($home || $compact) ? [] : [['Home', '/'], [$heading]]));
    $backHref = null; $backLabel = null;
    foreach ($crumbs as $c) { if (!empty($c[1])) { $backHref = $c[1]; $backLabel = $c[0]; } }
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $isPreview = engage_is_theme_preview();

    // Public pages get the About DCW links (inside the menu); staff pages (/admin, /finance) do not need them.
    $about = engage_is_staff_path() ? [] : engage_about_links(true);
    // A page that already passes the member menu has the About links inside it. Everyone else gets the small menu.
    $hasMemberMenu = $tools !== null && strpos((string) $tools, 'mmenu') !== false;
    $showGuestMenu = !$hasMemberMenu && !empty($about);
    $joinInMenu    = $tools !== null && strpos((string) $tools, '/membership') !== false;

    $heroClass = 'hero' . ($slim ? ' slim' : '') . ($home ? ' home' : '') . ($compact ? ' compact' : '') . ($bare ? ' bare' : '') . ($flat ? ' flat' : '');
    $isStaff   = engage_is_staff_path();
    // The brand goes to the workspace on staff pages, to the public home page everywhere else.
    $brandHref = $isStaff ? '/admin/dashboard' : '/';

    // Chapter look: a signed-in member sees their chapter's colors and logo. Staff pages and pages with no
    // member keep the default DCW look ($themeCss is '' and the logo is the DCW seal).
    // The theme is worked out once, here, and printed from the same array.
    $chapterKey = (!$isStaff && $member) ? engage_chapter_key($member) : '';
    $themeVars  = $chapterKey !== '' ? engage_chapter_theme_vars($member) : [];
    $themeCss   = engage_theme_vars_css($themeVars);
    $themeColor = $isStaff ? '#051A2B' : ($themeVars['--primary'] ?? '#2E6599');   // fallback = --primary in engage.css
    $logoUrl    = engage_logo_url($chapterKey);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= $e($themeColor) ?>">
    <?php if ($isStaff || $isPreview): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <title><?= $e($title) ?> - DCW Engage</title>
    <?php ob_start(); require __DIR__ . '/favicon.php'; echo engage_strip_social_images(ob_get_clean()); ?>
    <?php if (!$isStaff && !$isPreview) engage_social_meta([
        'title'       => $title . ' - DCW Engage',
        'description' => $o['description'] ?? null,
        'image'       => $o['image'] ?? null,
        'image_alt'   => $o['image_alt'] ?? $heading,
    ]); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php /* Versioned by file modification time: edits to the CSS show up at once, never stuck in a cache. */ ?>
    <link rel="stylesheet" href="<?= $e(engage_asset('/assets/css/engage.css')) ?>">
    <?php /* The member's chapter colors. Must come AFTER engage.css so these variables override the defaults. */ ?>
    <?php if ($themeCss !== ''): ?><style><?= $themeCss ?></style><?php endif; ?>
    <?php /* Staff pages (/admin, /finance) add the staff scheme on top of the shared design system. */ ?>
    <?php if ($isStaff): ?><link rel="stylesheet" href="<?= $e(engage_asset('/assets/css/staff.css')) ?>"><?php endif; ?>
</head>
<body<?= $isStaff ? ' class="admin"' : '' ?>>
<header class="<?= $e($heroClass) ?>">
    <div class="topbar">
        <?php /* The logo sits inside .brand-logo: a small circle that crops the image (see engage.css). It is the member's chapter logo (assets/chapters/<chapter>.svg) or the DCW seal (generic.svg). */ ?>
        <a class="brand" href="<?= $e($brandHref) ?>"><span class="brand-logo"><img src="<?= $e($logoUrl) ?>" alt=""></span><span class="bname">DCW Engage</span></a>
        <div class="tools">
            <?php if ($tools !== null): ?>
                <?= $tools ?>
            <?php elseif ($member): ?>
                <span class="who"><?= $e($member['full_name'] ?: $member['member_id']) ?> (<?= $e($member['member_id']) ?>)</span>
                <a class="chip-btn" href="/member/dashboard">My dashboard</a>
                <a class="chip-btn" href="/member/logout">Sign out</a>
            <?php else: ?>
                <a class="chip-btn" href="/">All programs</a>
            <?php endif; ?>
            <?php if ($showGuestMenu) echo engage_guest_menu($about, $joinInMenu); ?>
        </div>
    </div>
    <?php if ($crumbs): ?>
    <nav class="crumbs" aria-label="Breadcrumb">
        <?php if ($backHref): ?><a class="back" href="<?= $e($backHref) ?>">&larr; Back</a><?php endif; ?>
        <?php foreach ($crumbs as $i => $c): ?>
            <?php if ($i > 0): ?><span class="sep" aria-hidden="true">/</span><?php endif; ?>
            <?php if (!empty($c[1])): ?>
                <a href="<?= $e($c[1]) ?>"><?= $e($c[0]) ?></a>
            <?php else: ?>
                <span class="here" aria-current="page"><?= $e($c[0]) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if (!$bare && !$flat): ?>
        <?php if ($slim): ?><div class="hero-copy"><?php endif; ?>
        <?php if ($back): ?><a class="hero-back" href="<?= $e($back[1]) ?>">&larr; <?= $e($back[0]) ?></a><?php endif; ?>
        <?php if (!$slim): ?><p class="kicker"><?= $e($kicker) ?></p><?php endif; ?>
        <h1><?= $e($heading) ?></h1>
        <?php if ($lead): ?><p class="lead"><?= $e($lead) ?></p><?php endif; ?>
        <?php if ($leadHtml !== ''): ?><?= $leadHtml ?><?php endif; ?>
        <?php if ($slim): ?></div><?php endif; ?>
    <?php endif; ?>
</header>
<main class="wrap cards-wrap<?= $wide ? ' wide' : '' ?>">
<?php
}

/**
 * Footer and "About DCW" settings: the ONE place to edit. Anything left empty is simply not shown.
 *
 *   about          pages about DCW, shown in the header menu and the footer: [['Label', 'https://...'], ...]
 *   contact_url    the Contact page
 *   contact_email  a public mailbox, e.g. 'hello@dcwwiki.org' (optional, shown as well)
 *   subscribe_url  the mailing-list sign-up page
 *   community      chat or community groups:  [['Telegram', 'https://...'], ...]
 *   social         social profiles:           [['Facebook', 'https://...'], ...]
 *   help_url       FAQ / help page
 *   privacy_url    privacy notice (worth having: Engage holds applicants' personal data)
 *   conduct_url    friendly space policy / code of conduct (conduct_label is the text of the link)
 *   staff_login    show a small "Staff sign in" link
 */
function engage_footer_config(): array {
    return [
        'site'          => 'https://dcwwiki.org',
        'site_label'    => 'dcwwiki.org',
        'about'         => [
            ['Our history',         'https://dcwwiki.org/History'],
            ['Vision & objectives', 'https://dcwwiki.org/Vision_%26_Objectives'],
        ],
        'contact_url'   => 'https://dcwwiki.org/Contact',
        'contact_email' => '',
        'subscribe_url' => 'https://lists.wikimedia.org/postorius/lists/wikimedia-dcw.lists.wikimedia.org/',
        'community'     => [],
        'social'        => [
            ['X',         'https://x.com/dcwwiki'],
            ['Facebook',  'https://www.facebook.com/dcwwiki'],
            ['Instagram', 'https://www.instagram.com/dcwwiki'],
            ['Threads',   'https://www.threads.com/@dcwwiki'],
            ['YouTube',   'https://www.youtube.com/@dcwwiki'],
            ['LinkedIn',  'https://www.linkedin.com/company/deoband-community-wikimedia'],
        ],
        'help_url'      => '',
        'privacy_url'   => '',
        'conduct_url'   => 'https://dcwwiki.org/Friendly_space_policy',
        'conduct_label' => 'Friendly space policy',
        'staff_login'   => true,
    ];
}

/** Only web addresses are ever printed as links, so a typo in the settings cannot become a script link. */
function engage_safe_url($u): string {
    return preg_match('#^https?://#i', (string) $u) ? (string) $u : '';
}

/**
 * True on the owner-only chapter theme preview page. That page sets $GLOBALS['ENGAGE_THEME_PREVIEW'] = true
 * (after its own owner check) so the header and footer draw the member look instead of the staff look.
 */
function engage_is_theme_preview(): bool {
    return !empty($GLOBALS['ENGAGE_THEME_PREVIEW']);
}

/** Staff pages live under /admin and /finance (except the owner theme preview, which shows the member look). */
function engage_is_staff_path(): bool {
    if (engage_is_theme_preview()) return false;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return (bool) preg_match('#^/(admin|finance)(/|$)#', $path);
}

/** "About DCW" links, in order: the pages from settings, then the main website. */
function engage_about_links(bool $withContact = false): array {
    $c = engage_footer_config();
    $out = [];
    foreach ($c['about'] as $p) {
        $u = engage_safe_url($p[1] ?? '');
        if ($u !== '' && !empty($p[0])) $out[] = [(string) $p[0], $u];
    }
    $contact = engage_safe_url($c['contact_url'] ?? '');
    if ($withContact && $contact !== '') $out[] = ['Contact', $contact];
    $site = engage_safe_url($c['site']);
    if ($site !== '') $out[] = ['Main website', $site];
    return $out;
}

/**
 * The small menu for visitors who are not signed in: three lines on the right of the top bar, opening a
 * panel with Track application and the About DCW links. Sign in and Join stay visible beside it.
 * On very small phones the "Join" button is hidden from the bar (see engage.css), so when the page
 * shows one ($withJoin) the same link appears at the top of this menu on those screens only.
 * Returns HTML (already escaped). The open/close behaviour comes from the script in engage_footer().
 */
function engage_guest_menu(array $about, bool $withJoin = false): string {
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
    <nav class="mnav" aria-label="Site menu">
        <details class="mmenu burger plain">
            <summary aria-label="Open menu"><span class="bars" aria-hidden="true"><i></i><i></i><i></i></span></summary>
            <div class="mpop">
                <?php if ($withJoin): ?><a class="xs-only" href="/membership">Join DCW</a><?php endif; ?>
                <a href="/track">Track application</a>
                <div class="msep"></div>
                <div class="mlabel">About DCW</div>
                <?php foreach ($about as [$name, $href]): ?>
                    <a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a>
                <?php endforeach; ?>
            </div>
        </details>
    </nav>
    <?php
    return ob_get_clean();
}

/**
 * Kept for older pages that may still call it. The header no longer uses it: the About DCW links now
 * live inside the top-bar menus (engage_guest_menu() and engage_member_menu()).
 */
function engage_about_menu(array $about): void {
    if (!$about) return;
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $site = rtrim(engage_safe_url(engage_footer_config()['site'] ?? ''), '/');
    ?>
    <details class="about" id="about-menu">
        <summary class="chip-btn">About DCW <span class="caret" aria-hidden="true">&#9662;</span></summary>
        <div class="about-menu">
            <?php foreach ($about as [$name, $href]): ?>
                <?php $isSite = $site !== '' && rtrim($href, '/') === $site; ?>
                <?php if ($isSite): ?><div class="about-sep"></div><?php endif; ?>
                <a href="<?= $e($href) ?>" rel="noopener"<?= $isSite ? ' class="ext"' : '' ?>><?= $e($name) ?><?php if ($isSite): ?> <span aria-hidden="true">&#8599;</span><?php endif; ?></a>
            <?php endforeach; ?>
        </div>
    </details>
    <?php
}

/**
 * The signed-in member's menu for the top bar: avatar plus three lines, opening a panel.
 * Pass the result as the 'tools' option of engage_header() on any member page, so every member page
 * has the same navigation. Returns HTML (already escaped). It also holds the About DCW links, so
 * members do not need a separate About button in the bar.
 *
 *   $member   MemberSession::current() row
 *   $o['home']      true on the home page: "Programs" and "Events" jump links replace the "Home" link
 *   $o['programs']  home only: show "Programs" (there are open programs)
 *   $o['events']    home only: show "Events" (there are upcoming events)
 *   $o['current']   the path of the page being shown (e.g. '/member/dashboard'); that item is highlighted
 *
 * Support, Talk to DCW Support and Report a problem are always listed for a signed-in member: those pages
 * explain any lock themselves (for example an expired membership), which is clearer than a missing link.
 * The menu's open/close behaviour comes from the script in engage_footer().
 */
function engage_member_menu(array $member, array $o = []): string {
    $e       = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $id      = (string) ($member['member_id'] ?? '');
    $display = trim((string) ($member['full_name'] ?? '')) ?: $id;
    $initial = mb_strtoupper(mb_substr($display, 0, 1));
    $current = (string) ($o['current'] ?? '');
    $home    = !empty($o['home']);

    $link = function (string $label, string $href, string $class = '') use ($e, $current): string {
        $isCur = $current !== '' && $href === $current;
        $cls   = trim($class . ($isCur ? ' cur' : ''));
        return '<a href="' . $e($href) . '"' . ($cls !== '' ? ' class="' . $e($cls) . '"' : '')
             . ($isCur ? ' aria-current="page"' : '') . '>' . $e($label) . '</a>';
    };

    ob_start(); ?>
    <nav class="mnav" aria-label="Member menu">
        <details class="mmenu burger">
            <summary aria-label="Open menu">
                <span class="mav" aria-hidden="true"><?= $e($initial) ?></span>
                <span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>
            </summary>
            <div class="mpop">
                <div class="mwho">
                    <span class="mav lg" aria-hidden="true"><?= $e($initial) ?></span>
                    <span>
                        <strong><?=
