-- =========================================================
-- NoteSphere - Announcement Tables
-- Database: 1notesphere_db
--
-- Missing tables required by:
--   professor/add_announcement.php
--   dashboard/professor_announcements.php
--   dashboard/submit_announcement_work.php
--   admin/manage_announcements.php
--   system_settings (semester / academic year used by announcements)
-- =========================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. ANNOUNCEMENTS
-- =========================================================

CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `professor_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(100) NOT NULL DEFAULT '',
    `target_course` VARCHAR(100) NOT NULL DEFAULT 'All Courses',
    `year_level` VARCHAR(50) NOT NULL DEFAULT 'All Years',
    `section` VARCHAR(100) NOT NULL DEFAULT 'All',
    `message` LONGTEXT NOT NULL,
    `attachment` VARCHAR(500) DEFAULT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'Announcement',
    `meet_link` VARCHAR(500) DEFAULT NULL,
    `quiz_link` VARCHAR(500) DEFAULT NULL,
    `youtube_link` VARCHAR(500) DEFAULT NULL,
    `deadline` DATETIME DEFAULT NULL,
    `semester` VARCHAR(50) DEFAULT NULL,
    `academic_year` VARCHAR(20) DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_announcements_professor` (`professor_id`),
    KEY `idx_announcements_status` (`status`),
    KEY `idx_announcements_period` (`semester`, `academic_year`),
    KEY `idx_announcements_target` (`target_course`, `year_level`, `section`),
    CONSTRAINT `fk_announcements_professor`
        FOREIGN KEY (`professor_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 2. ANNOUNCEMENT ATTACHMENTS
-- One announcement may have several uploaded files.
-- =========================================================

CREATE TABLE IF NOT EXISTS `announcement_attachments` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `announcement_id` INT(10) UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `original_name` VARCHAR(255) DEFAULT NULL,
    `file_size` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_announcement_attachments_announcement` (`announcement_id`),
    CONSTRAINT `fk_announcement_attachments_announcement`
        FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 3. ANNOUNCEMENT READS
-- Tracks which student has already opened an announcement.
-- Identified by the (announcement_id, student_id) pair.
-- =========================================================

CREATE TABLE IF NOT EXISTS `announcement_reads` (
    `announcement_id` INT(10) UNSIGNED NOT NULL,
    `student_id` INT(10) UNSIGNED NOT NULL,
    `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`announcement_id`, `student_id`),
    KEY `idx_announcement_reads_student` (`student_id`),
    CONSTRAINT `fk_announcement_reads_announcement`
        FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_announcement_reads_student`
        FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 4. ANNOUNCEMENT SUBMISSIONS
-- Student work submitted for an announcement.
-- =========================================================

CREATE TABLE IF NOT EXISTS `announcement_submissions` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `announcement_id` INT(10) UNSIGNED NOT NULL,
    `student_id` INT(10) UNSIGNED NOT NULL,
    `submission_text` LONGTEXT DEFAULT NULL,
    `file_path` VARCHAR(500) DEFAULT NULL,
    `student_note` TEXT DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'submitted',
    `grade` VARCHAR(50) DEFAULT NULL,
    `feedback` TEXT DEFAULT NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_announcement_student` (`announcement_id`, `student_id`),
    KEY `idx_announcement_submissions_student` (`student_id`),
    KEY `idx_announcement_submissions_status` (`status`),
    CONSTRAINT `fk_announcement_submissions_announcement`
        FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_announcement_submissions_student`
        FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 5. SYSTEM SETTINGS
-- Holds the active semester and academic year stamped on
-- every published announcement.
-- =========================================================

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `current_sy` VARCHAR(20) DEFAULT NULL,
    `current_sem` VARCHAR(30) DEFAULT NULL,
    `current_academic_year` VARCHAR(20) DEFAULT NULL,
    `current_semester` VARCHAR(30) DEFAULT NULL,
    `allow_student_update` TINYINT(1) NOT NULL DEFAULT 0,
    `setting_key` VARCHAR(100) DEFAULT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings`
    (`id`, `current_sy`, `current_sem`, `current_academic_year`, `current_semester`, `allow_student_update`)
VALUES
    (1, '2026-2027', '1st Semester', '2026-2027', '1st Semester', 0)
ON DUPLICATE KEY UPDATE
    `current_sy` = VALUES(`current_sy`),
    `current_sem` = VALUES(`current_sem`),
    `current_academic_year` = VALUES(`current_academic_year`),
    `current_semester` = VALUES(`current_semester`);
