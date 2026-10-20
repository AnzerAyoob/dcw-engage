<?php
/**
 * DCW Engage - organizer view of feedback responses.
 *
 *   /admin/feedback-review            list of feedback forms with response counts
 *   /admin/feedback-review?form=ID    responses to one form
 *
 * Read-only apart from deleting a response (for privacy requests). "Email respondent" is a plain
 * mailto: link, so organizers write from their own mail client and nothing is recorded here.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../includes/mail/CoreMail.php';   // formatExpiryIST(): stored UTC -> IST text

Auth::requireLogin();
requireRole(['owner', 'organizer']);

if (!function_exists('fb_format_answer')) {
    function fb_format_answer($v): string {
        if (is_array($v)) {
            $v = implode(', ', $v);
        }
        $v = (string) $v;
        return $v === '' ? '<span style="color:#64748b;">—</span>' : nl2br(htmlspecialchars($v));
    }
}

$formModel = new FormModel();
$fbModel   = new FeedbackModel();
$formId    = (int) ($_GET['form'] ?? $_POST['form_id'] ?? 0);

$form = null;
if ($formId) {
    $form = $formModel->getFormById($formId);
    // One answer for "missing", "not a feedback form" and "not yours", as in the builder.
    if (!$form || !FeedbackModel::isFeedbackSchema($form['schema']) || !FormModel::userCanOpen($form)) {
        http_response_code(404);
        die("Form not found.");
    }
}

// Close / reopen the form. Same permission as form_manager.php (anyone who can open the form).
// A form that closed by deadline is reopened by extending the deadline in the builder, not here.
if ($form && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $formModel->toggleFormStatus($formId, !empty($_POST['activate']));
    header('Location: /admin/feedback-review?form=' . $formId . '&toggled=1');
    exit;
}

if ($form && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $row = $fbModel->getById((int) ($_POST['response_id'] ?? 0));
    // The response must belong to the form this user was allowed to open above.
    if ($row && (int) $row['form_id'] === $formId) {
        $fbModel->delete((int) $row['id']);
    }
    header('Location: /admin/feedback-review?form=' . $formId . '&deleted=1');
    exit;
}

$perPage = 25;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$forms   = [];
$rows    = [];
$total   = 0;
$labels  = [];

if ($form) {
    $total = $fbModel->countForForm($formId);
    $rows  = $fbModel->listForForm($formId, $perPage, ($page - 1) * $perPage);
    foreach (($form['schema']['fields'] ?? []) as $f) {
        if (!empty($f['name'])) {
            $labels[$f['name']] = MiniWikiText::stripToPlainText((string) ($f['label'] ?? $f['name']));
        }
    }
} else {
    foreach ($fbModel->feedbackForms() as $f) {
        if (FormModel::userCanOpen($f)) {
            $forms[] = $f;
        }
    }
}

engage_header([
    'title'   => 'Feedback',
    'heading' => $form ? ($form['schema']['title'] ?? 'Feedback') : 'Feedback',
    'kicker'  => 'Organizer workspace',
    'lead'    => $form ? 'Responses to this feedback form.' : 'Responses to your feedback forms.',
    'tools'   => '',
    'crumbs'  => $form
        ? [['Workspace', '/admin/dashboard'], ['Feedback', '/admin/feedback-review'], [$form['schema']['title'] ?? 'Feedback']]
        : [['Workspace', '/admin/dashboard'], ['Feedback']],
]);
?>
<div class="builder">
<?php if (!$form): ?>

    <?php if (!$forms): ?>
        <div class="alert ok">No feedback forms yet. Create one in the form builder and choose the "Feedback" type.</div>
    <?php else: ?>
        <div style="display:grid; gap:12px;">
            <?php foreach ($forms as $f): ?>
                <a href="/admin/feedback-review?form=<?= (int) $f['id'] ?>"
                   style="display:flex; justify-content:space-between; gap:12px; padding:16px 18px; background:#fff; border:1px solid var(--border); border-radius:12px; text-decoration:none; color:inherit;">
                    <span><strong><?= htmlspecialchars((string) ($f['title'] ?: $f['form_type'])) ?></strong>
                        <span style="color:#64748b;"> /<?= htmlspecialchars($f['form_type']) ?></span></span>
                    <span><?= (int) $f['response_count'] ?> response<?= (int) $f['response_count'] === 1 ? '' : 's' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>

    <?php if (!empty($_GET['deleted'])): ?><div class="alert ok">Response deleted.</div><?php endif; ?>
    <?php if (!empty($_GET['toggled'])): ?><div class="alert ok">Form status updated.</div><?php endif; ?>

    <?php
    $isActive   = !empty($form['is_active']);
    $isOpenNow  = FormModel::isOpen($form);
    $byDeadline = FormModel::closedByDeadline($form);
    ?>
    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:0 0 16px;">
        <span class="chip <?= $isOpenNow ? 'clear' : 'attn' ?>">
            <?= $isOpenNow ? 'Open' : ($byDeadline ? 'Closed (deadline)' : 'Closed') ?>
        </span>
        <a class="btn-outline btn-sm" href="/<?= htmlspecialchars(rawurlencode($form['form_type'])) ?>" target="_blank" rel="noopener">View public form</a>
        <?php if (FormModel::userCanEdit($form)): ?>
            <a class="btn-outline btn-sm" href="/admin/builder?edit=<?= (int) $formId ?>">Edit form<?= $byDeadline ? ' / extend deadline' : '' ?></a>
        <?php endif; ?>
        <form method="POST" style="margin:0;">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="form_id" value="<?= (int) $formId ?>">
            <input type="hidden" name="activate" value="<?= $isActive ? '' : '1' ?>">
            <button type="submit" class="btn-outline btn-sm"><?= $isActive ? 'Close form' : 'Reopen form' ?></button>
        </form>
    </div>

    <p style="color:#64748b; margin:0 0 16px;">
        <?= (int) $total ?> response<?= $total === 1 ? '' : 's' ?>.
        Names and emails are removed automatically after <?= (int) FeedbackModel::retentionMonths() ?> months.
    </p>

    <?php if (!$rows): ?>
        <div class="alert ok">No responses yet.</div>
    <?php endif; ?>

    <?php foreach ($rows as $r):
        $answers  = is_array($r['answers']) ? $r['answers'] : [];
        $scrubbed = !empty($r['scrubbed_at']);
        $subject  = 'Re: ' . MiniWikiText::stripToPlainText((string) ($form['schema']['title'] ?? 'your feedback'));
        ?>
        <div style="background:#fff; border:1px solid var(--border); border-radius:12px; padding:18px 20px; margin:0 0 14px;">
            <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:10px; margin-bottom:12px;">
                <div>
                    <?php if ($scrubbed): ?>
                        <strong>Details removed</strong>
                    <?php else: ?>
                        <strong><?= htmlspecialchars((string) $r['name']) ?></strong>
                        <span style="color:#64748b;"> · <?= htmlspecialchars((string) $r['email']) ?></span>
                    <?php endif; ?>
                    <div style="font-size:13px; color:#64748b;"><?= htmlspecialchars(CoreMail::formatExpiryIST($r['created_at'])) ?></div>
                </div>
                <div style="display:flex; gap:8px; align-items:flex-start;">
                    <?php if (!$scrubbed && !empty($r['email'])): ?>
                        <a class="btn-outline btn-sm"
                           href="<?= htmlspecialchars('mailto:' . $r['email'] . '?subject=' . rawurlencode($subject)) ?>">Email respondent</a>
                    <?php endif; ?>
                    <form method="POST" style="margin:0;"
                          onsubmit="return confirm('Delete this response permanently?');">
                        <?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="form_id" value="<?= (int) $formId ?>">
                        <input type="hidden" name="response_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-outline btn-sm">Delete</button>
                    </form>
                </div>
            </div>

            <?php foreach ($labels as $fieldName => $label): ?>
                <div style="margin:0 0 10px;">
                    <div style="font-size:13px; font-weight:600; color:#475569;"><?= htmlspecialchars($label) ?></div>
                    <div><?= fb_format_answer($answers[$fieldName] ?? '') ?></div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($answers as $fieldName => $v): if (isset($labels[$fieldName])) continue; ?>
                <?php /* Answer to a question that has since been removed from the form. */ ?>
                <div style="margin:0 0 10px;">
                    <div style="font-size:13px; font-weight:600; color:#475569;"><?= htmlspecialchars((string) $fieldName) ?> <em>(removed question)</em></div>
                    <div><?= fb_format_answer($v) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?>
        <div style="display:flex; gap:10px; justify-content:center; margin-top:18px;">
            <?php if ($page > 1): ?>
                <a class="btn-outline btn-sm" href="?form=<?= $formId ?>&page=<?= $page - 1 ?>">Newer</a>
            <?php endif; ?>
            <span style="align-self:center; color:#64748b;">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn-outline btn-sm" href="?form=<?= $formId ?>&page=<?= $page + 1 ?>">Older</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
</div>
<?php engage_footer(); ?>
