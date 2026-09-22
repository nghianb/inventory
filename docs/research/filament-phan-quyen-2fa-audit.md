# Hệ sinh thái Filament cho phân quyền, 2FA và audit log

Research cho issue [#4](https://github.com/nghianb/inventory/issues/4) (con của map [#1](https://github.com/nghianb/inventory/issues/1)). Ngày kiểm tra: 2026-09-14. Mọi số phiên bản lấy từ GitHub Releases và `composer.json` của từng repo tại ngày này.

## Câu hỏi

Với Filament (phiên bản ổn định hiện tại) trên Laravel, nên dùng gì cho:

1. phân quyền theo vai trò (filament-shield, spatie/laravel-permission hay policy thuần),
2. 2FA/MFA cho người quản trị (có sẵn trong Filament hay cần plugin),
3. audit log ghi lại mỗi lần xem đầy đủ nội dung **Mã dùng một lần** / **Tài khoản** (spatie/laravel-activitylog hay tự làm)?

Kèm theo: mức độ tương thích và tình trạng bảo trì của từng lựa chọn.

## Tóm tắt khuyến nghị

| Nhu cầu | Khuyến nghị | Lý do chính |
|---|---|---|
| Nền tảng | **Filament 5.x** (5.8.1) trên **Laravel 13** (13.31.0), PHP 8.4 | Bản ổn định mới nhất của cả hai. Filament 6.x đang phát triển trên nhánh riêng, chưa có release |
| Phân quyền | **Policy của Laravel + spatie/laravel-permission ^8**. **Không dùng filament-shield** trong MVP | Filament tự dùng policy. 1–10 nhân viên chỉ cần vài vai trò cố định, khai báo bằng seeder là đủ. Shield thêm một lớp phụ thuộc và giao diện quản lý quyền mà MVP chưa cần |
| 2FA | **MFA có sẵn của Filament**: `AppAuthentication` (TOTP) + `recoverable()`, với `isRequired: true` ở panel quản trị | Có trong core từ 4.x, không cần plugin. 5.8 thêm `MultiFactorChallenge` để yêu cầu nhập lại mã trước thao tác nhạy cảm |
| Audit log xem mã | **Tự làm bảng audit riêng, chỉ cho phép thêm (append-only)**, ghi trong cùng Action/transaction trả nội dung đầy đủ. Có thể dùng spatie/laravel-activitylog ^5 cho log thay đổi dữ liệu thông thường | Sự kiện "xem mã" là yêu cầu bảo mật cốt lõi: cần schema chặt, không bị lệnh dọn log xoá, không ghi nội dung mã vào log |

## 1. Phiên bản nền tảng

- **Filament**: bản ổn định mới nhất là `v5.8.1` (2026-09-08), song song với `v4.13.1`. Repo có nhánh `6.x` đang được merge từ `5.x` nhưng chưa có release 6.x nào. ([releases](https://github.com/filamentphp/filament/releases), [branches](https://github.com/filamentphp/filament/branches))
- Chính sách hỗ trợ của Filament: 5.x nhận tính năng mới cho tới khi 6.x ổn định, sửa lỗi khoảng 1 năm và vá bảo mật khoảng 2 năm sau đó. 4.x nhận sửa lỗi tới 15/01/2027. ([version support policy](https://github.com/filamentphp/filament/blob/5.x/docs/01-introduction/06-version-support-policy.md))
- Filament 5 yêu cầu PHP 8.2+, Laravel 11.28+, Livewire 4, Tailwind CSS 4. `filament/support` 5.x cho phép `illuminate/contracts ^11.28|^12.0|^13.0`. ([upgrade guide](https://github.com/filamentphp/filament/blob/5.x/docs/14-upgrade-guide.md), [packages/support/composer.json](https://github.com/filamentphp/filament/blob/5.x/packages/support/composer.json))
- **Laravel**: mới nhất là `v13.31.0` (2026-09-08). Laravel 13 phát hành 17/03/2026, cần PHP 8.3–8.5, vá bảo mật tới 17/03/2028. Laravel 12 hết giai đoạn sửa lỗi ngày 13/08/2026. ([releases](https://github.com/laravel/framework/releases), [support policy](https://laravel.com/docs/13.x/releases#support-policy))
- **Hệ quả**: nên chọn PHP 8.4, vì spatie/laravel-activitylog v5 và plugin xem log cần `^8.4` (xem mục 3).

## 2. Phân quyền theo vai trò

### Filament làm gì sẵn

- Filament dùng **model policy** đã đăng ký của Laravel: `viewAny` (ẩn resource), `view`, `create`, `update`, `delete`/`deleteAny`, `forceDelete*`, `restore*`, `reorder`. ([resources/overview § Authorization](https://github.com/filamentphp/filament/blob/5.x/docs/03-resources/01-overview.md))
- Action tuỳ biến có thể gọi policy bằng `->authorize('tenMethod')`, hoặc dùng `visible()`/`hidden()`. ([actions/overview § Authorization](https://github.com/filamentphp/filament/blob/5.x/packages/actions/docs/01-overview.md))
- Muốn vào panel ở môi trường production, `User` phải implement `FilamentUser::canAccessPanel()`. ([users/overview](https://github.com/filamentphp/filament/blob/5.x/docs/07-users/01-overview.md))

Nghĩa là thao tác "xem đầy đủ nội dung mã" có thể là một Action gắn `->authorize('reveal')`, gọi method `reveal` trong policy. Không cần package nào thêm.

### Các lựa chọn

| Lựa chọn | Phiên bản / tương thích | Bảo trì | Đánh giá cho dự án |
|---|---|---|---|
| Policy thuần (cột `role` / enum trên `users`) | Có sẵn trong Laravel | Không có phụ thuộc | Đủ dùng nếu vai trò cố định và ít. Nhược điểm: đổi quyền phải sửa code |
| **spatie/laravel-permission** | `8.3.0` (2026-07-03). Cần PHP 8.3+, Laravel 12 hoặc 13. v8 chỉ đổi chữ ký `findByName`/`findOrCreate` để nhận `BackedEnum` | Hoạt động đều (push gần nhất 2026-09-04), của Spatie | **Khuyến nghị.** Vai trò và quyền lưu trong DB, kiểm tra qua Gate (`$user->can(...)`), dùng tự nhiên trong policy. Tách được quyền nhỏ như `stock-unit.reveal` khỏi quyền quản lý chung |
| bezhanSalleh/filament-shield | `4.3.1` (2026-07-25). Hỗ trợ Filament 4 và 5, Laravel 11.28–13, laravel-permission `^6\|^7\|^8` | Một người duy trì chính, ~2.8k sao, 5 issue mở, release đều. 4.x là bản viết lại, không tương thích ngược với 3.x | Tự sinh policy và permission theo Resource/Page/Widget, kèm giao diện quản lý vai trò. Chưa cần cho MVP |

Nguồn: [laravel-permission releases](https://github.com/spatie/laravel-permission/releases), [prerequisites](https://github.com/spatie/laravel-permission/blob/main/docs/prerequisites.md), [upgrading](https://github.com/spatie/laravel-permission/blob/main/docs/upgrading.md), [composer.json](https://github.com/spatie/laravel-permission/blob/main/composer.json); [filament-shield README](https://github.com/bezhanSalleh/filament-shield), [composer.json](https://github.com/bezhanSalleh/filament-shield/blob/main/composer.json), [release 4.3.0](https://github.com/bezhanSalleh/filament-shield/releases/tag/4.3.0).

### Khuyến nghị

- Dùng **spatie/laravel-permission ^8** và tự viết policy cho từng model: Sản phẩm, Lô nhập, Đơn vị hàng, Giao hàng...
- Vai trò và quyền khai báo bằng seeder, đưa vào version control.
- Tách riêng quyền "xem đầy đủ nội dung mã" để nhân viên bình thường chỉ thấy dạng che.
- **Chưa dùng Shield**, vì nó khiến danh sách quyền phụ thuộc vào cấu trúc Resource được sinh tự động. Với 1–10 nhân viên, việc tạo vai trò qua UI không đáng lớp phụ thuộc thêm. Shield cũng dựa trên laravel-permission, nên nếu sau này cần UI quản lý vai trò thì thêm vào mà không phải đổi nền.
- Chỉ nên cân nhắc Shield khi shop cần tự tạo vai trò mới mà không cần lập trình viên.

## 3. 2FA / MFA cho quản trị

### MFA có sẵn trong Filament

([docs/07-users/02-multi-factor-authentication.md](https://github.com/filamentphp/filament/blob/5.x/docs/07-users/02-multi-factor-authentication.md))

- Có sẵn hai phương thức:
  - **App authentication**: TOTP, dùng được với Google Authenticator, Authy, Microsoft Authenticator. `filament/filament` phụ thuộc sẵn `pragmarx/google2fa` và `chillerlan/php-qrcode` ([packages/panels/composer.json](https://github.com/filamentphp/filament/blob/5.x/packages/panels/composer.json)).
  - **Email authentication**: gửi mã một lần qua email.
- `->recoverable()` sinh recovery code (mặc định 8 mã) và có thể tắt việc tự sinh lại mã.
- `multiFactorAuthentication([...], isRequired: true)` bắt người dùng thiết lập MFA ngay sau khi đăng nhập.
- Bước MFA diễn ra **trước** khi người dùng được xác thực vào panel, nên không cần thêm middleware cho các route của panel.
- Lưu ý bảo mật trong tài liệu:
  - Nên dùng cache store hỗ trợ atomic lock và dùng chung giữa các server (database hoặc Redis) để một mã TOTP không bị chấp nhận hai lần.
  - Việc dùng recovery code được khoá theo từng user.
  - Nếu app có đường đăng nhập khác ngoài panel, đường đó không bị hỏi MFA.
- Từ **5.8.0 / 4.13.0** (2026-09-07) có class `MultiFactorChallenge` để hỏi lại mã MFA với người đã đăng nhập trước một thao tác nhạy cảm, có rate limit dùng chung với trang login. Tài liệu nhấn mạnh: xác minh challenge **không** thay cho authorization, vẫn phải kiểm tra lại quyền ngay trước thao tác. ([release v5.8.0](https://github.com/filamentphp/filament/releases/tag/v5.8.0), PR [#20353](https://github.com/filamentphp/filament/pull/20353))
- Có interface `MultiFactorAuthenticationProvider` để tự viết phương thức khác (ví dụ SMS).

### Khuyến nghị

- Dùng MFA có sẵn: `AppAuthentication::make()->recoverable()`, đặt `isRequired: true` cho panel nội bộ.
- Vì chỉ 1–10 nhân viên, nên bắt **mọi người** bật MFA, không chỉ quản trị. Filament cấu hình `isRequired` theo panel, và tất cả nhân viên đều có quyền xem mã dạng che.
- **Không dùng email làm yếu tố thứ hai** nếu email nhân viên không được bảo vệ tốt.
- Không cần plugin 2FA bên thứ ba.
- Cấu hình cache store `database` hoặc `redis` trên VPS.
- Quyết định mở: có bắt `MultiFactorChallenge` trước mỗi lần xem đầy đủ nội dung mã hay không. Để sau MVP cùng với giới hạn số lần xem.

## 4. Audit log cho mỗi lần xem đầy đủ nội dung mã

### spatie/laravel-activitylog

- Phiên bản `5.1.1` (2026-09-08). Cần **PHP 8.4+**, Laravel 12 hoặc 13. Được bảo trì đều. ([releases](https://github.com/spatie/laravel-activitylog/releases), [composer.json](https://github.com/spatie/laravel-activitylog/blob/main/composer.json))
- v5 thay đổi lớn so với v4:
  - schema mới: thêm cột `attribute_changes`, bỏ `batch_uuid`;
  - bỏ hệ thống batch;
  - đổi tên nhiều method.
  
  Các hướng dẫn cho v4 trên mạng không còn đúng. ([UPGRADING.md](https://github.com/spatie/laravel-activitylog/blob/main/UPGRADING.md))
- Ghi sự kiện tuỳ ý bằng `activity()->performedOn($model)->causedBy($user)->event('revealed')->withProperties([...])->log(...)`. Nếu không truyền causer thì tự lấy user đang đăng nhập. ([logging-activity](https://github.com/spatie/laravel-activitylog/blob/main/docs/basic-usage/logging-activity.md))
- Lệnh `activitylog:clean` xoá bản ghi cũ hơn `clean_after_days` và thường được lên lịch chạy hằng ngày. Nếu audit xem mã dùng chung bảng thì dễ bị xoá nhầm. ([cleaning-up-the-log](https://github.com/spatie/laravel-activitylog/blob/main/docs/basic-usage/cleaning-up-the-log.md))
- Plugin giao diện cho Filament:
  - `pxlrbt/filament-activity-log` v3.1.2: Filament 4 và 5, activitylog ^5, PHP ^8.4.
  - `rmsramos/activitylog` v4.0.4: Filament 5 nhưng vẫn khoá activitylog **^4.8**, nên không cài chung được với activitylog v5.
  
  ([pxlrbt composer.json](https://github.com/pxlrbt/filament-activity-log/blob/main/composer.json), [rmsramos composer.json](https://github.com/rmsramos/activitylog/blob/main/composer.json))

### So sánh cho sự kiện "xem mã"

| Tiêu chí | activitylog | Bảng audit riêng |
|---|---|---|
| Schema | Chung chung (`log_name`, `description`, `subject_*`, `causer_*`, `properties` JSON) | Cột rõ ràng: người xem, Đơn vị hàng/Slot, lý do hoặc ngữ cảnh (ví dụ lần Giao hàng), IP, user agent, thời điểm. Dễ đánh index và báo cáo |
| Chống sửa/xoá | Không có sẵn; `activitylog:clean` xoá theo tuổi | Có thể chặn update/delete ở model và bằng quyền PostgreSQL (chỉ `INSERT`/`SELECT` cho user ứng dụng) |
| Công sức | Ít | Thêm một migration, một model và một chỗ ghi log. Không đáng kể |
| Giao diện | Có plugin (pxlrbt) | Một Resource Filament chỉ đọc |

### Khuyến nghị

- **Tự làm bảng audit riêng cho sự kiện xem đầy đủ nội dung mã** (tên nghiệp vụ chờ chốt trong `CONTEXT.md`, ví dụ "Lượt xem mã").
- Ghi log **trong cùng transaction, trước khi trả nội dung giải mã** cho người dùng. Nếu ghi log thất bại thì không trả nội dung.
- Không bao giờ ghi nội dung mã, hay dạng hash dùng chống trùng, vào log.
- Bảng chỉ cho thêm, không có lệnh dọn tự động.
- Giao Hàng cũng làm lộ nội dung, nên cần quyết định có ghi vào cùng bảng hay không.
- spatie/laravel-activitylog ^5 (kèm pxlrbt/filament-activity-log nếu cần UI) là tuỳ chọn cho log thay đổi dữ liệu thông thường (sửa Sản phẩm, Lô nhập...). Không bắt buộc cho MVP.

## Rủi ro và điểm cần theo dõi

- **Filament 6.x** có thể ra trong vòng đời dự án. Shield và các plugin thường chậm hơn core. Càng ít plugin thì nâng cấp càng dễ, đây là một lý do nữa để không dùng Shield.
- `MultiFactorChallenge` mới có từ 2026-09-07, API có thể còn được tinh chỉnh ở các bản 5.x kế tiếp.
- activitylog v5 và pxlrbt cần PHP 8.4, nên chốt PHP 8.4 trên VPS ngay từ đầu.
- Tài liệu MFA cảnh báo cache store phải hỗ trợ lock dùng chung, cần tính khi thiết kế hạ tầng (mục "Hạ tầng triển khai" trong map #1).
