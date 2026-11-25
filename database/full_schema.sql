-- =========================================================
-- NoteSphere - Complete Database Schema
-- Database : 1notesphere_db
-- Engine   : InnoDB / utf8mb4
--
-- Safe to run more than once (CREATE TABLE IF NOT EXISTS).
-- Contains every table used by the NoteSphere pages that was
-- still missing from the live database.
-- =========================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- =========================================================
-- USERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `profile_pic` VARCHAR(500) NULL,
    `course` VARCHAR(100) NULL,
    `institute` VARCHAR(10) NULL COMMENT 'ICS, IBE, or ITE',
    `student_status` VARCHAR(50) NULL,
    `year_level` VARCHAR(50) NULL,
    `section` VARCHAR(100) NULL,
    `enrolled_subjects` TEXT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('student','professor','admin') NOT NULL DEFAULT 'student',
    `warning_count` INT NOT NULL DEFAULT 0,
    `account_status` VARCHAR(30) NOT NULL DEFAULT 'active',
    `is_first_login` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- USERS (existing table, only the missing column is added)
-- =========================================================
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `profile_pic` VARCHAR(500) NULL AFTER `email`;

-- =========================================================
-- ANNOUNCEMENTS / ATTACHMENTS / READS / SUBMISSIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `professor_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(100) NOT NULL DEFAULT '',
    `target_course` VARCHAR(100) NOT NULL DEFAULT 'All Courses',
    `year_level` VARCHAR(50) NOT NULL DEFAULT 'All Years',
    `section` VARCHAR(100) NOT NULL DEFAULT 'All',
    `message` LONGTEXT NOT NULL,
    `attachment` VARCHAR(500) NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'Announcement',
    `meet_link` VARCHAR(500) NULL,
    `quiz_link` VARCHAR(500) NULL,
    `youtube_link` VARCHAR(500) NULL,
    `deadline` DATETIME NULL,
    `semester` VARCHAR(50) NULL,
    `academic_year` VARCHAR(20) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_announcements_professor` (`professor_id`),
    KEY `idx_announcements_status` (`status`),
    KEY `idx_announcements_period` (`semester`, `academic_year`),
    KEY `idx_announcements_target` (`target_course`, `year_level`, `section`),
    CONSTRAINT `fk_announcements_professor` FOREIGN KEY (`professor_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `announcement_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `original_name` VARCHAR(255) NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_announcement_attachments_announcement` (`announcement_id`),
    CONSTRAINT `fk_announcement_attachments_announcement` FOREIGN KEY (`announcement_id`)
        REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_reads` (
    `announcement_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`announcement_id`, `student_id`),
    KEY `idx_announcement_reads_student` (`student_id`),
    CONSTRAINT `fk_announcement_reads_announcement` FOREIGN KEY (`announcement_id`)
        REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_announcement_reads_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_submissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `announcement_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `submission_text` LONGTEXT NULL,
    `file_path` VARCHAR(500) NULL,
    `student_note` TEXT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'submitted',
    `grade` VARCHAR(50) NULL,
    `feedback` TEXT NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_announcement_student` (`announcement_id`, `student_id`),
    KEY `idx_announcement_submissions_student` (`student_id`),
    KEY `idx_announcement_submissions_status` (`status`),
    CONSTRAINT `fk_announcement_submissions_announcement` FOREIGN KEY (`announcement_id`)
        REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_announcement_submissions_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- NOTES / NOTE ATTACHMENTS / NOTE RATINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `content` LONGTEXT NULL,
    `institute` VARCHAR(10) NULL COMMENT 'ICS, IBE, or ITE',
    `file_path` VARCHAR(500) NULL,
    `thumbnail` VARCHAR(500) NULL,
    `note_type` VARCHAR(30) NOT NULL DEFAULT 'file',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notes_user` (`user_id`),
    KEY `idx_notes_institute` (`institute`),
    KEY `idx_notes_status` (`status`),
    CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `note_attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `note_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `original_name` VARCHAR(255) NULL,
    `mime_type` VARCHAR(150) NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_note_attachments_note` (`note_id`),
    KEY `idx_note_attachments_user` (`user_id`),
    CONSTRAINT `fk_note_attachments_note` FOREIGN KEY (`note_id`)
        REFERENCES `notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_note_attachments_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `note_ratings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `note_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_note_rating_user` (`note_id`, `user_id`),
    KEY `idx_note_ratings_user` (`user_id`),
    CONSTRAINT `fk_note_ratings_note` FOREIGN KEY (`note_id`)
        REFERENCES `notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_note_ratings_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- DIRECT MESSAGES / GROUP CHAT
-- =========================================================
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sender_id` INT UNSIGNED NOT NULL,
    `receiver_id` INT UNSIGNED NOT NULL,
    `message` TEXT NULL,
    `file_path` VARCHAR(500) NULL,
    `file_type` VARCHAR(30) NULL,
    `message_type` VARCHAR(20) NOT NULL DEFAULT 'text',
    `file_name` VARCHAR(255) NULL,
    `file_mime` VARCHAR(150) NULL,
    `file_size` BIGINT UNSIGNED NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_messages_sender` (`sender_id`),
    KEY `idx_messages_receiver` (`receiver_id`),
    KEY `idx_messages_pair` (`sender_id`, `receiver_id`, `id`),
    CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_preferences` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `other_user_id` INT UNSIGNED NOT NULL,
    `theme_name` VARCHAR(24) NOT NULL DEFAULT 'default',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_message_theme` (`user_id`, `other_user_id`),
    KEY `idx_message_preferences_user` (`user_id`),
    CONSTRAINT `fk_message_preferences_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_message_preferences_other` FOREIGN KEY (`other_user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_groups` (
    `group_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_name` VARCHAR(120) NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`group_id`),
    KEY `idx_message_groups_creator` (`created_by`),
    CONSTRAINT `fk_message_groups_creator` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_group_members` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `member_role` VARCHAR(16) NOT NULL DEFAULT 'member',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_group_member` (`group_id`, `user_id`),
    KEY `idx_group_members_user` (`user_id`),
    KEY `idx_group_members_group` (`group_id`),
    CONSTRAINT `fk_group_members_group` FOREIGN KEY (`group_id`)
        REFERENCES `message_groups` (`group_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_group_members_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_group_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `sender_id` INT UNSIGNED NOT NULL,
    `message` TEXT NULL,
    `file_path` VARCHAR(500) NULL,
    `file_type` VARCHAR(30) NULL,
    `message_type` VARCHAR(20) NOT NULL DEFAULT 'text',
    `file_name` VARCHAR(255) NULL,
    `file_mime` VARCHAR(150) NULL,
    `file_size` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_group_messages_group_id` (`group_id`, `id`),
    KEY `idx_group_messages_sender` (`sender_id`),
    CONSTRAINT `fk_group_messages_group` FOREIGN KEY (`group_id`)
        REFERENCES `message_groups` (`group_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_group_messages_sender` FOREIGN KEY (`sender_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Last message each member has opened in a group chat.
CREATE TABLE IF NOT EXISTS `message_group_reads` (
    `group_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `last_read_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`group_id`, `user_id`),
    KEY `idx_group_reads_user` (`user_id`),
    CONSTRAINT `fk_group_reads_group` FOREIGN KEY (`group_id`)
        REFERENCES `message_groups` (`group_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_group_reads_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- HELP BOARD
-- =========================================================
CREATE TABLE IF NOT EXISTS `help_questions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `details` TEXT NOT NULL,
    `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0,
    `moderation_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `moderation_note` VARCHAR(500) NULL,
    `moderated_at` DATETIME NULL,
    `moderated_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_help_questions_student` (`student_id`),
    KEY `idx_help_questions_status` (`moderation_status`),
    KEY `idx_help_questions_created` (`created_at`),
    CONSTRAINT `fk_help_questions_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `help_comments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `question_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `comment` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_help_comments_question` (`question_id`),
    KEY `idx_help_comments_user` (`user_id`),
    CONSTRAINT `fk_help_comments_question` FOREIGN KEY (`question_id`)
        REFERENCES `help_questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_help_comments_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `help_hidden_posts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `question_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_help_hidden_post` (`question_id`, `user_id`),
    KEY `idx_help_hidden_user` (`user_id`),
    CONSTRAINT `fk_help_hidden_question` FOREIGN KEY (`question_id`)
        REFERENCES `help_questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_help_hidden_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `help_board_bans` (
    `student_id` INT UNSIGNED NOT NULL,
    `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
    `reason` VARCHAR(500) NULL,
    `banned_at` DATETIME NULL,
    `banned_by` INT UNSIGNED NULL,
    `unbanned_at` DATETIME NULL,
    `unbanned_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`student_id`),
    KEY `idx_help_board_bans_status` (`is_banned`),
    CONSTRAINT `fk_help_board_bans_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `help_board_settings` (
    `id` INT NOT NULL,
    `posting_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `help_board_settings` (`id`, `posting_enabled`) VALUES (1, 1);

CREATE TABLE IF NOT EXISTS `help_notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `actor_id` INT UNSIGNED NULL,
    `question_id` INT UNSIGNED NULL,
    `comment_id` INT UNSIGNED NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'help_board',
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `message` TEXT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_help_notifications_user` (`user_id`, `is_read`),
    KEY `idx_help_notifications_question` (`question_id`),
    CONSTRAINT `fk_help_notifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_help_notifications_actor` FOREIGN KEY (`actor_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_help_notifications_question` FOREIGN KEY (`question_id`)
        REFERENCES `help_questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_help_notifications_comment` FOREIGN KEY (`comment_id`)
        REFERENCES `help_comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'general',
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `message` TEXT NULL,
    `announcement_id` INT UNSIGNED NULL,
    `question_id` INT UNSIGNED NULL,
    `comment_id` INT UNSIGNED NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`),
    KEY `idx_notifications_user` (`user_id`, `is_read`),
    KEY `idx_notifications_announcement` (`announcement_id`),
    KEY `idx_notifications_question` (`question_id`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_announcement` FOREIGN KEY (`announcement_id`)
        REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_question` FOREIGN KEY (`question_id`)
        REFERENCES `help_questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_comment` FOREIGN KEY (`comment_id`)
        REFERENCES `help_comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_warnings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `message` TEXT NULL,
    `dismissed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_student_warnings_student` (`student_id`, `dismissed`),
    CONSTRAINT `fk_student_warnings_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ENROLLMENT / ANNOUNCEMENT ARCHIVES
-- =========================================================
CREATE TABLE IF NOT EXISTS `student_archives` (
    `archive_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `course` VARCHAR(100) NULL,
    `year_level` VARCHAR(50) NULL,
    `section` VARCHAR(100) NULL,
    `student_status` VARCHAR(50) NULL,
    `enrolled_subjects` TEXT NULL,
    `academic_year` VARCHAR(20) NULL,
    `semester` VARCHAR(50) NULL,
    `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`archive_id`),
    KEY `idx_student_archives_user` (`user_id`),
    CONSTRAINT `fk_student_archives_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dismissed_announcements` (
    `user_id` INT UNSIGNED NOT NULL,
    `announcement_id` INT UNSIGNED NOT NULL,
    `dismissed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `announcement_id`),
    KEY `idx_dismissed_announcement` (`announcement_id`),
    CONSTRAINT `fk_dismissed_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_dismissed_announcement` FOREIGN KEY (`announcement_id`)
        REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_history` (
    `announcement_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`student_id`, `announcement_id`),
    KEY `idx_announcement_history_announcement` (`announcement_id`),
    CONSTRAINT `fk_announcement_history_announcement` FOREIGN KEY (`announcement_id`)
        REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_announcement_history_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- USER SATISFACTION
-- =========================================================
CREATE TABLE IF NOT EXISTS `user_satisfaction_ratings` (
    `rating_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `user_role` VARCHAR(30) NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `comments` TEXT NULL,
    `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`rating_id`),
    UNIQUE KEY `uq_user_satisfaction_lifetime_user` (`user_id`),
    KEY `idx_satisfaction_rating` (`rating`),
    KEY `idx_satisfaction_submitted_at` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_satisfaction_responses` (
    `response_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `user_role` VARCHAR(20) NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL,
    `semester` VARCHAR(50) NOT NULL,
    `q1` TINYINT UNSIGNED NOT NULL,
    `q2` TINYINT UNSIGNED NOT NULL,
    `q3` TINYINT UNSIGNED NOT NULL,
    `q4` TINYINT UNSIGNED NOT NULL,
    `q5` TINYINT UNSIGNED NOT NULL,
    `q6` TINYINT UNSIGNED NOT NULL,
    `comments` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`response_id`),
    UNIQUE KEY `uniq_user_period` (`user_id`, `academic_year`, `semester`),
    KEY `idx_sat_period` (`academic_year`, `semester`),
    KEY `idx_sat_role` (`user_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- SYSTEM SETTINGS / STORAGE / LIMITS
-- =========================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `current_sy` VARCHAR(20) NULL,
    `current_sem` VARCHAR(30) NULL,
    `current_academic_year` VARCHAR(20) NULL,
    `current_semester` VARCHAR(30) NULL,
    `allow_student_update` TINYINT(1) NOT NULL DEFAULT 0,
    `setting_key` VARCHAR(100) NULL,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings`
    (`id`, `current_sy`, `current_sem`, `current_academic_year`, `current_semester`, `allow_student_update`)
VALUES
    (1, '2026-2027', '1st Semester', '2026-2027', '1st Semester', 0);

CREATE TABLE IF NOT EXISTS `system_storage` (
    `id` INT NOT NULL,
    `total_storage_tb` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
    `student_storage_gb` DECIMAL(10,2) NOT NULL DEFAULT 500.00,
    `professor_storage_gb` DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `system_storage`
    (`id`, `total_storage_tb`, `student_storage_gb`, `professor_storage_gb`)
VALUES
    (1, 10.00, 500.00, 1000.00);

CREATE TABLE IF NOT EXISTS `system_limits` (
    `id` INT NOT NULL,
    `max_users` INT NOT NULL DEFAULT 1000,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `system_limits` (`id`, `max_users`) VALUES (1, 1000);

-- =========================================================
-- LOGIN SECURITY
-- =========================================================
CREATE TABLE IF NOT EXISTS `login_security_control` (
    `id` TINYINT UNSIGNED NOT NULL,
    `login_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `lock_reason` VARCHAR(500) NULL,
    `updated_by` INT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `login_security_control` (`id`, `login_locked`) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS `login_security_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email_attempted` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(500) NULL,
    `attempt_status` VARCHAR(50) NOT NULL,
    `details` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_security_created_at` (`created_at`),
    KEY `idx_login_security_email` (`email_attempted`),
    KEY `idx_login_security_status` (`attempt_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_security_blocklist` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `blocked_ip` VARCHAR(45) NOT NULL,
    `email_attempted` VARCHAR(255) NULL,
    `alert_id` BIGINT UNSIGNED NULL,
    `blocked_by` INT NULL,
    `blocked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_login_security_blocked_ip` (`blocked_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
