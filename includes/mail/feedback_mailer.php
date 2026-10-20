<?php
// includes/mail/feedback_mailer.php
//
// Emails for feedback forms. Wording only: sending is CoreMail::send(), like every other subject class.

require_once __DIR__ . '/CoreMail.php';

class FeedbackMailer {
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
