-- Email verification: link -> 8-character OTP.
--
-- 1. attempts: wrong guesses spent against a code (cap enforced in
--    EmailVerificationModel::consume()).
-- 2. token_hash now holds an HMAC-SHA256 of (form|email|code). Same length
--    (64 hex chars) as the old SHA-256 of the link token, so no column change.
-- 3. Link-based rows still waiting to be used can never match an HMAC, so they
--    are retired here instead of lingering until they expire.
--
-- NOTE: if token_hash has a UNIQUE index it can stay: the HMAC includes form and
-- email, so collisions are not a concern.

ALTER TABLE email_verifications
    ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER token_hash;

UPDATE email_verifications
   SET invalidated_at = NOW()
 WHERE used_at IS NULL
   AND invalidated_at IS NULL;
