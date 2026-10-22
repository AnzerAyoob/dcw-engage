-- Tech issues and their messages.
-- Schema only. Safe to run on an install that already has these tables
-- (CREATE TABLE IF NOT EXISTS). Needs admin_users / forms / members from
-- database.sql or earlier migrations where foreign keys point at them.

CREATE TABLE IF NOT EXISTS `tech_issues` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(16) NOT NULL,
  `reporter_type` varchar(10) NOT NULL,
  `reporter_ref` varchar(190) NOT NULL,
  `reporter_label` varchar(190) NOT NULL,
  `category` varchar(20) NOT NULL,
  `severity` varchar(10) NOT NULL,
  `title` varchar(160) NOT NULL,
  `page_url` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Open',
  `last_sender` varchar(10) NOT NULL DEFAULT 'reporter',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tech_tracking` (`tracking_id`),
  KEY `idx_tech_status` (`status`,`updated_at`),
  KEY `idx_tech_reporter` (`reporter_type`,`reporter_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tech_issue_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_id` int(10) UNSIGNED NOT NULL,
  `sender` varchar(10) NOT NULL,
  `body` text NOT NULL,
  `author_email` varchar(190) DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_msg_issue` (`issue_id`,`id`),
  CONSTRAINT `fk_msg_issue` FOREIGN KEY (`issue_id`) REFERENCES `tech_issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
