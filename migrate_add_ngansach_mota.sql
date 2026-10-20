-- Add NGANSACH (budget) and MOTA (description) to congtrinh table
ALTER TABLE `congtrinh`
  ADD COLUMN IF NOT EXISTS `NGANSACH` DECIMAL(15,2) DEFAULT NULL COMMENT 'Ngân sách (VND)',
  ADD COLUMN IF NOT EXISTS `MOTA` TEXT DEFAULT NULL COMMENT 'Mô tả công trình';

-- If your MySQL version does not support IF NOT EXISTS for ADD COLUMN,
-- run these two statements instead (one at a time):
-- ALTER TABLE `congtrinh` ADD COLUMN `NGANSACH` DECIMAL(15,2) DEFAULT NULL COMMENT 'Ngân sách (VND)';
-- ALTER TABLE `congtrinh` ADD COLUMN `MOTA` TEXT DEFAULT NULL COMMENT 'Mô tả công trình';
