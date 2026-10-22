-- Schema catch-up for installs created before these changes.
--
-- WHEN TO RUN: only on an EXISTING database set up from an older
-- database.sql / earlier migrations. A fresh install from the current
-- database.sql already has everything here; running this file on it is
-- harmless (see below) but unnecessary.
--
-- SAFE TO RE-RUN: each ADD COLUMN first checks information_schema and is
-- skipped if the column already exists, and the MODIFY statements restate a
-- definition, so they do nothing on a database that already matches. It works
-- on both MySQL 8.0+ and MariaDB 10.4+ (which is why it does not use
-- "ADD COLUMN IF NOT EXISTS", a MariaDB-only feature).
--
-- Take a backup first. Run the earlier migrations (including
-- 2026_09_27_reimbursements.sql) before this one.
--
-- WHAT IT BRINGS UP TO DATE
--   forms                    deadline_at
--   email_verifications      name (and form_id may now be NULL)
--   admin_invites            roles, chapters (and role becomes VARCHAR(50))
--   admin_users              role becomes VARCHAR(50) instead of a fixed ENUM
--   applications             status gains 'Discarded'
--   reimbursement_requests   event_name, event_date, receipts_downloaded_at,
--                            approved_amount_*, paid_amount_*, sent_back_*,
--                            reimbursement_form_id may be NULL, and status
--                            gains 'Discarded' and 'Info Requested'
--
-- Not changed here: reimbursement_requests.payment_method. Newer code no
-- longer offers 'cash'; the column is left alone so that no existing 'cash'
-- row can be affected.

-- ---- forms ----------------------------------------------------------------
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forms' AND COLUMN_NAME = 'deadline_at') = 0,
            'ALTER TABLE `forms` ADD COLUMN `deadline_at` DATETIME NULL DEFAULT NULL AFTER `is_active`',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---- email_verifications --------------------------------------------------
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_verifications' AND COLUMN_NAME = 'name') = 0,
            'ALTER TABLE `email_verifications` ADD COLUMN `name` VARCHAR(150) NULL DEFAULT NULL AFTER `email`',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE `email_verifications` MODIFY `form_id` INT NULL DEFAULT NULL;

-- ---- admin_users / admin_invites ------------------------------------------
ALTER TABLE `admin_users` MODIFY `role` VARCHAR(50) NOT NULL DEFAULT 'organizer';

ALTER TABLE `admin_invites` MODIFY `role` VARCHAR(50) NOT NULL DEFAULT 'organizer';

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_invites' AND COLUMN_NAME = 'roles') = 0,
            'ALTER TABLE `admin_invites` ADD COLUMN `roles` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `role`',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_invites' AND COLUMN_NAME = 'chapters') = 0,
            'ALTER TABLE `admin_invites` ADD COLUMN `chapters` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `roles`',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---- applications ---------------------------------------------------------
ALTER TABLE `applications`
    MODIFY `status` ENUM('Draft','New','Under Review','Accepted','Rejected','Discarded') DEFAULT 'New';

-- ---- reimbursement_requests -----------------------------------------------
ALTER TABLE `reimbursement_requests` MODIFY `reimbursement_form_id` INT NULL DEFAULT NULL;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'event_name') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `event_name` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `applicant_name`',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'event_date') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `event_date` DATE NULL DEFAULT NULL AFTER `event_name`',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'receipts_downloaded_at') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `receipts_downloaded_at` DATETIME NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'approved_amount_paise') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `approved_amount_paise` INT UNSIGNED NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'approved_amount_note') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `approved_amount_note` VARCHAR(500) NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'paid_amount_paise') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `paid_amount_paise` INT UNSIGNED NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'paid_amount_note') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `paid_amount_note` VARCHAR(500) NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'sent_back_by') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `sent_back_by` VARCHAR(255) NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'sent_back_at') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `sent_back_at` DATETIME NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reimbursement_requests' AND COLUMN_NAME = 'sent_back_note') = 0,
            'ALTER TABLE `reimbursement_requests` ADD COLUMN `sent_back_note` VARCHAR(1000) NULL DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Status values, including 'Info Requested' which the model already uses.
-- New values are added at the END of the list so existing rows keep their value.
ALTER TABLE `reimbursement_requests`
    MODIFY `status` ENUM('Submitted','Under Review','Rejected','Approved for Payment','Paid','Payment Failed','Discarded','Info Requested')
    NOT NULL DEFAULT 'Submitted';
