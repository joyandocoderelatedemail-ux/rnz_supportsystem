CREATE DATABASE IF NOT EXISTS `rnz_website`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `rnz_website`;

CREATE TABLE IF NOT EXISTS `demo_requests` (
  `id` varchar(40) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'New',
  `name` varchar(160) NOT NULL,
  `contact` varchar(80) NOT NULL,
  `location` varchar(180) NOT NULL,
  `preferred_pos` varchar(80) NOT NULL,
  `other_system` varchar(160) NOT NULL DEFAULT '',
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

