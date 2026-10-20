-- Migration: thêm cột users_id vào bảng nhanvien và tạo ràng buộc khóa ngoại
-- Chạy trong phpMyAdmin hoặc bằng mysql client

-- Chú ý: project có thể dùng database tên `quanlytiendo` hoặc `quanlytiendo_db`.
-- Nếu cần, thay dòng USE cho phù hợp trước khi chạy.

USE `quanlytiendo_db`;

ALTER TABLE `nhanvien`
  ADD COLUMN `users_id` INT UNSIGNED NULL AFTER `Tennv`;

-- Thêm khoá ngoại, nếu bảng users tồn tại
ALTER TABLE `nhanvien`
  ADD CONSTRAINT `fk_nhanvien_users` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Nếu bạn sử dụng DB `quanlytiendo`, thay `USE` cho đúng và chạy lại file.
