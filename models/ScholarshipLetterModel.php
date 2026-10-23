<?php
/**
 * DCW Engage - Scholarship letters
 *
 * The committee-written award and decline emails for a scholarship program. They live in the
 * database and are written in the admin dashboard (Application review), never in code or Git.
 * When a program has them switched on, Accept sends the award letter and Reject sends the decline
 * letter INSTEAD of the standard status email, so an applicant never gets two emails.
 *
 * Until the migration has been run, allByForm() returns [] and the whole feature stays off.
 */
class ScholarshipLetterModel {
    public const SUBJECT_MAX = 150;
    public const BODY_MAX = 5000;

    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    /** Every program's letters, keyed by form id. [] if the table does not exist yet. */
    public function allByForm(): array {
        try {
            $rows = $this->db->query("SELECT * FROM scholarship_letters")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['form_id']] = $r;
        }
        return $out;
    }

    /** True when the program has letters switched on AND both letters are complete. */
    public static function isActive($row): bool {
        return is_array($row)
            && !empty($row['enabled'])
            && trim((string) ($row['award_subject'] ?? '')) !== ''
            && trim((string) ($row['award_body'] ?? '')) !== ''
            && trim((string) ($row['decline_subject'] ?? '')) !== ''
            && trim((string) ($row['decline_body'] ?? '')) !== '';
    }

    /**
     * Save both letters for one program. Drafts may be saved while switched off; switching on needs
     * both letters complete. Throws an Exception with a message that is safe to show the organizer.
     */
    public function save($formId, $enabled, $awardSubject, $awardBody, $declineSubject, $declineBody, $by): void {
        $subj = fn($s) => trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $s));
        $body = fn($s) => trim(str_replace(["\r\n", "\r"], "\n", (string) $s));
        $as = $subj($awardSubject);   $ab = $body($awardBody);
        $ds = $subj($declineSubject); $db = $body($declineBody);

        foreach ([$as, $ds] as $s) {
            if (mb_strlen($s) > self::SUBJECT_MAX) {
                throw new Exception('Subjects can be at most ' . self::SUBJECT_MAX . ' characters.');
            }
        }
        foreach ([$ab, $db] as $b) {
            if (mb_strlen($b) > self::BODY_MAX) {
                throw new Exception('Each letter can be at most ' . number_format(self::BODY_MAX) . ' characters.');
            }
        }
        if ($enabled && ($as === '' || $ab === '' || $ds === '' || $db === '')) {
            throw new Exception('To turn scholarship letters on, write both the award and the decline letter (subject and message). You can save drafts while it is off.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO scholarship_letters
                (form_id, enabled, award_subject, award_body, decline_subject, decline_body, version, saved_by, saved_at)
            VALUES (:form_id, :enabled, :as, :ab, :ds, :db, 1, :by, NOW())
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                award_subject = VALUES(award_subject), award_body = VALUES(award_body),
                decline_subject = VALUES(decline_subject), decline_body = VALUES(decline_body),
                version = version + 1, saved_by = VALUES(saved_by), saved_at = NOW()
        ");
        $stmt->execute([
            'form_id' => (int) $formId, 'enabled' => $enabled ? 1 : 0,
            'as' => $as, 'ab' => $ab, 'ds' => $ds, 'db' => $db,
            'by' => mb_substr((string) $by, 0, 190),
        ]);
    }

    /** The letter went out. */
    public function markSent($applicationId): void {
        $this->db->prepare("UPDATE applications SET letter_sent_at = NOW(), letter_failed = 0 WHERE id = :id")
                 ->execute(['id' => (int) $applicationId]);
    }

    /** The decision is saved but the letter could not be sent: shows "Resend letter". */
    public function markFailed($applicationId): void {
        $this->db->prepare("UPDATE applications SET letter_failed = 1 WHERE id = :id")
                 ->execute(['id' => (int) $applicationId]);
    }

    /**
     * Claim an unsent letter before resending it. Returns true for exactly one caller, so a double
     * click (or two organizers) cannot send the same letter twice. A failed resend marks it failed again.
     */
    public function claimResend($applicationId): bool {
        $stmt = $this->db->prepare("UPDATE applications SET letter_failed = 0 WHERE id = :id AND letter_failed = 1");
        $stmt->execute(['id' => (int) $applicationId]);
        return $stmt->rowCount() === 1;
    }
}
