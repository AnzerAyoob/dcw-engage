-- Feedback form responses.
--
-- Run once on existing installs, and append to database.sql for fresh ones.
-- IMPORTANT: form_id must have EXACTLY the same type as forms.id (check database.sql:
-- INT vs INT UNSIGNED vs BIGINT), or the foreign key will be refused.
--
-- Deliberately has no tracking_id, status, token, or magic link: feedback is not tracked.
-- name/email/answers_json are nulled by cron/scrub_feedback.php after the retention period.

CREATE TABLE IF NOT EXISTS feedback_responses (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_id      INT NOT NULL,
    name         VARCHAR(100) NULL,
    email        VARCHAR(254) NULL,
    answers_json LONGTEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scrubbed_at  DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_form_created (form_id, created_at),
    KEY idx_form_email_created (form_id, email, created_at),
    KEY idx_scrub (scrubbed_at, created_at),
    CONSTRAINT fk_feedback_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
