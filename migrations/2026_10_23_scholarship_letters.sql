-- Scholarship letters: committee-written award / decline emails that replace the standard
-- status email when a scholarship application is Accepted / Rejected.
-- Run this BEFORE deploying the code. Also add both pieces to database.sql.

CREATE TABLE IF NOT EXISTS scholarship_letters (
    form_id         INT          NOT NULL,                 -- forms.id (no FK on purpose: keeps this independent of the column's exact type)
    enabled         TINYINT(1)   NOT NULL DEFAULT 0,       -- 1 = Accept/Reject send these letters instead of the status email
    award_subject   VARCHAR(190) NOT NULL DEFAULT '',
    award_body      TEXT         NOT NULL,                 -- wikitext subset, rendered by MiniWikiText
    decline_subject VARCHAR(190) NOT NULL DEFAULT '',
    decline_body    TEXT         NOT NULL,
    version         INT          NOT NULL DEFAULT 1,       -- +1 on every save; written to the app log with each send
    saved_by        VARCHAR(190) NOT NULL DEFAULT '',
    saved_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- letter_sent_at: when the award/decline letter went out.
-- letter_failed : 1 only when a decision was saved but its letter could not be sent (drives "Resend letter").
--                 Applications decided before letters were switched on stay 0, so they never show a resend button.
ALTER TABLE applications
    ADD COLUMN letter_sent_at DATETIME   NULL,
    ADD COLUMN letter_failed  TINYINT(1) NOT NULL DEFAULT 0;
