<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../includes/engage_page.php';

requireRole('owner');   // who may see which chapter is an owner decision

$model = new MemberModel();
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    try {
        $email = (string) ($_POST['email'] ?? '');
        $model->setChapters($email, (array) ($_POST['chapters'] ?? []));
        app_log('Membership chapters for ' . $email . ' changed by ' . currentAdminIdentifier());
        $msg = 'Saved chapters for ' . $email . '.';
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$h = fn($v) => htmlspecialchars((string) $v);
$names = ['generic' => 'DCW Generic Community', 'amu' => 'Wiki Club AMU', 'jamia' => 'Wiki Club Jamia', 'photographers' => 'DCW Photographers Club'];

engage_header([
    'title'   => 'Membership access',
    'heading' => 'Membership access',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Choose which chapters each chapter coordinator can see.',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Membership access']],
]);
?>
<style>
    /* Membership access page only. Everything else comes from engage.css and staff.css */
    .chap-list { display: flex; flex-wrap: wrap; gap: 8px 22px; margin: 12px 0 14px; }
    .chap-list label { display: inline-flex; align-items: center; gap: 8px; font-weight: 500; cursor: pointer; }
    .chap-none { color: var(--gold-dark, #b45309); margin: 0 0 12px; }
</style>

<section class="fcard wide">
    <p class="intro" style="margin:0;">Chapter coordinators see only the chapters ticked here. Accounts with the role <code>membership_reviewer</code>
    (DCW Generic Reviewers), organizers and owners see every chapter. Create coordinator accounts from <a href="/admin/team">Team</a>.</p>
</section>

<?php if ($msg): ?><div class="alert ok"><?= $h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert error"><?= $h($err) ?></div><?php endif; ?>

<?php foreach ($model->coordinators() as $email): $have = $model->chaptersFor($email); ?>
    <form method="POST" class="fcard wide">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="email" value="<?= $h($email) ?>">
        <strong><?= $h($email) ?></strong>
        <div class="chap-list">
            <?php foreach ($names as $k => $n): ?>
                <label><input type="checkbox" name="chapters[]" value="<?= $h($k) ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>> <?= $h($n) ?></label>
            <?php endforeach; ?>
        </div>
        <?php if (!$have): ?><p class="chap-none">No chapters yet: this person sees nothing.</p><?php endif; ?>
        <button type="submit" class="tb-btn tb-save">Save</button>
    </form>
<?php endforeach; ?>

<?php if (!$model->coordinators()): ?>
    <div class="empty-note">No accounts have the role <code>membership_coordinator</code> yet.</div>
<?php endif; ?>
<?php engage_footer(); ?>
