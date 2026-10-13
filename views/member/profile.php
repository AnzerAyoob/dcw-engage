<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../models/MemberProfileModel.php';

/**
 * DCW Engage - member profile (/member/profile).
 * Signed-in members only. For now: the Wikimedia username. A reviewer or coordinator verifies it;
 * changing it to a different account clears the verification. Internet support needs a verified username.
 */
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];
$profiles = new MemberProfileModel();
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_username') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    try {
        $profiles->saveByMember($memberId, (string) ($_POST['wikimedia_username'] ?? ''), 'member:' . $memberId);
        header('Location: /member/profile?saved=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Engage profile save failed: ' . $e->getMessage());
        $error = 'We could not save your profile just now. Please try again in a moment.';
    }
}

$profile = null;
try { $profile = $profiles->get($memberId); } catch (Throwable $e) { error_log('Engage profile read failed: ' . $e->getMessage()); }

$username = (string) ($profile['wikimedia_username'] ?? '');
$verified = $username !== '' && !empty($profile['username_verified_at']);
$typed    = $error !== '' ? (string) ($_POST['wikimedia_username'] ?? '') : $username;
$h = fn($v) => htmlspecialchars((string) $v);

engage_header([
    'title'   => 'My profile',
    'heading' => 'My profile',
    'kicker'  => 'Member profile',
    'lead'    => 'Member ID ' . $memberId . ' · ' . (string) $member['email'],
    'member'  => $member,
    'crumbs'  => [['Home', '/'], ['Dashboard', '/member/dashboard'], ['My profile']],
]);
?>
<div class="fcard">
    <a class="back-link" href="/member/dashboard">&larr; Back to dashboard</a>

    <?php if (($_GET['saved'] ?? '') === '1'): ?>
        <div class="alert ok">Saved.<?= $verified ? '' : ' A DCW reviewer or coordinator will verify your username.' ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert error"><strong>Notice:</strong> <?= $h($error) ?></div>
    <?php endif; ?>

    <h3>Wikimedia username</h3>

    <?php if ($username === ''): ?>
        <p class="intro">Add your Wikimedia username. It is needed to request internet support, because reviewers check your contributions against it.</p>
    <?php else: ?>
        <p class="intro">
            <strong><?= $h($username) ?></strong>
            <?php if ($verified): ?>
                <span class="pill" style="--tone:#047857;">Verified</span>
                <br><span style="color:var(--muted); font-size:14px;">Checked on <?= $h(MemberAuthModel::formatIst((string) $profile['username_verified_at'], 'j M Y')) ?>.</span>
            <?php else: ?>
                <span class="pill" style="--tone:#b45309;">Awaiting verification</span>
                <br><span style="color:var(--muted); font-size:14px;">A DCW reviewer or coordinator will check that this account exists and is yours.
                    If it is taking long, write to us from <a href="/member/talk">Talk to DCW Support</a>.</span>
            <?php endif; ?>
        </p>
        <p style="font-size:14px; margin:0 0 18px;">
            <a href="<?= $h(MemberProfileModel::globalUrl($username)) ?>" target="_blank" rel="noopener">Global account</a> &middot;
            <a href="<?= $h(MemberProfileModel::contribsUrl($username)) ?>" target="_blank" rel="noopener">Contributions</a>
        </p>
    <?php endif; ?>

    <form method="POST" action="/member/profile">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="action" value="save_username">
        <div class="field">
            <label for="wikimedia_username"><?= $username === '' ? 'Your Wikimedia username' : 'Change your username' ?> <span class="req-star">*</span></label>
            <input type="text" id="wikimedia_username" name="wikimedia_username" required maxlength="255"
                   autocomplete="off" value="<?= $h($typed) ?>">
            <span class="hint">The username only, without "User:" or a link.
                <?= $username !== '' ? 'Switching to a different account means it has to be verified again.' : '' ?></span>
        </div>
        <button type="submit"><?= $username === '' ? 'Save username' : 'Save changes' ?></button>
    </form>
</div>
<?php engage_footer(); ?>
