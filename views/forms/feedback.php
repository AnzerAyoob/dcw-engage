<?php
/**
 * DCW Engage - public feedback form.
 *
 * Included by views/forms/renderer.php when the form's schema has "kind": "feedback".
 * Runs in the renderer's scope, so it uses $form, $schema, $formType and $previewSchema from there.
 * The renderer has already dealt with closed / missing forms.
 *
 * What is different from an application:
 *   - no email verification gate (name and email are asked on the form itself)
 *   - no draft, no magic link, no tracking ID, no duplicate check, no member-ID logic
 *   - the response goes to feedback_responses, not applications
 *   - the person gets a receipt email and nothing else
 */
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../includes/mail/feedback_mailer.php';

$isPreview = !empty($previewSchema);
$fbFormId  = $form['id'] ?? null;
$fbPath    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fbFields  = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
$fbTitle   = (string) ($schema['title'] ?? 'Feedback');
$fbErrors  = [];
$fbName    = '';
$fbEmail   = '';

if (!$isPreview && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    // Honeypot: real people never see this field. Pretend it worked, store nothing, send nothing.
    if (trim((string) ($_POST['hp_website'] ?? '')) !== '') {
        $_SESSION['fb_sent'][$fbFormId] = true;
        header('Location: ' . $fbPath);
        exit;
    }

    $fbName  = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['respondent_name'] ?? '')));
    $fbEmail = strtolower(trim((string) ($_POST['respondent_email'] ?? '')));

    if ($fbName === '' || mb_strlen($fbName) > 100) {
        $fbErrors['respondent_name'] = 'Please enter your name (maximum 100 characters).';
    }
    if ($fbEmail === '' || strlen($fbEmail) > 254 || !filter_var($fbEmail, FILTER_VALIDATE_EMAIL)) {
        $fbErrors['respondent_email'] = 'Please enter a valid email address.';
    }

    [$fbAnswers, $fbAnswerErrors] = FeedbackModel::collectAnswers($schema, $_POST);
    $fbErrors = array_merge($fbErrors, $fbAnswerErrors);

    if (!$fbErrors) {
        try {
            $fbModel = new FeedbackModel();
            $formIdInt = (int) $fbFormId;

            if ($fbModel->recentCountForEmail($formIdInt, $fbEmail, 60) >= FeedbackModel::MAX_PER_EMAIL_PER_HOUR
                || $fbModel->recentCountForForm($formIdInt, 10) >= FeedbackModel::MAX_PER_FORM_PER_10_MIN) {
                $fbErrors['system'] = 'We are receiving a high volume of submissions at the moment. Please try again shortly.';
            } else {
                $fbModel->create($formIdInt, $fbName, $fbEmail, $fbAnswers);

                // The response is saved; a mail problem must never turn that into an error for the person.
                try {
                    FeedbackMailer::sendReceipt($fbEmail, $fbName, $fbTitle);
                    FeedbackMailer::sendOrganizerAlert($form['notify_emails'] ?? '', $fbTitle, $formIdInt);
                } catch (Throwable $mailEx) {
                    app_log("Feedback mail failed for form '$formType': " . $mailEx->getMessage());
                }

                // Post/Redirect/Get so a refresh cannot submit twice.
                $_SESSION['fb_sent'][$fbFormId] = true;
                header('Location: ' . $fbPath);
                exit;
            }
        } catch (Throwable $ex) {
            app_log("Feedback save failed for form '$formType': " . $ex->getMessage());
            $fbErrors['system'] = 'An error occurred while saving your feedback. Please try again.';
        }
    }
}

$fbSent = false;
if (!$isPreview && !empty($_SESSION['fb_sent'][$fbFormId])) {
    unset($_SESSION['fb_sent'][$fbFormId]);
    $fbSent = true;
}

// Link-only forms are kept out of search results (same as the application renderer).
if (!empty($schema['unlisted'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

engage_header([
    'title'       => $fbTitle,
    'heading'     => $fbTitle,
    'kicker'      => 'Feedback',
    'lead'        => '',
    'member'      => null,
    'crumbs'      => [['Home', '/'], [$fbTitle]],
    'description' => MiniWikiText::stripToPlainText($schema['description'] ?? ''),
    'image'       => $schema['banner_image'] ?? null,
    'image_alt'   => $fbTitle,
]);
?>
<style>
    /* Feedback form only. The shared look comes from /assets/css/engage.css; these few rules
       mirror the ones the application renderer defines inline. */
    .hero h1 { max-width: 880px; margin-left: auto; margin-right: auto; text-wrap: balance; }
    .banner-fig { margin: -30px -30px 26px; }
    .banner-img { display: block; width: 100%; height: auto; max-height: 260px; object-fit: cover; border-radius: 16px 16px 0 0; }
    .banner-credit { padding: 6px 30px 0; font-size: 12px; color: var(--muted); text-align: right; }
    .banner-credit a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }
    .form-desc { margin: 0 0 26px; padding-bottom: 24px; border-bottom: 1px solid var(--border); color: var(--ink); font-size: 15.5px; line-height: 1.7; }
    .form-desc p { margin: 0 0 12px; }
    .form-desc > :last-child { margin-bottom: 0; }
    .form-desc ol, .form-desc ul { margin: 0 0 12px; padding-left: 22px; }
    .form-desc h2, .form-desc h3, .form-desc h4 { margin: 22px 0 8px; padding: 0; border: 0; font-size: 17px; font-weight: 800; line-height: 1.3; }
    .boxed { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; margin: 0 0 24px; }
    .boxed .field:last-child { margin-bottom: 0; }
    .verified-line { margin: 0 0 16px; font-size: 14px; color: var(--muted); }
    .error-text { display: block; margin-top: 6px; font-size: 13px; color: #b91c1c; }
    .alert h3 { margin: 0 0 6px; padding: 0; border: 0; font-size: 17px; }
    .alert p { margin: 8px 0 0; font-size: 14px; }
    .opt { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 8px; font-weight: 500; font-size: 15px; cursor: pointer; }
    .opt input { width: 17px; height: 17px; margin: 3px 0 0; accent-color: var(--primary); flex: none; }
    .opt-group { display: grid; gap: 2px; margin-top: 6px; }
    .field input[type=url], .field input[type=time], .field input[type=tel] { width: 100%; padding: 12px 14px; background: #fff; color: var(--ink); border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 15px; }
    .privacy-note { margin: 18px 0 0; font-size: 13px; color: var(--muted); }
    .hp-field { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>

<div class="fcard">
    <?php if (!empty($schema['banner_image'])): ?>
        <figure class="banner-fig">
            <img class="banner-img" src="<?= htmlspecialchars(engage_resolve_image($schema['banner_image'], 1280)) ?>" alt="">
            <?= engage_commons_caption($schema['banner_image']) ?>
        </figure>
    <?php endif; ?>

    <?php if ($isPreview): ?>
        <div class="action-banner" role="status">🔍 Preview — this is how the form will appear. Submissions are disabled in this mode.</div>
    <?php endif; ?>

    <?php if (!$fbSent): ?>
        <?php if (!empty($form['deadline_at'])): ?>
            <p class="verified-line">Feedback will remain open until <strong><?= htmlspecialchars(FormModel::formatIst($form['deadline_at'])) ?></strong>.</p>
        <?php endif; ?>

        <?php if (!empty($schema['description'])): ?>
            <div class="form-desc"><?= MiniWikiText::render($schema['description']) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($fbSent): ?>
        <div class="alert ok">
            <h3>Thank you for sharing your feedback!</h3>
            <p>We have emailed you a receipt. No further action is required on your part.</p>
        </div>

    <?php else: ?>
        <?php if (!empty($fbErrors['system'])): ?>
            <div class="alert error"><strong>Notice:</strong> <?= htmlspecialchars($fbErrors['system']) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= CSRF::getInputField() ?>

            <div class="hp-field" aria-hidden="true">
                <label>Please leave this field blank <input type="text" name="hp_website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="boxed">
                <div class="field">
                    <label for="respondent_name">Your Name <span class="req-star">*</span></label>
                    <input type="text" name="respondent_name" id="respondent_name" maxlength="100" autocomplete="name"
                        value="<?= htmlspecialchars($fbName) ?>" required>
                    <?php if (!empty($fbErrors['respondent_name'])): ?>
                        <span class="error-text"><?= htmlspecialchars($fbErrors['respondent_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="respondent_email">Email Address <span class="req-star">*</span></label>
                    <input type="email" name="respondent_email" id="respondent_email" maxlength="254" autocomplete="email"
                        value="<?= htmlspecialchars($fbEmail) ?>" required>
                    <span class="hint">We will send a receipt to this email address.</span>
                    <?php if (!empty($fbErrors['respondent_email'])): ?>
                        <span class="error-text"><?= htmlspecialchars($fbErrors['respondent_email']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach ($fbFields as $field):
                $name = (string) ($field['name'] ?? '');
                if ($name === '') continue;
                $type = (string) ($field['type'] ?? 'text');
                if ($type === 'file') continue;   // not supported on feedback forms

                $label     = (string) ($field['label'] ?? $name);
                $required  = !empty($field['required']) ? 'required' : '';
                $star      = $required ? ' <span class="req-star">*</span>' : '';
                $safeName  = htmlspecialchars($name);
                $rawValue  = is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
                $value     = htmlspecialchars($rawValue);
                $fieldErr  = $fbErrors[$name] ?? null;
                $labelHtml = MiniWikiText::inline(htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
                $inputTypes = ['text', 'email', 'number', 'date', 'time', 'url', 'tel'];
                ?>
                <div class="field">
                    <?php if ($type === 'checkbox'): ?>
                        <label for="<?= $safeName ?>" class="opt">
                            <input type="checkbox" name="<?= $safeName ?>" id="<?= $safeName ?>" value="Yes"
                                <?= !empty($_POST[$name]) ? 'checked' : '' ?> <?= $required ?>>
                            <span><?= $labelHtml ?><?= $star ?></span>
                        </label>

                    <?php elseif ($type === 'checkbox_group'):
                        $picked = is_array($_POST[$name] ?? null) ? $_POST[$name] : [];
                        ?>
                        <label><?= $labelHtml ?><?= $star ?></label>
                        <div class="opt-group">
                            <?php foreach (($field['options'] ?? []) as $opt): ?>
                                <label class="opt">
                                    <input type="checkbox" name="<?= $safeName ?>[]" value="<?= htmlspecialchars($opt) ?>"
                                        <?= in_array($opt, $picked, true) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($opt) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <label for="<?= $safeName ?>"><?= $labelHtml ?><?= $star ?></label>

                        <?php if ($type === 'select'): ?>
                            <select name="<?= $safeName ?>" id="<?= $safeName ?>" <?= $required ?>>
                                <option value="">-- Please select an option --</option>
                                <?php foreach (($field['options'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $rawValue === $opt ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($type === 'textarea'): ?>
                            <textarea name="<?= $safeName ?>" id="<?= $safeName ?>" rows="5"
                                maxlength="<?= FeedbackModel::MAX_LONG ?>" <?= $required ?>><?= $value ?></textarea>

                        <?php else: ?>
                            <input type="<?= in_array($type, $inputTypes, true) ? $type : 'text' ?>"
                                name="<?= $safeName ?>" id="<?= $safeName ?>" value="<?= $value ?>"
                                maxlength="<?= FeedbackModel::MAX_TEXT ?>" <?= $required ?>>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($fieldErr): ?>
                        <span class="error-text"><?= htmlspecialchars($fieldErr) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <button type="submit" <?= $isPreview ? 'disabled title="Disabled in preview mode"' : '' ?>>Submit Feedback</button>

            <p class="privacy-note">
                Your name and email address are used to send you a receipt and, if necessary, to follow up with you.
                They are purged automatically after <?= (int) FeedbackModel::retentionMonths() ?> months.
            </p>
        </form>
    <?php endif; ?>
</div>
<?php engage_footer(); ?>
