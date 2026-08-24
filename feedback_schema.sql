CREATE TABLE IF NOT EXISTS `feedback_admins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(20) NOT NULL,
  `display_name` varchar(30) NOT NULL DEFAULT '',
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `notification_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_feedback_admin_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `feedback_threads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('issue','suggestion') NOT NULL,
  `user_id` varchar(20) NOT NULL,
  `title` varchar(120) NOT NULL,
  `content` text NOT NULL,
  `template_key` varchar(64) DEFAULT '',
  `status` enum('open','replied','closed') NOT NULL DEFAULT 'open',
  `visibility` enum('private','public') NOT NULL,
  `reply_count` int unsigned NOT NULL DEFAULT 0,
  `like_count` int unsigned NOT NULL DEFAULT 0,
  `pinned_reply_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_reply_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_threads_type_status` (`type`,`status`),
  KEY `idx_feedback_threads_user_created` (`user_id`,`created_at`),
  KEY `idx_feedback_threads_visibility_created` (`visibility`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `feedback_replies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` bigint unsigned NOT NULL,
  `user_id` varchar(20) NOT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `content` text NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_replies_thread_created` (`thread_id`,`created_at`),
  KEY `idx_feedback_replies_thread_pinned` (`thread_id`,`is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `feedback_likes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` bigint unsigned NOT NULL,
  `user_id` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_feedback_like` (`thread_id`,`user_id`),
  KEY `idx_feedback_likes_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `feedback_notification_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(80) NOT NULL,
  `thread_id` bigint unsigned NOT NULL,
  `actor_user_id` varchar(20) NOT NULL,
  `recipient_user_id` varchar(20) NOT NULL,
  `provider_agent_id` varchar(20) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `detail` varchar(500) NOT NULL DEFAULT '',
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `next_retry_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_notification_thread` (`thread_id`,`created_at`),
  KEY `idx_feedback_notification_success` (`success`,`created_at`),
  KEY `idx_feedback_notification_retry` (`success`,`next_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `feedback_admins` (`user_id`, `display_name`, `is_super_admin`, `notification_enabled`, `enabled`)
VALUES
  ('2022140101', '', 0, 1, 1),
  ('2023195077', '东方世家', 1, 1, 1)
ON DUPLICATE KEY UPDATE
  `display_name` = VALUES(`display_name`),
  `is_super_admin` = VALUES(`is_super_admin`),
  `enabled` = VALUES(`enabled`);
