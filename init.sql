-- Database initialization for QuanLyTienDo
-- Import this file in phpMyAdmin or run with mysql client

CREATE DATABASE IF NOT EXISTS `quanlytiendo_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `quanlytiendo_db`;

-- Users table: passwords stored as SHA2(...,256) hash
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` CHAR(64) NOT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `role` ENUM('manager','employee') NOT NULL DEFAULT 'employee',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projects table
CREATE TABLE `projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories / Hạng mục
CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `fk_categories_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks / Hạng mục con / Công việc
CREATE TABLE `tasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `progress` TINYINT UNSIGNED DEFAULT 0,
  `status` VARCHAR(50) DEFAULT 'in_progress',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_assigned` (`assigned_to`),
  CONSTRAINT `fk_tasks_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_user` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports (simple table for exported reports)
CREATE TABLE `reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reports_project` (`project_id`),
  CONSTRAINT `fk_reports_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reports_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample demo data (users use SHA2 hash so DB computes hash on import)
INSERT INTO `users` (`email`, `password_hash`, `name`, `role`) VALUES
  ('admin@congty.com', SHA2('admin123', 256), 'Quản trị viên', 'manager'),
  ('user@congty.com', SHA2('user123', 256), 'Nhân viên Demo', 'employee');

INSERT INTO `projects` (`name`, `description`, `start_date`, `end_date`, `status`) VALUES
  ('Vinhomes Grand Park - Tòa S1', 'Dự án mẫu', '2024-01-01', '2025-12-31', 'active'),
  ('Sun Grand City - Tòa A2', 'Dự án mẫu 2', '2024-03-01', '2025-10-31', 'active');

-- Add categories and tasks sample
INSERT INTO `categories` (`project_id`, `name`, `description`) VALUES
  (1, 'Hoàn thiện PCCC', 'Hạng mục PCCC cho Tòa S1'),
  (1, 'Sơn hoàn thiện', 'Sơn và hoàn thiện nội thất');

INSERT INTO `tasks` (`category_id`, `title`, `description`, `assigned_to`, `start_date`, `end_date`, `progress`, `status`) VALUES
  (1, 'Lắp đặt hệ thống PCCC', 'Lắp đặt hệ thống theo bản vẽ', 2, '2024-05-01', '2024-06-15', 60, 'in_progress'),
  (2, 'Sơn lót tường ngoài', 'Sơn lót mặt ngoài tòa nhà', NULL, '2024-07-01', '2024-07-20', 10, 'in_progress');

-- End of init.sql
