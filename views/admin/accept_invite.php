<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';   // engage_asset(): versioned CSS links
require_once __DIR__ . '/../../models/InviteModel.php';

/**
 * Public page — the visitor has no account yet, so this is the one route
 * under /admin that must not call Auth::requireLogin().
 *
 * Holding the token is the proof of identity. It came from an email we sent
 * to that address, which is why there is no separate verification step.
 */

$invites = new InviteModel();

// The token arrives on the query string on first click, then rides the POST
// so a failed password attempt does not lose it.
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// Someone already signed in has no business creating a second account.
if (Auth::check()) {
    header('Location: /admin/dashboard');
    exit;
}

$invite = $invites->findUsableByToken($token);
$error = '';
$done = false;

if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (strlen($password) < InviteModel::MIN_PASSWORD_LENGTH) {
        $error = 'Password must be at least ' . InviteModel::MIN_PASSWORD_LENGTH . ' characters.';
    } elseif ($password !== $confirm) {
        $error = 'Those passwords do not match.';
    } else {
        $account = $invites->redeem($token, $password);

        if (!$account) {
            // Only reachable if the invite was revoked or consumed between
            // loading this page and submitting it.
            $error = 'This invitation is no longer valid. Ask an owner to send a new one.';
            $invite = null;
        } else {
            // The actor is the new account itself — a self-serve event, not an
            // owner action — so it is logged under their own identity.
            AuditLog::record('invite.accepted', $account['id'], $account['email'], $account['email'], 'Account created from invitation');

            // Sign them straight in. They have just proved control of the
            // inbox and chosen the password, so a login form here would only
            // ask for what they typed thirty seconds ago.
            session_regenerate_id(true);
            $_SESSION['admin_id']    = $account['id'];
            $_SESSION['admin_email'] = $account['email'];
            $_SESSION['admin_role']  = $account['role'];
            // A brand new account has never changed its password, so the
            // stamp starts null and matches what Auth::check() will read.
            $_SESSION['admin_pw_stamp'] = null;

            $done = true;
        }
    }
}

if ($done) {
    header('Location: /admin/dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#061A1E">
    <meta name="robots" content="noindex, nofollow">
    <title>Accept invitation - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(engage_asset('/assets/css/engage.css'), ENT_QUOTES) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(engage_asset('/assets/css/staff.css'), ENT_QUOTES) ?>">
</head>
<body class="admin signin">
    <main class="signin-shell">
        <section class="signin-brand">
            <span class="s-logo"><img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia"></span>
            <p class="kicker">Organizer workspace</p>
            <h1>Join <em>DCW Engage</em></h1>
            <p class="lead">One step left: choose a password and your organizer account is ready.</p>
            <div class="stripe" aria-hidden="true"></div>
        </section>

        <div class="auth-card">
        <?php if (!$invite): ?>
            <h2>Invitation not valid</h2>
            <p class="sub">
                This link has expired, has already been used, or was withdrawn.
                Invitations are single use and time limited.
            </p>
            <?php if ($error): ?>
                <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <p class="sub" style="margin-bottom:0;">
                Ask a workspace owner to send you a new invitation.
            </p>
        <?php else: ?>
            <h2>Set your password</h2>
            <p class="sub">
                You have been invited to the DCW Engage organizer workspace as
                <strong><?= htmlspecialchars($invite['email']) ?></strong>.
                Choose a password to activate the account.
            </p>

            <?php if ($error): ?>
                <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

                <label for="password">Password</label>
                <input type="password" name="password" id="password" required autofocus
                       minlength="<?= InviteModel::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                <p class="hint">
                    At least <?= InviteModel::MIN_PASSWORD_LENGTH ?> characters. This workspace holds
                    applicant information, so use a password you do not use anywhere else.
                </p>

                <label for="password_confirm">Confirm password</label>
                <input type="password" name="password_confirm" id="password_confirm" required
                       minlength="<?= InviteModel::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">

                <button type="submit" class="btn-primary">Activate account</button>
            </form>

            <p class="alt">
                Not expecting this? You can close this page &mdash; nothing is
                created until you set a password.
            </p>
        <?php endif; ?>
        </div>
    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div><a href="/admin/login">Go to sign in</a></div>
    </footer>
</body>
</html>
