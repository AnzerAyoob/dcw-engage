-- Member conversations (complaints, suggestions, questions) and internal notes.
-- Schema only. Safe to run on an install that already has these tables
-- (CREATE TABLE IF NOT EXISTS). Needs admin_users / forms / members from
-- database.sql or earlier migrations where foreign keys point at them.

CREATE TABLE IF NOT EXISTS `member_tickets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(12) NOT NULL,
  `member_id` varchar(16) NOT NULL,
  `member_email` varchar(255) NOT NULL,
  `member_name` varchar(255) NOT NULL DEFAULT '',
  `type` enum('complaint','suggestion','question') NOT NULL,
  `subject` varchar(160) NOT NULL,
  `status` varchar(32) NOT NULL,
  `about_staff` tinyint(1) NOT NULL DEFAULT 0,
  `hide_name` tinyint(1) NOT NULL DEFAULT 0,
  `last_sender` enum('member','staff') NOT NULL DEFAULT 'member',
  `assigned_to` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_member` (`member_id`,`updated_at`),
  KEY `idx_staff` (`about_staff`,`status`,`updated_at`),
  KEY `idx_assigned` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_ticket_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `sender` enum('member','staff','note') NOT NULL,
  `author` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`,`id`),
  CONSTRAINT `fk_mtm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `member_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internal_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` enum('reimbursement','internet') NOT NULL,
  `request_id` int(11) NOT NULL,
  `author_email` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_queue_request` (`queue`,`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
