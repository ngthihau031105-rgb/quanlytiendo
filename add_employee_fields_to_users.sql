-- Migration: thêm cột thông tin nhân viên vào bảng `users`
-- Thêm `manv`, `sdt`, `chucvu` để lưu mã nhân viên và thông tin cơ bản
-- Chạy bằng phpMyAdmin hoặc CLI; đảm bảo dùng đúng tên database (quanlytiendo hoặc quanlytiendo_db)

USE `quanlytiendo_db`;

ALTER TABLE `users`
  ADD COLUMN `manv` VARCHAR(100) NULL AFTER `name`,
  ADD COLUMN `sdt` VARCHAR(32) NULL AFTER `manv`,
  ADD COLUMN `chucvu` VARCHAR(255) NULL AFTER `sdt`;

-- Nếu DB bạn đang dùng là `quanlytiendo`, thay USE hoặc chạy tương tự trên DB đó.
