<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../includes/mailer.php';   // Mailer::sendStatusUpdate() and Mailer::sendSentBack()

/**
 * /admin/application-review: one queue for the responses to every ordinary form.
 * Membership forms are deliberately left out: they are handled in Membership review.
 * Feedback forms (schema "kind": "feedback") are left out too: their responses are not applications and
 * are read in the Feedback tab (/admin/feedback-review).
 *
 * Status rules (same ones ApplicationModel::saveApplication() enforces):
 *   open       New, Submitted, Under Review   -> can be decided here
 *   waiting    Draft                          -> the applicant is editing (or was sent back); nothing to decide
 *   decided    Accepted, Rejected             -> locked
 *   discarded  Discarded                      -> spam, hidden from the queue; can be restored
 * Accept / Reject / Under Review email the applicant exactly as the form manager does (Mailer::sendStatusUpdate),
 * with the optional note added to that email.
 * "Needs information" sets the application back to Draft, makes a one-time link (/resume/<token>) and EMAILS
 * the applicant your message plus that link (Mailer::sendSentBack). If the email cannot be sent, the link and
 * message are shown on screen instead so you can pass them on yourself. The status change is kept either way.
 * "Resend link" (Draft applications) does not email: it only shows a new one-time link.
 * "Discard" sets Discarded and sends NOTHING (that is what $notifyApplicant already does for any status
 * other than Under Review / Accepted / Rejected). "Restore" puts a discarded application back to New.
 */
requireRole(['organizer', 'owner']);

const APP_OPEN = ['New', 'Submitted', 'Under Review'];
// action name -> status it sets ('info' sets Draft and issues a link; 'restore' only works on Discarded)
const APP_ACTIONS = ['review' => 'Under Review', 'approve' => 'Accepted', 'reject' => 'Rejected', 'info' => 'Draft',
                     'discard' => 'Discarded', 'restore' => 'New'];
const BULK_MAX = 100;

$model = new ApplicationModel();
$formModel = new FormModel();

// Forms this person may review: ordinary forms only (no membership, no feedback), and only ones FormModel
// says they can open. getAllForms() returns schema_json, which FeedbackModel::isFeedbackRow() reads.
$allForms = array_values(array_filter(
    $formModel->getAllForms(),
    fn($f) => !FormModel::isMembershipType($f['form_type'])
           && !FeedbackModel::isFeedbackRow($f)
           && FormModel::userCanOpen($f)
));
$allowed = [];   // form id => true
foreach ($allForms as $f) $allowed[(int) $f['id']] = true;


// Programs that are not shown in the review queue: closed forms, and test forms.
// A form counts as a test form when "test"/"testing"/"dummy" is a whole word in its title or URL slug
// (so "Test form" and "scholarship-test" match, "Contest 2026" does not). Add exact slugs to $extra to hide others.
// KEEP IN SYNC with the same function in views/admin/dashboard.php and application_review.php.
if (!function_exists('review_hidden_form')) {
    function review_hidden_form(array $form, array $extra = []): bool {
        if (empty($form['is_active'])) return true;
        if (in_array((string) $form['form_type'], $extra, true)) return true;
        return (bool) preg_match('/(^|[^a-z])(test|testing|dummy)([^a-z]|$)/i',
            (string) ($form['title'] ?? '') . ' ' . (string) $form['form_type']);
    }
}

$notice = ''; $error = '';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$app = false;
if ($id) {
    $app = $model->getApplicationById($id);
    if ($app && FormModel::isMembershipType($app['form_type'])) {
        http_response_code(404);
        die('Membership applications are reviewed in Membership review.');
    }
    // A response to a feedback form is not in $allowed, so it lands here as "not found".
    if (!$app || !isset($allowed[(int) $app['form_id']])) { http_response_code(404); die('Application not found.'); }
}

$config = require __DIR__ . '/../../includes/config.php';
$resumeUrl = fn($token) => rtrim($config['app']['url'], '/') . '/resume/' . $token;

// Same outcome email the form manager sends. A mail failure never undoes a decision that is already saved.
// Only Under Review / Accepted / Rejected are emailed, so Discard and Restore are always silent.
$notifyApplicant = function (array $row, string $status, string $note) {
    if (!in_array($status, ['Under Review', 'Accepted', 'Rejected'], true)) return;
    try {
        Mailer::sendStatusUpdate($row['email'], $row['applicant_name'], $status, $row['tracking_id'],
                                 $row['form_title'] ?: $row['form_type'], $note);
    } catch (Throwable $e) {
        app_log("Status email failed for application #{$row['id']}: " . $e->getMessage());
    }
};

// "Needs information": emails the applicant what is needed plus their one-time link.
// Returns true only when the email really went out; on false the caller shows the link on screen instead.
// A mail failure never undoes the status change that is already saved.
$emailSentBack = function (array $row, string $message, string $token): bool {
    try {
        return (bool) Mailer::sendSentBack($row['email'], $row['applicant_name'], $row['tracking_id'],
                                           $row['form_title'] ?: $row['form_type'], $message, $token);
    } catch (Throwable $e) {
        app_log("Send-back email failed for application #{$row['id']}: " . $e->getMessage());
        return false;
    }
};

// ---------------------------------------------------------------------------
// Bulk action from the queue. Every application is re-loaded and re-checked,
// so editing the request can never reach a form outside the allowed list, and
// anything not in the right state is skipped instead of failing the whole batch.
// ---------------------------------------------------------------------------
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');

    $bulkAct = (string) ($_POST['bulk_action'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    $note = trim((string) ($_POST['note'] ?? ''));

    if (!isset(APP_ACTIONS[$bulkAct])) {
        $error = 'Choose an action to apply.';
    } elseif (!$ids) {
        $error = 'Tick at least one application first.';
    } elseif (count($ids) > BULK_MAX) {
        $error = 'Please act on at most ' . BULK_MAX . ' applications at a time.';
    } elseif ($bulkAct === 'info' && $note === '') {
        $error = 'Say what you need from the applicants.';
    } else {
        $by = currentAdminIdentifier();
        // 'links' now holds only the applicants whose send-back email could NOT be sent.
        $result = ['act' => $bulkAct, 'done' => 0, 'skipped' => [], 'links' => [], 'message' => $bulkAct === 'info' ? $note : ''];

        foreach ($ids as $appId) {
            $row = $model->getApplicationById($appId);
            if (!$row || !isset($allowed[(int) $row['form_id']])) {
                $result['skipped'][] = "#$appId: not found, or not one you can review";
                continue;
            }
            $who = $row['applicant_name'] ?: $row['email'];

            // Restore only works on discarded applications; everything else only on open ones.
            $need = $bulkAct === 'restore' ? ['Discarded'] : APP_OPEN;
            if (!in_array($row['status'], $need, true)) {
                $result['skipped'][] = $bulkAct === 'restore'
                    ? "$who: is not discarded"
                    : "$who: already " . ($row['status'] === 'Draft' ? 'awaiting the applicant' : strtolower($row['status']));
                continue;
            }
            if ($bulkAct === 'review' && $row['status'] === 'Under Review') {
                $result['skipped'][] = "$who: already under review";
                continue;
            }
            try {
                if ($bulkAct === 'info') {
                    $token = $model->generateMagicLink((int) $row['id'], true);
                    $model->updateStatus((int) $row['id'], 'Draft');
                    if (!$emailSentBack($row, $note, $token)) {
                        // Email failed: keep the link so the organizer can pass it on by hand.
                        $result['links'][] = ['who' => $who, 'email' => $row['email'], 'url' => $resumeUrl($token)];
                    }
                } else {
                    $model->updateStatus((int) $row['id'], APP_ACTIONS[$bulkAct]);
                    $notifyApplicant($row, APP_ACTIONS[$bulkAct], $note);
                }
                app_log("Application bulk $bulkAct: #{$row['id']} ({$row['tracking_id']}, {$row['form_type']}) by $by");
                $result['done']++;
            } catch (Throwable $e) {
                $result['skipped'][] = "$who: " . $e->getMessage();
            }
        }

        $_SESSION['application_bulk'] = $result;
        $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
        header('Location: /admin/application-review' . ($keep ? '?' . http_build_query($keep) : ''));
        exit;
    }
}

// ---------------------------------------------------------------------------
// Single-application action
// ---------------------------------------------------------------------------
if ($app && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $act = (string) ($_POST['action'] ?? '');
    $by = currentAdminIdentifier();
    $doneKey = $act;   // which confirmation message to show after the redirect
    try {
        if ($act === 'resend') {
            if ($app['status'] !== 'Draft') throw new Exception('Only an application waiting on the applicant can be sent a new link.');
            $token = $model->generateMagicLink((int) $app['id'], true);
            // Not emailed: the organizer passes this link on by hand.
            $_SESSION['application_link'] = ['who' => $app['applicant_name'] ?: $app['email'], 'email' => $app['email'],
                                             'message' => '', 'url' => $resumeUrl($token), 'emailFailed' => false];
        } elseif (isset(APP_ACTIONS[$act])) {
            // Restore only works on discarded applications; everything else only on open ones.
            $need = $act === 'restore' ? ['Discarded'] : APP_OPEN;
            if (!in_array($app['status'], $need, true)) {
                throw new Exception($act === 'restore'
                    ? 'This application is not discarded.'
                    : 'This application is no longer open for a decision.');
            }
            if ($act === 'info') {
                $msg = trim((string) ($_POST['message'] ?? ''));
                if ($msg === '') throw new Exception('Say what you need from the applicant.');
                $token = $model->generateMagicLink((int) $app['id'], true);
                $model->updateStatus((int) $app['id'], 'Draft');
                if (!$emailSentBack($app, $msg, $token)) {
                    // Email failed: show the link and message so the decision is not wasted.
                    $_SESSION['application_link'] = ['who' => $app['applicant_name'] ?: $app['email'], 'email' => $app['email'],
                                                     'message' => $msg, 'url' => $resumeUrl($token), 'emailFailed' => true];
                    $doneKey = 'info_manual';
                }
            } else {
                $model->updateStatus((int) $app['id'], APP_ACTIONS[$act]);
                $notifyApplicant($app, APP_ACTIONS[$act], trim((string) ($_POST['note'] ?? '')));
            }
        } else {
            throw new Exception('Unknown action.');
        }
        app_log("Application $act: #{$app['id']} ({$app['tracking_id']}, {$app['form_type']}) by $by");
        header('Location: /admin/application-review?id=' . $app['id'] . '&done=' . urlencode($doneKey));
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $app = $model->getApplicationById($id);   // fresh state after an error
}

$doneText = ['approve' => 'Accepted.', 'reject' => 'Rejected.', 'review' => 'Marked under review.',
             'info' => 'Sent back to the applicant. They were emailed your message and a link to update their application.',
             'info_manual' => 'Sent back to the applicant, but the email could not be sent. See below.',
             'resend' => 'New link created.',
             'discard' => 'Discarded. The applicant was not notified.', 'restore' => 'Restored to the queue.'];
if (!empty($_GET['done'])) $notice = $doneText[$_GET['done']] ?? 'Saved.';

// One-time link(s) to hand to the applicant: a new link from "Create a new link" (never emailed),
// or the link from a send-back whose email could not be sent.
$manual = $_SESSION['application_link'] ?? null;
unset($_SESSION['application_link']);
$bulk = $_SESSION['application_bulk'] ?? null;
unset($_SESSION['application_bulk']);
$bulkVerb = ['approve' => 'accepted', 'reject' => 'rejected', 'review' => 'marked under review',
             'info' => 'sent back for more information', 'discard' => 'discarded (no one was notified)',
             'restore' => 'restored to the queue'];

$fForm = (string) ($_GET['form'] ?? ''); $fStatus = (string) ($_GET['status'] ?? '');
$h = fn($v) => htmlspecialchars((string) $v);
$label = fn($s) => $s === 'Draft' ? 'Awaiting applicant' : $s;
$tone = fn($s) => ['New' => 'st-new', 'Submitted' => 'st-new', 'Under Review' => 'st-review',
                   'Draft' => 'st-wait', 'Accepted' => 'st-ok', 'Rejected' => 'st-bad',
                   'Discarded' => 'st-off'][$s] ?? 'st-new';

$crumbs = $app
    ? [['Workspace', '/admin/dashboard'], ['Application review', '/admin/application-review'], [$app['applicant_name'] ?: $app['email']]]
    : [['Workspace', '/admin/dashboard'], ['Application review']];

engage_header([
    'title'   => 'Application review',
    'heading' => 'Application review',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Responses to program forms. Membership forms are handled in Membership review, feedback in the Feedback tab.',
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => $crumbs,
]);
?>
<style>
    /* Application review only. Shared pieces (.sect, .btn-*.sm, tokens) come from engage.css and staff.css */
    .pill.st-new { --tone: var(--primary); }
    .pill.st-review { --tone: var(--violet); }
    .pill.st-wait { --tone: var(--amber); }
    .pill.st-ok { --tone: var(--leaf-dark); }
    .pill.st-bad { --tone: var(--accent); }
    .pill.st-off { --tone: var(--slate); }

    .action-banner ul { flex-basis: 100%; margin: 6px 0 0; padding-left: 18px; }
    .flash-link { display: block; flex-basis: 100%; margin-top: 8px; padding: 10px; background: rgba(6,26,30,.07); border-radius: 8px; font-size: 12px; word-break: break-all; }

    dl.answers { margin: 14px 0 0; }
    dl.answers dt { margin-top: 14px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    dl.answers dd { margin: 3px 0 0; white-space: pre-wrap; }

    .actrow { display: flex; flex-wrap: wrap; gap: 10px; }
    .actform { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
    .actform .field { margin-bottom: 12px; }
    .actform textarea { width: 100%; padding: 12px 14px; border: 1.5px solid #BFD3D1; border-radius: 10px; font: inherit; }
    .actform textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 20%, transparent); }

    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 16px; }
    .filters .total { margin-left: auto; font-size: 13.5px; font-weight: 600; color: var(--muted); }
    .bulkbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; padding: 12px 14px; background: #fff; border: 1.5px solid var(--primary); border-radius: 14px; box-shadow: 0 3px 0 var(--primary-dark), 0 10px 22px rgba(6,26,30,.14); }
    .bulkbar .count { min-width: 90px; font-size: 14px; font-weight: 800; color: var(--primary-dark); }
    .bulkbar input[type=text] { flex: 1 1 240px; min-width: 200px; }
    .rowform { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
    .rowform input[type=text] { width: 170px; padding: 6px 10px; font-size: 13px; }
    .rowform select { padding: 6px 8px; font-size: 13px; }
    .tbl th.pick, .tbl td.pick { width: 36px; padding-right: 0; }
    .tbl td.pick input, .tbl th.pick input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
    .tbl td.pick input:disabled { cursor: not-allowed; }
    .dash { color: var(--muted); }

    /* One collapsible block per program */
    details.prog-group { padding: 0; overflow: hidden; border-left: 6px solid var(--primary); }
    details.prog-group > summary {
        display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 16px 22px;
        cursor: pointer; list-style: none; background: linear-gradient(90deg, var(--primary-tint), #fff 70%);
    }
    details.prog-group > summary::-webkit-details-marker { display: none; }
    details.prog-group > summary::before { content: '\25B8'; color: var(--primary); font-size: 15px; }
    details.prog-group[open] > summary::before { content: '\25BE'; }
    details.prog-group > summary:focus-visible { outline: 3px solid var(--focus); outline-offset: -3px; }
    details.prog-group .pg-title { font-size: 17px; font-weight: 800; letter-spacing: -.01em; }
    details.prog-group .pg-slug { font-size: 12.5px; color: var(--muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    details.prog-group .pg-stats { margin-left: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; font-size: 13px; font-weight: 600; color: var(--muted); }
    details.prog-group .pg-body { padding: 4px 18px 18px; border-top: 1px solid var(--border); }
    details.prog-group .tbl-wrap { margin-top: 14px; }
    .toolbar-right { margin-left: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
    .toolbar-right .total { font-size: 13.5px; font-weight: 600; color: var(--muted); }
</style>

<?php if ($notice): ?><div class="alert ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($manual): ?>
    <div class="action-banner">
        <?php if (!empty($manual['emailFailed'])): ?>
            <span><strong>The email could not be sent.</strong> Send <?= $h($manual['who']) ?> (<?= $h($manual['email']) ?>) this one-time link<?= $manual['message'] !== '' ? ' with your message' : '' ?>:</span>
        <?php else: ?>
            <span><strong>Nothing was emailed.</strong> Send <?= $h($manual['who']) ?> (<?= $h($manual['email']) ?>) this one-time link<?= $manual['message'] !== '' ? ' with your message' : '' ?>:</span>
        <?php endif; ?>
        <?php if ($manual['message'] !== ''): ?><code class="flash-link"><?= nl2br($h($manual['message'])) ?></code><?php endif; ?>
        <code class="flash-link"><?= $h($manual['url']) ?></code>
    </div>
<?php endif; ?>
<?php if ($bulk): ?>
    <div class="alert <?= $bulk['done'] ? 'ok' : 'error' ?>">
        <?= (int) $bulk['done'] ?> application<?= $bulk['done'] === 1 ? '' : 's' ?> <?= $h($bulkVerb[$bulk['act']] ?? 'updated') ?>.
        <?= $bulk['skipped'] ? count($bulk['skipped']) . ' skipped.' : '' ?>
        <?php if ($bulk['act'] === 'info' && $bulk['done'] > count($bulk['links'])): ?>
            <?= (int) ($bulk['done'] - count($bulk['links'])) ?> emailed.
        <?php endif; ?>
    </div>
    <?php if ($bulk['skipped']): ?>
        <div class="action-banner"><strong>Skipped</strong><ul><?php foreach ($bulk['skipped'] as $s): ?><li><?= $h($s) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($bulk['links']): ?>
        <div class="action-banner"><strong>These emails could not be sent.</strong> Send each of these applicants your message and their one-time link:
            <?php if ($bulk['message'] !== ''): ?><code class="flash-link"><?= nl2br($h($bulk['message'])) ?></code><?php endif; ?>
            <ul><?php foreach ($bulk['links'] as $l): ?><li><?= $h($l['who']) ?> (<?= $h($l['email']) ?>): <code><?= $h($l['url']) ?></code></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($app):
    $form = $formModel->getFormById((int) $app['form_id']);
    $schema = $form['schema'] ?? [];
    $data = json_decode($app['form_data'] ?? '', true) ?: [];
    $open = in_array($app['status'], APP_OPEN, true); ?>
    <section class="sect">
        <div class="qhead">
            <h3><?= $h($app['applicant_name'] ?: $app['email']) ?></h3>
            <span class="pill <?= $tone($app['status']) ?>"><?= $h($label($app['status'])) ?></span>
        </div>
        <p class="qmeta">
            <?= $h($app['email']) ?> &middot;
            <?= $h($app['form_title'] ?: $app['form_type']) ?> &middot;
            <?= $h($app['tracking_id']) ?> &middot;
            Submitted <?= $h($app['created_at']) ?>
        </p>
        <dl class="answers">
        <?php foreach ($schema['fields'] ?? [] as $f):
            if (empty($f['name'])) continue;
            $v = $data[$f['name']] ?? ''; if (is_array($v)) $v = implode(', ', $v); ?>
            <dt><?= MiniWikiText::inline($h($f['label'] ?? $f['name'])) ?></dt>
            <dd><?= $v === '' ? '&mdash;' : (($f['type'] ?? '') === 'file' ? 'File uploaded: ' . $h($v) : $h($v)) ?></dd>
        <?php endforeach; ?>
        </dl>
        <p class="qmeta" style="margin-top:16px;"><a href="/admin/form_manager?id=<?= (int) $app['form_id'] ?>">Open in form manager</a>
            for internal notes and CSV export.</p>
    </section>

    <?php if ($open): ?>
    <section class="sect">
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <div class="field">
                <label>Note to include in the email to the applicant (optional)</label>
                <input type="text" name="note" style="width:100%">
            </div>
            <div class="actrow">
            <button name="action" value="approve" class="btn-ok"
                    onclick="return confirm('Accept this application? It will be locked.');">Accept</button>
            <?php if ($app['status'] !== 'Under Review'): ?>
                <button name="action" value="review" class="btn-ghost">Mark under review</button>
            <?php endif; ?>
            <button name="action" value="reject" class="btn-bad"
                    onclick="return confirm('Reject this application? It will be locked.');">Reject</button>
            <button name="action" value="discard" class="btn-ghost"
                    onclick="return confirm('Discard this application? The applicant will NOT be notified. You can restore it later.');">Discard</button>
            </div>
        </form>
        <form method="POST" class="actform"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <div class="field">
                <label>Ask for more information (emails the applicant your message and a one-time link to edit and resubmit)</label>
                <textarea name="message" rows="3" placeholder="e.g. Please upload a clearer copy of your ID."></textarea>
            </div>
            <button name="action" value="info" class="btn-ghost">Send back for more information</button>
        </form>
    </section>
    <?php elseif ($app['status'] === 'Draft'): ?>
    <section class="sect">
        <p style="margin:0 0 12px;">Waiting for the applicant to finish or update. It returns to the queue when they submit.</p>
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="resend" class="btn-ghost">Create a new link</button>
        </form>
    </section>
    <?php elseif ($app['status'] === 'Discarded'): ?>
    <section class="sect">
        <p style="margin:0 0 12px;">Discarded. The applicant was not notified, and this application is hidden from the queue.</p>
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="restore" class="btn-ghost">Restore to queue</button>
        </form>
    </section>
    <?php else: ?>
        <div class="empty-note">This application has been decided and is locked.</div>
    <?php endif; ?>

<?php else:
    // The queue lists open, non-test programs only. A program the person asked for by name (?form=...,
    // e.g. from the form manager) is shown even if it is closed or a test form.
    $listedForms = array_values(array_filter($allForms, fn($f) => $f['form_type'] === $fForm || !review_hidden_form($f)));
    $listed = [];
    foreach ($listedForms as $f) $listed[(int) $f['id']] = true;

    // Discarded applications are hidden unless the Discarded status filter is chosen.
    $rows = array_values(array_filter($model->getAllApplications(), function ($r) use ($listed, $fForm, $fStatus) {
        return isset($listed[(int) $r['form_id']])
            && ($fForm === '' || $r['form_type'] === $fForm)
            && ($fStatus === '' ? $r['status'] !== 'Discarded' : $r['status'] === $fStatus);
    }));

    // One group per program (form): programs with applications waiting come first, then the busiest.
    $groups = [];
    foreach ($listedForms as $f) {
        $groups[(int) $f['id']] = ['form' => $f, 'rows' => [], 'waiting' => 0, 'counts' => []];
    }
    foreach ($rows as $r) {
        $fid = (int) $r['form_id'];
        if (!isset($groups[$fid])) continue;
        $groups[$fid]['rows'][] = $r;
        $groups[$fid]['counts'][$r['status']] = ($groups[$fid]['counts'][$r['status']] ?? 0) + 1;
        if (in_array($r['status'], ['New', 'Submitted'], true)) $groups[$fid]['waiting']++;
    }
    $groups = array_filter($groups, fn($g) => $g['rows']);
    uasort($groups, fn($a, $b) => ($b['waiting'] <=> $a['waiting']) ?: (count($b['rows']) <=> count($a['rows'])));
    $filtering = $fForm !== '' || $fStatus !== '';
    $totalWaiting = array_sum(array_column($groups, 'waiting')); ?>
    <section class="sect">
        <form method="GET" class="filters">
            <select name="form"><option value="">All programs</option>
                <?php foreach ($listedForms as $f): ?>
                    <option value="<?= $h($f['form_type']) ?>" <?= $f['form_type'] === $fForm ? 'selected' : '' ?>><?= $h($f['title'] ?: $f['form_type']) ?></option>
                <?php endforeach; ?></select>
            <select name="status"><option value="">All statuses</option>
                <?php foreach (['New','Submitted','Under Review','Draft','Accepted','Rejected','Discarded'] as $s): ?>
                    <option value="<?= $h($s) ?>" <?= $s === $fStatus ? 'selected' : '' ?>><?= $h($label($s)) ?></option>
                <?php endforeach; ?></select>
            <button class="btn-ghost sm">Filter</button>
            <?php if ($filtering): ?><a href="/admin/application-review" class="btn-ghost sm" style="text-decoration:none">Clear</a><?php endif; ?>
            <span class="toolbar-right">
                <span class="total"><?= count($rows) ?> application<?= count($rows) === 1 ? '' : 's' ?>
                    in <?= count($groups) ?> program<?= count($groups) === 1 ? '' : 's' ?><?= $totalWaiting ? ' &middot; ' . $totalWaiting . ' waiting' : '' ?></span>
                <?php if (count($groups) > 1): ?>
                    <button type="button" class="btn-ghost sm" id="expandAll">Expand all</button>
                    <button type="button" class="btn-ghost sm" id="collapseAll">Collapse all</button>
                <?php endif; ?>
            </span>
        </form>

        <?php /* Posts back to this same URL, so the active filters are kept. Ticks from every program count together. */ ?>
        <form method="POST" id="bulkForm" class="bulkbar" style="display:none"><?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="bulk">
            <div id="bulkIdsContainer"></div>
            <span class="count" id="bulkCount"></span>
            <select name="bulk_action" id="bulkAction" required>
                <option value="">Set status to&hellip;</option>
                <option value="review">Under Review</option>
                <option value="approve">Accepted</option>
                <option value="info">Needs information</option>
                <option value="reject">Rejected</option>
                <option value="discard">Discard (no email)</option>
                <option value="restore">Restore discarded</option>
            </select>
            <input type="text" name="note" id="bulkNote"
                placeholder="Note emailed with the decision (optional) / what you need (required for Needs information)">
            <button type="submit" id="bulkApply" class="btn-solid sm">Apply to selected</button>
        </form>

        <?php if (!$groups): ?>
            <div class="empty-note">Nothing to review here.</div>
        <?php endif; ?>
    </section>

    <?php foreach ($groups as $g):
        $f = $g['form']; $c = $g['counts']; $n = count($g['rows']);
        $isOpen = $filtering || $g['waiting'] > 0; ?>
    <details class="sect prog-group"<?= $isOpen ? ' open' : '' ?>>
        <summary>
            <span class="pg-title"><?= $h($f['title'] ?: $f['form_type']) ?></span>
            <span class="pg-slug">/<?= $h($f['form_type']) ?></span>
            <span class="pg-stats">
                <?php if ($g['waiting']): ?><span class="pill badge-attn"><?= (int) $g['waiting'] ?> waiting</span><?php endif; ?>
                <?php if (!empty($c['Under Review'])): ?><span class="pill st-review"><?= (int) $c['Under Review'] ?> under review</span><?php endif; ?>
                <?php if (!empty($c['Draft'])): ?><span class="pill st-wait"><?= (int) $c['Draft'] ?> awaiting applicant</span><?php endif; ?>
                <?php if (!empty($c['Discarded'])): ?><span class="pill st-off"><?= (int) $c['Discarded'] ?> discarded</span><?php endif; ?>
                <span><?= (int) ($c['Accepted'] ?? 0) ?> accepted &middot; <?= (int) ($c['Rejected'] ?? 0) ?> rejected &middot; <?= $n ?> total</span>
            </span>
        </summary>
        <div class="pg-body">
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th class="pick"><input type="checkbox" class="pick-all" aria-label="Select all in <?= $h($f['title'] ?: $f['form_type']) ?>"></th>
            <th>Applicant</th><th>Tracking ID</th><th>Status</th><th>Submitted</th><th>Update</th></tr></thead><tbody>
        <?php foreach ($g['rows'] as $r):
            $rowOpen = in_array($r['status'], APP_OPEN, true);
            $rowDiscarded = $r['status'] === 'Discarded';
            $rowPick = $rowOpen || $rowDiscarded; ?>
            <tr>
                <td class="pick"><input type="checkbox" class="pick-row" value="<?= (int) $r['id'] ?>"
                    <?= $rowPick ? '' : 'disabled title="Already decided or waiting on the applicant"' ?>></td>
                <td><a class="dl" href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a>
                    <span class="sub"><?= $h($r['email']) ?></span></td>
                <td><code><?= $h($r['tracking_id']) ?></code></td>
                <td><span class="pill <?= $tone($r['status']) ?>"><?= $h($label($r['status'])) ?></span></td>
                <td><?= $h($r['created_at']) ?></td>
                <td>
                <?php if ($rowOpen): ?>
                    <form method="POST" class="rowform"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <select name="bulk_action" aria-label="Set status">
                            <option value="review">Under Review</option>
                            <option value="approve">Accepted</option>
                            <option value="info">Needs information</option>
                            <option value="reject">Rejected</option>
                            <option value="discard">Discard (no email)</option>
                        </select>
                        <input type="text" name="note" placeholder="Note (optional)">
                        <button type="submit" class="btn-ghost sm">Apply</button>
                    </form>
                <?php elseif ($rowDiscarded): ?>
                    <form method="POST" class="rowform"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="bulk_action" value="restore">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-ghost sm">Restore</button>
                    </form>
                <?php else: ?><span class="dash">&mdash;</span><?php endif; ?>
                </td></tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        </div>
    </details>
    <?php endforeach; ?>

    <script>
    (function () {
        var bulkForm = document.getElementById('bulkForm');
        var rows = Array.prototype.slice.call(document.querySelectorAll('.pick-row:not(:disabled)'));
        var alls = Array.prototype.slice.call(document.querySelectorAll('.pick-all'));
        var count = document.getElementById('bulkCount');
        var action = document.getElementById('bulkAction');
        var note = document.getElementById('bulkNote');
        var ids = document.getElementById('bulkIdsContainer');

        function picked() { return rows.filter(function (r) { return r.checked; }); }
        function actionable(table) { return Array.prototype.slice.call(table.querySelectorAll('.pick-row:not(:disabled)')); }

        // The bar only appears once something is ticked, in any program.
        function sync() {
            var n = picked().length;
            bulkForm.style.display = n > 0 ? 'flex' : 'none';
            count.textContent = n + ' selected';
            alls.forEach(function (a) {
                var rs = actionable(a.closest('table'));
                var c = rs.filter(function (r) { return r.checked; }).length;
                a.checked = rs.length > 0 && c === rs.length;
                a.indeterminate = c > 0 && c < rs.length;
                a.disabled = rs.length === 0;
            });
        }

        // "Select all" ticks only the rows of its own program that can actually be acted on.
        alls.forEach(function (a) {
            a.addEventListener('change', function () {
                actionable(a.closest('table')).forEach(function (r) { r.checked = a.checked; });
                sync();
            });
        });
        rows.forEach(function (r) { r.addEventListener('change', sync); });

        var ex = document.getElementById('expandAll'), co = document.getElementById('collapseAll');
        if (ex) ex.addEventListener('click', function () {
            document.querySelectorAll('details.prog-group').forEach(function (d) { d.open = true; });
        });
        if (co) co.addEventListener('click', function () {
            document.querySelectorAll('details.prog-group').forEach(function (d) { d.open = false; });
        });

        // Returns false (and says why) if the action needs a message and has none.
        function ok(act, text) {
            if (act === 'info' && text.trim() === '') {
                alert('Say what you need from the applicants.');
                return false;
            }
            return true;
        }

        function confirmText(act, n) {
            var plural = n + ' application' + (n === 1 ? '' : 's');
            return {
                approve: 'Accept ' + plural + '? Each applicant is emailed, they are locked and this cannot be undone.',
                reject: 'Reject ' + plural + '? Each applicant is emailed, they are locked and this cannot be undone.',
                info: 'Send ' + plural + ' back for more information? Each applicant is emailed your message and a link to update their application.',
                discard: 'Discard ' + plural + '? The applicants are NOT notified. You can restore them later from the Discarded status filter.'
            }[act];
        }

        bulkForm.addEventListener('submit', function (e) {
            var chosen = picked();
            if (chosen.length === 0) { e.preventDefault(); return; }
            if (!action.value) { e.preventDefault(); alert('Choose a status first.'); return; }
            if (!ok(action.value, note.value)) { e.preventDefault(); return; }
            var warn = confirmText(action.value, chosen.length);
            if (warn && !confirm(warn)) { e.preventDefault(); return; }

            ids.innerHTML = '';
            chosen.forEach(function (cb) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'ids[]'; input.value = cb.value;
                ids.appendChild(input);
            });
            var apply = document.getElementById('bulkApply');
            apply.disabled = true; apply.textContent = 'Working…';
        });

        // Per-row Apply uses the same checks. (Restore rows have no dropdown, so they submit straight away.)
        document.querySelectorAll('.rowform').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                var sel = f.querySelector('select');
                if (!sel) return;
                var act = sel.value;
                var text = f.querySelector('input[type=text]').value;
                if (!ok(act, text)) { e.preventDefault(); return; }
                var warn = confirmText(act, 1);
                if (warn && !confirm(warn)) { e.preventDefault(); }
            });
        });

        sync();
    })();
    </script>
<?php endif; ?>
<?php engage_footer(); ?>
