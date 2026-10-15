<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/MemberAuthModel.php';
require_once __DIR__ . '/../models/MemberCardModel.php';

/**
 * DCW Engage - public membership check (/card/<token>), opened by scanning a member's QR code.
 *
 * PUBLIC: no sign-in, and it does not use the session at all. A scan usually comes from a camera or another
 * app, and the session cookie is SameSite=Strict, so it would not arrive on that first request anyway.
 *
 * What it shows is deliberately small: first name and last initial, each chapter, and whether it is current.
 * Never an email, phone, Member ID or Wikimedia username. An unknown token (never issued, or replaced when the
 * member replaced their card) gets the same plain "not valid" page, so nothing can be learned by guessing.
 *
 * Not indexed and not cached, so an old answer is never shown and search engines never keep one.
 * $cardToken comes from the router (the /card/{token} route in index.php).
 */
global $cardToken;
$token = (string) $cardToken;

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$state   = 'unknown';    // valid | expired | inactive | unknown | busy | error
$card    = null;

if (!MemberCardModel::allow()) {
    http_response_code(429);
    header('Retry-After: 60');
    $state = 'busy';
} else {
    try {
        $card = (new MemberCardModel())->lookup($token);
        if ($card === null) {
            http_response_code(404);
        } else {
            $state = $card['overall'];
        }
    } catch (Throwable $e) {
        app_log('Card check failed: ' . $e->getMessage());
        http_response_code(500);
        $state = 'error';
    }
}

// state => [accent color, headline, one line under it]
$look = [
    'valid'    => ['#047857', 'Valid DCW member',       'This membership is current.'],
    'expired'  => ['#b45309', 'Membership expired',     'This person was a member, but the membership has lapsed.'],
    'inactive' => ['#b91c1c', 'Not an active member',   'This membership is not active.'],
    'unknown'  => ['#b91c1c', 'Card not valid',         'This code is not recognised. The card may have been replaced or cancelled. Please ask the person for their current card.'],
    'busy'     => ['#b45309', 'Please wait a moment',   'Too many checks from this connection. Try again in a minute.'],
    'error'    => ['#b45309', 'Could not check',        'Something went wrong. Please try again in a moment.'],
];
[$accent, $headline, $subline] = $look[$state];

$checkedAt = MemberAuthModel::formatIst(gmdate('Y-m-d H:i:s'), 'j M Y, g:i A');

engage_header([
    'title'   => 'Membership check',
    'heading' => 'Membership check',
    'lead'    => 'Deoband Community Wikimedia',
    'compact' => true,
]);
?>
<style>
    /* Public membership check only. */
    .cc { max-width: 560px; margin: 0 auto 30px; }
    .cc-box { position: relative; overflow: hidden; padding: 26px 26px 22px; background: var(--card); border: 1px solid var(--border); border-left: 8px solid var(--tone); border-radius: 16px; box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .cc-head { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -.02em; line-height: 1.2; color: var(--tone); }
    .cc-sub { margin: 6px 0 0; color: var(--muted); font-size: 15px; line-height: 1.55; }
    .cc-name { margin: 20px 0 0; padding-top: 16px; border-top: 1px solid var(--border); font-size: 22px; font-weight: 800; letter-spacing: -.01em; }
    .cc-list { list-style: none; margin: 10px 0 0; padding: 0; }
    .cc-list li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 4px 12px; padding: 10px 0; border-top: 1px solid var(--border); font-size: 15px; }
    .cc-list li:first-child { border-top: 0; }
    .cc-list .n { font-weight: 600; }
    .cc-list .st { font-size: 13.5px; color: var(--muted); }
    .cc-pill { padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; margin-right: 6px; color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, #fff); }
    .cc-note { margin: 18px 0 0; padding-top: 14px; border-top: 1px solid var(--border); color: var(--muted); font-size: 13px; line-height: 1.6; }
</style>

<div class="cc">
    <section class="cc-box" style="--tone: <?= $h($accent) ?>;" aria-live="polite">
        <h2 class="cc-head"><?= $h($headline) ?></h2>
        <p class="cc-sub"><?= $h($subline) ?></p>

        <?php if ($card !== null): ?>
            <p class="cc-name"><?= $h($card['name']) ?></p>
            <ul class="cc-list">
                <?php foreach ($card['memberships'] as $m):
                    $tone = $m['state'] === 'active' ? '#047857' : ($m['state'] === 'expired' ? '#b45309' : '#b91c1c'); ?>
                    <li style="--tone: <?= $h($tone) ?>;">
                        <span class="n"><?= $h($m['name']) ?></span>
                        <span class="st">
                            <?php if ($m['state'] === 'active'): ?>
                                <span class="cc-pill">Current</span>valid until <?= $h($m['date']) ?>
                            <?php elseif ($m['state'] === 'expired'): ?>
                                <span class="cc-pill">Expired</span>on <?= $h($m['date']) ?>
                            <?php else: ?>
                                <span class="cc-pill">Not active</span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="cc-note">This confirms membership only. If you need to be sure who is presenting the card, ask to see photo ID.
                Checked on <?= $h($checkedAt) ?>.</p>
        <?php endif; ?>
    </section>
</div>
<?php engage_footer(); ?>
