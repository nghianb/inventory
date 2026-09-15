# Kho hàng số

Hệ thống quản lý kho nội bộ cho shop bán hàng số. Thuật ngữ nghiệp vụ nằm trong [`CONTEXT.md`](CONTEXT.md), quyết định kiến trúc trong [`docs/adr/`](docs/adr/).

Nền tảng: Laravel 13, Filament 5, PostgreSQL 17, Pest. Chạy bằng Docker Compose (PHP 8.4).

## Chạy lần đầu

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed
docker compose up -d
```

Panel ở <http://localhost:8080/admin>. Mọi nhân viên phải bật 2FA (TOTP) ngay sau lần đăng nhập đầu tiên.

## Khôi phục quyền Quản trị

Khi Quản trị tự khoá mình ngoài hệ thống (bị Khoá nhân viên, mất thiết bị 2FA), người vận hành server chạy:

```bash
docker compose run --rm app php artisan staff:recover-quan-tri chu@shop.test --unlock --reset-2fa
```

Chỉ áp dụng cho nhân viên mang Vai trò Quản trị; mỗi thao tác ghi Nhật ký bảo mật.

## Kiểm tra

```bash
docker compose run --rm app php artisan test          # Pest trên PostgreSQL (DB inventory_test)
docker compose run --rm app vendor/bin/phpstan analyse --memory-limit=1G
docker compose run --rm app vendor/bin/pint
```

## Cấu trúc

- `app/Inventory`: module nghiệp vụ Kho (Vai trò, Nhật ký bảo mật...). Filament và API chỉ là adapter mỏng gọi vào đây.
- `app/Filament`: panel quản trị.
- `database/seeders/RoleSeeder.php`: ba Vai trò Quản trị, Nhập kho, Bán hàng.
