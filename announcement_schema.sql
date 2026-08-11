CREATE TABLE IF NOT EXISTS `update_announcements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `announcement_key` varchar(64) NOT NULL,
  `title` varchar(120) NOT NULL,
  `content` text NOT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `push_version` int unsigned NOT NULL DEFAULT 1,
  `popup_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `created_by` varchar(20) NOT NULL,
  `updated_by` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_announcement_key` (`announcement_key`),
  KEY `idx_announcement_public` (`status`,`published_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
