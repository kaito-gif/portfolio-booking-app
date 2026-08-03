-- テスト用DBを開発用DBと分離する。
-- phpunit.xml が RefreshDatabase で truncate する対象は必ずこちらに向ける
-- (開発用DBを指してしまうと `php artisan test` のたびにシードデータが消える)。
CREATE DATABASE IF NOT EXISTS booking_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON booking_testing.* TO 'booking'@'%';
FLUSH PRIVILEGES;
