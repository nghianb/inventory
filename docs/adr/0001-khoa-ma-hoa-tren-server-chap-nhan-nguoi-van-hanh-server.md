# Khoá mã hoá nằm trên server; Người vận hành server là lỗ hổng audit được chấp nhận

Nội dung mã được mã hoá ở tầng ứng dụng, và khoá mã hoá, khoá HMAC nằm trong `.env` trên chính VPS chạy kho, không dùng KMS, Vault hay HSM. Vì vậy mã hoá chỉ bảo vệ khi DB hoặc backup bị lộ mà khoá không lộ theo. Nó không bảo vệ khi server bị chiếm, và **Người vận hành server** đọc được mọi nội dung mà không đi qua **Nhật ký xem mã**. Chúng tôi chấp nhận điều này vì shop chỉ có 1–10 nhân viên và chạy trên một VPS. Dịch vụ quản lý khoá bên ngoài sẽ thêm phụ thuộc và thêm chỗ có thể hỏng, trong khi ứng dụng đang chạy vẫn phải cầm khoá dạng rõ, nên mô hình rủi ro không đổi. Thay vào đó, rủi ro được kiểm soát bằng quy trình:
- Chỉ chủ shop giữ quyền server lâu dài.
- Người khác chỉ được cấp quyền server theo từng đợt, và khi họ rời đi thì xoay khoá.
- Xoay khoá chỉ làm bằng dòng lệnh trên server, không có nút trong Filament.
- Khi lộ cả khoá lẫn dữ liệu, mọi nội dung trong kho bị coi là đã lộ.

## Considered Options

- **KMS/Vault (envelope encryption)**: bị loại. Mỗi lần giải mã phải gọi ra ngoài, nên nhà cung cấp KMS gặp sự cố là không giao hàng được. Kẻ chiếm server vẫn gọi KMS thay cho app được.
- **Giới hạn số lần xem mã / whitelist IP** để thu hẹp thiệt hại: để sau MVP.

## Consequences

- Nếu kho lớn hơn, hoặc có nhiều người vận hành không tin cậy nhau, phải xem lại quyết định này. Chuyển sang KMS khi đó đồng nghĩa với mã hoá lại toàn kho.
- Khoá phải có bản sao ngoài server, tách khỏi backup, và được giữ tới khi backup cuối cùng dùng nó hết hạn lưu. Mất khoá nội dung là mất toàn bộ hàng.
