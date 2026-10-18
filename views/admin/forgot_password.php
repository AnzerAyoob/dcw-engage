<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/engage_page.php';   // engage_asset(): versioned CSS links
require_once __DIR__ . '/../../models/PasswordResetModel.php';

/**
 * Public page. Asks for an address and, if it belongs to an organizer, emails
 * a reset link.
 *
 * The response is identical whether or not the address has an account, and
 * whether or not the mail actually went out. Anything that varies with the
 * outcome — different wording, a different status code, a visibly different
 * delay — turns this form into a way to discover which addresses are
 * organizers, which is exactly the list an attacker wants.
 */

if (Auth::check()) {
    header('Location: /admin/dashboard');
    exit;
}

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        // Repeat submission from a double click. Show the same confirmation
        // the first one produced rather than sending a second email.
        $sent = true;
    } else {
        $email = trim($_POST['email'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resets = new PasswordResetModel();
            $reset = $resets->request($email);

            // Null means unknown address or too many recent requests. Both are
            // silent on purpose.
            if ($reset) {
                Mailer::sendPasswordReset(
                    $reset['email'],
                    $reset['token'],
                    $reset['expires_at']
                );
            }
        }

        // Always the same answer, including for a malformed address.
        $sent = true;
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
    <title>Reset password - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(engage_asset('/assets/css/engage.css'), ENT_QUOTES) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(engage_asset('/assets/css/staff.css'), ENT_QUOTES) ?>">
</head>
<body class="admin signin">
    <main class="signin-shell">
        <section class="signin-brand">
            <span class="s-logo"><img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia"></span>
            <p class="kicker">Organizer workspace</p>
            <h1>Locked <em>out?</em></h1>
            <p class="lead">It happens. We will email a link that lets you choose a new password.</p>
            <div class="stripe" aria-hidden="true"></div>
        </section>

        <div class="auth-card">
        <?php if ($sent): ?>
            <h2>Check your email</h2>
            <p class="sub">
                If that address belongs to an organizer account, a reset link is
                on its way. It expires in an hour and can be used once.
            </p>
            <div class="notice">
                Nothing arrived? Check spam, and make sure you used the address
                your account was created with. Asking again sends a fresh link
                and cancels the previous one.
            </div>
            <p class="alt"><a href="/admin/login">Back to sign in</a></p>
        <?php else: ?>
            <h2>Reset your password</h2>
            <p class="sub">
                Enter the email address on your organizer account and we will
                send you a link to choose a new password.
            </p>

            <?php if ($error): ?>
                <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= CSRF::getInputField() ?>
                <?= CSRF::getSubmitField() ?>

                <label for="email">Email</label>
                <input type="email" name="email" id="email" required autofocus
                       autocomplete="email" placeholder="you@dcwwiki.org">

                <button type="submit" class="btn-primary">Send reset link</button>
            </form>

            <p class="alt"><a href="/admin/login">Back to sign in</a></p>
        <?php endif; ?>
        </div>
    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div><a href="/">Back to DCW Engage</a></div>
    </footer>

    <script>
        // Stops the obvious double click. The single-use submit token on the
        // server is what actually guarantees one email.
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type=submit]');
                if (!button || button.disabled) return;
                button.style.minWidth = button.offsetWidth + 'px';
                button.disabled = true;
                button.textContent = 'Sending...';
            });
        });
    </script>
</body>
</html>
