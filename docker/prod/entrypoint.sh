#!/bin/sh
set -e

# Volume gắn vào /app/storage/app có thể rỗng ở lần chạy đầu.
mkdir -p storage/app/intake \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

# Cache cấu hình lúc khởi động, KHÔNG lúc build: cache lúc build sẽ đóng băng env của máy build
# — kể cả khoá mã hoá — vào image rồi đẩy lên registry, và đổi secret trên server sẽ vô tác dụng.
php artisan config:cache

exec "$@"
