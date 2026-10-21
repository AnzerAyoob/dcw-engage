-- Members: accounts, profiles, password tokens, strikes, chapter scopes, decisions, ID links.
-- Schema only. Safe to run on an install that already has these tables
-- (CREATE TABLE IF NOT EXISTS). Needs admin_users / forms / members from
-- database.sql or earlier migrations where foreign keys point at them.

CREATE TABLE IF NOT EXISTS `members` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(20) NOT NULL,
  `email` varchar(190) NOT NULL,
  `full_name` varchar(190) NOT NULL,
  `chapter` enum('generic','amu','jamia','photographers') NOT NULL,
  `status` enum('active','expired') NOT NULL DEFAULT 'active',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `application_id` int(10) UNSIGNED DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `password_set_at` datetime DEFAULT NULL,
  `failed_logins` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_member_chapter` (`email`,`chapter`),
  UNIQUE KEY `uq_member_id_chapter` (`member_id`,`chapter`),
  KEY `idx_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_password_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_row_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `purpose` enum('set','reset') NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_member` (`member_row_id`),
  CONSTRAINT `fk_mpt_member` FOREIGN KEY (`member_row_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_profile` (
  `member_id` varchar(32) NOT NULL,
  `wikimedia_username` varchar(255) DEFAULT NULL,
  `username_key` varchar(255) DEFAULT NULL,
  `username_verified_at` datetime DEFAULT NULL,
  `username_verified_by` varchar(255) DEFAULT NULL,
  `bio` varchar(280) DEFAULT NULL,
  `languages` varchar(500) DEFAULT NULL,
  `contribution_areas` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` varchar(255) DEFAULT NULL,
  `card_title` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `uq_member_profile_username` (`username_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_strikes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(16) NOT NULL,
  `reason` text NOT NULL,
  `struck_by` varchar(190) NOT NULL,
  `struck_at` datetime NOT NULL,
  `lifted_at` datetime DEFAULT NULL,
  `lifted_by` varchar(190) DEFAULT NULL,
  `lift_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_strikes_member` (`member_id`,`lifted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_scopes` (
  `email` varchar(190) NOT NULL,
  `chapter` enum('generic','amu','jamia','photographers') NOT NULL,
  PRIMARY KEY (`email`,`chapter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_decisions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` int(10) UNSIGNED NOT NULL,
  `decision` enum('approved','rejected','under_review','info_requested') NOT NULL,
  `reason` text DEFAULT NULL,
  `decided_by` varchar(190) NOT NULL,
  `decided_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_app` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_id_links` (
  `application_id` int(10) UNSIGNED NOT NULL,
  `member_id` varchar(20) NOT NULL,
  `linked_by` varchar(255) NOT NULL,
  `linked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`application_id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
