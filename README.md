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
docker compose run --rm app php artisan staff:create-first-owner
docker compose up -d
```

`staff:create-first-owner` hỏi tên, email và mật khẩu ban đầu ngay trên terminal, nên mật khẩu không nằm lại trong lịch sử shell. Lệnh chạy được đúng một lần: kho đã có Quản trị (kể cả Quản trị đang bị Khoá nhân viên) thì nó từ chối, vì từ đó Quản trị tự tạo nhân viên ở trang Nhân viên.

Panel ở <http://localhost:8080/admin>. Mọi nhân viên phải bật 2FA (TOTP) ngay sau lần đăng nhập đầu tiên.

## Khoá mã hoá

Khoá nội dung, khoá HMAC và khoá backup nằm trong `.env`, tách khỏi `APP_KEY` (xem ADR 0001). Mỗi khoá có phiên bản; DB chỉ lưu dấu vân tay của khoá, không lưu giá trị. Giữ bản sao khoá ngoài server, tách khỏi backup: mất khoá nội dung là mất toàn bộ hàng.

- `php artisan inventory:keys:register`: đăng ký dấu vân tay các khoá mới (lần đầu, hoặc khi thêm phiên bản). Không ghi đè dấu vân tay đã có; ghi Nhật ký bảo mật.
- `php artisan inventory:keys:verify`: chạy trước khi web server khởi động; queue worker cũng tự kiểm tra khi khởi động. Khoá không khớp thì từ chối chạy.
- `php artisan inventory:keys:rotate <content|hmac|backup>`: xoay một khoá sang phiên bản mới, khi nghi lộ khoá hoặc khi người giữ khoá rời đi. Sửa `.env` trước (thêm phiên bản mới; khoá nội dung cũ chuyển sang `INVENTORY_CONTENT_PREVIOUS_KEYS`) rồi chạy lệnh **ngay**: từ lúc `.env` đổi tới lúc lệnh đăng ký xong dấu vân tay, mọi tiến trình ghi đều từ chối chạy. Mỗi lần xoay ghi hai dòng Nhật ký bảo mật (bắt đầu và kết thúc) kèm loại khoá, phiên bản cũ/mới, dấu vân tay và mốc thời gian, không bao giờ kèm giá trị khoá. Lệnh bị ngắt giữa chừng thì cứ chạy lại: nó chỉ làm nốt phần còn lại.
  - `content`: mã hoá lại từng chunk 500 Đơn vị hàng (`secret_key_version` trên bản ghi cho biết còn ai ở khoá cũ). Kho chạy bình thường suốt lúc chạy vì bản ghi chưa mã hoá lại vẫn đọc được bằng khoá cũ. Giữ khoá cũ trong `INVENTORY_CONTENT_PREVIOUS_KEYS` thêm ít nhất `INVENTORY_INTAKE_PENDING_TTL_HOURS` giờ: nội dung Lô nhập chờ xác nhận nằm trên disk còn mã hoá bằng khoá đó.
  - `hmac`: tính lại `stock_units.dedupe_hash` từ chính nội dung hàng, không cần khoá HMAC cũ. Nhập hàng tạm dừng chừng nào còn Đơn vị hàng ở `dedupe_hmac_version` cũ (kho không giữ song song hai hash), xuất kho vẫn chạy vì Thứ tự xuất không đọc Khoá chống trùng; tra cứu theo Khoá chống trùng không tìm thấy trong lúc lệnh chạy dở. Xem ADR 0003.
  - `backup`: chỉ thêm dấu vân tay phiên bản mới, không đụng dữ liệu trong app; backup cũ vẫn cần khoá cũ.

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

## API xuất kho cho website

- Kênh bán loại API (chỉ Quản trị): hạn Giữ hàng (`hold_minutes`, mặc định `INVENTORY_API_HOLD_MINUTES` = 15 phút, trần 1.440) và cờ bắt buộc Giá bán. Khoá API (`ApiKeys`): bí mật hiện một lần, DB lưu SHA-256, tối đa hai khoá cùng hoạt động để xoay khoá không làm đứt website; tạo, xoay, thu hồi đều ghi Nhật ký bảo mật. Kênh đã có Phiếu xuất hoặc Khoá API thì không đổi loại được.
- Xác thực `Authorization: Bearer <khoá>` (`AuthenticateApiKey`); rate limit `INVENTORY_API_RATE_LIMIT` (mặc định 60/phút) theo từng Khoá API, và theo IP cho request chưa qua xác thực. Đường dẫn và khoá JSON dùng tiếng Anh như tên cột DB, thông báo lỗi tiếng Việt; lỗi luôn một hình dạng `{"error": {"code", "message", ...}}`.
- `GET /api/v1/stock?product_codes[]=…`: số Slot Tồn bán được theo Mã sản phẩm. Chỉ tham khảo, không giữ hàng.
- `POST /api/v1/dispatches`: một đơn, idempotent theo (Kênh bán, mã đơn ngoài). Mặc định giữ và giao ngay (phiếu Hoàn tất); `"hold": true` thì chỉ Giữ hàng — Slot Còn hàng → Đã giữ, phiếu Đang giữ kèm `hold_expires_at` theo hạn của kênh. Gửi lại cùng mã đơn với Dòng xuất giống hệt trả lại đúng phiếu ấy **ở trạng thái hiện tại**, không giữ hay giao thêm; Dòng xuất khác là `dispatch_conflict`. Mã đơn chỉ bị chiếm khi phiếu được tạo, nên đơn hết hàng gửi lại được.
- `POST /api/v1/dispatches/{mã đơn}/confirm`: phiếu Đang giữ thì giao đúng Slot đã giữ (Đã giữ → Đã giao, không chọn lại, nên khách nhận đúng phần hàng đã giữ cho mình); phiếu Hết hạn giữ thì chọn lại theo Thứ tự xuất, không đủ thì `out_of_stock` và phiếu ở nguyên Hết hạn giữ; phiếu Hoàn tất trả lại nội dung cũ, không giao thêm.
- `POST /api/v1/dispatches/{mã đơn}/cancel`: nhả mọi Slot đang giữ, phiếu Đã huỷ. Đã huỷ là trạng thái cuối — huỷ lại không đổi gì, còn xác nhận hoặc gửi lại đơn với mã ấy là `dispatch_conflict`. Phiếu Hoàn tất không huỷ được vì hàng đã ra khỏi kho.
- `GET /api/v1/dispatches/{mã đơn}`: đọc lại phiếu cho trang đơn của khách — thông tin phiếu và **mọi** lần Giao hàng kèm trạng thái (`active`, `replaced`, `voided`), gồm cả phần nhân viên Giao thêm, Giao thay hay Đổi hàng sau đó; `replaces_delivery_id` trỏ về lần giao được thay.
- Nội dung (`text` ghép theo Mẫu giao hàng, `fields` theo định danh Trường nội dung) chỉ trả cho lần giao còn `active` **và** còn trong Hạn bảo hành; lần giao đã bị thay hoặc hết bảo hành vẫn được liệt kê nhưng `text`/`fields` là `null`. Mỗi Slot có trả nội dung ghi một dòng Nhật ký xem mã ngữ cảnh Giao hàng với tác nhân là Khoá API; không trả nội dung thì không ghi.
- Slot đang giữ nằm ở `slot_holds` (một hàng mỗi Slot, xoá khi Slot được giao hoặc nhả ra; lịch sử đã có ở Sổ biến động kho). Đã giữ không thuộc Tồn bán được nên hiện tách riêng: cột Đã giữ ở bảng Sản phẩm và báo cáo Tồn kho, còn trang Phiếu xuất Đang giữ hiện hạn giữ cùng danh sách Slot đang giữ.
- Service `scheduler` chạy `inventory:dispatches:release-holds` **mỗi phút** (hạn Giữ hàng tính bằng phút, khác các job dọn dẹp chạy mỗi giờ): phiếu Đang giữ quá hạn nhả Slot về Còn hàng và chuyển Hết hạn giữ, mỗi phiếu một transaction dưới khoá hàng Phiếu xuất. Dòng Sổ biến động kho không mang tác nhân, vì hết hạn là việc của kho chứ không của nhân viên hay Khoá API nào.
- Đơn qua API dùng chung module Kho với xuất thủ công (`SlotPicker`, `DispatchWriter`, `SellableStock`, `ContentReveal`): cùng Thứ tự xuất, cùng "giữ đủ hoặc thất bại". `DispatchActor` cho phép "ai" của Phiếu xuất, Sổ biến động kho và Nhật ký xem mã là Khoá API thay vì nhân viên.

## Tạm dừng xuất kho và xử lý lộ nội dung

- Tạm dừng xuất kho (`DispatchFreeze`) là trạng thái toàn kho, giữ ở bảng một hàng `dispatch_freeze`. Chỉ Quản trị bật/tắt kèm lý do, ở trang **Tạm dừng xuất kho** (`/admin/tam-dung-xuat-kho`); mỗi lần ghi Nhật ký bảo mật (`dispatch_frozen`, `dispatch_unfrozen`). Đang dừng thì điều hướng panel hiện badge đỏ "Đang dừng" ở mọi màn.
- Chỗ chặn: `SlotPicker::lockAndPick` và `lockAndPickReplacement` — mọi đường lấy hàng theo Thứ tự xuất (tạo Phiếu xuất, Giao thêm, Giao thay, Đổi hàng, đơn API) — cộng `DispatchWriter::deliverHeld`, đường giao duy nhất không chọn lại Slot (website xác nhận phiếu Đang giữ). Cờ được đọc bằng **khoá chia sẻ trong chính transaction của lần giao**, còn bật/tắt khoá độc quyền: bật tạm dừng chờ các lần giao đang chạy dở commit xong thay vì cắt ngang, và không lần giao nào bắt đầu được sau đó.
- Không bị chặn: nhập hàng, Huỷ hàng, huỷ đơn và nhả hold của API, đọc lại phiếu, kiểm tra tồn — không cái nào lấy thêm hàng ra khỏi kho. API bị chặn trả `503` với `error.code` là `dispatch_frozen`; lý do tạm dừng là chuyện nội bộ, không trả ra cho website.
- Sau khi khôi phục từ backup, chạy ngay `php artisan inventory:dispatches:freeze` (tuỳ chọn `--reason=`): kho vào Tạm dừng xuất kho với dòng Nhật ký bảo mật không mang người thực hiện. Chạy lại không ghi đè lý do đầu tiên. Không có lệnh tắt — chỉ Quản trị tắt trong panel, sau khi đối chiếu xong các đơn phát sinh sau mốc khôi phục.
- Ghi nhận giao bù (`RecordedLostDelivery`, cùng trang): Quản trị tra Khoá chống trùng khách đang giữ (`DedupeLookup`; kết quả dạng che, không ghi Nhật ký xem mã), chọn **đích danh** một Slot Còn hàng, rồi chọn Phiếu xuất Hoàn tất đã có hoặc dựng phiếu mới (Kênh bán loại API cũng chọn được, vì đơn website cũng mất khi khôi phục), kèm mốc đã giao thật và lý do bắt buộc. Slot Còn hàng → Đã giao trong một Dòng xuất loại **Ghi nhận giao bù** — loại này có Giá bán và vào Lãi gộp cùng phần Xuất của báo cáo như Giao bán, vì nó chép lại một lần bán đã thu tiền (xem ADR 0002; danh sách loại có Giá bán nằm ở `DispatchLineKind::salePriceKinds()`). Là ngoại lệ duy nhất cho Thứ tự xuất, và là thao tác giao duy nhất làm được khi kho đang dừng.
- Huỷ hàng hàng loạt vì lộ nội dung (`ContentExposure`): chọn phạm vi **một Sản phẩm** hoặc **cả kho** (kịch bản lộ cả khoá mã hoá lẫn dữ liệu của ADR 0001), xem trước số lượng, nhập lý do; mọi Đơn vị hàng Hoạt động trong phạm vi → Đã huỷ cùng các Slot Còn hàng, lý do Lộ nội dung. Slot Đã giao giữ nguyên; Đơn vị hàng đang có Slot Đã giữ bị bỏ lại và đếm riêng trong kết quả (chạy lại sau khi hết hạn giữ). Không giải phóng Khoá chống trùng. Slot có Báo lỗi Chờ xác minh không cần rào riêng: Báo lỗi chỉ tạo được cho Slot Đã giao, mà đây chỉ đụng Slot Còn hàng.
- Lần giao bị ảnh hưởng khi lộ nội dung (`AffectedDelivery::forExposedAccounts`): bảng trên cùng trang liệt kê lần giao **Tài khoản** còn Đã giao và còn trong Hạn bảo hành, lọc được theo Sản phẩm. Mã dùng một lần không vào đây vì mã đã kích hoạt xong. Chỉ để nhân viên chủ động liên hệ khách: hệ thống không tự Báo lỗi hay Đổi hàng.

## Huỷ hàng

- Chỉ Quản trị, ở chi tiết Đơn vị hàng (cả đơn vị) và bảng Slot (`StockVoid`), lý do Giao nhầm, Lộ nội dung hoặc Ngừng kinh doanh lô kèm ghi chú. Slot Còn hàng hoặc Đã giao → Đã huỷ; Đơn vị hàng Hoạt động → Đã huỷ cùng các Slot Còn hàng, Slot Đã giao giữ nguyên. Lý do và thời điểm lưu ở `void_reason`, `voided_at` (để tính Tổn thất theo lý do); ai và ghi chú nằm trong Sổ biến động kho. Không giải phóng Khoá chống trùng.
- Đơn vị hàng đang có Slot Đã giữ cho một Phiếu xuất thì không Huỷ hàng được (`SlotHolds::anyHeldForUnit`), và Slot Đã giữ cũng không Huỷ hàng lẻ được: lệnh huỷ cả đơn vị chỉ đụng Slot Còn hàng, nên Slot đang giữ sẽ ở lại trên một Đơn vị hàng đã huỷ rồi quay về Còn hàng khi hết hạn giữ, thành hàng chết. Chờ hết hạn giữ hoặc để website huỷ đơn.

## Đánh dấu Lỗi và Khôi phục

- Chỉ Quản trị, ở chi tiết Đơn vị hàng (`StockDefect`), lý do bắt buộc, ghi Sổ biến động kho. Đánh dấu Lỗi: Đơn vị hàng Hoạt động → Lỗi không cần Báo lỗi (nhà cung cấp thu hồi, hỏng trong kho), thông báo số Lần giao bị ảnh hưởng và chi tiết Đơn vị hàng liệt kê chúng; không tự Báo lỗi hay Đổi hàng. Khôi phục: Lỗi → Hoạt động, Slot Còn hàng bán lại được; Báo lỗi và Đổi hàng đã làm giữ nguyên.
- Tồn lỗi (Slot Còn hàng của Đơn vị hàng Lỗi) không thuộc Tồn bán được; đếm riêng qua `Product::defectiveStockSlots` (cột Tồn lỗi của bảng Sản phẩm) và `SellableStock::defectiveCounts` (badge cạnh Tồn bán được khi xuất kho). Slot Tồn lỗi không Huỷ hàng được (đã tính Tổn thất hàng Lỗi); Khôi phục trước.
- Dữ liệu Tổn thất hàng Lỗi: mỗi lần Đơn vị hàng chuyển Lỗi (Đánh dấu Lỗi hoặc Báo lỗi Xác nhận cả Đơn vị hàng, cùng qua `StockDefect::markDefectiveWithin`) ghi `stock_units.defective_at` và `slots.defective_loss_at` cho Slot Còn hàng lúc đó, trừ khi Đơn vị hàng đã quá Hạn sử dụng (đã là Tổn thất hết hạn); báo cáo lấy Giá vốn Slot (`slots.cost`) theo mốc này, và Tổn thất hết hạn bỏ qua Slot đã có mốc. Khôi phục xoá cả hai. Migration điền mốc cho hàng đã Lỗi từ trước theo Sổ biến động kho. CHECK `stock_units_defective_at`: có `defective_at` khi và chỉ khi Đơn vị hàng Lỗi.
- Đơn vị hàng đang có Slot Đã giữ cũng không Đánh dấu Lỗi được (và Báo lỗi Xác nhận cả Đơn vị hàng cũng vậy, vì cùng đi qua `markDefectiveWithin`): Slot ấy vừa phải ghi Tổn thất hàng Lỗi (nó còn trong kho) vừa sắp được giao cho khách khi website xác nhận, mà mỗi Slot chỉ tính tổn thất một lần — và hàng đã biết là lỗi thì không giao.

## Khiếu nại nhà cung cấp

- Quản trị và Nhập kho (menu Khiếu nại nhà cung cấp, `SupplierClaims`); Bán hàng không thấy. Khiếu nại gồm một hoặc nhiều Đơn vị hàng Lỗi của đúng một Nhà cung cấp (Nhà cung cấp của Lô nhập), trạng thái Nháp → Đã gửi → Đã giải quyết, hoặc Nháp/Đã gửi → Đã huỷ (lý do bắt buộc). Chỉ Nháp thêm hoặc gỡ Đơn vị hàng; gửi phải còn Đơn vị hàng.
- Mỗi Đơn vị hàng nằm trong nhiều nhất một khiếu nại chưa giải quyết (`supplier_claim_units.active` và chưa có kết quả, partial unique index `supplier_claim_units_one_open_per_unit`). Gỡ khỏi Nháp, Khôi phục Đơn vị hàng (chỉ khiếu nại Nháp hoặc Đã gửi, `StockDefect::restore` gọi `SupplierClaims::releaseRestored`) và huỷ khiếu nại tắt cờ này; dòng giữ lại làm lịch sử kèm lý do gỡ. Khôi phục làm khiếu nại Đã gửi hết Đơn vị hàng thì khiếu nại tự huỷ (người huỷ là người Khôi phục); Nháp giữ lại để sửa. Khiếu nại đã giải quyết giữ dòng; Đơn vị hàng Khôi phục rồi Lỗi lại (dòng tạo trước `defective_at` mới) là hàng Lỗi chưa khiếu nại (`SupplierClaimUnit::coversCurrentDefect`).
- Danh sách Khiếu nại có bảng Đơn vị hàng Lỗi chưa khiếu nại (`SupplierClaims::unclaimedDefectiveUnits`): chọn nhiều Đơn vị hàng cùng Nhà cung cấp để tạo Khiếu nại Nháp.
- Giải quyết ghi kết quả cho mọi Đơn vị hàng còn trong khiếu nại: Bồi hoàn tiền (số tiền > 0, ngày không sau hôm nay), Hàng thay thế hoặc Bị từ chối (CHECK `supplier_claim_units_outcome`). Ngày bồi hoàn (`refunded_on`) là ngày nhận tiền, để đối chiếu; Lãi ròng kho cộng bồi hoàn tiền theo ngày giải quyết (`supplier_claims.resolved_at`).
- Xem mã ở bảng Đơn vị hàng của khiếu nại (`ContentReveal::revealClaimUnit`): khi Đơn vị hàng còn trong khiếu nại (kể cả Đã giải quyết) và còn Lỗi, ghi Nhật ký xem mã ngữ cảnh Khiếu nại nhà cung cấp cho mỗi Slot của Đơn vị hàng (nội dung là của cả Đơn vị hàng).
- Hàng thay thế: nút Nhập hàng thay thế mở trang tạo Lô nhập với `?khieu-nai={id}`. Lô nhập bình thường liên kết với khiếu nại (`batches.supplier_claim_id`), chỉ cho Khiếu nại Đã giải quyết có kết quả Hàng thay thế, cùng Nhà cung cấp; mọi Dòng nhập Giá vốn 0 và cột `gia_von` của file bị bỏ qua. Tổng Đơn vị hàng nhập bằng các Lô nhập hàng thay thế của một khiếu nại (trừ hàng Huỷ nhập) không vượt số Đơn vị hàng có kết quả Hàng thay thế: kiểm khi gửi (đã đủ thì không tạo được) và khi xác nhận (đếm sau khi ghi, dưới khoá khiếu nại).

## Báo cáo tồn kho

- Cả ba Vai trò (menu Báo cáo tồn kho, `StockReport`): tồn hiện tại, mỗi Sản phẩm một dòng, đếm theo Slot. Cột Tồn bán được (join `SellableStock::slots`, cùng định nghĩa với form xuất kho), Đã giữ, Tạm ngừng (Đơn vị hàng có Báo lỗi Chờ xác minh, `SellableStock::pausedUnits`), Không đạt Hạn còn lại tối thiểu, Tồn lỗi, Ngừng bán còn lại (Slot đạt hạn của Sản phẩm Ngừng bán, chỉ còn dùng cho Đổi hàng và Giao thay), số Đơn vị hàng, Giá trị tồn (tổng `slots.cost`, **không** gồm Tồn lỗi vì Giá vốn đó đã là Tổn thất hàng Lỗi) và Giá vốn Tồn lỗi. Slot Còn hàng của Đơn vị hàng Hoạt động đã quá Hạn sử dụng là Tổn thất hết hạn, không còn là tồn; Tồn lỗi đếm cả khi đã quá hạn.
- Sắp hết: có Ngưỡng sắp hết, chưa Ngừng bán, Tồn bán được của cả Sản phẩm ≤ ngưỡng (kể cả khi đang lọc theo Nhà cung cấp). Hết hạn trong N ngày (N chọn ở bộ lọc, mặc định 7): Slot Còn hàng của Đơn vị hàng Hoạt động có Hạn sử dụng từ hôm nay đến hôm nay + N, kèm Giá vốn sắp mất.
- Bộ lọc: Sản phẩm, Nhà cung cấp (chỉ đếm hàng của Lô nhập từ Nhà cung cấp đó), sắp hết, Hết hạn trong N ngày; nằm trên URL (`?filters[...]`). Xuất CSV (UTF-8 có BOM) hoặc XLSX theo bộ lọc đang áp, sinh lúc tải, không ghi Nhật ký xem mã hay Nhật ký bảo mật.
- Bán hàng không thấy Giá trị tồn, Giá vốn sắp mất (cả trên màn hình, file xuất và widget) và bộ lọc Nhà cung cấp; module từ chối bộ lọc Nhà cung cấp của Bán hàng.
- Widget Cảnh báo tồn kho trên dashboard: Sản phẩm sắp hết hoặc có hàng hết hạn trong 7 ngày, bấm dòng mở báo cáo lọc theo Sản phẩm.

## Báo cáo nhập/xuất

- Cả ba Vai trò (menu Báo cáo nhập/xuất, `MovementReport`): luồng hàng vào ra trong một khoảng ngày (Asia/Ho_Chi_Minh, tính cả hai đầu; mặc định từ đầu tháng tới hôm nay), mỗi Sản phẩm một dòng, đếm theo Slot. Chỉ Sản phẩm có biến động trong khoảng mới có dòng.
- Nhập tính theo ngày xác nhận Lô nhập (`batches.confirmed_at`), không phải ngày tạo. Hàng **Huỷ nhập** bị loại hẳn như chưa từng vào kho, nên số Nhập của một kỳ cũ giảm đi sau khi Huỷ nhập. Cột Trong đó hàng thay thế là phần hàng thay thế từ **Khiếu nại nhà cung cấp** (Giá vốn 0), nằm trong cột Nhập.
- Xuất tính theo thời điểm **Giao hàng**: Giao bán (gồm cả Giao thêm), Đổi hàng và Giao thay. Giao thay nhận ra ở `deliveries.corrects_delivery_id`, vì Giao thay cùng Sản phẩm vào thẳng Dòng xuất gốc nên loại Dòng xuất không đủ để phân biệt. Lần giao **đã bị Giao thay** không tính vào cột nào của phần Xuất (Slot đó đã **Huỷ hàng**, khách nhận Slot khác), cùng cách **Lãi gộp** bỏ qua Slot giao nhầm; Slot vẫn hiện ở cột Huỷ hàng.
- Huỷ hàng đếm Slot theo `slots.voided_at`; Chuyển Tồn lỗi đếm Slot **còn trong kho** thành **Tồn lỗi** theo `slots.defective_loss_at` (gồm **Đánh dấu Lỗi** và **Báo lỗi** Xác nhận cả Đơn vị hàng; **Khôi phục** xoá mốc nên hàng đã khôi phục không còn tính). Cùng mốc với Tổn thất hàng Lỗi của báo cáo Lãi/lỗ, nên đây là số Slot thành Tồn lỗi, **không phải** số lần Đánh dấu Lỗi: Slot đã giao của Đơn vị hàng chuyển Lỗi không vào cột này, và Đơn vị hàng đã quá **Hạn sử dụng** cũng không (đã là Tổn thất hết hạn).
- Giá vốn nhập và Giá vốn xuất là tổng `slots.cost` của Slot vào và ra. Giá bán tính một lần cho cả **Dòng xuất**, vào kỳ có lần Giao hàng đầu tiên của dòng, vì Giá bán là tổng tiền của cả dòng chứ không phải đơn giá; Dòng xuất loại Đổi hàng và Giao thay không có Giá bán.
- Bộ lọc: khoảng ngày, Sản phẩm, Nhà cung cấp, Kênh bán; nằm trên URL (`?filters[...]`). Mỗi bộ lọc chỉ giữ cột mà nó mô tả được: lọc Kênh bán thì ẩn Nhập, Trong đó hàng thay thế, Giá vốn nhập, Huỷ hàng và Chuyển Tồn lỗi (các biến động này không đi qua Kênh bán nào); lọc Nhà cung cấp thì ẩn Giá bán (một Dòng xuất có thể gồm hàng của nhiều Nhà cung cấp).
- Bán hàng không thấy Giá vốn nhập, Giá vốn xuất, Giá bán và bộ lọc Nhà cung cấp; module từ chối bộ lọc Nhà cung cấp của Bán hàng. Xuất CSV (UTF-8 có BOM) hoặc XLSX theo bộ lọc đang áp, sinh lúc tải, không ghi Nhật ký xem mã hay Nhật ký bảo mật.

## Báo cáo tỉ lệ lỗi theo nhà cung cấp

- Quản trị và Nhập kho (menu Báo cáo tỉ lệ lỗi, `DefectRateReport`); Bán hàng không thấy. Mỗi Nhà cung cấp × Sản phẩm một dòng, mỗi Nhà cung cấp kết thúc bằng một dòng tổng; đếm theo **Đơn vị hàng**, không phải Slot.
- Tính theo **lứa nhập**: tập xét là Đơn vị hàng có Lô nhập xác nhận trong khoảng (`batches.confirmed_at`, hàng Huỷ nhập bị loại hẳn), nên tử số và mẫu số luôn trên cùng một tập và con số của một kỳ cũ còn tăng dần khi hàng đã bán lộ lỗi. Mẫu số: Đơn vị hàng đã giao ít nhất một Slot; lần giao **đã bị Giao thay** không tính (Slot đó đã Huỷ hàng, khách nhận Slot khác). Tử số: mọi Đơn vị hàng của lứa đang Lỗi, gồm Lỗi từ **Báo lỗi** Phạm vi cả Đơn vị hàng và từ **Đánh dấu Lỗi**, **kể cả hàng chưa giao Slot nào** (nhà cung cấp thu hồi, hỏng trong kho) — bỏ ra thì nhà cung cấp bị bắt lỗi hết trong kho lại hiện 0%; **Khôi phục** đưa Đơn vị hàng về Hoạt động nên nó rời tử số, Báo lỗi **chỉ Slot** không đổi trạng thái Đơn vị hàng nên không vào tử số.
- Tử số không phải tập con của mẫu số, nên tỉ lệ **vượt 100% được** khi phần lớn lứa nhập còn trong kho; chưa giao Đơn vị hàng nào thì cột tỉ lệ để trống (không chia được cho 0). Cột **Lỗi trong kho** tách riêng phần tử số chưa giao Slot nào. Chỉ **Dòng lỗi/trùng khi nhập** (tổng `invalid_count + file_duplicate_count + stock_duplicate_count` của các Dòng nhập, tức chất lượng file Nhà cung cấp gửi) đứng ngoài cả tử số lẫn mẫu số, vì các dòng đó chưa từng thành hàng. Dòng tổng cộng số Đơn vị hàng rồi mới chia, không lấy trung bình các tỉ lệ.
- Dòng nhập mà mọi Đơn vị hàng đã bị Huỷ nhập vẫn còn dòng trong báo cáo: chất lượng file vẫn nói lên điều gì đó, chỉ là không còn hàng để tính Tỉ lệ lỗi.
- Bộ lọc: khoảng ngày nhập, Nhà cung cấp, Sản phẩm; nằm trên URL (`?filters[...]`). Bảng chạy trên các dòng của báo cáo (không phải truy vấn Eloquent) nên không phân trang và không sắp xếp lại được: đổi thứ tự thì dòng tổng rời khỏi khối của nó. Xuất CSV (UTF-8 có BOM) hoặc XLSX gồm cả dòng tổng, sinh lúc tải, không ghi Nhật ký xem mã hay Nhật ký bảo mật.

## Báo cáo lãi/lỗ

- Chỉ Quản trị, ba trang dùng chung một bộ lọc (`ProfitReportFilter`): Báo cáo lãi/lỗ theo Sản phẩm (`ProfitReport`), Lãi/lỗ theo phiếu xuất (`DispatchProfitReport`), Lỗ theo nhà cung cấp (`SupplierLossReport`). Khoảng ngày Asia/Ho_Chi_Minh tính cả hai đầu, mặc định từ đầu tháng tới hôm nay; bộ lọc nằm trên URL (`?filters[...]`).
- **Hai tầng.** Tầng trên là **Lãi gộp** = Doanh thu − Giá vốn hàng bán. Đơn vị tính là **Dòng xuất**, không phải từng lần giao: Giá bán là tổng tiền của cả dòng chứ không phải đơn giá, nên cả Giá bán lẫn Giá vốn của một dòng rơi vào kỳ có lần **Giao hàng đầu tiên** của dòng (`SoldLines`), và Doanh thu − Giá vốn hàng bán luôn đúng bằng Lãi gộp của kỳ. Tầng dưới là **Điều chỉnh** (`AdjustmentEvents`), tính theo **thời điểm phát sinh**, gắn Sản phẩm và Nhà cung cấp của Đơn vị hàng. **Lãi ròng kho** = Lãi gộp − Điều chỉnh.
- Giá vốn hàng bán chỉ tính **Slot khách thực nhận**: lần giao đã bị **Giao thay** bị loại (Slot đó đã Huỷ hàng, khách nhận Slot khác), còn Slot giao bù quy về **Dòng xuất gốc** qua `deliveries.corrects_delivery_id` — kể cả khi Giao thay sang Sản phẩm khác, để không có chuyện Sản phẩm gốc có doanh thu không kèm Giá vốn còn Sản phẩm kia có Giá vốn không kèm doanh thu. Slot giao nhầm không biến mất: nó thành Tổn thất giao nhầm ở tầng Điều chỉnh.
- Điều chỉnh gồm: **Chi phí đổi hàng** (`replacements.cost`, theo lúc đổi, gắn Sản phẩm và Nhà cung cấp của Đơn vị hàng **lỗi**); **Tổn thất hàng Lỗi** (`slots.defective_loss_at`, **Khôi phục** xoá mốc nên hàng đã khôi phục rời khỏi báo cáo); **Tổn thất Huỷ hàng** tách theo từng lý do (giao nhầm, lộ nội dung, ngừng kinh doanh lô, theo `slots.voided_at`); **Tổn thất hết hạn** (Slot Còn hàng của Đơn vị hàng Hoạt động quá **Hạn sử dụng**, tính vào **ngày hết hạn**); trừ **Bồi hoàn tiền** (`supplier_claim_units.refund_amount`, theo **ngày giải quyết** khiếu nại chứ không phải ngày nhận tiền). Mỗi Slot chỉ tính tổn thất một lần.
- Dòng xuất **chưa ghi Giá bán** đứng ngoài Doanh thu và Lãi gộp, gom vào một dòng 'Chưa có Giá bán' (số Slot + Giá vốn) bấm được sang danh sách Phiếu xuất để đi điền giá. Dòng xuất loại **Đổi hàng** và **Giao thay** không rơi vào đó: chúng không có Giá bán theo thiết kế.
- Bộ lọc **Nhà cung cấp** áp cả hai tầng; ở tầng Lãi gộp, Giá bán của cả Dòng xuất được **chia đều theo Slot** (một dòng có thể gồm Slot của nhiều Nhà cung cấp) — khác báo cáo Nhập/xuất, nơi cột Giá bán bị ẩn hẳn. Bộ lọc **Kênh bán** chỉ áp Lãi gộp và **ẩn** Điều chỉnh cùng Lãi ròng kho: hàng lỗi, huỷ, hết hạn không đi qua Kênh bán nào.
- Mọi con số luôn tính lại theo dữ liệu hiện tại, nên **sửa Giá bán** hay **Khôi phục** hàng Lỗi làm đổi cả số của một kỳ đã qua.
- Chi tiết theo Phiếu xuất: Giá bán, Giá vốn, Lãi gộp, kèm cột **tham khảo** 'Chi phí đổi hàng phát sinh' (theo lần giao gốc mà mỗi Đổi hàng bù cho) — không trừ vào Lãi gộp của phiếu, vì Chi phí đổi hàng chỉ trừ vào Lãi ròng kho. Phiếu mà mọi Dòng xuất đều chưa ghi Giá bán không có dòng ở đây.
- Lỗ theo Nhà cung cấp: Giá vốn hàng lỗi, Chi phí đổi hàng, Đã bồi hoàn tiền, và **Lỗ ròng** = hai khoản đầu trừ bồi hoàn tiền. Chỉ phần lỗi thuộc về Nhà cung cấp; Tổn thất Huỷ hàng và Tổn thất hết hạn là chuyện của shop nên đứng ngoài. Bồi hoàn **bằng hàng** chỉ là cột tham khảo (đếm Đơn vị hàng), không trừ vào Lỗ ròng vì hàng thay thế vào kho với Giá vốn 0 nên đã tự phản ánh khi bán.
- Bảng chạy trên các dòng của báo cáo (không phải truy vấn Eloquent) nên không phân trang và không sắp xếp lại được: đổi thứ tự thì dòng tổng và dòng 'Chưa có Giá bán' rời khỏi chỗ của chúng. Xuất CSV (UTF-8 có BOM) hoặc XLSX theo bộ lọc đang áp, sinh lúc tải, không ghi Nhật ký xem mã hay Nhật ký bảo mật.

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
docker compose run --rm app php artisan staff:recover-owner chu@shop.test --unlock --reset-2fa
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
