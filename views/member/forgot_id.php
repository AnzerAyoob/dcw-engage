<?php
/**
 * Forgot your Member ID?
 *
 * The member types their Wikimedia username and the email address they joined with.
 * If both match one member, the Member ID is EMAILED to the address on file. It is never
 * shown on this page, and the screen after submitting is identical whether or not anything
 * matched, so nobody can use this page to find out who is a member or to read out an ID.
 *
 * Route: /member/forgot-id  (add next to /member/forgot in index.php)
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../includes/mail/membership_mailer.php';

// Already signed in: the ID is on their dashboard, nothing to recover.
if (MemberSession::check()) {
    header('Location: /member/dashboard');
    exit;
}

/** Same rules MediaWiki applies: underscores are spaces, first letter capital. */
function normaliseWikiUsernameInput(string $u): string
{
    $u = trim((string) preg_replace('/[\s_]+/u', ' ', $u));
    if ($u === '') {
        return '';
    }
    return mb_strtoupper(mb_substr($u, 0, 1)) . mb_substr($u, 1);
}

$error = '';
$sent = false;
$username = '';
$email = '';

// Light throttle: 5 attempts per 15 minutes per browser session. This is only a first line of
// defence (a script can drop its cookie); if forgot.php already has a proper per-IP / per-email
// limiter, call that here instead and delete this block.
$now = time();
$_SESSION['id_recovery_hits'] = array_values(array_filter(
    $_SESSION['id_recovery_hits'] ?? [],
    fn($t) => is_int($t) && $t > $now - 900
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = normaliseWikiUsernameInput((string) ($_POST['wiki_username'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (count($_SESSION['id_recovery_hits']) >= 5) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif ($username === '' || mb_strlen($username) > 85) {
        $error = 'Please enter your Wikimedia username.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $_SESSION['id_recovery_hits'][] = $now;

        try {
            $member = (new MemberModel())->findForIdReminder($username, $email);
            if ($member) {
                // Always to the address on file (which equals the typed one, since it had to match).
                MembershipMailer::sendMemberIdReminder($member['email'], $member['member_id'], $member['name'] ?? '');
            }
        } catch (Throwable $e) {
            // Log it, but show the same screen: the page must not reveal the outcome.
            app_log('Member ID reminder failed: ' . $e->getMessage());
        }
        $sent = true;
    }
}

$pageTitle = 'Forgot your Member ID?';
$heading = 'Forgot your Member ID?';
$sub = 'Enter your Wikimedia username and the email address you joined with. We will email your Member ID to that address.';
require __DIR__ . '/_head.php';
?>
<?php if ($sent): ?>
            <div class="alert ok">
                <strong>Check your inbox.</strong>
                If that username and email address belong to a member, we have sent the Member ID to that address.
                It should arrive within a few minutes; please check your spam folder too.
            </div>
            <p class="links">
                <a href="/member/login">Back to sign in</a><br>
                <a href="/member/forgot">Forgot your password too?</a>
            </p>
<?php else: ?>
            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= CSRF::getInputField() ?>

                <label for="wiki_username">Wikimedia username</label>
                <input type="text" name="wiki_username" id="wiki_username" required autofocus maxlength="85"
                       autocapitalize="sentences" spellcheck="false"
                       value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">

                <label for="email">Email address</label>
                <input type="email" name="email" id="email" required autocomplete="email"
                       value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">

                <button type="submit">Email me my Member ID</button>
            </form>

            <p class="links">
                <a href="/member/login">Back to sign in</a><br>
                <a href="/membership">Not a member yet? Join DCW</a>
            </p>
<?php endif; ?>
<?php require __DIR__ . '/_foot.php'; ?>
