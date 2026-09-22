# Lô nhập còn Chờ xác nhận sửa được Giá trị áp cho Đơn vị hàng và chứng từ

**Lô nhập** không có trang sửa: `BatchPolicy::update` và `delete` đều trả `false`, và **Giá vốn** có trigger DB từ chối mọi lần sửa sau khi hàng vào kho. Nhưng cửa sổ giữa **Chờ xác nhận** và Xác nhận chính là lúc nhân viên đọc màn xem trước và phát hiện mình gõ nhầm một chữ số Giá vốn, hay quên đặt **Hạn sử dụng** — và cho tới nay đó cũng là lúc họ không làm được gì ngoài **Bỏ Lô nhập** rồi dán lại toàn bộ nội dung. Với lô vài trăm dòng dán tay, một ký tự sai là mất trắng công nhập liệu.

Từ nay, khi Lô nhập còn Chờ xác nhận và bản kiểm tra còn trong hạn, sửa được hai thứ: **Giá trị áp cho Đơn vị hàng** của từng **Dòng nhập** (Giá vốn, **Số slot**, Hạn sử dụng) và phần chứng từ của Lô nhập (Ngày nhập, Số chứng từ, Ghi chú). Lưu xong, Lô nhập quay về **Đang kiểm tra** và chạy lại pha 1.

Nội dung — danh sách **Đơn vị hàng** — thì không sửa, và đó mới là điểm chính: nó là thứ tốn công nhập nhất, nằm mã hoá trong nội dung tạm, và **Khoá chống trùng** tính theo nó. **Nhà cung cấp** và **Sản phẩm** cũng đứng ngoài: Sản phẩm quyết các **Trường nội dung** và phạm vi Khoá chống trùng của chính nội dung đã kiểm tra, còn Nhà cung cấp là điều kiện của Lô nhập hàng thay thế. Đổi một trong ba thứ đó thì bản kiểm tra không còn nói về cùng một lô hàng nữa — đó là một Lô nhập khác, không phải một lần sửa.

## Hệ quả

- **Hạn 24 giờ vẫn tính từ lúc tạo Lô nhập; sửa không gia hạn.** Nội dung tạm là nội dung khách sẽ nhận, nằm trên ổ của server, nên thời gian nó tồn tại không được tự kéo dài bằng cách sửa đi sửa lại. Sửa sát hạn thì lô vẫn hết hạn đúng giờ cũ và nhân viên phải tạo lại Lô nhập.
- **Quyền đi theo một ability riêng (`revise`), không mở `BatchPolicy::update`** — cùng lý do như `recordInvoiceTotal` ở ADR 0006: mở `update` sẽ bật các affordance sửa mặc định của Filament trên một tài nguyên mà hàng đã vào kho vẫn bất biến.
- **Sửa là kiểm tra lại từ đầu, không phải vá kết quả cũ.** Số dòng lỗi, dòng trùng và mẫu đã che được tính lại theo khai báo hiện tại của Sản phẩm. `dedupe_hash` tính theo nội dung nên không đổi vì mấy giá trị này, nhưng lần kiểm tra lại vẫn tra lại kho: một dòng có thể thành trùng trong kho nếu Lô nhập khác vừa nhập cùng mã trong lúc đó.
- **Tính bất biến của Lô nhập từ nay đọc là "bất biến sau khi Xác nhận"**, không phải "bất biến từ lúc gửi đi kiểm tra". ADR 0006 nói `invoice_total` là ngoại lệ duy nhất sửa được _sau khi xác nhận_; câu đó vẫn đúng, còn đây là chuyện của giai đoạn trước xác nhận.
- Lần sửa phải nêu Giá trị áp cho Đơn vị hàng của **mọi** Dòng nhập, không phải chỉ dòng vừa đổi: một Dòng nhập bị bỏ quên mà im lặng giữ giá trị cũ là đúng loại lỗi mà issue này muốn diệt.
