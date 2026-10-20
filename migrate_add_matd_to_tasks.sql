-- Migration: add matd column to tasks and make it unique
-- Run this file in phpMyAdmin or using the provided runner script migrate_add_matd.php

START TRANSACTION;

-- 1) add nullable column
ALTER TABLE `tasks` ADD COLUMN `matd` VARCHAR(100) NULL DEFAULT NULL;

-- 2) populate matd for existing rows where null using id-based value
UPDATE `tasks` SET `matd` = CONCAT('TD', LPAD(id, 6, '0')) WHERE `matd` IS NULL OR `matd` = '';

-- 3) make column NOT NULL
ALTER TABLE `tasks` MODIFY `matd` VARCHAR(100) NOT NULL;

-- 4) add unique index
ALTER TABLE `tasks` ADD UNIQUE INDEX `ux_tasks_matd` (`matd`);

COMMIT;
