-- Conversation Hour sessions/attendance, eligibility snapshots, tech contributors.
-- Schema only. Safe to run on an install that already has these tables
-- (CREATE TABLE IF NOT EXISTS). Needs admin_users / forms / members from
-- database.sql or earlier migrations where foreign keys point at them.

CREATE TABLE IF NOT EXISTS `ch_sessions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `held_on` date NOT NULL,
  `created_by` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_by` varchar(255) DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_held_on` (`held_on`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ch_attendance` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` int(10) UNSIGNED NOT NULL,
  `person_key` varchar(300) NOT NULL,
  `member_id` varchar(20) DEFAULT NULL,
  `wikimedia_username` varchar(255) NOT NULL DEFAULT '',
  `status` enum('present','excused') NOT NULL DEFAULT 'present',
  `note` varchar(500) NOT NULL DEFAULT '',
  `logged_by` varchar(255) NOT NULL,
  `logged_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_person` (`session_id`,`person_key`),
  KEY `idx_member` (`member_id`),
  KEY `idx_username` (`wikimedia_username`),
  CONSTRAINT `fk_ch_att_session` FOREIGN KEY (`session_id`) REFERENCES `ch_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ch_eligibility_snapshots` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(40) NOT NULL,
  `member_id` varchar(20) NOT NULL,
  `snapshot_json` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tracking` (`tracking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tech_contributors` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(20) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` varchar(500) NOT NULL,
  `confirmed_until` date DEFAULT NULL,
  `removed_by` varchar(255) DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `removed_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
