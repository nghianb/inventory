# Mã hoá nội dung mã và chống trùng trong Laravel/Postgres

Ticket: [#3](https://github.com/nghianb/inventory/issues/3) (thuộc map [#1](https://github.com/nghianb/inventory/issues/1)). Ngày tra cứu: 2026-09-14.

## Câu hỏi

Cách tốt nhất để lưu nội dung **Mã dùng một lần** và thông tin đăng nhập **Tài khoản** ở dạng mã hoá trong Laravel + PostgreSQL: encrypted cast hay pgcrypto, xoay khoá thế nào, chống nhập trùng không cần giải mã (blind index / HMAC, chuẩn hoá chuỗi), hiệu năng khi import vài chục nghìn mã, và package nào đáng tin.

## Tóm tắt khuyến nghị

1. **Mã hoá ở tầng ứng dụng bằng encrypter có sẵn của Laravel (AES-256, có MAC), không dùng pgcrypto.** Đặt cipher `AES-256-GCM` (Laravel hỗ trợ sẵn, là chế độ authenticated được OWASP ưu tiên) hoặc giữ mặc định `AES-256-CBC` + HMAC-SHA256 (cũng đạt chuẩn Encrypt-then-MAC).
2. **Dùng khoá riêng cho nội dung kho, tách khỏi `APP_KEY`.** Tạo một `Encrypter` riêng (ví dụ `INVENTORY_ENCRYPTION_KEY` + `INVENTORY_PREVIOUS_KEYS`) và gắn cho model qua `Model::encryptUsing()` hoặc một custom cast. Lý do: xoay `APP_KEY` sẽ đăng xuất mọi phiên và gắn vòng đời khoá dữ liệu kho vào khoá session/cookie.
3. **Chống trùng bằng một cột HMAC-SHA256 đầy đủ (không cắt ngắn) của chuỗi đã chuẩn hoá, dùng khoá HMAC thứ ba tách biệt, với UNIQUE index trong Postgres.** Import dùng `INSERT ... ON CONFLICT DO NOTHING RETURNING` để biết dòng nào trùng.
4. **Không dùng `spatie/laravel-ciphersweet` cho MVP.** Package tốt nhưng lưu blind index vào bảng polymorphic `blind_indexes` không có unique trên giá trị, nên không ép được "không trùng" ở tầng DB, và blind index của CipherSweet được thiết kế cắt ngắn (Bloom filter) cho tìm kiếm, không phải cho unique.
5. **Import vài chục nghìn mã là tải nhẹ**; chi phí chính là round-trip DB, không phải AES/HMAC. Xử lý theo chunk (ví dụ 500–1000 dòng/lệnh insert) trong queue job, tự mã hoá + tính HMAC trong PHP rồi bulk insert.

Chi tiết và nguồn bên dưới.

## 1. Encrypted cast của Laravel so với pgcrypto

### Laravel encrypter / encrypted cast

- Laravel mã hoá bằng OpenSSL AES-256/AES-128 và "All of Laravel's encrypted values are signed using a message authentication code (MAC)". Mặc định là AES-256-CBC. ([Laravel 12 Encryption](https://laravel.com/docs/12.x/encryption), nội dung giống hệt ở [13.x](https://laravel.com/docs/13.x/encryption))
- Mã nguồn `Encrypter` hỗ trợ bốn cipher: `aes-128-cbc`, `aes-256-cbc`, `aes-128-gcm`, `aes-256-gcm`. Với CBC, MAC là `hash_hmac('sha256', $iv.$value, $key)`; với GCM thì dùng tag AEAD. IV sinh bằng `random_bytes` mỗi lần, nên **cùng một plaintext cho ra ciphertext khác nhau** (không deterministic). ([Encrypter.php, nhánh 12.x](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Encryption/Encrypter.php))
- Cấu hình ở `config/app.php`: `'cipher' => 'AES-256-CBC'`, `'key' => env('APP_KEY')`, `'previous_keys'` đọc từ `APP_PREVIOUS_KEYS`. ([laravel/laravel config/app.php](https://github.com/laravel/laravel/blob/12.x/config/app.php))
- Cast `encrypted` (và `encrypted:array`, `encrypted:object`...) mã hoá thuộc tính khi lưu. Tài liệu lưu ý: cột phải là `TEXT` trở lên vì độ dài không đoán trước, và "you will not be able to query or search encrypted attribute values". ([Eloquent Mutators & Casting, Encrypted Casting](https://laravel.com/docs/12.x/eloquent-mutators#encrypted-casting))
- Model có `encryptUsing($encrypter)` và `currentEncrypter()` trả về `static::$encrypter ?? Crypt::getFacadeRoot()`, tức là có thể thay khoá cho encrypted cast mà không đụng `APP_KEY`. ([HasAttributes.php, 12.x](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php))

Lưu ý: `encryptUsing` gán vào thuộc tính static dùng chung cho mọi model (khai báo trên trait `HasAttributes`), nên nếu cả ứng dụng chỉ có dữ liệu kho cần mã hoá bằng cast thì gọi một lần ở service provider là đủ; nếu cần khoá khác nhau cho model khác nhau thì viết custom cast nhận encrypter riêng. (Suy luận từ mã nguồn trên.)

### pgcrypto

- Tài liệu PostgreSQL, mục Security Limitations: "All `pgcrypto` functions run inside the database server. That means that all the data and passwords move between `pgcrypto` and client applications in clear text. Thus you must: Connect locally or use SSL connections. Trust both system and database administrator. If you cannot, then better do crypto inside client application." ([PostgreSQL pgcrypto F.26.8.3](https://www.postgresql.org/docs/current/pgcrypto.html))
- Cũng mục đó: "The implementation does not resist side-channel attacks."

Hệ quả cho dự án: khoá phải được gửi kèm từng câu SQL, có thể lộ qua log truy vấn, `pg_stat_activity`, hay log lỗi. Điều này đi ngược định hướng "mã hoá ở tầng ứng dụng" trong map #1.

### OWASP

- Mã hoá có thể đặt ở tầng ứng dụng, database, filesystem hay phần cứng; chọn theo mô hình đe doạ. Mã hoá tầng ứng dụng bảo vệ trước tấn công từ xa vào DB (SQL injection, lộ bản backup). ([OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html), mục Architectural Design)
- Thuật toán: AES với khoá ít nhất 128 bit, lý tưởng 256 bit; "Where available, authenticated modes should always be used. The most commonly used authenticated modes are GCM and CCM"; nếu không có thì CBC/CTR kèm Encrypt-then-MAC. (mục Algorithms, Cipher Modes)
- "Where possible, encryption keys should be stored in a separate location from encrypted data." (mục Separation of Keys and Data)

**Kết luận mục 1:** dùng encrypter của Laravel. Cả AES-256-CBC+HMAC (mặc định) và AES-256-GCM đều đạt khuyến nghị OWASP; nên chọn `aes-256-gcm` cho encrypter riêng của kho ngay từ đầu vì đổi cipher về sau đồng nghĩa phải mã hoá lại toàn bộ.

## 2. Xoay khoá

### Cơ chế có sẵn của Laravel

- "When you set this environment variable, Laravel will always use the "current" encryption key when encrypting values. However, when decrypting values, Laravel will first try the current key, and if decryption fails using the current key, Laravel will try all previous keys until one of the keys is able to decrypt the value." ([Laravel Encryption, Gracefully Rotating Encryption Keys](https://laravel.com/docs/12.x/encryption#gracefully-rotating-encryption-keys))
- Trong mã nguồn, `getAllKeys()` trả `[$this->key, ...$this->previousKeys]` và `decrypt` thử lần lượt từng khoá, kiểm MAC/tag với mỗi khoá. ([Encrypter.php](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Encryption/Encrypter.php))
- Đổi `APP_KEY` thì "all authenticated user sessions will be logged out", vì cookie và session cũng mã hoá bằng khoá này. (cùng trang)
- Laravel **không** có lệnh re-encrypt dữ liệu cũ sang khoá mới; `APP_PREVIOUS_KEYS` chỉ giúp đọc được dữ liệu cũ. Không tìm thấy lệnh như vậy trong tài liệu 12.x và 13.x.

### Khuyến nghị

- **Khoá riêng cho kho** (DEK của kho), không dùng `APP_KEY`: tạo `new Encrypter($key, 'aes-256-gcm')` rồi `->previousKeys([...])` từ biến môi trường riêng, đăng ký trong service provider và gắn qua `Model::encryptUsing()` hoặc custom cast. Như vậy xoay khoá kho không đăng xuất nhân viên, và xoay `APP_KEY` (ví dụ khi lộ) không đụng dữ liệu kho.
- **Quy trình xoay:** (1) đưa khoá cũ vào danh sách previous, đặt khoá mới làm current, deploy; (2) chạy một artisan command/queue job duyệt bảng theo chunk, đọc (giải mã bằng khoá cũ) rồi ghi lại (mã hoá bằng khoá mới); (3) khi xong, bỏ khoá cũ khỏi danh sách previous. Nên lưu thêm cột nhỏ `key_version` (hoặc tương đương) để job biết dòng nào chưa xoay và để tránh chi phí thử nhiều khoá khi giải mã.
- **Khoá HMAC chống trùng xoay riêng và hiếm khi xoay:** đổi khoá HMAC nghĩa là phải tính lại toàn bộ cột hash (cần giải mã từng dòng) và trong lúc chuyển, unique index không so được giữa hash cũ và mới. Chỉ xoay khi nghi lộ khoá.
- OWASP liệt kê lý do xoay: khoá bị lộ hoặc nghi lộ, hết cryptoperiod, lượng dữ liệu đã mã hoá, thay đổi độ an toàn của thuật toán. OWASP cũng gợi ý mô hình DEK/KEK ("The Key Encryption Key (KEK) is used to encrypt the DEK"). ([OWASP, Key Lifetimes and Rotation; Encrypting Stored Keys](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)) Với quy mô 1 VPS, 1–10 nhân viên, DEK/KEK qua KMS là quá tay cho MVP; lưu khoá trong biến môi trường ngoài repo, ngoài bản backup DB là mức hợp lý. Quy trình backup khoá và khôi phục khi mất khoá thuộc mục "Hạ tầng triển khai" còn mở ở map #1.

## 3. Chống nhập trùng không cần giải mã

### Vì sao không dùng ciphertext

Encrypter của Laravel dùng IV ngẫu nhiên nên cùng một mã cho ra ciphertext khác nhau (xem mục 1). Không thể đặt unique trên cột mã hoá.

### Blind index / HMAC

- CipherSweet định nghĩa: "A blind index is: A deterministic one-way hash of the plaintext. Truncated to a specified number of bits. Treated as a Bloom filter for database lookups." và "Each blind index has a distinct key, provided by HKDF-HMAC-SHA256." ([CipherSweet Security](https://ciphersweet.paragonie.com/security))
- Việc cắt ngắn tạo "false positives ... but not false negatives", dùng để giảm rò rỉ khi *tìm kiếm*. (cùng trang) Nhưng với bài toán chống trùng, bắt buộc phải có "cùng hash ⇔ cùng mã", nên **không được cắt ngắn**: hash cắt ngắn sẽ khiến unique index từ chối nhầm mã khác nhau.
- pgcrypto có `hmac()`: "similar to `digest()` but the hash can only be recalculated knowing the key". ([PostgreSQL pgcrypto F.26.1.2](https://www.postgresql.org/docs/current/pgcrypto.html)) Tuy nhiên tính trong DB lại gặp vấn đề gửi khoá qua SQL ở mục 1, nên tính trong PHP.
- PHP: `hash_hmac(string $algo, string $data, string $key, bool $binary = false)`; `$binary = true` trả 32 byte thô cho SHA-256. ([php.net hash_hmac](https://www.php.net/manual/en/function.hash-hmac.php))

**Vì sao HMAC chứ không phải SHA-256 thường:** nhiều loại mã có không gian nhỏ hoặc có cấu trúc (thẻ nạp theo dải số, key 5×5 ký tự theo định dạng). Hash không khoá cho phép ai có bản dump DB thử-sai để khôi phục mã. HMAC có khoá bí mật chặn việc này miễn là khoá không lộ. (Suy luận; cùng lý do CipherSweet dùng khoá riêng cho mỗi blind index.)

**Rò rỉ chấp nhận được:** hash đầy đủ tiết lộ "hai dòng có cùng mã" với người đọc DB. Vì unique index đã đảm bảo không có hai dòng trùng, rò rỉ này gần như bằng không trong thiết kế này.

### Khuyến nghị cụ thể

- Cột `content_hash bytea NOT NULL` = `hash_hmac('sha256', normalize($raw), DEDUP_KEY, true)`. Khoá `DEDUP_KEY` tách khỏi khoá mã hoá (tránh dùng một khoá cho hai mục đích).
- Phạm vi unique: **mặc định unique toàn kho** (`UNIQUE (content_hash)` riêng cho Mã dùng một lần, và một unique riêng cho Tài khoản), vì một mã bị nhập nhầm sang sai Sản phẩm vẫn là mã trùng thật. Chỉ thu hẹp thành `UNIQUE (product_id, content_hash)` nếu nghiệp vụ xác nhận có trường hợp cùng một chuỗi hợp lệ ở hai Sản phẩm; chốt ở ticket mô hình dữ liệu.
- Với **Tài khoản**: hash trên danh tính đăng nhập đã chuẩn hoá (ví dụ username/email + tên dịch vụ), **không** hash cả mật khẩu, vì cùng tài khoản đổi mật khẩu vẫn là trùng.
- Unique index giữ nguyên với **Đơn vị hàng** đã giao hoặc bị Báo lỗi: mã đã giao nhập lại vẫn phải bị chặn (thường là dấu hiệu nhà cung cấp bán lại mã cũ). Nếu dùng soft delete, không nên loại dòng đã xoá khỏi unique index vì lý do tương tự.

### Chuẩn hoá chuỗi trước khi hash

Quy tắc phải cố định (mọi thay đổi đều buộc tính lại toàn bộ hash), nên đặt thành một hàm duy nhất có version:

1. Unicode normalize dạng NFKC: `Normalizer::normalize($s, Normalizer::FORM_KC)` (cần extension `intl`; trả `false` khi lỗi, phải xử lý). NFKC gộp ký tự full-width/compatibility về dạng thường, hay gặp khi dán từ chat. ([php.net Normalizer::normalize](https://www.php.net/manual/en/normalizer.normalize.php))
2. Bỏ khoảng trắng đầu/cuối, kể cả NBSP và zero-width (ví dụ `\u{200B}`, `\u{FEFF}`).
3. Với **Mã dùng một lần**: bỏ khoảng trắng và dấu gạch ngang bên trong, rồi đổi sang chữ hoa, **chỉ khi thuộc tính Sản phẩm cho biết mã không phân biệt hoa thường và dấu gạch chỉ để trình bày** (đúng với phần lớn CD key như Windows/Steam; không chắc đúng với mọi gift card). Tức là chuẩn hoá nên là thuộc tính của Sản phẩm (ví dụ `case_sensitive`, `strip_separators`), không phải một quy tắc chung.
4. Với **Tài khoản**: email/username đổi sang chữ thường, trim.
5. Lưu nội dung đã mã hoá là **chuỗi gốc** (sau trim), không phải chuỗi chuẩn hoá, để giao cho khách đúng như nhà cung cấp gửi.

Thứ tự "NFKC rồi mới đổi hoa/thường" và danh sách ký tự bị bỏ là suy luận thực hành; không có tài liệu chính thống nào quy định riêng cho CD key.

## 4. Hiệu năng khi import vài chục nghìn mã

- Mã hoá bằng Encrypter: một lần `random_bytes`, một lần `openssl_encrypt`, một lần `hash_hmac` (với CBC), rồi `json_encode` + base64 ([Encrypter.php](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Encryption/Encrypter.php)). Với chuỗi vài chục byte đây là thao tác cỡ micro giây trên CPU có AES-NI, nên 50.000 mã tốn chưa tới vài giây CPU. Chưa đo được trong môi trường này (máy không có PHP); nên đo lại trên VPS thật trước khi chốt kích thước chunk.
- Nút thắt thực tế là số round-trip DB và việc tạo từng Eloquent model. Khuyến nghị:
  - Chạy import trong **queue job**, không trong request Filament.
  - Tự mã hoá và tính HMAC trong PHP, rồi **bulk insert theo chunk** (500–1000 dòng/câu) bằng query builder. Lưu ý query builder không chạy cast của model, nên phải gọi encrypter tường minh; hoặc dùng Eloquent `save()` từng dòng nếu chấp nhận chậm hơn (vẫn đủ nhanh ở quy mô này).
  - Dùng `INSERT ... ON CONFLICT (…) DO NOTHING RETURNING id, content_hash`: PostgreSQL chỉ trả về "rows that were successfully inserted or updated", nên phần hash không có trong kết quả chính là mã trùng với DB. ([PostgreSQL INSERT](https://www.postgresql.org/docs/current/sql-insert.html)) `insertOrIgnore` của Laravel không trả lại danh sách dòng và tài liệu cảnh báo "other types of errors may also be ignored depending on the database engine" ([Laravel Query Builder, Insert Statements](https://laravel.com/docs/12.x/queries#insert-statements)), nên với báo cáo trùng cho nhân viên thì dùng SQL `ON CONFLICT ... RETURNING` là rõ ràng hơn.
  - Loại trùng **trong chính file** bằng mảng hash trong PHP trước khi insert (cùng một câu `INSERT ... ON CONFLICT` không cho phép hai dòng trùng key trong cùng lệnh khi dùng `DO UPDATE`; với `DO NOTHING` thì dòng sau bị bỏ, nhưng loại trước giúp báo cáo rõ "trùng trong file" và "trùng với kho").
  - Mỗi Lô nhập nên nằm trong một transaction để import lỗi giữa chừng không để lại lô dở dang; vài chục nghìn dòng trong một transaction là bình thường với PostgreSQL.
- Unique index trên `bytea` 32 byte: 50.000 dòng/tháng là rất nhỏ với B-tree; không cần tối ưu thêm.
- Không dùng blind index "slow" (Argon2/PBKDF2) cho chống trùng: CipherSweet mặc định "use a password hashing function" cho blind index ([CipherSweet PHP usage](https://ciphersweet.paragonie.com/php/usage)), rất chậm khi import hàng loạt; HMAC-SHA256 với khoá bí mật 256 bit đã đủ vì kẻ tấn công không có khoá thì không brute-force được.

## 5. Package

| Lựa chọn | Nhận xét | Kết luận |
|---|---|---|
| Encrypter + cast có sẵn của Laravel | Có sẵn, AES-256-GCM/CBC+MAC, hỗ trợ previous keys, `Model::encryptUsing()` để dùng khoá riêng. Không có blind index. | **Dùng** |
| [`spatie/laravel-ciphersweet`](https://github.com/spatie/laravel-ciphersweet) | Bọc [`paragonie/ciphersweet`](https://github.com/paragonie/ciphersweet) (thư viện của Paragon IE, v4.10.0 phát hành 2026-03-05). Package yêu cầu PHP ^8.1, Laravel ^10–^13, ciphersweet ^4.0.1 ([composer.json](https://github.com/spatie/laravel-ciphersweet/blob/main/composer.json)); bản 1.9.0 phát hành 2026-09-01, vẫn được bảo trì. Có lệnh `ciphersweet:generate-key`, `ciphersweet:encrypt` (xoay khoá, chạy lại được) và scope `whereBlind`. **Nhưng** blind index lưu ở bảng `blind_indexes` polymorphic với unique trên `(indexable_type, indexable_id, name)`, còn `(name, value)` chỉ là index thường ([migration](https://github.com/spatie/laravel-ciphersweet/blob/main/database/migrations/create_blind_indexes_table.php)), nên không ép unique giá trị ở tầng DB được; blind index mặc định cắt ngắn và dùng hash chậm. | Không dùng cho MVP; cân nhắc lại nếu sau này cần tìm kiếm theo một phần nội dung mã |
| `paragonie/ciphersweet` dùng trực tiếp | Có `FastBlindIndex`, có thể đặt index không cắt ngắn, có `FieldRotator`/`RowRotator` cho xoay khoá ([CipherSweet key rotation](https://ciphersweet.paragonie.com/php/key-rotation)). Thêm một lớp phụ thuộc và khái niệm (backend, key provider) cho nhu cầu mà HMAC + unique index đã giải quyết. | Không cần |
| pgcrypto | Khoá và plaintext đi qua SQL; tài liệu Postgres khuyên mã hoá ở client nếu không tin DBA/hệ thống. | Không dùng |

## Rủi ro và điểm còn mở

- **Mất khoá mã hoá = mất toàn bộ hàng trong kho.** Cần quy trình backup khoá tách khỏi backup DB (mục "Hạ tầng triển khai" ở map #1).
- **Lộ khoá HMAC** làm mã có không gian nhỏ bị dò được từ bản dump; nhưng nếu khoá HMAC và khoá mã hoá cùng nằm trong `.env` thì lộ một thường là lộ cả hai. Tách khoá chủ yếu để xoay độc lập và đúng nguyên tắc một khoá một mục đích.
- **Dạng che cho nhân viên** (ví dụ `XXXXX-XXXXX-…-AB12`): nếu muốn hiển thị dạng che mà không giải mã, phải lưu thêm vài ký tự cuối dạng rõ, và điều này rò rỉ một phần mã. Với mã ngắn (thẻ nạp) nên cân nhắc chỉ che sau khi giải mã ở server và ghi audit log. Nên chốt ở ticket về luồng xem mã.
- **Quy tắc chuẩn hoá theo Sản phẩm** cần chốt cùng mô hình dữ liệu Sản phẩm.
- Chưa có số đo hiệu năng thực tế; nên benchmark import 50.000 mã trên VPS mục tiêu.

## Nguồn

- Laravel 12.x/13.x Encryption: https://laravel.com/docs/12.x/encryption, https://laravel.com/docs/13.x/encryption
- Laravel 12.x Eloquent Mutators & Casting: https://laravel.com/docs/12.x/eloquent-mutators#encrypted-casting
- Laravel 12.x Query Builder, Insert Statements: https://laravel.com/docs/12.x/queries#insert-statements
- `Illuminate\Encryption\Encrypter`: https://github.com/laravel/framework/blob/12.x/src/Illuminate/Encryption/Encrypter.php
- `Illuminate\Database\Eloquent\Concerns\HasAttributes`: https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php
- `laravel/laravel` config/app.php: https://github.com/laravel/laravel/blob/12.x/config/app.php
- PostgreSQL pgcrypto: https://www.postgresql.org/docs/current/pgcrypto.html
- PostgreSQL INSERT: https://www.postgresql.org/docs/current/sql-insert.html
- OWASP Cryptographic Storage Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html
- CipherSweet Security: https://ciphersweet.paragonie.com/security
- CipherSweet PHP usage: https://ciphersweet.paragonie.com/php/usage
- CipherSweet PHP key rotation: https://ciphersweet.paragonie.com/php/key-rotation
- spatie/laravel-ciphersweet: https://github.com/spatie/laravel-ciphersweet
- PHP `hash_hmac`: https://www.php.net/manual/en/function.hash-hmac.php
- PHP `Normalizer::normalize`: https://www.php.net/manual/en/normalizer.normalize.php
