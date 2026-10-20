<?php
// includes/mail/feedback_mailer.php
//
// Emails for feedback forms. Wording only: sending is CoreMail::send(), like every other subject class.

require_once __DIR__ . '/CoreMail.php';

class FeedbackMailer {
    /**
     * Verification link, sent BEFORE the feedback form opens. The link only opens a confirmation
     * page on the form; the address is not verified until the person clicks the button there.
     */
    public static function sendVerification($email, $name, $formTitle, $url, $expiresAtText = '') {
        $title  = CoreMail::e($formTitle);
        $expiry = $expiresAtText !== '' ? ' This link works once and expires on ' . CoreMail::e($expiresAtText) . '.' : '';
        $expiryAlt = $expiresAtText !== '' ? " This link works once and expires on $expiresAtText." : '';

        $html = '<p>' . CoreMail::greeting($name) . '</p>'
              . "<p>Please confirm your email address to open the feedback form for <strong>$title</strong>.</p>"
              . CoreMail::button($url, 'Verify Email and Continue')
              . "<p>$expiry</p>"
              . '<p>If you did not ask for this, you can ignore this email. Nothing will be submitted.</p>';

        $alt = CoreMail::greeting($name, false) . "\n\n"
             . "Please confirm your email address to open the feedback form for $formTitle.\n\n"
             . "Verify: $url\n\n"
             . ltrim($expiryAlt) . "\n"
             . "If you did not ask for this, you can ignore this email. Nothing will be submitted.\n";

        return CoreMail::send([
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => 'Verify your email to give feedback: ' . $formTitle,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => true,
            'dev_log'    => "Feedback verification link to $email ($formTitle): $url",
        ]);
    }

    /**
     * Receipt to the person who sent feedback. It is a receipt and nothing more: no tracking ID,
     * no link, nothing to click.
     */
    public static function sendReceipt($email, $name, $formTitle) {
        $title = CoreMail::e($formTitle);

        $html = '<p>' . CoreMail::greeting($name) . '</p>'
              . "<p>Thank you. We have received your feedback regarding <strong>$title</strong>.</p>"
              . '<p>No further action is required on your part. We do not issue a tracking ID for feedback, '
              . 'and we will only reach out to you if we need any further clarification.</p>';

        $alt = CoreMail::greeting($name, false) . "\n\n"
             . "Thank you. We have received your feedback regarding $formTitle.\n\n"
             . "No further action is required on your part. We do not issue a tracking ID for feedback, "
             . "and we will only reach out to you if we need any further clarification.\n";

        return CoreMail::send([
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => 'We have received your feedback: ' . $formTitle,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => true,
            'dev_log'    => "Feedback receipt to $email ($formTitle)",
        ]);
    }

    /**
     * Alert to the form's notify_emails (comma separated). Says that feedback arrived and links to
     * the review page; it does not repeat the feedback or the respondent's details.
     */
    public static function sendOrganizerAlert($notifyEmails, $formTitle, $formId) {
        $addresses = array_values(array_filter(
            array_map('trim', explode(',', (string) $notifyEmails)),
            fn($a) => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)
        ));
        if (!$addresses) {
            return false;
        }

        $url   = CoreMail::appUrl() . '/admin/feedback-review?form=' . (int) $formId;
        $title = CoreMail::e($formTitle);

        $html = "<p>New feedback has been submitted for <strong>$title</strong>.</p>"
              . CoreMail::button($url, 'View Feedback');

        $alt = "New feedback has been submitted for $formTitle.\n\nView it (sign-in required): $url\n";

        return CoreMail::send([
            'to'         => $addresses,
            'subject'    => 'New Feedback Received: ' . $formTitle,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => true,
            'dev_log'    => 'Feedback alert for ' . $formTitle . ' to ' . implode(', ', $addresses),
        ]);
    }
}
