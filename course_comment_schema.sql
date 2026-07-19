CREATE TABLE IF NOT EXISTS `course_comment_threads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_key` char(64) NOT NULL,
  `course_name` varchar(120) NOT NULL,
  `teacher_name` varchar(80) NOT NULL,
  `comment_count` int unsigned NOT NULL DEFAULT 0,
  `like_count` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_comment_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_course_comment_thread_key` (`course_key`),
  KEY `idx_course_comment_threads_last_comment` (`last_comment_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `course_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` bigint unsigned NOT NULL,
  `user_id` varchar(20) NOT NULL,
  `content` varchar(500) NOT NULL,
  `status` enum('active','deleted_by_user','deleted_by_admin') NOT NULL DEFAULT 'active',
  `like_count` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by_user_id` varchar(20) DEFAULT NULL,
  `deleted_by_role` enum('user','admin') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_course_comments_thread_created` (`thread_id`,`created_at`),
  KEY `idx_course_comments_user_created` (`user_id`,`created_at`),
  KEY `idx_course_comments_thread_status_created` (`thread_id`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `course_comment_likes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` bigint unsigned NOT NULL,
  `user_id` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_course_comment_like` (`comment_id`,`user_id`),
  KEY `idx_course_comment_likes_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
