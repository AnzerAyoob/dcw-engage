-- Membership QR cards and the public card-check rate limiter.
-- Schema only. Safe to run on an install that already has these tables
-- (CREATE TABLE IF NOT EXISTS). Needs admin_users / forms / members from
-- database.sql or earlier migrations where foreign keys point at them.

CREATE TABLE IF NOT EXISTS `member_cards` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(16) NOT NULL,
  `token` char(32) NOT NULL,
  `created_at` datetime NOT NULL,
  `replaced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_cards_token` (`token`),
  KEY `idx_member_cards_member` (`member_id`,`replaced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_card_hits` (
  `ip_hash` char(64) NOT NULL,
  `window_start` datetime NOT NULL,
  `hits` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`ip_hash`,`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
