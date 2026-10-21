-- Internet support requests, their messages, and settings.
-- Schema only (plus the default settings row at the end). Safe to run on an install that already has these tables
-- (CREATE TABLE IF NOT EXISTS). Needs admin_users / forms / members from
-- database.sql or earlier migrations where foreign keys point at them.

CREATE TABLE IF NOT EXISTS `internet_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(20) NOT NULL,
  `email` varchar(255) NOT NULL,
  `applicant_name` varchar(255) NOT NULL,
  `phone_enc` varbinary(512) NOT NULL,
  `operator` varchar(50) NOT NULL,
  `package_name` varchar(120) NOT NULL,
  `package_price_paise` int(11) NOT NULL,
  `package_validity_days` int(11) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Submitted',
  `decided_by` varchar(255) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `recharged_by` varchar(255) DEFAULT NULL,
  `recharged_at` datetime DEFAULT NULL,
  `recharge_reference` varchar(255) DEFAULT NULL,
  `recharge_notes` text DEFAULT NULL,
  `receipt_path` varchar(255) NOT NULL DEFAULT '',
  `receipt_submitted_at` datetime DEFAULT NULL,
  `finance_notes` text DEFAULT NULL,
  `closed_by` varchar(255) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `receipts_downloaded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `wikimedia_username` varchar(255) DEFAULT NULL,
  `edits_80` tinyint(1) DEFAULT NULL,
  `attended_ch` tinyint(1) DEFAULT NULL,
  `tech_contributor` tinyint(1) DEFAULT NULL,
  `contributions` text DEFAULT NULL,
  `plans` text DEFAULT NULL,
  `approved_amount_paise` int(10) UNSIGNED DEFAULT NULL,
  `approved_amount_note` varchar(500) DEFAULT NULL,
  `paid_amount_paise` int(10) UNSIGNED DEFAULT NULL,
  `paid_amount_note` varchar(500) DEFAULT NULL,
  `sent_back_by` varchar(255) DEFAULT NULL,
  `sent_back_at` datetime DEFAULT NULL,
  `sent_back_note` varchar(1000) DEFAULT NULL,
  `current_pack_ends_on` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_internet_status` (`status`),
  KEY `idx_internet_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internet_request_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `sender` enum('reviewer','applicant') NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`,`id`),
  CONSTRAINT `fk_irm_request` FOREIGN KEY (`request_id`) REFERENCES `internet_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internet_settings` (
  `id` tinyint(4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `max_amount_paise` int(11) NOT NULL DEFAULT 100000,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings row (id = 1), switched off. The app reads this row, and
-- nothing else creates it. INSERT IGNORE leaves an existing row untouched.
INSERT IGNORE INTO `internet_settings` (`id`, `is_active`, `max_amount_paise`)
VALUES (1, 0, 100000);
