# Kho hàng số (Inventory)

Hệ thống quản lý kho nội bộ cho một shop bán hàng số (CD key, code, tài khoản). Người dùng là nhân viên nội bộ của shop.

## Language

**Sản phẩm** (Product):
Một mặt hàng mà shop bán, ví dụ "Windows 11 Pro key" hay "Netflix Premium 1 tháng". Sản phẩm là loại hàng, không phải từng đơn vị hàng trong kho.
_Avoid_: mặt hàng, SKU (khi nói về đơn vị trong kho)

**Mã dùng một lần** (One-time code):
Một đơn vị hàng là chuỗi kích hoạt, giao cho đúng một khách và hết giá trị sau khi giao. Gồm cả CD key (Steam, Windows...) và code (gift card, thẻ nạp có mệnh giá, voucher). CD key và code chỉ khác nhau ở thuộc tính của **Sản phẩm**.
_Avoid_: key, serial (khi cần phân biệt với **Tài khoản**)

**Tài khoản** (Account):
Một đơn vị hàng là thông tin đăng nhập (username, password, có thể kèm email khôi phục hoặc 2FA). Tài khoản có thể chia thành nhiều slot để bán cho nhiều khách, có thể có thời hạn và có thể phải bảo hành.
_Avoid_: acc, nick

**Đơn vị hàng** (Stock unit):
Một thứ cụ thể nằm trong kho: một **Mã dùng một lần** hoặc một **Tài khoản**.
_Avoid_: item, hàng (khi nói chung chung)

**Slot**:
Một phần của **Tài khoản** được bán cho một khách. Shop tự khai báo số slot tối đa khi nhập; mã dùng một lần coi như có đúng một slot.

**Giữ hàng** (Reserve):
Tạm khoá một **Slot** cho một đơn để không giao trùng cho đơn khác, trước khi **Giao hàng**.

**Giao hàng** (Deliver):
Việc trao nội dung **Slot** cho khách. Đây là thời điểm hàng rời kho và, với **Tài khoản**, là thời điểm bắt đầu tính thời hạn.
_Avoid_: bán (kho không quản lý việc bán)

**Kênh bán** (Sales channel):
Nơi phát sinh đơn cần giao: xuất thủ công bởi nhân viên (chat, sàn) hoặc website gọi vào kho.

**Báo lỗi** (Defect report):
Ghi nhận một **Slot** đã giao nhưng không dùng được.

**Đổi hàng** (Replacement):
Giao một **Slot** khác thay cho **Slot** bị **Báo lỗi**, có liên kết với lần **Giao hàng** gốc. Kho không xử lý hoàn tiền.

**Khiếu nại nhà cung cấp** (Supplier claim):
Việc đòi **Nhà cung cấp** bồi hoàn cho **Đơn vị hàng** lỗi.

**Lô nhập** (Batch):
Một lần nhập hàng vào kho từ một **Nhà cung cấp**, mang **Giá vốn** của các đơn vị hàng trong lô.

**Nhà cung cấp** (Supplier):
Bên bán hàng số cho shop.

**Giá vốn** (Cost):
Giá shop trả cho một đơn vị hàng, xác định theo **Lô nhập**, dùng để tính lãi/lỗ.

## Relationships

- Một **Sản phẩm** là loại **Mã dùng một lần** hoặc loại **Tài khoản**
- Một **Lô nhập** đến từ đúng một **Nhà cung cấp** và chứa nhiều đơn vị hàng của một hoặc nhiều **Sản phẩm**
- Mỗi **Đơn vị hàng** thuộc đúng một **Lô nhập**
- Một **Tài khoản** có một hoặc nhiều **Slot**; một **Mã dùng một lần** có đúng một **Slot**
- Mỗi lần **Giao hàng** trao đúng một **Slot** cho một đơn của một **Kênh bán**
- Một **Đổi hàng** nối lần **Giao hàng** mới với lần **Giao hàng** có **Báo lỗi**

## Flagged ambiguities

- "key" và "code" được dùng lẫn cho nhau. Đã chốt: cả hai đều là **Mã dùng một lần**.
