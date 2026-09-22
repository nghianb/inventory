# Tổng tiền hoá đơn sửa được sau khi Lô nhập đã xác nhận

Mọi thứ khác của một **Lô nhập** đóng lại khi xác nhận: **Giá vốn** có trigger DB từ chối mọi lần sửa, `BatchPolicy::update` trả `false` nên không có trang sửa, và rút hàng ra phải đi qua **Huỷ nhập** của **Quản trị**. `invoice_total` là ngoại lệ cố ý duy nhất: nó nhập và sửa được ở màn xem trước, kể cả khi Lô nhập đã Xác nhận.

Lý do là hoá đơn thường về sau hàng — nhà cung cấp hàng số giao key trước, vài ngày sau mới xuất hoá đơn. Khoá trường này lúc xác nhận là đảm bảo con số vĩnh viễn trống hoặc vĩnh viễn sai đúng trong ca thường gặp nhất, mà không đổi lại được gì: `invoice_total` không đẻ ra thứ gì trong kho. Nó không phải **Giá vốn**, không vào **Lãi gộp**, **Tổn thất** hay **Điều chỉnh**, và không báo cáo nào đọc nó. Công dụng duy nhất là để nhân viên **Nhập kho** đối chiếu bằng mắt với Tổng Giá vốn mà kho tự tính ra.

Nó cũng được chuyển khỏi form tạo Lô nhập sang màn xem trước, vì đối chiếu một con số với Tổng Giá vốn chưa tồn tại thì vô nghĩa: lúc tạo lô, kho chưa đọc file, chưa loại dòng lỗi và dòng trùng, nên chưa biết tổng Giá vốn thật là bao nhiêu.

## Hệ quả

- Tính bất biến của Lô nhập từ nay phải đọc là "bất biến về **hàng trong kho**", không phải "bản ghi Lô nhập không đổi byte nào". Người đọc thấy một trường sửa được trên lô đã đóng thì đây là lý do, không phải sơ sót.
- Quyền sửa đi theo một ability riêng (`recordInvoiceTotal`), không mở `BatchPolicy::update`. Mở `update` sẽ bật các affordance sửa mặc định của Filament trên một tài nguyên mà mọi thứ còn lại đều bất biến.
- Trường này không vào **Sổ biến động kho**: sổ đó ghi chuyển trạng thái của **Slot** và **Đơn vị hàng**, còn đây là ghi chú chứng từ.
