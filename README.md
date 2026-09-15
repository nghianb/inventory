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
- Màn kết quả hiện nội dung đầy đủ đúng một lần cho người tạo phiếu, ghép theo Mẫu giao hàng của Sản phẩm (biến `{{dinh_danh_truong}}`, `{{san_pham}}`, `{{ma_don}}`, `{{han_su_dung}}`, `{{han_bao_hanh}}`; chưa có mẫu thì mỗi trường một dòng "Tên trường: giá trị"), ghi Nhật ký xem mã ngữ cảnh Giao hàng cho mỗi Slot; Copy chạy ở trình duyệt, không ghi nhật ký. Tải lại trang thì không hiện nội dung nữa.
- Phiếu từ `INVENTORY_DISPATCH_RESULT_MASK_SLOTS` (mặc định 50) Slot trở lên: màn kết quả chỉ hiện bảng dạng che, không Copy từng Slot; Copy tất cả gọi server và ghi Nhật ký xem mã cho mọi Slot.
- Tải TXT (theo mẫu) và CSV (mỗi Slot một dòng, mỗi Trường nội dung một cột) sinh lúc tải, không lưu trên server; chỉ người tạo phiếu, trong `INVENTORY_DISPATCH_RESULT_DOWNLOAD_MINUTES` (mặc định 30) phút sau khi màn kết quả hiện; mỗi lần tải ghi Nhật ký xem mã cho mọi Slot.
- Sản phẩm đã có Phiếu xuất không đổi được Mã sản phẩm.
- Trang xem Phiếu xuất: bảng Lần giao dạng che, Xem mã từng lần giao có xác nhận (`ContentReveal::revealDelivery`): Bán hàng xem mọi phiếu trong Hạn bảo hành (tính cả ngày hết hạn), quá hạn chỉ Quản trị; mỗi lần ghi Nhật ký xem mã ngữ cảnh Giao hàng.
- Mã đơn ngoài đã chiếm lưu ở `dispatch_external_refs` (chỉ-ghi-thêm): mã bị chiếm vĩnh viễn trong kênh, kể cả sau khi phiếu sửa sang mã khác; tạo phiếu và sửa phiếu đều chiếm mã qua `ExternalRefs::claim`.
- Giao thêm (Quản trị, Bán hàng; nút ở trang xem phiếu Hoàn tất): mở lại trang tạo với `?giao-them={id}`, Thông tin đơn chỉ đọc. `ManualDispatch::addLines` khoá phiếu, kiểm tra như tạo phiếu (giới hạn Slot tính cả Slot đã giao; Sản phẩm trùng Dòng xuất cũ được), chọn Slot cùng Thứ tự xuất; phần thêm giao đủ hoặc thất bại, phiếu cũ giữ nguyên. Dòng xuất mới có Loại Giao thêm. Màn kết quả của phiếu chuyển sang lần Giao thêm (`dispatches.result_by`, `result_from_line_id`): chỉ hiện Slot vừa giao, chỉ cho người vừa Giao thêm, thời hạn tải tính lại từ lúc màn này hiện.
- Giao thay (modal ở bảng Lần giao, `CorrectiveDelivery`): Bán hàng trong `INVENTORY_DISPATCH_CORRECTIVE_HOURS` (mặc định 24) giờ kể từ lúc giao, quá hạn chỉ Quản trị kèm lý do. Khoá phiếu và Slot, Huỷ hàng Slot với lý do Giao nhầm rồi chọn Slot theo Thứ tự xuất (`SlotPicker`, dùng chung với tạo phiếu và Giao thêm), bỏ qua Đơn vị hàng vừa giao nhầm (Slot của một Tài khoản chung nội dung); Sản phẩm gốc đã Ngừng bán vẫn giao thay được; lần giao mới trỏ về lần giao bị huỷ (`deliveries.corrects_delivery_id`, unique). Cùng Sản phẩm thì vào Dòng xuất gốc; Sản phẩm khác thì thêm Dòng xuất loại Giao thay, chưa có Giá bán. Nội dung đã gửi thì chọn Giữ nguyên hoặc Huỷ hàng cả Đơn vị hàng; modal liệt kê Lần giao bị ảnh hưởng (`AffectedDelivery`), không tự Báo lỗi hay Đổi hàng. Xong thì hiện mã lần giao mới như Xem mã.
- Sửa phiếu Hoàn tất (`DispatchEditor`): mã đơn ngoài (phải chưa bị phiếu khác chiếm), khách, ghi chú, Giá bán; không sửa Kênh bán, Dòng xuất, Slot. Mỗi trường đổi ghi một dòng vào `dispatch_revisions` (chỉ-ghi-thêm, chặn bằng trigger), tách khỏi Sổ biến động kho.
- Danh sách Phiếu xuất tìm theo mã đơn ngoài, khách, Kênh bán, người tạo, khoảng ngày và trường không nhạy cảm của hàng đã giao. Tìm theo Khoá chống trùng (`DeliveryLookup`) qua modal: chuỗi dán vào chuẩn hoá theo từng Sản phẩm, so HMAC khớp chính xác, trả lần giao và phiếu, không hiện nội dung, không ghi Nhật ký xem mã.

## Huỷ hàng

- Chỉ Quản trị, ở chi tiết Đơn vị hàng (cả đơn vị) và bảng Slot (`StockVoid`), lý do Giao nhầm, Lộ nội dung hoặc Ngừng kinh doanh lô kèm ghi chú. Slot Còn hàng hoặc Đã giao → Đã huỷ; Đơn vị hàng Hoạt động → Đã huỷ cùng các Slot Còn hàng, Slot Đã giao giữ nguyên. Lý do và thời điểm lưu ở `void_reason`, `voided_at` (để tính Tổn thất theo lý do); ai và ghi chú nằm trong Sổ biến động kho. Không giải phóng Khoá chống trùng.

## Báo lỗi

- Tạo (Quản trị, Bán hàng) ở bảng Lần giao của Phiếu xuất: từng dòng hoặc chọn nhiều dòng, mỗi Slot một Báo lỗi Chờ xác minh (`DefectReporting::report`), mô tả bắt buộc, ảnh tuỳ chọn: form không lưu file, `DefectReporting` chỉ lưu vào disk `local` (thư mục `defect-reports`, private) sau khi kiểm tra xong và xoá lại nếu transaction lỗi; service `scheduler` chạy `inventory:defect-reports:purge` mỗi giờ để xoá ảnh cũ hơn một giờ không còn Báo lỗi nào trỏ tới. Cả phần tạo đủ hoặc thất bại. Bán hàng chỉ tạo trong Hạn bảo hành (tính cả ngày hết hạn) của lần giao có thời hạn bảo hành khác 0; Quản trị vượt được kèm lý do (`warranty_override_reason`, chỉ lưu cho lần giao ngoài bảo hành).
- Mỗi Slot tối đa một Báo lỗi Chờ xác minh hoặc Xác nhận (partial unique index `defect_reports_one_open_per_slot`); tạo lại được sau Bác bỏ, form hiện các lần Bác bỏ trước.
- Trong lúc Chờ xác minh, Slot Còn hàng của cùng Đơn vị hàng không thuộc Tồn bán được (`SellableStock`), nên không được chọn khi xuất; tạo Báo lỗi khoá Đơn vị hàng như Huỷ hàng để phiếu đang chọn Slot của nó giao xong trước. Bác bỏ thì mở bán lại. Slot đang có Báo lỗi Chờ xác minh không Huỷ hàng hay Giao thay được (`StockVoid::pendingDefectReportId`); phải xác minh trước.
- Trang Báo lỗi (menu Báo lỗi): Xem mã (`ContentReveal::revealDefectReport`, chỉ khi Chờ xác minh, ghi Nhật ký xem mã ngữ cảnh Báo lỗi), Xác nhận hoặc Bác bỏ với ghi chú bắt buộc; người tạo tự xác minh được. Xác nhận chọn Phạm vi lỗi: cả Đơn vị hàng (mặc định; Đơn vị hàng Hoạt động → Lỗi, ghi Sổ biến động kho; Đơn vị hàng Đã huỷ chỉ Xác nhận được chỉ Slot) hoặc chỉ Slot (Đơn vị hàng giữ nguyên).
- Đơn vị hàng chuyển Lỗi theo Báo lỗi: liệt kê Lần giao bị ảnh hưởng và cho tạo Báo lỗi hàng loạt tự Xác nhận cả Đơn vị hàng (`confirmAffected`, `source_defect_report_id`), cùng quy tắc Hạn bảo hành; modal chỉ liệt kê lần giao còn tạo được Báo lỗi. Chi tiết Đơn vị hàng Lỗi có mục Lần giao bị ảnh hưởng kèm trạng thái Báo lỗi (chỉ Bán hàng và Quản trị thấy vì có thông tin khách). Không tự Đổi hàng.
- Tab tồn đọng: Báo lỗi Chờ xác minh quá `INVENTORY_DEFECT_BACKLOG_HOURS` (mặc định 24) giờ kể từ lúc tạo.

## Đổi hàng

- Báo lỗi Xác nhận (kể cả tự Xác nhận hàng loạt) có Kết quả xử lý Chờ đổi (`defect_reports.resolution`); tab Chờ đổi ở danh sách Báo lỗi. Từ Chờ đổi sang Đã đổi (qua Đổi hàng) hoặc Không đổi (`DefectReporting::declineReplacement`, lý do bắt buộc, cờ `refunded` khi khách được hoàn tiền ngoài kho); không đổi lại được. Không đổi kèm hoàn tiền thì panel nhắc sửa Giá bán của Dòng xuất qua Sửa phiếu (có lịch sử sửa phiếu).
- Đổi hàng ở trang Báo lỗi (`ReplacementDelivery`): khoá Báo lỗi rồi lần giao gốc của chuỗi, chọn Slot (`SlotPicker::replacementCandidates`, kể cả Sản phẩm Ngừng bán nếu cùng Sản phẩm, bỏ qua Đơn vị hàng lỗi) theo Thứ tự xuất nhưng thay Hạn còn lại tối thiểu bằng Hạn sử dụng ≥ Hạn bảo hành kế thừa. Không có Slot phủ đủ thì modal cảnh báo và chỉ đổi khi chấp nhận Slot hạn ngắn hơn (chọn Slot hạn dài nhất; Hạn bảo hành không vượt Hạn sử dụng của Slot đó). Mặc định cùng Sản phẩm; Sản phẩm khác bắt buộc lý do.
- Lần giao mới vào một Dòng xuất loại Đổi hàng (không có Giá bán) của Phiếu xuất gốc, lưu Hạn bảo hành kế thừa của lần giao gốc (`deliveries.warranty_ends_on`). Bảng `replacements`: Báo lỗi (unique), lần giao gốc của chuỗi và lần đổi thứ mấy (unique theo cặp), lần giao mới, lý do đổi Sản phẩm, chấp nhận hạn ngắn, Chi phí đổi hàng (Giá vốn Slot thay thế) gắn Sản phẩm và Nhà cung cấp của Đơn vị hàng lỗi (kể cả Phạm vi chỉ Slot). Panel không hiện Chi phí đổi hàng hay Nhà cung cấp. Dòng xuất loại Đổi hàng không nhận Giá bán ở Sửa phiếu (`DispatchEditor`), và lần giao Đổi hàng không Giao thay được (sẽ mất Hạn bảo hành kế thừa và vị trí trong chuỗi).
- Lần đổi 1–2 của chuỗi Bán hàng tự làm. Từ lần 3 modal cảnh báo và nút gửi bị khoá: Bán hàng bấm Yêu cầu Quản trị duyệt (`ReplacementDelivery::requestApproval`, Báo lỗi vào tab Chờ Quản trị duyệt), Quản trị bấm Duyệt Đổi hàng (`approve`, `defect_reports.replacement_approved_by`), rồi Bán hàng Đổi hàng như thường để nhận mã gửi khách. Quản trị tự Đổi hàng thì không cần duyệt riêng; `replacements.approved_by` ghi người duyệt cho mọi lần đổi từ thứ 3.
- Xong thì hiện mã Slot mới qua màn kết quả Đổi hàng (`ContentReveal::revealReplacement`): chỉ người vừa Đổi hàng, một lần, ghi Nhật ký xem mã ngữ cảnh Đổi hàng; xem lại sau đó qua Xem mã của lần giao. Bảng Lần giao của Phiếu xuất có cột Đổi hàng cho.

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
