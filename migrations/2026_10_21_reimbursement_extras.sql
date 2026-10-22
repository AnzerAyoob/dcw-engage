-- Reimbursement settings, global eligibility, and request messages (extends 2026_09_27_reimbursements.sql).
-- Schema only (plus the default settings row at the end). Safe to run on an install that already has these tables
-- (CREATE TABLE IF NOT EXISTS). Needs admin_users / forms / members from
-- database.sql or earlier migrations where foreign keys point at them.

CREATE TABLE IF NOT EXISTS `reimbursement_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `cash_threshold_paise` int(11) NOT NULL DEFAULT 80000,
  `expense_categories` JSON NOT NULL,
  `instructions` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_global_eligibility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_request_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `sender` enum('reviewer','applicant') NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  CONSTRAINT `fk_reimb_msg_request` FOREIGN KEY (`request_id`) REFERENCES `reimbursement_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings row (id = 1), switched off. The app reads this row, and
-- nothing else creates it. INSERT IGNORE leaves an existing row untouched.
INSERT IGNORE INTO `reimbursement_settings` (`id`, `is_active`, `cash_threshold_paise`, `expense_categories`, `instructions`)
VALUES (1, 0, 80000, JSON_ARRAY('Travel', 'Accommodation', 'Food', 'Other'), NULL);
