<?php
/**
 * DCW Engage - shared helpers for the member support views
 * (views/member/ticket_new.php, views/member/ticket.php, views/admin/member_support.php).
 *
 * Uses the project's own CSRF class (validate / getInputField / getSubmitField / consumeSubmitToken).
 *
 * Styles: the .ms-* rules now live in assets/css/engage.css (section "Member support"), written with the
 * shared colour tokens, so the same markup is blue on member pages and teal on staff pages.
 * ms_styles() is kept (and returns nothing) so pages that still call it keep working.
 */
require_once __DIR__ . '/engage_page.php';

function ms_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** UTC from the database, shown in IST. */
function ms_date(?string $utc, bool $withTime = false): string
{
    if (!$utc) return '';
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($withTime ? 'j M Y, g:i a' : 'j M Y');
}

/**
 * Colour for a status pill, as a CSS value for the --tone variable. Uses the page's own tokens, with the old
 * colour as the fallback for tokens a page does not define (the staff scheme defines --amber, --violet, --slate).
 */
function ms_tone(string $status): string
{
    switch ($status) {
        case 'Awaiting member':
        case 'Under consideration': return 'var(--amber, #b45309)';
        case 'Resolved':
        case 'Done':                return 'var(--leaf-dark, #15803d)';
        case 'Planned':             return 'var(--violet, #0f766e)';
        case 'Not planned':         return 'var(--slate, #6b7280)';
        default:                    return 'var(--primary, #106b9a)';
    }
}

// ---------------------------------------------------------------- CSRF
/** Both hidden fields every form needs: the CSRF token and the single-use submit token. */
function ms_csrf_field(): string { return CSRF::getInputField() . CSRF::getSubmitField(); }

function ms_csrf_valid(): bool { return (bool) CSRF::validate($_POST['csrf_token'] ?? ''); }

/**
 * True the first time a rendered form is submitted, false for a double click. Callers drop the
 * duplicate silently (redirect, no flash), so the first submission's result is what the person sees.
 */
function ms_submit_once(): bool { return (bool) CSRF::consumeSubmitToken($_POST['submit_token'] ?? ''); }

// ---------------------------------------------------------------- flash messages (post/redirect/get)
function ms_flash(string $kind, string $msg): void { $_SESSION['ms_flash'] = [$kind, $msg]; }

function ms_flash_html(): string
{
    if (empty($_SESSION['ms_flash'])) return '';
    [$kind, $msg] = $_SESSION['ms_flash'];
    unset($_SESSION['ms_flash']);
    return '<div class="' . ($kind === 'ok' ? 'ms-ok' : 'ms-error') . '" role="status">' . ms_e($msg) . '</div>';
}

// ---------------------------------------------------------------- styles
/** The styles moved to engage.css. Kept so existing calls (echo ms_styles()) still work. */
function ms_styles(): string
{
    return '';
}

// ---------------------------------------------------------------- member page chrome
/** $links: [[href, label], ...] shown as buttons in the top bar. Opens <main>; ms_foot() closes it. */
function ms_head(string $title, string $h1, string $lead, array $links = [], string $kicker = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <meta name="robots" content="noindex, nofollow">
    <title><?= ms_e($title) ?> - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ms_e(engage_asset('/assets/css/engage.css')) ?>">
    <style>.hero { padding-bottom: 44px; } .wrap.cards-wrap { margin-top: 34px; }</style>
</head>
<body>
    <header class="hero">
        <div class="topbar">
            <a class="brand" href="/">
                <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
                <span>DCW Engage</span>
            </a>
            <div class="tools">
                <?php foreach ($links as $l): ?>
                    <a href="<?= ms_e($l[0]) ?>" class="chip-btn"><?= ms_e($l[1]) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($kicker !== ''): ?><p class="kicker"><?= ms_e($kicker) ?></p><?php endif; ?>
        <h1><?= ms_e($h1) ?></h1>
        <p class="lead"><?= ms_e($lead) ?></p>
    </header>

    <main class="wrap wide cards-wrap">
        <?= ms_flash_html() ?>
<?php
}

function ms_foot(): void
{
    ?>
    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div>&copy; <?= date('Y') ?> · <a href="/">dcwwiki.org</a></div>
    </footer>
</body>
</html>
<?php
}
