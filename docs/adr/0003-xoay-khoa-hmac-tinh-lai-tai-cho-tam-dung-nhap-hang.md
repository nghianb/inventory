# Xoay khoá HMAC tính lại Khoá chống trùng tại chỗ và tạm dừng nhập hàng

Khi xoay khoá HMAC, kho tính lại `stock_units.dedupe_hash` **tại chỗ** từ nội dung gốc của từng **Đơn vị hàng** (không cần khoá HMAC cũ, vì hash được dựng lại từ giá trị **Khoá chống trùng** dạng rõ), và **không bao giờ giữ song song hai hash**. Mỗi Đơn vị hàng mang thêm `dedupe_hmac_version` — phiên bản khoá HMAC đã tính ra hash của nó — nên:

- Lệnh `inventory:keys:rotate hmac` chỉ tính lại phần còn ở phiên bản cũ, do đó chạy lại được sau khi bị ngắt giữa chừng.
- **Nhập hàng tạm dừng** chừng nào trong kho còn Đơn vị hàng ở phiên bản khác phiên bản đang cấu hình: hash mới ghi vào không so được với hash cũ, nên dòng trùng sẽ lọt qua mà không ai biết. Việc tạm dừng tự hết khi lệnh chạy xong, kể cả sau một lần chạy bị ngắt.
- **Xuất kho vẫn chạy**: **Thứ tự xuất** không đọc Khoá chống trùng. Chỉ các đường *tra cứu* theo Khoá chống trùng (tìm lần giao, **Ghi nhận giao bù**) là không tìm thấy trong lúc lệnh chạy dở.

Mã hoá lại nội dung thì ngược lại, không cần dừng gì: khoá nội dung cũ nằm trong `INVENTORY_CONTENT_PREVIOUS_KEYS` nên bản ghi chưa mã hoá lại vẫn đọc được bình thường.

## Considered Options

- **Ghi song song hai hash** (thêm cột hash mới, đọc cả hai trong lúc chuyển): nhập hàng không phải dừng, nhưng hai partial unique index chống trùng phải nhân đôi, và trong suốt thời gian chuyển đổi tính duy nhất của **Mã dùng một lần** phụ thuộc vào việc mọi đường ghi nhớ kiểm tra đủ cả hai cột. Đây đúng là chỗ không được sai, nên chọn cách dừng nhập hàng vài phút thay vì cách nhanh hơn mà mỏng hơn.
- **Cờ tạm dừng trong một bảng** (như `dispatch_freeze`): lệnh bị ngắt giữa chừng thì cờ kẹt lại, phải có lệnh tắt tay, mà lúc đó vẫn không biết bản ghi nào còn chưa tính lại.
- **Advisory lock của PostgreSQL**: tự nhả khi tiến trình chết — nhưng đó chính là vấn đề: lần chạy bị ngắt sẽ mở lại nhập hàng trên một kho còn lẫn hash cũ và hash mới, tức là chống trùng sai âm thầm, đúng thứ cần tránh nhất.

## Consequences

- Lô nhập đang ở màn xem trước lúc lệnh chạy sẽ hỏng bước kiểm tra kèm lý do rõ ràng; nhân viên tạo lại sau khi lệnh xong. Đổi lại, không có Lô nhập nào được xác nhận dựa trên một kết quả chống trùng sai.
- Xoay khoá HMAC tốn thời gian tỉ lệ với số Đơn vị hàng trong kho (giải mã + tính HMAC từng bản ghi), nên nên chạy ngoài giờ nhập hàng.
- `dedupe_hmac_version` mặc định 1 cho hàng cũ: giá trị sai lệch chỉ làm nhập hàng *dừng* (fail-safe), không bao giờ làm chống trùng sai.
