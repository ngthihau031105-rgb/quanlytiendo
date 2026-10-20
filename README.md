# Import hướng dẫn — `db/init.sql`

File `init.sql` chứa các lệnh để tạo database mẫu `quanlytiendo_db` cùng các bảng: `users`, `projects`, `categories`, `tasks`, `reports` và một vài bản ghi demo.

Hướng dẫn import (phpMyAdmin):

1. Mở XAMPP Control Panel, đảm bảo Apache + MySQL đang chạy.
2. Mở trình duyệt tới `http://localhost/phpmyadmin`.
3. Chọn tab `Import` → Click `Choose File` → chọn `c:\xampp\htdocs\quanlytiendo\db\init.sql` (hoặc tải file lên máy chủ nếu cần).
4. Click `Go` để chạy SQL. Sau khi hoàn tất, database `quanlytiendo_db` sẽ xuất hiện trong phpMyAdmin.

Lưu ý về mật khẩu:

- Tệp dùng `SHA2('password',256)` để hash mật khẩu lúc import, vì vậy cột `password_hash` chứa hash SHA-256 (64 ký tự hex). Bạn cần đảm bảo phần đăng nhập (PHP) kiểm tra bằng SHA2 hoặc băm password tương tự trước so sánh.

Ví dụ xác thực (PHP, PDO) dùng cùng thuật toán SHA2:

```php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=quanlytiendo_db;charset=utf8mb4', 'root', '');
$stmt = $pdo->prepare('SELECT id, email, role, name FROM users WHERE email = ? AND password_hash = SHA2(?, 256) LIMIT 1');
$stmt->execute([$email, $password]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user) {
    // đăng nhập thành công
}
```

Tùy chọn nâng cao:

- Thay SHA2 bằng `password_hash()` / `password_verify()` của PHP để dùng bcrypt (an toàn hơn). Nếu muốn, tôi có thể cập nhật `login.php` để dùng bcrypt và cung cấp script tạo user với `password_hash()`.
