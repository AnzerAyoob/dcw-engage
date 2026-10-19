<?php
/**
 * Owner-only preview of the chapter themes: /admin/chapter-themes[?chapter=amu]
 *
 * Shows a sample member page in each chapter's colors and logo, so owners can check the look
 * without signing in as a member. It uses a made-up sample member, never real member data.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/engage_page.php';

Auth::requireOwner();   // same gate as team.php

// Draw this page with the member look (not the staff look) and keep it out of search engines.
$GLOBALS['ENGAGE_THEME_PREVIEW'] = true;

$e       = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$key     = engage_preview_chapter_key();
$sample  = engage_preview_member($key);
$vars    = engage_chapter_theme_vars($sample);
$label   = engage_chapter_label($key);
$hasLogo = $key !== 'generic' && engage_asset_file('/assets/chapters/' . $key . '.svg') !== null;

// The values the page is using right now (the DCW defaults when the chapter has no theme).
$defaults = [
    '--primary' => '#2E6599', '--primary-dark' => '#1F4A75', '--primary-light' => '#4A86BD',
    '--primary-tint' => '#EAF2F9', '--hero-end' => '#5A95C6', '--accent' => '#8D1A10',
];
$shown = array_intersect_key($vars + $defaults, $defaults);

engage_header([
    'title'   => 'Chapter theme preview',
    'heading' => 'Chapter preview: ' . $label,
    'lead'    => 'This is how member pages look for a member of this chapter.',
    'member'  => $sample,
    'tools'   => engage_member_menu($sample, ['current' => '/member/dashboard']),
    'compact' => true,
    'back'    => ['Workspace', '/admin/dashboard'],
]);
?>
<section class="ms-card">
    <div class="panel-head">
        <h2>Choose a chapter</h2>
        <p>Colors and logo change with the chapter. Nothing here is saved or shown to members.</p>
    </div>
    <nav class="tabs" aria-label="Chapters">
        <?php foreach (engage_chapter_choices() as $c): ?>
            <a class="tab<?= $c === $key ? ' on' : '' ?>" href="?chapter=<?= $e(urlencode($c)) ?>"<?= $c === $key ? ' aria-current="page"' : '' ?>><?= $e(engage_chapter_label($c)) ?></a>
        <?php endforeach; ?>
    </nav>

    <h3 style="margin:0 0 10px;font-size:15px;">Colors in use</h3>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach ($shown as $name => $hex): ?>
            <div style="min-width:120px;">
                <div style="height:38px;border-radius:10px;border:1.5px solid var(--border);background:<?= $e($hex) ?>;"></div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px;"><?= $e($name) ?><br><span style="font-family:ui-monospace,Menlo,monospace;"><?= $e($hex) ?></span></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($key !== 'generic'): ?>
        <p class="ms-meta">Logo: <?= $hasLogo ? 'assets/chapters/' . $e($key) . '.svg found.' : '<strong>assets/chapters/' . $e($key) . '.svg is missing</strong>, so the DCW seal is shown.' ?></p>
    <?php endif; ?>
</section>

<section class="ms-card">
    <div class="panel-head"><h2>Sample member page</h2><p>Banner, cards, buttons, form fields and messages in this theme.</p></div>

    <div class="grid" style="margin-bottom:18px;">
        <a class="prog featured" href="#" onclick="return false;">
            <span class="tag">Membership</span>
            <span class="tick"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
            <h3>Join or renew</h3>
            <p>The featured banner follows the accent color.</p>
            <span class="go">Open <span>&rarr;</span></span>
        </a>
        <a class="prog" href="#" onclick="return false;">
            <span class="tag">Open</span>
            <span class="tick"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
            <h3>Program card</h3>
            <p>Cards use the primary color for the top edge and the link.</p>
            <span class="go">Apply <span>&rarr;</span></span>
        </a>
    </div>

    <div class="mstrip">
        <div>
            <span class="pill">Active</span>
            <h2>Your membership is active</h2>
            <p>Valid until 31 December.</p>
        </div>
        <a class="btn-pill" href="#" onclick="return false;">Renew</a>
    </div>

    <div class="tiles">
        <a class="tile" href="#" onclick="return false;">
            <span class="tick"><svg viewBox="0 0 24 24"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/></svg></span>
            <span><h3>Request support</h3><p>Ask the DCW team for help with a program.</p></span>
            <span class="arrow">&rarr;</span>
        </a>
        <a class="tile" href="#" onclick="return false;">
            <span class="tick"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>
            <span><h3>My certificates</h3><p>Download your certificates.</p></span>
            <span class="arrow">&rarr;</span>
        </a>
    </div>

    <div class="fcard" style="margin-top:22px;">
        <p class="intro">A form card, as used for support and profile pages.</p>
        <div class="field">
            <label for="pv-name">Full name</label>
            <input id="pv-name" type="text" value="Sample Member">
            <span class="hint">Click into the field to see the focus ring in this theme.</span>
        </div>
        <div class="alert ok">Saved. This is a success message.</div>
        <div class="alert error">Something went wrong. This is an error message.</div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <button type="button" class="btn-ghost">Secondary</button>
            <button type="button" class="btn-danger-ghost">Cancel request</button>
        </div>
        <p style="margin-top:18px;"><button type="submit" class="btn-solid" onclick="return false;">Primary button</button></p>
    </div>
</section>
<?php engage_footer(); ?>
