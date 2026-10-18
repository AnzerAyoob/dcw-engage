<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/tech_issue_ui.php';

// Route: /admin/report-problem  (any signed-in team account)
Auth::requireLogin();
$email = strtolower((string) Auth::email());

$model = new TechIssueModel();
const REPORT_BASE = '/admin/report-problem';

// Handles new reports and replies, then redirects. Does nothing on GET.
tech_handle_reporter_post($model, 'team', $email, $email, REPORT_BASE);

$id    = trim((string) ($_GET['id'] ?? ''));
$issue = $id !== '' ? $model->getOwned($id, 'team', $email) : false;
$list  = $issue ? [] : $model->listFor('team', $email);

// The top-bar buttons, the same as on the workspace dashboard: who is signed in, owner shortcuts, sign out.
// Sign out is a POST with a CSRF token (logout.php ignores anything else).
ob_start(); ?>
<span class="who"><?= htmlspecialchars($email) ?></span>
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
    'title'   => 'Report a problem',
    'heading' => 'Report a problem',
    'lead'    => 'Something not working in the workspace? Tell the technical team. They will see your email on the report.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => $issue
        ? [['Workspace', '/admin/dashboard'], ['Report a problem', REPORT_BASE], [$issue['tracking_id']]]
        : [['Workspace', '/admin/dashboard'], ['Report a problem']],
    'tools'   => $topTools,
]);
tech_flash_html();
?>

<?php if ($issue): ?>
    <section class="fcard wide">
        <div class="qhead">
            <h3><?= htmlspecialchars($issue['title']) ?></h3>
            <span class="pill" style="--tone: <?= htmlspecialchars(tech_status_tone($issue['status'])) ?>;"><?= htmlspecialchars($issue['status']) ?></span>
        </div>
        <div class="issue-meta">
            <div><b>Reference</b><?= htmlspecialchars($issue['tracking_id']) ?></div>
            <div><b>About</b><?= htmlspecialchars(TechIssueModel::CATEGORIES[$issue['category']] ?? $issue['category']) ?></div>
            <div><b>Sent</b><?= htmlspecialchars(tech_date($issue['created_at'])) ?></div>
        </div>
        <?php tech_thread($model->messages((int) $issue['id'], false), 'reporter'); ?>
        <?php tech_reply_form($issue['tracking_id'], false); ?>
    </section>
<?php else: ?>
    <?php if ($list): ?>
    <section class="fcard wide">
        <div class="qhead"><h3>My reports</h3></div>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>Reference</th><th>Title</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($list as $r): ?>
                <tr>
                    <td><a class="dl" href="<?= REPORT_BASE ?>?id=<?= htmlspecialchars(rawurlencode($r['tracking_id'])) ?>"><?= htmlspecialchars($r['tracking_id']) ?></a></td>
                    <td><?= htmlspecialchars($r['title']) ?><?= $r['last_sender'] === 'tech' && $r['status'] !== 'Resolved' ? ' <span class="sub warn-text">New reply</span>' : '' ?></td>
                    <td><span class="pill" style="--tone: <?= htmlspecialchars(tech_status_tone($r['status'])) ?>;"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td><?= htmlspecialchars(tech_date($r['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

    <section class="fcard wide">
        <div class="qhead"><h3>Send a new report</h3></div>
        <?php tech_report_form(); ?>
    </section>
<?php endif; ?>

<?php engage_footer(); ?>
