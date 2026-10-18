<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/PasswordResetModel.php';

/**
 * Public page. Consumes a reset token and sets a new password.
 *
 * Completing this logs out every session the account already had open, which
 * is handled by Auth::check() comparing each session against the account's
 * password_changed_at stamp. That is the whole point of a reset when the
 * reason for it is "somebody else may be in my account".
 */

$resets = new PasswordResetModel();

// On the query string when the link is first opened, then carried through the
// POST so a rejected password does not lose it.
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$reset = $resets->findUsableByToken($token);

$error = '';
$minLength = Auth::MIN_PASSWORD_LENGTH;

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (strlen($password) < $minLength) {
        $error = 'Password must be at least ' . $minLength . ' characters.';
    } elseif ($password !== $confirm) {
        $error = 'Those passwords do not match.';
    } else {
        $account = $resets->complete($token, $password);

        if (!$account) {
            $error = 'This link is no longer valid. Please request a new one.';
            $reset = null;
        } else {
            // Self-serve event: the actor is the account whose password changed.
            AuditLog::record('password.reset', $account['id'], $account['email'], $account['email'], 'Password reset via emailed link');

            // Deliberately not signed in here. Sending them to the login form
            // confirms the new password actually works, and keeps this page
            // from being a way to obtain a session.
            $_SESSION['login_notice'] = 'Password updated. Sign in with your new password.';
            header('Location: /admin/login');
            exit;
        }
    }
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
    <title>Choose a new password - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(engage_asset('/assets/css/engage.css'), ENT_QUOTES) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(engage_asset('/assets/css/staff.css'), ENT_QUOTES) ?>">
</head>
<body class="admin signin">
    <div class="signin-shell">
        <div class="signin-brand">
            <span class="s-logo"><img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt=""></span>
            <p class="kicker">DCW Engage &middot; Staff</p>
            <h1>Reset your <em>password</em></h1>
            <p class="lead">The workspace for Deoband Community Wikimedia's organizers, reviewers and support team.</p>
            <div class="stripe"></div>
        </div>

        <div class="auth-card">
            <?php if (!$reset): ?>
                <h2>Link not valid</h2>
                <p class="sub">
                    This reset link has expired, has already been used, or was
                    replaced by a newer one. Reset links last an hour and work once.
                </p>
                <?php if ($error): ?>
                    <div class="alert error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <p class="alt"><a href="/admin/forgot-password">Request a new link</a></p>
            <?php else: ?>
                <h2>Choose a new password</h2>
                <p class="sub">
                    Setting a new password for
                    <strong><?= htmlspecialchars($reset['email']) ?></strong>.
                    Anyone signed in to this account elsewhere will be signed out.
                </p>

                <?php if ($error): ?>
                    <div class="alert error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

                    <label for="password">New password</label>
                    <input type="password" name="password" id="password" required autofocus
                           minlength="<?= $minLength ?>" autocomplete="new-password">
                    <p class="hint">
                        At least <?= $minLength ?> characters. This workspace holds applicant
                        information, so use a password you do not use anywhere else.
                    </p>

                    <label for="password_confirm">Confirm new password</label>
                    <input type="password" name="password_confirm" id="password_confirm" required
                           minlength="<?= $minLength ?>" autocomplete="new-password">

                    <button type="submit">Update password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div>&copy; <?= date('Y') ?> &middot; <a href="/admin/login">Staff sign in</a></div>
    </footer>
</body>
</html>
