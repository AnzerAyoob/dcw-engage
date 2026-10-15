<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/cert_rows.php';          // .d-btn buttons, and the copy buttons
require_once __DIR__ . '/../../includes/qr.php';
require_once __DIR__ . '/../../includes/member_card_template.php'; // member_card_accent()
require_once __DIR__ . '/../../models/MemberCardModel.php';

/**
 * DCW Engage - the member's own membership card (/member/card).
 * Signed-in members only. The card has the same design as the PDF: the chapter's logo and name, the verified
 * Wikimedia username, Member ID, other current chapters and the QR code. Status, validity dates, the PDF
 * download and "Replace card" sit below the card, because they are not part of the card itself.
 *
 * The first visit creates the member's card (a random token). The QR code carries the public link
 * /card/<token>, which shows the Wikimedia username, chapters and whether they are current
 * (views/card_check.php). Replacing the card retires the old token at once.
 */
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];
$cards    = new MemberCardModel();
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    if ((string) ($_POST['action'] ?? '') === 'replace_card') {
        try {
            $cards->replace($memberId, 'member:' . $memberId);
            header('Location: /member/card?saved=replaced');
            exit;
        } catch (Throwable $e) {
            error_log('Engage card replace failed: ' . $e->getMessage());
            $error = 'We could not replace your card just now. Please try again in a moment.';
        }
    }
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$data = null;
$url  = '';
$svg  = '';
try {
    $token = $cards->issue($memberId);
    $data  = $cards->summary($memberId);
    $url   = MemberCardModel::url($token);
    $svg   = QrCode::svg($url, 'QR code for your DCW membership card');
} catch (Throwable $e) {
    error_log('Engage card load failed: ' . $e->getMessage());
    if ($error === '') {
        $error = 'We could not load your card just now. Please try again in a moment.';
    }
}

$saved = (string) ($_GET['saved'] ?? '');

// overall state => [colour, headline]
$look = [
    'valid'    => ['#047857', 'Your membership is current'],
    'expired'  => ['#b45309', 'Your membership has expired'],
    'inactive' => ['#b91c1c', 'Your membership is not active'],
];
[$tone, $headline] = $look[$data['overall'] ?? 'inactive'];

// Card header and "also a member of" follow the PDF.
$accent = '#047857';
$sub    = 'Membership card';
$others = [];
if ($data !== null) {
    $org    = 'Deoband Community Wikimedia';
    $key    = (string) $data['primary']['chapter'];
    $accent = member_card_accent($key);
    $isOrg  = $key === 'generic' || strcasecmp(trim((string) $data['primary']['name']), $org) === 0;
    $sub    = $isOrg ? 'Membership card' : $org . ' membership card';
    foreach (array_slice($data['memberships'], 1) as $m) {
        if ($m['state'] === 'active') {
            $others[] = $m['name'];
        }
    }
}

engage_header([
    'title'   => 'Membership card',
    'heading' => 'Membership card',
    'lead'    => 'Show this code to prove you are a DCW member.',
    'back'    => ['Dashboard', '/member/dashboard'],
    'compact' => true,
    'member'  => $member,
    'tools'   => engage_member_menu($member, ['current' => '/member/card']),
]);
cert_rows_css();
?>
<style>
    /* Member card page only. */
    .mc { max-width: 520px; margin: 0 auto 30px; }

    /* The card: white, the chapter colour as its edge and header rule, the logo with no box around it. */
    .mc-card { margin: 0 0 20px; background: #fff; border: 2px solid var(--accent); border-radius: 16px; overflow: hidden; box-shadow: 0 10px 26px rgba(15,23,42,.10); }
    .mc-head { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-bottom: 3px solid var(--accent); }
    .mc-logo { flex: none; height: 44px; width: auto; max-width: 96px; object-fit: contain; }
    .mc-chapter { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -.01em; line-height: 1.2; overflow-wrap: anywhere; }
    .mc-org { margin: 2px 0 0; font-size: 12.5px; color: var(--muted); }
    .mc-main { display: grid; grid-template-columns: minmax(0, 1fr) minmax(110px, 38%); gap: 16px; align-items: start; padding: 18px 20px 14px; }
    .mc-lb { margin: 0 0 2px; font-size: 12px; color: var(--muted); }
    .mc-name { margin: 0 0 14px; font-size: 20px; font-weight: 800; letter-spacing: -.01em; overflow-wrap: anywhere; }
    .mc-id { margin: 0; font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; font-size: 18px; font-weight: 700; letter-spacing: .04em; }
    .mc-also { margin: 14px 0 0; padding: 0; list-style: none; font-size: 14px; line-height: 1.5; }
    .mc-qr svg { display: block; width: 100%; height: auto; border: 1px solid var(--border); border-radius: 10px; }
    .mc-scan { margin: 6px 0 0; text-align: center; color: var(--muted); font-size: 12.5px; }
    .mc-foot { margin: 0; padding: 0 20px 14px; color: var(--muted); font-size: 12px; }
    @media (max-width: 380px) {
        .mc-main { grid-template-columns: 1fr; }
        .mc-qr { max-width: 200px; margin: 0 auto; }
    }

    /* Below the card: status, chapters, actions. */
    .mc-box { margin: 0 0 20px; padding: 18px 22px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; }
    .mc-state { margin: 0; font-size: 15px; font-weight: 700; color: var(--tone); }
    .mc-hint { margin: 8px 0 0; font-size: 13.5px; color: var(--muted); line-height: 1.5; }
    .mc-hint a { color: var(--primary); font-weight: 600; }
    .mc-ms { list-style: none; margin: 14px 0 0; padding: 0; }
    .mc-ms li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 4px 12px; padding: 9px 0; border-top: 1px solid var(--border); font-size: 14.5px; }
    .mc-ms .n { display: inline-flex; align-items: center; gap: 10px; font-weight: 600; }
    .mc-ms .n img { width: 26px; height: 26px; object-fit: contain; }
    .mc-ms .st { font-size: 13px; color: var(--muted); }
    .mc-pill { padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; margin-right: 6px; color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, #fff); }
    .mc-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0 0; }
    .mc-box p { margin: 0 0 10px; color: var(--muted); font-size: 14px; line-height: 1.55; }
    .mc-box summary { cursor: pointer; font-weight: 700; color: var(--primary); font-size: 14.5px; }
    .mc-box form { margin-top: 12px; }
</style>

<div class="mc">
<?php if ($saved === 'replaced'): ?>
    <div class="alert ok">Your card has been replaced. The old QR code no longer works.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert error"><strong>Notice:</strong> <?= $h($error) ?></div>
<?php endif; ?>

<?php if ($data !== null): ?>
    <section class="mc-card" aria-label="Membership card" style="--accent: <?= $h($accent) ?>;">
        <div class="mc-head">
            <?php if (!empty($data['primary']['logo'])): ?>
                <img class="mc-logo" src="<?= $h($data['primary']['logo']) ?>" alt="">
            <?php endif; ?>
            <div>
                <h2 class="mc-chapter"><?= $h($data['primary']['name']) ?></h2>
                <p class="mc-org"><?= $h($sub) ?></p>
            </div>
        </div>
        <div class="mc-main">
            <div>
                <?php if ($data['username'] !== ''): ?>
                    <p class="mc-lb">Wikimedia username</p>
                    <p class="mc-name"><?= $h($data['username']) ?></p>
                <?php endif; ?>
                <p class="mc-lb">Member ID</p>
                <p class="mc-id"><?= $h($memberId) ?></p>
                <?php if ($others): ?>
                    <p class="mc-lb" style="margin-top: 14px;">Also a member of</p>
                    <ul class="mc-also">
                        <?php foreach ($others as $n): ?><li><?= $h($n) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div>
                <div class="mc-qr"><?= $svg /* generated by QrCode::svg, label escaped there */ ?></div>
                <p class="mc-scan">Scan to verify</p>
            </div>
        </div>
        <p class="mc-foot">Anyone can scan this to check your membership. It confirms membership only, not identity.</p>
    </section>

    <section class="mc-box" aria-label="Membership status" style="--tone: <?= $h($tone) ?>;">
        <p class="mc-state"><?= $h($headline) ?></p>
        <?php if ($data['username'] === ''): ?>
            <p class="mc-hint">Your Wikimedia username appears on the card once it is verified. <a href="/member/profile#wikimedia">Check it on your profile</a>.</p>
        <?php endif; ?>

        <ul class="mc-ms">
            <?php foreach ($data['memberships'] as $m):
                $mtone = $m['state'] === 'active' ? '#047857' : ($m['state'] === 'expired' ? '#b45309' : '#b91c1c'); ?>
                <li style="--tone: <?= $h($mtone) ?>;">
                    <span class="n">
                        <?php if (!empty($m['logo'])): ?><img src="<?= $h($m['logo']) ?>" alt="" width="26" height="26"><?php endif; ?>
                        <?= $h($m['name']) ?>
                    </span>
                    <span class="st">
                        <?php if ($m['state'] === 'active'): ?>
                            <span class="mc-pill">Current</span><?= $m['date'] !== '' ? 'valid until ' . $h($m['date']) : '' ?>
                        <?php elseif ($m['state'] === 'expired'): ?>
                            <span class="mc-pill">Expired</span><?= $m['date'] !== '' ? 'on ' . $h($m['date']) : '' ?>
                        <?php else: ?>
                            <span class="mc-pill">Not active</span>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="mc-actions">
            <a class="d-btn fill" href="/member/card/pdf">Download PDF</a>
            <button type="button" class="d-btn line" data-copy="<?= $h($url) ?>"><span>Copy link</span></button>
        </div>
    </section>

    <section class="mc-box" aria-label="Replace card">
        <details>
            <summary>Lost your card or shared it by mistake?</summary>
            <form method="POST" action="/member/card" onsubmit="return confirm('Replace your card? The old QR code will stop working straight away.');">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="replace_card">
                <p>Replacing makes a new QR code. Any printed or saved copy of the old card will show &ldquo;Card not valid&rdquo; from then on, so download the new PDF afterwards.</p>
                <button type="submit" class="d-btn line">Replace my card</button>
            </form>
        </details>
    </section>
<?php endif; ?>
</div>
<?php cert_rows_js(); /* copy buttons */ ?>
<?php engage_footer(); ?>
