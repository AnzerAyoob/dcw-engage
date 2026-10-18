<?php
// includes/internal_notes_ui.php
// The "Internal notes" box shown under a request card on the staff pages
// (finance queue, internet review, reimbursement review).
//
// Call it OUTSIDE any other <form> (HTML does not allow nested forms):
//
//   internal_notes_block('internet', (int) $req['id'], $noteModel->forRequest('internet', (int) $req['id']), 'decision', 'internet');
//
// $buttonName is the name of the submit button the page's POST handler reads:
// 'result' on the finance page, 'decision' on the two review pages. The value is always 'internal_note'.
// $tab is only used by the finance page, so the page stays on the same tab afterwards.
//
// Styles: the .inotes rules live in assets/css/engage.css (section "Internal notes"). This file prints no <style>.

function internal_notes_block(string $queue, int $id, array $notes, string $buttonName = 'result', string $tab = ''): void
{
    $h = fn($s) => htmlspecialchars((string) $s);
    ?>
    <details class="inotes"<?= $notes ? ' open' : '' ?>>
        <summary>Internal notes (<?= count($notes) ?>): staff only, never shown to the applicant</summary>
        <?php foreach ($notes as $n): ?>
            <div class="inote">
                <div class="inote-meta"><?= $h($n['author_email']) ?> &middot; <?= $h($n['created_at']) ?> UTC</div>
                <?= nl2br($h($n['body'])) ?>
            </div>
        <?php endforeach; ?>
        <form method="POST">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="queue" value="<?= $h($queue) ?>">
            <input type="hidden" name="tab" value="<?= $h($tab) ?>">
            <input type="hidden" name="request_id" value="<?= (int) $id ?>">
            <textarea name="internal_note" maxlength="2000" required placeholder="Add an internal note (colleagues can see it; the applicant cannot)"></textarea>
            <button type="submit" name="<?= $h($buttonName) ?>" value="internal_note" class="btn-neutral">Add note</button>
        </form>
    </details>
    <?php
}
