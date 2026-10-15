<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/wiki_username.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/MemberProfileModel.php';
require_once __DIR__ . '/../../models/MemberStrikeModel.php';
require_once __DIR__ . '/../../includes/mail/membership_mailer.php';

// Organizers only work with application forms, so they are not in this list.
requireRole(['membership_coordinator', 'membership_reviewer', 'owner']);

$model = new MemberModel();
$profiles = new MemberProfileModel();
$strikes = new MemberStrikeModel();
// Pass the FULL role list: an account can hold several roles, and Auth::role() is only the primary one.
$scope = $model->scopeFor(Auth::roles(), (string) Auth::email());   // null = every chapter
// Changing which Member ID a club membership uses is a reviewer decision, not a coordinator one.
$canLinkIds = Auth::hasAnyRole(['membership_reviewer', 'owner']);
// Striking members off and reinstating them: reviewers and owners only.
$canStrike = $canLinkIds;

// Profile rights: reviewers and owners for everyone; a coordinator for a person who holds at least
// one membership in a chapter assigned to them. Every profile write goes through this check.
$mayEditProfile = function (string $memberId) use ($model, $scope, $canLinkIds): bool {
    if ($canLinkIds || $scope === null) return true;
    if (!$scope) return false;
    foreach ($model->membershipsFor($memberId) as $m) {
        if (in_array(trim((string) ($m['chapter'] ?? '')), $scope, true)) return true;
    }
    return false;
};

$notice = ''; $error = '';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$app = $id ? $model->getApplication($id, $scope) : false;
if ($id && !$app) { http_response_code(404); die('Application not found.'); }

// ---------------------------------------------------------------------------
// Bulk action from the queue: one action applied to every ticked application.
// Each application is re-loaded through getApplication($id, $scope), so a
// coordinator can never touch a chapter outside their scope by editing the
// request, and anything no longer open is skipped rather than failing the
// batch. Every item is handled on its own: one failure never stops the rest,
// and an email failure never undoes a decision that is already saved.
// A struck-off member is refused by MemberModel::approve(), so a bulk approve
// reports them under "Skipped".
// ---------------------------------------------------------------------------
const BULK_MAX = 100;

if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');

    $bulkAct = (string) ($_POST['bulk_action'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    // One note field serves both: it is the rejection reason or the "what we
    // need from you" message, depending on the action chosen.
    $note = trim((string) ($_POST['note'] ?? ''));
    $reason = $note;
    $message = $note;

    if (!in_array($bulkAct, ['review', 'approve', 'info', 'reject'], true)) {
        $error = 'Choose an action to apply.';
    } elseif (!$ids) {
        $error = 'Tick at least one application first.';
    } elseif (count($ids) > BULK_MAX) {
        $error = 'Please act on at most ' . BULK_MAX . ' applications at a time.';
    } elseif ($bulkAct === 'reject' && $reason === '') {
        $error = 'Give a reason so the applicants know what to do next.';
    } elseif ($bulkAct === 'info' && $message === '') {
        $error = 'Say what you need from the applicants.';
    } else {
        @set_time_limit(300);   // each approve/reject sends an email
        $by = currentAdminIdentifier();
        $result = ['act' => $bulkAct, 'done' => 0, 'skipped' => [], 'links' => []];

        foreach ($ids as $appId) {
            $row = $model->getApplication($appId, $scope);
            if (!$row) {
                $result['skipped'][] = "#$appId: not found, or outside your chapters";
                continue;
            }
            $who = $row['applicant_name'] ?: $row['email'];

            if (!in_array($row['status'], MemberModel::OPEN, true)) {
                $result['skipped'][] = "$who: already " . ($row['status'] === 'Draft' ? 'awaiting the applicant' : strtolower($row['status']));
                continue;
            }
            if ($bulkAct === 'review' && $row['status'] === 'Under Review') {
                $result['skipped'][] = "$who: already under review";
                continue;
            }

            try {
                $mail = null;
                if ($bulkAct === 'approve') {
                    [$memberId, $exp, $shared] = $model->approve($row, $by);
                    $mail = ['approved', $memberId, $exp, $shared];
                } elseif ($bulkAct === 'reject') {
                    $model->reject($row, $reason, $by);
                    $mail = ['rejected', null, $reason];
                } elseif ($bulkAct === 'review') {
                    $model->markUnderReview($row, $by);
                } else {   // info
                    $token = $model->requestInfo($row, $message, $by);
                    $sent = false;
                    try {
                        $sent = (bool) MembershipMailer::sendInfoRequestFor($row, $message, $token);
                    } catch (Throwable $e) {
                        app_log("Info-request email failed for application #{$row['id']}: " . $e->getMessage());
                    }
                    if (!$sent) {   // never strand the link
                        $config = require __DIR__ . '/../../includes/config.php';
                        $result['links'][] = [
                            'who' => $who,
                            'url' => rtrim($config['app']['url'], '/') . '/resume/' . $token,
                        ];
                    }
                }

                app_log("Membership bulk $bulkAct: application #{$row['id']} ({$row['tracking_id']}) by $by");
                $result['done']++;

                if ($mail) {
                    try {
                        // Also emails the new member a "set your password" link on approval.
                        MembershipMailer::sendDecisionFor($row, $mail[0], $mail[1], $mail[2], $mail[3] ?? false);
                    } catch (Throwable $e) {
                        app_log("Membership email failed for application #{$row['id']}: " . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $result['skipped'][] = "$who: " . $e->getMessage();
            }
        }

        $_SESSION['membership_bulk'] = $result;
        $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
        header('Location: /admin/membership-review' . ($keep ? '?' . http_build_query($keep) : ''));
        exit;
    }
}

// ---------------------------------------------------------------------------
// Quick verify / un-verify from the Accepted members table. The Member ID is posted, so the
// chapter scope is checked here, not assumed from what the table showed.
// ---------------------------------------------------------------------------
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['profile_verify', 'profile_unverify'], true)) {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $verify = ($_POST['action'] === 'profile_verify');
    try {
        $mid = trim((string) ($_POST['member_id'] ?? ''));
        if ($mid === '' || !$mayEditProfile($mid)) throw new Exception('You cannot change this member\'s profile.');
        $profiles->setVerified($mid, currentAdminIdentifier(), $verify);
        $_SESSION['membership_flash'] = ['ok', $verify ? 'Wikimedia username verified.' : 'Verification removed.'];
    } catch (Throwable $e) {
        $_SESSION['membership_flash'] = ['error', $e->getMessage()];
    }
    $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
    header('Location: /admin/membership-review' . ($keep ? '?' . http_build_query($keep) : '') . '#acceptedSect');
    exit;
}

// ---------------------------------------------------------------------------
// Reinstate from the "Struck off" list on the queue. Reviewers and owners only; the check is
// made here, not assumed from the list being hidden for coordinators.
// ---------------------------------------------------------------------------
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'strike_lift') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    try {
        if (!$canStrike) throw new Exception('Only reviewers can reinstate members.');
        $strikes->reinstate((string) ($_POST['member_id'] ?? ''), (string) ($_POST['reason'] ?? ''), currentAdminIdentifier());
        $_SESSION['membership_flash'] = ['ok', 'Member reinstated. They get a new card on their next visit.'];
    } catch (Throwable $e) {
        $_SESSION['membership_flash'] = ['error', $e->getMessage()];
    }
    $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
    header('Location: /admin/membership-review' . ($keep ? '?' . http_build_query($keep) : '') . '#struckSect');
    exit;
}

if ($app && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $act = $_POST['action'] ?? '';
    $by = currentAdminIdentifier();
    try {
        if ($act === 'approve') {
            [$memberId, $exp, $shared] = $model->approve($app, $by);
            $mail = ['approved', $memberId, $exp, $shared];
        } elseif ($act === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') throw new Exception('Give a reason so the applicant knows what to do next.');
            $model->reject($app, $reason, $by);
            $mail = ['rejected', null, $reason];
        } elseif ($act === 'info' || $act === 'resend') {
            if ($act === 'info') {
                $msg = trim($_POST['message'] ?? '');
                if ($msg === '') throw new Exception('Say what you need from the applicant.');
                $token = $model->requestInfo($app, $msg, $by);
            } else {
                $token = $model->resendLink($app);
                $msg = (string) $model->lastInfoMessage((int) $app['id']);
            }
            $sent = false;   // Mailer returns false when it only logged (dev mode) or failed
            try {
                $sent = (bool) MembershipMailer::sendInfoRequestFor($app, $msg, $token);
            } catch (Throwable $e) { app_log("Info-request email failed for application #{$app['id']}: " . $e->getMessage()); }
            if (!$sent) {   // same idea as the invite flow: never strand the link
                $config = require __DIR__ . '/../../includes/config.php';
                $_SESSION['membership_link'] = rtrim($config['app']['url'], '/') . '/resume/' . $token;
            }
            $mail = null;
        } elseif ($act === 'review') {
            $model->markUnderReview($app, $by);
            $mail = null;
        } elseif ($act === 'fix_username') {
            // Correct a Wikimedia username answer (capitalisation, underscores, a typo). Allowed for
            // every role on this page; $app is already scope-checked. Only username fields can be
            // edited here, and only while the application is open. No email is sent.
            $schemaFields = (json_decode($app['schema_json'], true) ?: [])['fields'] ?? [];
            $fieldName = (string) ($_POST['field'] ?? '');
            $match = null;
            foreach ($schemaFields as $f) {
                if (($f['name'] ?? '') === $fieldName && WikiUsername::isField($f)) { $match = $f; break; }
            }
            if (!$match) throw new Exception('That field cannot be edited here.');
            $new = WikiUsername::normalize((string) ($_POST['value'] ?? ''));
            if ($new === '') throw new Exception('The username cannot be empty.');
            $model->setAnswer($app, $fieldName, $new, $by);
            $mail = null;
        } elseif (in_array($act, ['profile_save', 'profile_save_verify', 'profile_verify', 'profile_unverify'], true)) {
            // The person's profile (Wikimedia username). The Member ID comes from the application's
            // email on the server, never from the request, and the chapter scope is checked.
            $held = $model->membershipsForEmail((string) $app['email']);
            $profileMemberId = $held ? (string) $held[0]['member_id'] : '';
            if ($profileMemberId === '') throw new Exception('This person has no Member ID yet. The username is saved to their profile once they are approved.');
            if (!$mayEditProfile($profileMemberId)) throw new Exception('You cannot change this member\'s profile.');
            if ($act === 'profile_save' || $act === 'profile_save_verify') {
                $profiles->saveByStaff($profileMemberId, (string) ($_POST['username'] ?? ''), $by, $act === 'profile_save_verify');
            } else {
                $profiles->setVerified($profileMemberId, $by, $act === 'profile_verify');
            }
            $mail = null;
        } elseif (in_array($act, ['strike', 'strike_reinstate'], true)) {
            // Strike a person off, or lift it. Reviewers and owners only. The Member ID comes from
            // the application's email on the server, never from the request. No email is sent.
            if (!$canStrike) throw new Exception('Only reviewers can strike members off or reinstate them.');
            $strikeHeld = $model->membershipsForEmail((string) $app['email']);
            $strikeMid = $strikeHeld ? (string) $strikeHeld[0]['member_id'] : '';
            if ($strikeMid === '') throw new Exception('This person has no Member ID yet, so there is nothing to strike.');
            $why = (string) ($_POST['reason'] ?? '');
            if ($act === 'strike') {
                $strikes->strike($strikeMid, $why, $by);
            } else {
                $strikes->reinstate($strikeMid, $why, $by);
            }
            $mail = null;
        } elseif (in_array($act, ['link_id', 'unlink_id', 'relink_id'], true)) {
            // Share one Member ID between the Generic Community and a club. No email is sent.
            if (!$canLinkIds) throw new Exception('Only reviewers can change Member IDs.');
            $given = (string) ($_POST['link_member_id'] ?? '');
            $confirm = !empty($_POST['link_confirm']);
            if ($act === 'link_id') {
                $model->linkMemberId($app, $given, $by, $confirm);
            } elseif ($act === 'unlink_id') {
                $model->unlinkMemberId((int) $app['id']);
            } else {
                $old = $model->relinkApproved($app, $given, $by, $confirm);
                app_log("Membership ID changed: application #{$app['id']} ({$app['tracking_id']}) $old -> "
                    . strtoupper(trim($given)) . " by $by");
            }
            $mail = null;
        } else { throw new Exception('Unknown action.'); }

        app_log("Membership $act: application #{$app['id']} ({$app['tracking_id']}) by $by");
        // Email failure must never undo a decision that is already saved.
        if (!empty($mail)) {
            try {
                // Also emails the new member a "set your password" link on approval.
                MembershipMailer::sendDecisionFor($app, $mail[0], $mail[1], $mail[2], $mail[3] ?? false);
            } catch (Throwable $e) { app_log("Membership email failed for application #{$app['id']}: " . $e->getMessage()); }
        }
        header('Location: /admin/membership-review?id=' . $app['id'] . '&done=' . urlencode($act)
            . (strpos($act, 'profile_') === 0 ? '#profile' : (strpos($act, 'strike') === 0 ? '#strike' : '')));
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $app = $model->getApplication($id, $scope);   // show fresh state after an error
}
$doneText = ['approve' => 'Approved.', 'reject' => 'Rejected.', 'review' => 'Marked under review.',
             'info' => 'Sent back to the applicant.', 'resend' => 'Link sent again.',
             'fix_username' => 'Wikimedia username corrected.',
             'profile_save' => 'Profile saved.',
             'profile_save_verify' => 'Username saved and verified.',
             'profile_verify' => 'Username verified.',
             'profile_unverify' => 'Verification removed.',
             'strike' => 'Member struck off. Their sessions, card and reset links no longer work.',
             'strike_reinstate' => 'Member reinstated. They get a new card on their next visit.',
             'link_id' => 'Linked. On approval this member keeps that ID and no new one is made.',
             'unlink_id' => 'Link removed.',
             'relink_id' => 'Member ID changed. The confirmation email they received shows the old ID, so let them know.'];
if (!empty($_GET['done'])) $notice = $doneText[$_GET['done']] ?? 'Saved.';
$manualLink = $_SESSION['membership_link'] ?? '';
unset($_SESSION['membership_link']);

// Result of the last bulk action, shown once on the queue.
$bulk = $_SESSION['membership_bulk'] ?? null;
unset($_SESSION['membership_bulk']);
$bulkVerb = ['approve' => 'approved', 'reject' => 'rejected', 'review' => 'marked under review', 'info' => 'sent back for more information'];

// One-line result of a quick verify / reinstate from the queue.
$flash = $_SESSION['membership_flash'] ?? null;
unset($_SESSION['membership_flash']);

$fSlug = (string) ($_GET['form'] ?? ''); $fStatus = (string) ($_GET['status'] ?? '');
$h = fn($v) => htmlspecialchars((string) $v);
$label = fn($s) => $s === 'Draft' ? 'Awaiting applicant' : $s;
// Colour class for a status pill.
$tone = fn($s) => ['New' => 'st-new', 'Submitted' => 'st-new', 'Under Review' => 'st-review',
                   'Draft' => 'st-wait', 'Accepted' => 'st-ok', 'Rejected' => 'st-bad'][$s] ?? 'st-new';

// Queue URL that keeps the active filters, with some of them overridden.
$qUrl = function (array $over) use ($fSlug, $fStatus): string {
    $q = array_filter(array_merge(['form' => $fSlug, 'status' => $fStatus], $over), 'strlen');
    return '/admin/membership-review' . ($q ? '?' . http_build_query($q) : '');
};

// Wikimedia links for a username: global account page and contributions.
$wmLinks = fn(string $u) => '<a class="dl" href="' . $h(MemberProfileModel::globalUrl($u)) . '" target="_blank" rel="noopener">Global account</a>'
    . ' &middot; <a class="dl" href="' . $h(MemberProfileModel::contribsUrl($u)) . '" target="_blank" rel="noopener">Contributions</a>';

// Chapter name for a queue row: from the slug, or from the answer on renewals
// (MemberModel::chapterOf handles both). Falls back to the raw slug if unknown.
$chapterLabel = function (array $r): string {
    $key = MemberModel::chapterOf($r);
    return ($key !== null ? (MemberModel::CHAPTER_NAMES[$key] ?? null) : null) ?? (string) $r['form_type'];
};
// Display name for a form slug in the filter dropdown. The submitted value stays the slug.
$slugLabel = function (string $slug): string {
    if (str_starts_with($slug, 'membership-renewal')) return 'Renewals';
    $key = substr($slug, strlen('membership-'));
    return MemberModel::CHAPTER_NAMES[$key] ?? $slug;
};
// Existing memberships, so "joining another chapter" can be told apart from "renewal" and "new".
$held = $model->heldPairs();

// Member IDs a person already holds: member rows grouped by ID, e.g. ['D48213977' => ['DCW Generic Community', 'Wiki Club AMU']].
$idGroups = function (array $mems): array {
    $g = [];
    foreach ($mems as $m) $g[$m['member_id']][] = MemberModel::CHAPTER_NAMES[$m['chapter']] ?? $m['chapter'];
    return $g;
};

$scopeLine = $scope !== null
    ? ($scope ? 'Your chapters: ' . implode(', ', array_map(fn($c) => MemberModel::CHAPTER_NAMES[$c] ?? $c, $scope)) : 'No chapters are assigned to you yet. Ask an owner.')
    : '';
$crumbs = $app
    ? [['Workspace', '/admin/dashboard'], ['Membership review', '/admin/membership-review'], [$app['applicant_name'] ?: $app['email']]]
    : [['Workspace', '/admin/dashboard'], ['Membership review']];

engage_header([
    'title'   => 'Membership review',
    'heading' => 'Membership review',
    'kicker'  => 'Organizer workspace',
    'lead'    => $scopeLine,
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => $crumbs,
]);
?>
<style>
    /* Membership review only. Everything else comes from /assets/css/engage.css */

    /* ---- Cards ---- */
    .sect { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 20px 22px; margin: 0 0 18px; box-shadow: 0 6px 18px rgba(15,23,42,.06); }
    .sect > h3:first-child, .sect .card-title { margin: 0 0 12px; font-size: 15px; font-weight: 800; letter-spacing: -.005em; }
    .sect select, .sect input[type=text] {
        padding: 8px 12px; background: #fff; color: var(--ink);
        border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 14px;
    }
    .sect select:focus, .sect input[type=text]:focus { outline: 2px solid var(--primary); outline-offset: -1px; border-color: transparent; }
    .btn-ghost.sm, .btn-solid.sm { width: auto; padding: 7px 16px; font-size: 13.5px; }

    .pill.st-new { --tone: var(--primary); }
    .pill.st-review { --tone: #6d28d9; }
    .pill.st-wait { --tone: #b45309; }
    .pill.st-ok { --tone: #047857; }
    .pill.st-bad { --tone: #b91c1c; }

    .action-banner ul { flex-basis: 100%; margin: 6px 0 0; padding-left: 18px; }
    .flash-link { display: block; flex-basis: 100%; margin-top: 8px; padding: 10px; background: rgba(0,0,0,.06); border-radius: 6px; font-size: 12px; word-break: break-all; }
    .muted { color: #94a3b8; }

    /* ---- Queue: summary tiles, filter chips ---- */
    .rv-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 0 0 18px; }
    .rv-stat { --tone: var(--primary); padding: 14px 16px; background: var(--card); border: 1px solid var(--border); border-top: 4px solid var(--tone); border-radius: 14px; }
    .rv-stat b { display: block; font-size: 28px; line-height: 1.1; font-weight: 800; color: var(--tone); font-variant-numeric: tabular-nums; }
    .rv-stat span { font-size: 13px; font-weight: 600; color: var(--muted); }

    .rv-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 18px; margin: 0 0 16px; }
    .rv-chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .rv-chip { padding: 6px 14px; border: 1px solid var(--border); border-radius: 999px; background: #fff; color: var(--ink); font-size: 13.5px; font-weight: 600; text-decoration: none; transition: border-color .15s, background .15s; }
    .rv-chip:hover { border-color: var(--primary); }
    .rv-chip .n { margin-left: 6px; color: var(--muted); font-weight: 700; font-variant-numeric: tabular-nums; }
    .rv-chip.on { background: var(--primary); border-color: var(--primary); color: #fff; }
    .rv-chip.on .n { color: #fff; opacity: .85; }
    .rv-bar form { display: flex; gap: 8px; align-items: center; margin: 0; }

    .bulkbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; padding: 12px 14px; background: #fff; border: 1px solid var(--primary); border-radius: 12px; box-shadow: 0 8px 20px rgba(15,23,42,.12); }
    .bulkbar .count { min-width: 90px; font-size: 14px; font-weight: 700; color: var(--primary); }
    .bulkbar input[type=text] { flex: 1 1 240px; min-width: 200px; }
    .tbl th.pick, .tbl td.pick { width: 36px; padding-right: 0; }
    .tbl td.pick input, .tbl th.pick input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
    .tbl td.pick input:disabled { cursor: not-allowed; }
    .tbl td .sub { display: block; color: var(--muted); font-size: 12.5px; }

    /* ---- Application page: answers on the left, decision and profile on the right ---- */
    .rv-back { display: inline-block; margin: 0 0 14px; font-size: 14px; font-weight: 600; color: var(--primary); text-decoration: none; }
    .rv-back:hover { text-decoration: underline; }
    .rv-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin: 0 0 4px; }
    .rv-head h2 { margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -.01em; overflow-wrap: anywhere; }
    .rv-meta { margin: 0 0 20px; color: var(--muted); font-size: 14px; overflow-wrap: anywhere; }
    .rv-grid { display: grid; gap: 0 22px; }
    @media (min-width: 1000px) {
        .rv-grid { grid-template-columns: minmax(0, 1fr) 370px; align-items: start; }
        .rv-side { position: sticky; top: 12px; }
    }

    dl.answers { margin: 0; display: grid; grid-template-columns: minmax(130px, 240px) minmax(0, 1fr); }
    dl.answers dt, dl.answers dd { margin: 0; padding: 11px 0; border-top: 1px solid var(--border); }
    dl.answers dt:first-of-type, dl.answers dt:first-of-type + dd { border-top: 0; }
    dl.answers dt { padding-right: 16px; font-size: 13px; font-weight: 700; color: var(--muted); }
    dl.answers dd { white-space: pre-wrap; overflow-wrap: anywhere; }
    dl.answers dd.u { white-space: normal; }
    @media (max-width: 700px) {
        dl.answers { grid-template-columns: 1fr; }
        dl.answers dt { padding-bottom: 0; border-top: 1px solid var(--border); }
        dl.answers dd { padding-top: 3px; border-top: 0; }
        dl.answers dt:first-of-type { border-top: 0; }
    }
    .hist { margin: 10px 0 0; font-size: 14px; }

    .actrow { display: flex; flex-wrap: wrap; gap: 10px; }
    .rv-fold { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border); }
    .rv-fold > summary { cursor: pointer; font-size: 14.5px; font-weight: 700; list-style: none; }
    .rv-fold > summary::-webkit-details-marker { display: none; }
    .rv-fold > summary::before { content: '\25B8'; margin-right: 8px; color: var(--muted); }
    .rv-fold[open] > summary::before { content: '\25BE'; }
    .rv-fold form { margin-top: 12px; }
    .rv-fold .field { margin-bottom: 12px; }

    .idline { margin: 6px 0 0; font-size: 14.5px; }
    .idform { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px; }
    .idform input[type=text] { flex: 1 1 180px; min-width: 0; }
    .idform label.chk { display: flex; align-items: center; gap: 6px; font-size: 13.5px; margin: 0; }
    .idform .btn-ghost, .idform .btn-solid, .idform .btn-ok { width: auto; padding: 7px 16px; font-size: 13.5px; }
    .idlist { margin: 10px 0 0; padding-left: 18px; font-size: 14.5px; }
    .idlist li { margin: 3px 0; }
    .idwarn { margin: 12px 0 0; padding: 10px 12px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; font-size: 14px; color: #92400e; }
    .idbad { margin: 12px 0 0; padding: 10px 12px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; font-size: 14px; color: #991b1b; }
    .rv-meta + .idbad { margin: -8px 0 18px; }
    dl.answers dd .idform.userfix { margin-top: 6px; }
    dl.answers dd .idform.userfix input[type=text] { max-width: 260px; }

    /* Wikimedia username card */
    .wm-name { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; margin: 0; font-size: 16px; font-weight: 700; overflow-wrap: anywhere; }
    .wm-sub { margin: 4px 0 0; font-size: 13px; color: var(--muted); }
    .wm-links { margin: 8px 0 0; font-size: 14px; }

    /* ---- Accepted members / Struck off: collapsible sections. The summary line is always visible. ---- */
    details.acc > summary {
        display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 16px;
        cursor: pointer; list-style: none;
    }
    details.acc > summary::-webkit-details-marker { display: none; }
    details.acc > summary::before { content: '\25B8'; margin-right: 8px; color: var(--muted); }
    details.acc[open] > summary::before { content: '\25BE'; }
    details.acc > summary:focus-visible { outline: 3px solid #f59e0b; outline-offset: 4px; border-radius: 6px; }
    details.acc .acc-title { flex: 1 1 auto; font-size: 17px; font-weight: 800; }
    details.acc .acc-count { font-size: 14px; font-weight: 600; color: var(--muted); }
    details.acc[open] > summary { margin-bottom: 4px; }
    .wm-cell form { display: inline; margin: 0; }
    .wm-cell .btn-ghost, .wm-cell .btn-ok { width: auto; padding: 3px 12px; font-size: 12.5px; }
</style>

<?php if ($notice): ?><div class="alert ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= $h($flash[1]) ?></div><?php endif; ?>
<?php if ($manualLink): ?>
    <div class="action-banner">
        <span>The email was not sent automatically. Give the applicant this one-time link:</span>
        <code class="flash-link"><?= $h($manualLink) ?></code>
    </div>
<?php endif; ?>
<?php if ($bulk): ?>
    <div class="alert <?= $bulk['done'] ? 'ok' : 'error' ?>">
        <?= (int) $bulk['done'] ?> application<?= $bulk['done'] === 1 ? '' : 's' ?> <?= $h($bulkVerb[$bulk['act']] ?? 'updated') ?>.
        <?= $bulk['skipped'] ? count($bulk['skipped']) . ' skipped.' : '' ?>
    </div>
    <?php if ($bulk['skipped']): ?>
        <div class="action-banner"><strong>Skipped</strong><ul><?php foreach ($bulk['skipped'] as $s): ?><li><?= $h($s) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($bulk['links']): ?>
        <div class="action-banner"><strong>These emails were not sent automatically.</strong> Give each applicant their one-time link:
            <ul><?php foreach ($bulk['links'] as $l): ?><li><?= $h($l['who']) ?>: <code><?= $h($l['url']) ?></code></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($app):
    $schema = json_decode($app['schema_json'], true) ?: [];
    $data = json_decode($app['form_data'] ?? '', true) ?: [];
    $open = in_array($app['status'], MemberModel::OPEN, true);
    $chapterKey = MemberModel::chapterOf($app);

    // Member IDs this person already has, for every reviewer and coordinator (not only those who can change IDs).
    $mine = $model->membershipsForEmail((string) $app['email']);
    $mineIds = $idGroups($mine);
    $statedId = MemberModel::statedMemberId($app);
    $outcome = $open ? $model->idOutcome($app) : null;

    // Wikimedia profile of this person (by Member ID) and the username they gave on the application.
    $pMid = $mine ? (string) $mine[0]['member_id'] : '';
    $prof = $pMid !== '' ? $profiles->get($pMid) : null;
    $profName = (string) ($prof['wikimedia_username'] ?? '');
    $profVerified = $profName !== '' && !empty($prof['username_verified_at']);
    $canProfile = $pMid !== '' && $mayEditProfile($pMid);

    // Strike in force for this person (covers every chapter they hold), or null.
    $struck = ($pMid !== '') ? $strikes->activeFor($pMid) : null;

    $appUser = '';
    foreach ($schema['fields'] ?? [] as $f) {
        if (!WikiUsername::isField($f)) continue;
        $val = $data[$f['name']] ?? '';
        if (is_string($val) && trim($val) !== '') { $appUser = WikiUsername::normalize($val); break; }
    } ?>
    <a class="rv-back" href="<?= $h($qUrl([])) ?>">&larr; Back to the queue</a>
    <div class="rv-head">
        <h2><?= $h($app['applicant_name'] ?: $app['email']) ?></h2>
        <span class="pill <?= $tone($app['status']) ?>"><?= $h($label($app['status'])) ?></span>
        <?php if ($struck): ?><span class="pill st-bad">Struck off</span><?php endif; ?>
    </div>
    <p class="rv-meta">
        <?= $h($app['email']) ?> &middot;
        <?= $h(MemberModel::kindLabel($app, $held)) ?> &middot;
        <?= $h($chapterLabel($app)) ?> &middot;
        <?= $h($app['tracking_id']) ?>
    </p>
    <?php if ($struck): ?>
        <div class="idbad"><strong>Struck off</strong> by <?= $h($struck['struck_by']) ?> on
            <?= $h(MemberAuthModel::formatIst((string) $struck['struck_at'], 'j M Y')) ?>. This applies to every chapter they hold.
            <br><em><?= nl2br($h($struck['reason'])) ?></em></div>
    <?php endif; ?>

    <div class="rv-grid">
    <div class="rv-main">
        <section class="sect">
            <h3>Application</h3>
            <dl class="answers">
            <?php foreach ($schema['fields'] ?? [] as $f): $v = $data[$f['name']] ?? ''; if (is_array($v)) $v = implode(', ', $v); ?>
                <dt><?= MiniWikiText::inline($h($f['label'] ?? $f['name'])) ?></dt>
                <?php if ($v !== '' && WikiUsername::isField($f)):
                    $fixed = WikiUsername::normalize((string) $v); ?>
                <dd class="u"><?= $h($v) ?><br><?= $wmLinks((string) $fixed) ?>
                <?php if ($open): ?>
                    <form method="POST" class="idform userfix"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                        <input type="hidden" name="field" value="<?= $h($f['name']) ?>">
                        <input type="text" name="value" value="<?= $h($fixed) ?>" maxlength="255" autocomplete="off" required
                               aria-label="Corrected Wikimedia username">
                        <button name="action" value="fix_username" class="btn-ghost sm"><?= $fixed !== (string) $v ? 'Fix capitalisation' : 'Save' ?></button>
                    </form>
                <?php endif; ?></dd>
                <?php else: ?>
                <dd><?= $v === '' ? '&mdash;' : (($f['type'] ?? '') === 'file' ? 'File uploaded: ' . $h($v) : $h($v)) ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
            </dl>
        </section>
    </div>

    <aside class="rv-side">
        <?php if ($open): ?>
        <section class="sect">
            <h3>Decision</h3>
            <form method="POST" class="actrow"><?= CSRF::getInputField() ?>
                <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                <?php if (!$struck): ?><button name="action" value="approve" class="btn-ok">Approve</button><?php endif; ?>
                <button name="action" value="review" class="btn-ghost">Mark under review</button>
            </form>
            <?php if ($struck): ?>
                <p class="wm-sub" style="margin-top:10px;">Approval is blocked while this person is struck off. Reinstate them first.</p>
            <?php endif; ?>
            <details class="rv-fold">
                <summary>Ask for more information</summary>
                <form method="POST"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                    <div class="field">
                        <label>Sent to the applicant with a link to edit and resubmit</label>
                        <textarea name="message" rows="3" placeholder="e.g. Please upload a clearer photo of your student ID."></textarea>
                    </div>
                    <button name="action" value="info" class="btn-ghost">Send back</button>
                </form>
            </details>
            <details class="rv-fold">
                <summary>Reject</summary>
                <form method="POST"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                    <div class="field">
                        <label>Reason (sent to the applicant)</label>
                        <textarea name="reason" rows="3"></textarea>
                    </div>
                    <button name="action" value="reject" class="btn-bad">Reject</button>
                </form>
            </details>
        </section>
        <?php elseif ($model->awaitingApplicant($app)): ?>
        <section class="sect">
            <h3>Waiting for the applicant</h3>
            <p style="margin:0 0 12px;">They need to update and resubmit. It returns to the queue when they do.</p>
            <form method="POST"><?= CSRF::getInputField() ?>
                <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                <button name="action" value="resend" class="btn-ghost">Send the link again</button>
            </form>
        </section>
        <?php else: ?>
            <div class="empty-note" style="margin-bottom:18px;">This application has been decided and is locked.</div>
        <?php endif; ?>

        <section class="sect" id="profile">
            <h3>Wikimedia username</h3>
            <?php if ($pMid === ''): ?>
                <p class="idline">No Member ID yet. Once approved, this person gets a profile and the username can be verified here.</p>
                <?php if ($appUser !== ''): ?>
                    <p class="idline">Given on the application: <strong><?= $h($appUser) ?></strong></p>
                    <p class="wm-links"><?= $wmLinks($appUser) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($profName !== ''): ?>
                    <p class="wm-name"><?= $h($profName) ?>
                        <?php if ($profVerified): ?><span class="pill" style="--tone:#047857;">Verified</span>
                        <?php else: ?><span class="pill" style="--tone:#b45309;">Not verified</span><?php endif; ?></p>
                    <?php if ($profVerified): ?>
                        <p class="wm-sub">By <?= $h($prof['username_verified_by']) ?> on <?= $h(MemberAuthModel::formatIst((string) $prof['username_verified_at'], 'j M Y')) ?></p>
                    <?php endif; ?>
                    <p class="wm-links"><?= $wmLinks($profName) ?></p>
                <?php else: ?>
                    <p class="idline">No username on the profile yet.<?= $appUser !== '' ? ' The one from the application is filled in below.' : '' ?></p>
                    <?php if ($appUser !== ''): ?><p class="wm-links"><?= $wmLinks($appUser) ?></p><?php endif; ?>
                <?php endif; ?>

                <?php if ($canProfile): ?>
                <form method="POST" class="idform"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                    <input type="text" name="username" maxlength="255" autocomplete="off" required
                           value="<?= $h($profName !== '' ? $profName : $appUser) ?>" aria-label="Wikimedia username">
                    <button name="action" value="profile_save" class="btn-ghost">Save</button>
                    <button name="action" value="profile_save_verify" class="btn-ok"><?= $profVerified ? 'Save &amp; re-verify' : 'Save &amp; verify' ?></button>
                    <?php if ($profVerified): ?>
                        <button name="action" value="profile_unverify" class="btn-ghost" formnovalidate>Remove verification</button>
                    <?php endif; ?>
                </form>
                <p class="wm-sub" style="margin-top:10px;">Check the account on the links above before verifying. Changing to a different account clears verification.
                    This profile is shared across all of this person's memberships.</p>
                <?php else: ?>
                    <p class="wm-sub" style="margin-top:10px;">You can view this profile, but only a reviewer or a coordinator of one of this person's chapters can change it.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="sect">
            <h3>Member ID</h3>
            <?php if ($mineIds): ?>
                <p class="idline" style="margin-top:0;">Already holds, under <?= $h($app['email']) ?>:</p>
                <ul class="idlist">
                <?php foreach ($mine as $m): ?>
                    <li><code><?= $h($m['member_id']) ?></code> &middot; <?= $h(MemberModel::CHAPTER_NAMES[$m['chapter']] ?? $m['chapter']) ?>
                        &middot; <?= $h($m['status']) ?>, valid until <?= $h(MemberAuthModel::formatIst($m['expires_at'], 'j M Y')) ?></li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="idline" style="margin-top:0;">No membership is held under this email yet.</p>
            <?php endif; ?>

            <?php if ($statedId !== null): ?>
                <p class="idline">Member ID given on this application: <code><?= $h($statedId) ?></code>
                    <?= isset($mineIds[$statedId]) ? '(matches their email)' : '(does not match any membership under this email)' ?></p>
            <?php endif; ?>

            <?php if (count($mineIds) > 1): ?>
                <div class="idwarn"><strong>More than one Member ID.</strong> This person holds <?= count($mineIds) ?> different IDs
                    (<?= $h(implode(', ', array_keys($mineIds))) ?>). They are meant to have just one.</div>
            <?php endif; ?>

            <?php if ($outcome !== null): ?>
                <?php if ($outcome['error']): ?>
                    <div class="idbad"><strong>Approval would fail:</strong> <?= $h($outcome['error']) ?></div>
                <?php elseif ($outcome['id'] !== null): ?>
                    <p class="idline"><strong>On approval:</strong> keeps <code><?= $h($outcome['id']) ?></code>. No new ID is made.</p>
                <?php else: ?>
                    <p class="idline"><strong>On approval:</strong> a new Member ID is generated.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($canStrike && $pMid !== ''): ?>
        <section class="sect" id="strike">
            <h3>Strike off</h3>
            <?php if ($struck): ?>
                <p class="idline" style="margin-top:0;">Currently struck off. Reinstating lifts it for all chapters; the old card stays dead.</p>
                <form method="POST"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                    <div class="field" style="margin-top:12px;">
                        <label>Reason for reinstating (kept in the record)</label>
                        <textarea name="reason" rows="3" required maxlength="2000"></textarea>
                    </div>
                    <button name="action" value="strike_reinstate" class="btn-ok"
                            onclick="return confirm('Reinstate this member?');">Reinstate</button>
                </form>
            <?php else: ?>
                <p class="idline" style="margin-top:0;">Covers every chapter under <code><?= $h($pMid) ?></code>. Ends their session,
                    retires their card, cancels reset links and blocks new approvals.</p>
                <details class="rv-fold"><summary>Strike this member off</summary>
                    <form method="POST"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                        <div class="field">
                            <label>Reason (kept in the record, not emailed)</label>
                            <textarea name="reason" rows="3" required maxlength="2000"></textarea>
                        </div>
                        <button name="action" value="strike" class="btn-bad"
                                onclick="return confirm('Strike this member off? It applies to all their chapters and takes effect immediately.');">Strike off</button>
                    </form>
                </details>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php
        // Member ID sharing: reviewers only, and only for club memberships (the Generic Community is the anchor).
        if ($canLinkIds && $chapterKey !== null && $chapterKey !== 'generic'):
            $link = $model->linkFor((int) $app['id']);
            $memberRow = $app['status'] === 'Accepted' ? $model->memberRowFor($app) : null;
            if ($open || $memberRow): ?>
        <section class="sect">
            <h3>Change Member ID</h3>
            <?php if ($memberRow): ?>
                <p class="idline" style="margin-top:0;">Current Member ID for this club: <code><?= $h($memberRow['member_id']) ?></code>.
                    To put this member on their DCW Generic Community ID instead, enter it below.</p>
            <?php elseif ($link): ?>
                <p class="idline" style="margin-top:0;">Linked to <code><?= $h($link['member_id']) ?></code> by <?= $h($link['linked_by']) ?>.
                    On approval this member keeps that ID and no new one is made.</p>
            <?php else: ?>
                <p class="idline" style="margin-top:0;">If this person already holds a membership under this email, that ID is reused automatically.
                    If they used a different email, enter their DCW Generic Community Member ID here.</p>
            <?php endif; ?>

            <form method="POST" class="idform"><?= CSRF::getInputField() ?>
                <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                <input type="text" name="link_member_id" maxlength="<?= MemberModel::MEMBER_ID_DIGITS + 1 ?>" autocomplete="off"
                       placeholder="e.g. D48213977" value="<?= $h($link['member_id'] ?? '') ?>" required>
                <label class="chk"><input type="checkbox" name="link_confirm" value="1"> Link even if the email differs</label>
                <?php if ($memberRow): ?>
                    <button name="action" value="relink_id" class="btn-ghost"
                            onclick="return confirm('Change this member\'s ID? The email they already received shows the old one.');">Change Member ID</button>
                <?php else: ?>
                    <button name="action" value="link_id" class="btn-ghost"><?= $link ? 'Update link' : 'Link Member ID' ?></button>
                    <?php if ($link): ?><button name="action" value="unlink_id" class="btn-ghost" formnovalidate>Remove link</button><?php endif; ?>
                <?php endif; ?>
            </form>
        </section>
        <?php endif; endif; ?>

        <?php if ($hist = $model->history((int) $app['id'])): ?>
        <section class="sect">
            <h3>History</h3>
            <?php foreach ($hist as $e): ?>
                <p class="hist"><?= $h(str_replace('_', ' ', $e['decision'])) ?> &middot;
                   <?= $h($e['decided_by']) ?> &middot; <?= $h($e['decided_at']) ?>
                   <?= $e['reason'] ? '<br><em>' . nl2br($h($e['reason'])) . '</em>' : '' ?></p>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </aside>
    </div>

<?php else:
    $all = $model->listApplications('', '', $scope);
    $rows = ($fSlug === '' && $fStatus === '') ? $all : $model->listApplications($fSlug, $fStatus, $scope);

    // Counts for the tiles and the status chips (within the chosen membership, if one is chosen).
    $scoped = $fSlug === '' ? $all : array_filter($all, fn($r) => (string) $r['form_type'] === $fSlug);
    $cnt = ['New' => 0, 'Submitted' => 0, 'Under Review' => 0, 'Draft' => 0, 'Accepted' => 0, 'Rejected' => 0];
    foreach ($scoped as $r) { if (isset($cnt[$r['status']])) $cnt[$r['status']]++; }

    // Accepted applications live in their own section below, with the Member ID each person was assigned.
    $queue = array_values(array_filter($rows, fn($r) => $r['status'] !== 'Accepted'));
    $accepted = array_values(array_filter($rows, fn($r) => $r['status'] === 'Accepted'));
    usort($accepted, fn($a, $b) => strcmp((string) ($b['approved_at'] ?? $b['created_at']), (string) ($a['approved_at'] ?? $a['created_at'])));
    $infoMap = $model->memberInfoMap();
    $byEmail = $model->membershipsByEmail();   // one query for the whole queue
    $showQueue = $fStatus !== 'Accepted';
    $showAccepted = $fStatus === '' || $fStatus === 'Accepted';

    // ONE ROW PER PERSON. People are counted by Member ID: one ID is one person, even if it covers
    // several clubs, and their memberships are listed together on that row. A row with no ID on file
    // yet falls back to its email, so nobody is dropped. $accepted is newest first, so the first
    // row seen for a person is their latest. A club that was accepted more than once (renewals)
    // is listed once, linking to its latest application.
    $acceptedGroups = [];
    foreach ($accepted as $r) {
        $chap = MemberModel::chapterOf($r) ?? '';
        $inf  = $infoMap[strtolower((string) $r['email']) . '|' . $chap] ?? null;
        $mid  = trim((string) ($inf['member_id'] ?? ''));
        $key  = $mid !== '' ? $mid : 'email:' . strtolower((string) $r['email']);
        $dedupe = $chap !== '' ? $chap : (string) $r['form_type'];

        if (!isset($acceptedGroups[$key])) {
            $acceptedGroups[$key] = ['mid' => $mid, 'latest' => $r, 'memberships' => [], 'expires' => null];
        }
        $g =& $acceptedGroups[$key];
        if ($g['mid'] === '' && $mid !== '') $g['mid'] = $mid;
        if (!isset($g['memberships'][$dedupe])) {
            $exp = !empty($inf['expires_at']) ? (string) $inf['expires_at'] : null;
            $g['memberships'][$dedupe] = ['id' => (int) $r['id'], 'label' => $chapterLabel($r), 'expires' => $exp];
            if ($exp !== null && ($g['expires'] === null || $exp > $g['expires'])) $g['expires'] = $exp;
        }
        unset($g);
    }
    $acceptedPeopleCount = count($acceptedGroups);
    $acceptedMembershipCount = 0;
    foreach ($acceptedGroups as $g) $acceptedMembershipCount += count($g['memberships']);

    // Wikimedia usernames for everyone in the accepted list, in one query.
    $profMap = [];
    try { $profMap = $profiles->mapFor(array_column($acceptedGroups, 'mid')); } catch (Throwable $e) { app_log('Profile lookup failed: ' . $e->getMessage()); }

    // Strikes in force: [member_id => row] for the "Struck off" pills, and the full list for reviewers.
    $strikeMap = $strikes->activeMap();
    $struckList = $canStrike ? $strikes->activeList() : [];

    $chips = [['', 'All', array_sum($cnt)], ['New', 'New', $cnt['New']], ['Submitted', 'Submitted', $cnt['Submitted']],
              ['Under Review', 'Under Review', $cnt['Under Review']], ['Draft', 'Awaiting applicant', $cnt['Draft']],
              ['Accepted', 'Accepted', $cnt['Accepted']], ['Rejected', 'Rejected', $cnt['Rejected']]];
    ?>
    <div class="rv-stats">
        <div class="rv-stat" style="--tone: var(--primary);"><b><?= $cnt['New'] + $cnt['Submitted'] ?></b><span>To review</span></div>
        <div class="rv-stat" style="--tone: #6d28d9;"><b><?= $cnt['Under Review'] ?></b><span>Under review</span></div>
        <div class="rv-stat" style="--tone: #b45309;"><b><?= $cnt['Draft'] ?></b><span>Awaiting applicant</span></div>
        <div class="rv-stat" style="--tone: #047857;"><b><?= $cnt['Accepted'] ?></b><span>Accepted</span></div>
        <div class="rv-stat" style="--tone: #b91c1c;"><b><?= $cnt['Rejected'] ?></b><span>Rejected</span></div>
    </div>

    <section class="sect">
        <div class="rv-bar">
            <nav class="rv-chips" aria-label="Filter by status">
                <?php foreach ($chips as [$val, $text, $n]): ?>
                    <a class="rv-chip<?= $val === $fStatus ? ' on' : '' ?>" href="<?= $h($qUrl(['status' => $val])) ?>"><?= $h($text) ?><span class="n"><?= (int) $n ?></span></a>
                <?php endforeach; ?>
            </nav>
            <form method="GET">
                <?php if ($fStatus !== ''): ?><input type="hidden" name="status" value="<?= $h($fStatus) ?>"><?php endif; ?>
                <select name="form" aria-label="Membership" onchange="this.form.submit()"><option value="">All memberships</option>
                    <?php foreach ($model->formSlugs() as $s): ?>
                        <option value="<?= $h($s) ?>" <?= $s === $fSlug ? 'selected' : '' ?>><?= $h($slugLabel($s)) ?></option>
                    <?php endforeach; ?></select>
                <noscript><button class="btn-ghost sm">Filter</button></noscript>
            </form>
        </div>

        <?php /* Posts back to this same URL, so the active filters are kept. */ ?>
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
            </select>
            <input type="text" name="note" id="bulkNote"
                placeholder="Note to applicants (required for Needs information / Rejected)">
            <button type="submit" id="bulkApply" class="btn-solid sm">Apply to selected</button>
        </form>

        <?php if ($showQueue): ?>
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th class="pick"><input type="checkbox" id="pickAll" aria-label="Select all"></th>
            <th>Applicant</th><th>Membership</th><th>Member ID</th><th>Status</th><th>Submitted</th><th></th></tr></thead><tbody>
        <?php foreach ($queue as $r):
            $rowOpen = in_array($r['status'], MemberModel::OPEN, true);
            $kind = MemberModel::kindLabel($r, $held);
            $rowIds = $idGroups($byEmail[strtolower((string) $r['email'])] ?? []);
            $rowStated = MemberModel::statedMemberId($r); ?>
            <tr>
                <td class="pick"><input type="checkbox" class="pick-row" value="<?= (int) $r['id'] ?>"
                    <?= $rowOpen ? '' : 'disabled title="Already decided or waiting on the applicant"' ?>></td>
                <td><a class="dl" href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a>
                    <span class="sub"><?= $h($r['email']) ?></span></td>
                <td><?= $h($chapterLabel($r)) ?>
                    <?php if ($kind !== 'New applicant'): ?><span class="sub"><?= $h($kind) ?></span><?php endif; ?></td>
                <td>
                <?php if ($rowIds): foreach ($rowIds as $mid => $chs): ?>
                    <code><?= $h($mid) ?></code> <span class="sub"><?= $h(implode(', ', $chs)) ?></span>
                    <?php if (isset($strikeMap[strtoupper(trim((string) $mid))])): ?><span class="pill st-bad">Struck off</span><?php endif; ?>
                <?php endforeach; else: ?><span class="muted">&mdash;</span><?php endif; ?>
                <?php if ($rowStated !== null && !isset($rowIds[$rowStated])): ?>
                    <span class="sub">Entered: <code><?= $h($rowStated) ?></code> (no match)</span>
                <?php endif; ?>
                <?php if (count($rowIds) > 1): ?><span class="sub" style="color:#b45309">More than one ID</span><?php endif; ?>
                </td>
                <td><span class="pill <?= $tone($r['status']) ?>"><?= $h($label($r['status'])) ?></span></td>
                <td><?= $h($r['created_at']) ?></td>
                <td><a class="dl" href="?id=<?= (int) $r['id'] ?>"><?= $rowOpen ? 'Review' : 'View' ?> &rarr;</a></td>
            </tr>
        <?php endforeach; if (!$queue): ?><tr><td colspan="7">Nothing to review here.</td></tr><?php endif; ?>
        </tbody></table>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($showAccepted): ?>
    <details class="sect acc" id="acceptedSect"<?= $fStatus === 'Accepted' ? ' open' : '' ?>>
        <summary>
            <span class="acc-title">Accepted members</span>
            <span class="acc-count"><?= $acceptedPeopleCount ?> <?= $acceptedPeopleCount === 1 ? 'person' : 'people' ?>
                &middot; <?= $acceptedMembershipCount ?> membership<?= $acceptedMembershipCount === 1 ? '' : 's' ?></span>
        </summary>
        <p class="rv-meta" style="margin:6px 0 14px;">One row per person, counted by Member ID: one ID is one person even when it covers several clubs,
            and all their memberships are listed together (click one to open that application). Approved by and Accepted show the latest approval;
            Valid until shows the latest expiry (hover for each membership). The Wikimedia username is shared by all of a person's memberships.</p>
        <?php if (!$acceptedGroups): ?>
            <div class="empty-note">No accepted memberships yet.</div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th>Member</th><th>Membership</th><th>Member ID</th><th>Wikimedia username</th><th>Approved by</th><th>Accepted</th><th>Valid until</th></tr></thead><tbody>
        <?php foreach ($acceptedGroups as $g):
            $latest = $g['latest'];
            $mships = array_values($g['memberships']);
            $pr = $g['mid'] !== '' ? ($profMap[$g['mid']] ?? null) : null;
            $prName = (string) ($pr['wikimedia_username'] ?? '');
            $prOk = $prName !== '' && !empty($pr['username_verified_at']);
            $expTitle = [];
            foreach ($mships as $m) {
                $expTitle[] = $m['label'] . ': ' . ($m['expires'] ? MemberAuthModel::formatIst($m['expires'], 'j M Y') : 'no date'); } ?>
            <tr>
                <td><a class="dl" href="?id=<?= (int) $latest['id'] ?>"><?= $h($latest['applicant_name'] ?: $latest['email']) ?></a>
                    <span class="sub"><?= $h($latest['email']) ?></span></td>
                <td><?php foreach ($mships as $i => $m): ?><?= $i ? ', ' : '' ?><a class="dl" href="?id=<?= (int) $m['id'] ?>"><?= $h($m['label']) ?></a><?php endforeach; ?></td>
                <td><?= $g['mid'] !== '' ? '<code>' . $h($g['mid']) . '</code>' : '<span class="muted">&mdash;</span>' ?>
                    <?php if ($g['mid'] !== '' && isset($strikeMap[strtoupper(trim($g['mid']))])): ?>
                        <span class="pill st-bad">Struck off</span>
                    <?php endif; ?></td>
                <td class="wm-cell">
                <?php if ($g['mid'] === ''): ?>
                    <span class="muted">&mdash;</span>
                <?php elseif ($prName === ''): ?>
                    <span class="muted">Not set</span> <a class="dl" href="?id=<?= (int) $latest['id'] ?>#profile">Add</a>
                <?php else: ?>
                    <strong><?= $h($prName) ?></strong>
                    <span class="pill" style="--tone:<?= $prOk ? '#047857' : '#b45309' ?>;"><?= $prOk ? 'Verified' : 'Not verified' ?></span>
                    <span class="sub"><?= $wmLinks($prName) ?> &middot; <a class="dl" href="?id=<?= (int) $latest['id'] ?>#profile">Edit</a></span>
                    <?php if (!$prOk): ?>
                    <form method="POST"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="member_id" value="<?= $h($g['mid']) ?>">
                        <button name="action" value="profile_verify" class="btn-ok">Verify</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                </td>
                <td><?= !empty($latest['approved_by']) ? $h($latest['approved_by']) : '<span class="muted">&mdash;</span>' ?></td>
                <td><?= !empty($latest['approved_at']) ? $h(MemberAuthModel::formatIst($latest['approved_at'])) : '<span class="muted">&mdash;</span>' ?></td>
                <td title="<?= $h(implode('; ', $expTitle)) ?>"><?= !empty($g['expires']) ? $h(MemberAuthModel::formatIst($g['expires'], 'j M Y')) : '<span class="muted">&mdash;</span>' ?>
                    <?php if (count($mships) > 1): ?><span class="sub">latest of <?= count($mships) ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($canStrike): ?>
    <details class="sect acc" id="struckSect"<?= $struckList ? '' : '' ?>>
        <summary>
            <span class="acc-title">Struck off</span>
            <span class="acc-count"><?= count($struckList) ?> <?= count($struckList) === 1 ? 'person' : 'people' ?></span>
        </summary>
        <p class="rv-meta" style="margin:6px 0 14px;">A strike covers every chapter a person holds. It ends their session, retires their card and blocks new approvals.
            To strike someone off, open their application and use the Strike off card.</p>
        <?php if (!$struckList): ?>
            <div class="empty-note">Nobody is struck off.</div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th>Member</th><th>Member ID</th><th>Reason</th><th>Struck by</th><th>When</th><th>Reinstate</th></tr></thead><tbody>
        <?php foreach ($struckList as $s): ?>
            <tr>
                <td><?= $h($s['full_name'] ?: ($s['email'] ?: '—')) ?><span class="sub"><?= $h($s['email']) ?></span></td>
                <td><code><?= $h($s['member_id']) ?></code></td>
                <td style="white-space:pre-wrap"><?= $h($s['reason']) ?></td>
                <td><?= $h($s['struck_by']) ?></td>
                <td><?= $h(MemberAuthModel::formatIst((string) $s['struck_at'], 'j M Y')) ?></td>
                <td>
                    <form method="POST" class="idform" style="margin:0"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="member_id" value="<?= $h($s['member_id']) ?>">
                        <input type="text" name="reason" placeholder="Reason" required maxlength="2000" aria-label="Reason for reinstating">
                        <button name="action" value="strike_lift" class="btn-ghost"
                                onclick="return confirm('Reinstate this member?');">Reinstate</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <script>
    (function () {
        var bulkForm = document.getElementById('bulkForm');
        var all = document.getElementById('pickAll') || { addEventListener: function () {}, style: {} };
        var rows = Array.prototype.slice.call(document.querySelectorAll('.pick-row:not(:disabled)'));
        var count = document.getElementById('bulkCount');
        var action = document.getElementById('bulkAction');
        var note = document.getElementById('bulkNote');
        var ids = document.getElementById('bulkIdsContainer');

        function picked() { return rows.filter(function (r) { return r.checked; }); }

        // The bar only appears once something is ticked.
        function sync() {
            var n = picked().length;
            bulkForm.style.display = n > 0 ? 'flex' : 'none';
            count.textContent = n + ' selected';
            all.checked = rows.length > 0 && n === rows.length;
            all.indeterminate = n > 0 && n < rows.length;
            all.disabled = rows.length === 0;
        }

        // Select all only ticks rows that can actually be acted on.
        all.addEventListener('change', function () {
            rows.forEach(function (r) { r.checked = all.checked; });
            sync();
        });
        rows.forEach(function (r) { r.addEventListener('change', sync); });

        // Returns false (and says why) if the action needs a note and has none.
        function ok(act, text) {
            if ((act === 'info' || act === 'reject') && text.trim() === '') {
                alert(act === 'reject'
                    ? 'Give a reason so the applicants know what to do next.'
                    : 'Say what you need from the applicants.');
                return false;
            }
            return true;
        }

        function confirmText(act, n) {
            var plural = n + ' application' + (n === 1 ? '' : 's');
            return {
                approve: 'Accept ' + plural + '? Each applicant is emailed and this cannot be undone.',
                reject: 'Reject ' + plural + '? Each applicant is emailed the reason and this cannot be undone.',
                info: 'Send ' + plural + ' back for more information? Each applicant is emailed.'
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

        sync();
    })();
    </script>
<?php endif; ?>
<?php engage_footer(); ?>
