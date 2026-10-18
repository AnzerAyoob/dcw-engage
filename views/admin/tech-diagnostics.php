<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/tech_issue_ui.php';
require_once __DIR__ . '/../../includes/portal_diagnostics.php';

// Route: /admin/tech-diagnostics  (technical_manager, owner)
Auth::requireLogin();
tech_require_staff();

header('Cache-Control: no-store');

$groups = PortalDiagnostics::run();
$tally  = PortalDiagnostics::tally($groups);

AuditLog::record('tech_diagnostics.run', Auth::id(), Auth::email(), null,
    'ok ' . $tally['ok'] . ', warn ' . $tally['warn'] . ', fail ' . $tally['fail']);

// Pass / warning / fail colours come from the shared tokens, so they follow the staff palette.
$tone  = ['ok' => 'var(--leaf-dark)', 'warn' => 'var(--amber, #b45309)', 'fail' => 'var(--accent)'];
$words = ['ok' => 'Pass', 'warn' => 'Warning', 'fail' => 'Fail'];

// The top-bar buttons, the same as on the workspace dashboard: who is signed in, owner shortcuts, sign out.
// Sign out is a POST with a CSRF token (logout.php ignores anything else).
ob_start(); ?>
<span class="who"><?= htmlspecialchars((string) Auth::email()) ?></span>
<?php if (Auth::isOwner()): ?>
    <a href="/admin/team" class="chip-btn">Team</a>
    <a href="/admin/audit" class="chip-btn">Audit log</a>
<?php endif; ?>
<form method="POST" action="/admin/logout">
    <?= CSRF::getInputField() ?>
    <button type="submit" class="chip-btn">Sign out</button>
</form>
<?php
$topTools = ob_get_clean();

engage_header([
    'title'   => 'Portal diagnostics',
    'heading' => 'Portal diagnostics',
    'lead'    => 'Read-only health checks. No member data, settings or secrets are shown. Details of a failure go to the server log.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Reported problems', '/admin/tech-issues'], ['Diagnostics']],
    'tools'   => $topTools,
]);
?>
<section class="fcard wide">
    <p class="note" style="margin:0;">
        <strong><?= (int) $tally['ok'] ?></strong> passed &middot;
        <strong><?= (int) $tally['warn'] ?></strong> warnings &middot;
        <strong><?= (int) $tally['fail'] ?></strong> failed
        &nbsp;&middot;&nbsp; <a href="/admin/tech-diagnostics">Run again</a>
        &nbsp;&middot;&nbsp; <a href="/admin/tech-issues">Back to reported problems</a>
    </p>
</section>

<?php foreach ($groups as $title => $checks): ?>
<section class="fcard wide">
    <div class="qhead"><h3><?= htmlspecialchars($title) ?></h3></div>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['label']) ?></td>
                    <td><span class="pill" style="--tone: <?= $tone[$c['state']] ?? 'var(--muted)' ?>;"><?= htmlspecialchars($words[$c['state']] ?? $c['state']) ?></span></td>
                    <td><?= htmlspecialchars($c['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<?php engage_footer(); ?>
