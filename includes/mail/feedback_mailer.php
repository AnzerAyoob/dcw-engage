<?php
// includes/mail/feedback_mailer.php
//
// Emails for feedback forms. Wording only: sending is CoreMail::send(), like every other subject class.

require_once __DIR__ . '/CoreMail.php';

class FeedbackMailer {
    /**
     * Verification code, sent BEFORE the feedback form opens. The respondent types the code into
     * the page they already have open; the address is not verified until it is accepted there.
     * $code is the raw 8-character code from EmailVerificationModel::request(); shown as ABCD-EFGH.
     * The subject deliberately does not contain the code.
     */
    public static function sendVerification($email, $name, $formTitle, $code, $expiresAtText = '') {
        $title     = CoreMail::e($formTitle);
        $shownCode = substr((string) $code, 0, 4) . '-' . substr((string) $code, 4);
        $expiry    = $expiresAtText !== '' ? ' This code is valid for a single use and expires on ' . CoreMail::e($expiresAtText) . '.' : ' This code is valid for a single use.';
        $expiryAlt = $expiresAtText !== '' ? " This code is valid for a single use and expires on $expiresAtText." : ' This code is valid for a single use.';

        $html = '<p>' . CoreMail::greeting($name) . '</p>'
              . "<p>Please use the verification code below to open the feedback form for <strong>$title</strong>. Enter it on the page where you provided your email address.</p>"
              . "<div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:16px 18px; margin:0 0 20px; text-align:center;'>"
              . "<span style='font-family:Menlo,Consolas,monospace; font-size:28px; font-weight:bold; letter-spacing:4px; color:#0f172a;'>" . CoreMail::e($shownCode) . "</span>"
              . "</div>"
              . "<p>$expiry Please do not share it with anyone.</p>"
              . '<p>If you did not request this, you may safely ignore this email. No submission will be made.</p>';

        $alt = CoreMail::greeting($name, false) . "\n\n"
             . "Please use the verification code below to open the feedback form for $formTitle. Enter it on the page where you provided your email address.\n\n"
             . "Verification code: $shownCode\n\n"
             . ltrim($expiryAlt) . " Please do not share it with anyone.\n\n"
             . "If you did not request this, you may safely ignore this email. No submission will be made.\n";

        return CoreMail::send([
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => 'Your verification code to submit feedback: ' . $formTitle,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => true,
            // Dev mode sends nothing, so the code is written to the dev log only so it can be tested.
            'dev_log'    => "Feedback verification code to $email ($formTitle): $shownCode",
        ]);
    }

    /**
     * Receipt to the person who sent feedback. It is a receipt and nothing more: no tracking ID,
     * no link, nothing to click.
     */
    public static function sendReceipt($email, $name, $formTitle) {
        $title = CoreMail::e($formTitle);

        $html = '<p>' . CoreMail::greeting($name) . '</p>'
              . "<p>Thank you. We have received your input regarding <strong>$title</strong>.</p>"
              . '<p>No further action is required on your part. We do not issue tracking IDs for feedback, '
              . 'and we will only reach out to you if we require any further clarification.</p>';

        $alt = CoreMail::greeting($name, false) . "\n\n"
             . "Thank you. We have received your input regarding $formTitle.\n\n"
             . "No further action is required on your part. We do not issue tracking IDs for feedback, "
             . "and we will only reach out to you if we require any further clarification.\n";

        return CoreMail::send([
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => 'We have received your input: ' . $formTitle,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => true,
            'dev_log'    => "Feedback receipt to $email ($formTitle)",
        ]);
    }

    /**
     * A message from an organizer to someone who sent feedback, written on the feedback review page
     * and sent through the system (replaces the old mailto: link). $subject and $message are typed by the
     * organizer. $replyTo is the organizer's own address so the respondent can answer; pass '' for none.
     * Returns true only when a message really went out (false in dev mode).
     */
    public static function sendReply($email, $name, $formTitle, $subject, $message, $replyTo = '', $replyToName = '') {
        $title = CoreMail::e($formTitle);
        $canReply = $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL);

        $html = '<p>' . CoreMail::greeting($name) . '</p>'
              . '<p>' . nl2br(CoreMail::e($message)) . '</p>'
              . "<p style='font-size:14px; color:#64748b; margin-bottom:0;'>You are receiving this message because you shared feedback on <strong>$title</strong>."
              . ($canReply ? ' You can reply directly to this email.' : '') . '</p>';

        $alt = CoreMail::greeting($name, false) . "\n\n"
             . $message . "\n\n"
             . "You are receiving this message because you shared feedback on $formTitle."
             . ($canReply ? ' You can reply directly to this email.' : '') . "\n";

        $opts = [
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => (string) $subject,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => false,   // dev mode sends nothing, so the organizer is told it was not sent
            'dev_log'    => "Feedback reply to $email ($formTitle)",
        ];
        if ($canReply) {
            $opts['reply_to'] = [$replyTo, (string) $replyToName];
        }
        return CoreMail::send($opts);
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
