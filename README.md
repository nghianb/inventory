# Kho hàng số

Hệ thống quản lý kho nội bộ cho shop bán hàng số. Thuật ngữ nghiệp vụ nằm trong [`CONTEXT.md`](CONTEXT.md), quyết định kiến trúc trong [`docs/adr/`](docs/adr/).

Nền tảng: Laravel 13, Filament 5, PostgreSQL 17, Pest. Chạy bằng Docker Compose (PHP 8.4).

## Chạy lần đầu

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
for k in CONTENT HMAC BACKUP; do
  sed -i "s|^INVENTORY_${k}_KEY=.*|INVENTORY_${k}_KEY=1:base64:$(openssl rand -base64 32)|" .env
done
docker compose run --rm app php artisan migrate --seed
docker compose run --rm app php artisan inventory:keys:register
docker compose up -d
```

Panel ở <http://localhost:8080/admin>. Mọi nhân viên phải bật 2FA (TOTP) ngay sau lần đăng nhập đầu tiên.

## Khoá mã hoá

Khoá nội dung, khoá HMAC và khoá backup nằm trong `.env`, tách khỏi `APP_KEY` (xem ADR 0001). Mỗi khoá có phiên bản; DB chỉ lưu dấu vân tay của khoá, không lưu giá trị. Giữ bản sao khoá ngoài server, tách khỏi backup: mất khoá nội dung là mất toàn bộ hàng.

- `php artisan inventory:keys:register`: đăng ký dấu vân tay các khoá mới (lần đầu, hoặc khi thêm phiên bản). Không ghi đè dấu vân tay đã có; ghi Nhật ký bảo mật.
- `php artisan inventory:keys:verify`: chạy trước khi web server khởi động; queue worker cũng tự kiểm tra khi khởi động. Khoá không khớp thì từ chối chạy.

## Nhập hàng

- Lô nhập gồm nhiều Dòng nhập, mỗi dòng một Sản phẩm: dán văn bản hoặc upload CSV/XLSX (dòng tiêu đề trùng định danh hoặc tên Trường nội dung; cột `slot`, `han_su_dung`, `gia_von` ghi đè Dòng nhập; cột khác bị bỏ qua và báo lại).
- Giới hạn mỗi file hoặc danh sách dán: `INVENTORY_INTAKE_MAX_LINES` (mặc định 20.000 dòng), `INVENTORY_INTAKE_MAX_BYTES` (mặc định 10 MB).
- Huỷ nhập (chỉ Quản trị, ở màn Lô nhập đã xác nhận): theo một Dòng nhập hoặc cả Lô nhập. Chỉ Đơn vị hàng Hoạt động mà mọi Slot còn Còn hàng chuyển sang Đã huỷ nhập và nhả Khoá chống trùng (cờ `holds_dedupe_key`, cả hai partial unique index đều tính cờ này), nên nhập lại được đúng mã; bản ghi giữ lại, mỗi lần chuyển trạng thái ghi Sổ biến động kho. Huỷ nhập một Tài khoản nhập lại thì Đơn vị hàng cũ chiếm lại khoá.
- Nội dung chờ xác nhận nằm mã hoá ở `storage/app/intake` (đổi bằng `INVENTORY_INTAKE_PATH`), trên ổ local, **không đưa vào backup**. Xoá khi xác nhận hoặc bỏ; service `scheduler` chạy `inventory:intake:purge` mỗi giờ để cho hết hạn bản kiểm tra quá `INVENTORY_INTAKE_PENDING_TTL_HOURS` (mặc định 24) giờ.

## Xuất kho

- Kênh bán (chỉ Quản trị): loại thủ công, cờ bắt buộc mã đơn ngoài; kênh ngừng dùng thì ẩn, không xoá.
- Phiếu xuất (Quản trị, Bán hàng): mã đơn ngoài duy nhất trong kênh, bị chiếm khi phiếu được tạo; để trống ở kênh không bắt buộc thì tự sinh `PX-YYYYMMDD-NNNN` (bộ đếm theo ngày ở bảng `dispatch_ref_counters`). Tối đa `INVENTORY_DISPATCH_MAX_SLOTS` (mặc định 1.000) Slot mỗi phiếu.
- `ManualDispatch` chọn Slot theo Thứ tự xuất bằng `FOR UPDATE SKIP LOCKED` và giao ngay trong một transaction: cả phiếu giao đủ hoặc thất bại, mỗi Slot Còn hàng → Đã giao ghi Sổ biến động kho, mỗi lần Giao hàng giữ thời hạn bảo hành của Sản phẩm lúc giao. Tồn bán được định nghĩa một chỗ ở `SellableStock`.
- Màn kết quả hiện nội dung đầy đủ đúng một lần cho người tạo phiếu, ghi Nhật ký xem mã ngữ cảnh Giao hàng cho mỗi Slot; Copy chạy ở trình duyệt, không ghi nhật ký. Tải lại trang thì không hiện nội dung nữa.
- Sản phẩm đã có Phiếu xuất không đổi được Mã sản phẩm.

## Xem mã

- Nội dung đầy đủ chỉ trả qua module Kho (`ContentReveal`, `BatchIntake::rejectedLines`); mỗi lần xem ghi Nhật ký xem mã (bảng `reveal_log_entries`, chỉ-ghi-thêm, chặn cả bằng trigger) trong cùng transaction, trước khi trả nội dung.
- Quản trị xem Slot Còn hàng ở chi tiết Đơn vị hàng, bắt buộc nhập lý do. Chi tiết Đơn vị hàng và màn Nhật ký xem mã chỉ Quản trị thấy lịch sử xem.
- Người tạo Lô nhập tải CSV dòng bị bỏ ở màn xem trước và trong `INVENTORY_INTAKE_REJECTED_DOWNLOAD_MINUTES` (mặc định 30) phút sau khi xác nhận. Dòng bị bỏ lưu mã hoá trên disk nhập hàng trong thời hạn đó rồi bị `inventory:intake:purge` xoá.

## Khôi phục quyền Quản trị

Khi Quản trị tự khoá mình ngoài hệ thống (bị Khoá nhân viên, mất thiết bị 2FA), người vận hành server chạy:

```bash
docker compose run --rm app php artisan staff:recover-quan-tri chu@shop.test --unlock --reset-2fa
```

Chỉ áp dụng cho nhân viên mang Vai trò Quản trị; mỗi thao tác ghi Nhật ký bảo mật.

## Kiểm tra

```bash
docker compose run --rm app php artisan test          # Pest trên PostgreSQL (DB inventory_test); suite Concurrency fork tiến trình (cần pcntl)
docker compose run --rm app vendor/bin/phpstan analyse --memory-limit=1G
docker compose run --rm app vendor/bin/pint
```

## Cấu trúc

- `app/Inventory`: module nghiệp vụ Kho (Vai trò, Nhật ký bảo mật, Danh mục Sản phẩm và Nhà cung cấp, Nhập hàng, Sổ biến động kho...). Filament và API chỉ là adapter mỏng gọi vào đây.
- `app/Filament`: panel quản trị.
- `database/seeders/RoleSeeder.php`: ba Vai trò Quản trị, Nhập kho, Bán hàng.
