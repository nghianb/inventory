# Kho hàng số (Inventory)

Hệ thống quản lý kho nội bộ cho một shop bán hàng số (CD key, code, tài khoản). Người dùng là nhân viên nội bộ của shop.

## Language

**Sản phẩm** (Product):
Một mặt hàng mà shop bán, ví dụ "Windows 11 Pro key" hay "Netflix Premium 1 tháng". Sản phẩm là loại hàng, không phải từng đơn vị hàng trong kho. Mỗi thời hạn bán khác nhau là một Sản phẩm riêng, có kho riêng. Sản phẩm khai báo các **Trường nội dung** của hàng thuộc nó. Sản phẩm đã có hàng thì không bị xoá, chỉ có thể **Ngừng bán**.
_Avoid_: mặt hàng, SKU (khi nói về đơn vị trong kho), gói bán

**Mã dùng một lần** (One-time code):
Một đơn vị hàng là chuỗi kích hoạt, giao cho đúng một khách và hết giá trị sau khi giao. Gồm cả CD key (Steam, Windows...) và code (gift card, thẻ nạp có mệnh giá, voucher). CD key và code chỉ khác nhau ở thuộc tính của **Sản phẩm**.
_Avoid_: key, serial (khi cần phân biệt với **Tài khoản**)

**Tài khoản** (Account):
Một đơn vị hàng là thông tin đăng nhập (username, password, có thể kèm email khôi phục hoặc 2FA). Tài khoản có thể chia thành nhiều slot để bán cho nhiều khách, có thể có thời hạn và có thể phải bảo hành.
_Avoid_: acc, nick

**Đơn vị hàng** (Stock unit):
Một thứ cụ thể nằm trong kho: một **Mã dùng một lần** hoặc một **Tài khoản**. Có trạng thái Hoạt động, Lỗi hoặc Đã huỷ; khi Lỗi hoặc Đã huỷ thì mọi **Slot** còn trong kho của nó không bán được. Mang **Giá vốn** của cả đơn vị.
_Avoid_: item, hàng (khi nói chung chung)

**Trường nội dung** (Content field):
Một phần nội dung của **Đơn vị hàng** do **Sản phẩm** khai báo, ví dụ Serial và Mã thẻ của thẻ nạp, hay username và password của **Tài khoản**. Mỗi trường có cờ nhạy cảm, mặc định bật: trường nhạy cảm được mã hoá và che hoàn toàn; trường không nhạy cảm (ví dụ Serial thẻ nạp) hiển thị và tìm kiếm được. Một trường được chọn làm **Khoá chống trùng**. Khi Sản phẩm đã có hàng, chỉ được thêm trường tuỳ chọn hoặc đổi tên hiển thị.

**Khoá chống trùng** (Dedupe key):
**Trường nội dung** dùng để phát hiện một **Đơn vị hàng** bị nhập hai lần. Với **Tài khoản** là định danh đăng nhập, không phải mật khẩu. Mã dùng một lần là duy nhất toàn kho mãi mãi; một Tài khoản được nhập lại khi Đơn vị hàng cũ đã bị **Huỷ hàng** hoặc quá **Hạn sử dụng**, và Đơn vị hàng mới liên kết với cái cũ.

**Slot**:
Một phần của **Tài khoản** được bán cho một khách. Shop tự khai báo số slot tối đa khi nhập; mã dùng một lần coi như có đúng một slot. Có trạng thái Còn hàng, Đã giữ, Đã giao hoặc Đã huỷ. Slot đã giao không bao giờ quay về Còn hàng.

**Giữ hàng** (Reserve):
Tạm khoá một **Slot** cho một **Phiếu xuất** để không giao trùng cho đơn khác, trước khi **Giao hàng**. Giữ hàng luôn có hạn; quá hạn thì Slot tự trở về Còn hàng. Xuất kho thủ công giữ và giao trong một bước.

**Giao hàng** (Deliver):
Việc trao nội dung **Slot** cho khách. Đây là thời điểm hàng rời kho và là mốc tính **Hạn bảo hành**.
_Avoid_: bán (kho không quản lý việc bán)

**Phiếu xuất** (Dispatch):
Một đơn cần giao từ một **Kênh bán**, gồm một hoặc nhiều **Dòng xuất**, định danh bằng mã đơn ngoài duy nhất trong kênh bán đó. Ghi thông tin khách dạng văn bản tự do để tra cứu khi bảo hành. Có trạng thái Đang giữ, Hoàn tất hoặc Đã huỷ; huỷ phiếu thì nhả mọi **Slot** đang giữ. Phiếu xuất giữ đủ số lượng cho mọi Dòng xuất hoặc thất bại toàn bộ, không giao thiếu.
_Avoid_: đơn hàng (kho không quản lý đơn bán), Khách hàng (kho không có thực thể khách)

**Dòng xuất** (Dispatch line):
Phần của một **Phiếu xuất** dành cho đúng một **Sản phẩm**: số lượng **Slot** cần giao và **Giá bán** tuỳ chọn. Các **Slot** được chọn tự động theo **Thứ tự xuất**, nhân viên không chọn đích danh.

**Thứ tự xuất** (Pick order):
Quy tắc chọn **Slot** Còn hàng cho một **Dòng xuất**: slot của **Tài khoản** đã giao dở trước, rồi **Hạn sử dụng** gần nhất trước (hàng không có hạn xếp sau), rồi hàng nhập trước. Bỏ qua Slot không đạt **Hạn còn lại tối thiểu**.

**Hạn còn lại tối thiểu** (Minimum remaining shelf life):
Số ngày **Hạn sử dụng** còn lại ít nhất mà một **Slot** phải có để được giao, do **Sản phẩm** khai báo, mặc định 0. Slot không đạt vẫn là tồn kho nhưng không bán được.

**Giá bán** (Sale price):
Số tiền (VND) khách trả cho một **Dòng xuất**, ghi tuỳ chọn khi xuất. Kho chỉ lưu để tính lãi/lỗ, không quản lý việc bán.

**Giao thêm** (Additional delivery):
Thêm **Dòng xuất** mới vào một **Phiếu xuất** đã Hoàn tất, khi khách của cùng đơn mua thêm. Phần thêm giữ đủ hoặc thất bại.

**Giao thay** (Corrective delivery):
Sửa một lần **Giao hàng** nhầm do nhân viên: **Huỷ hàng** Slot đã giao với lý do giao nhầm, rồi giao Slot khác (có thể của Sản phẩm khác) vào cùng **Phiếu xuất**, liên kết với lần giao bị huỷ. Không đi qua **Báo lỗi** và không tính là hàng lỗi.
_Avoid_: Đổi hàng (Đổi hàng là do hàng lỗi)

**Mẫu giao hàng** (Delivery template):
Văn bản do **Sản phẩm** khai báo để ghép nội dung một **Slot** thành tin nhắn gửi khách, gồm các **Trường nội dung**, **Hạn sử dụng**, **Hạn bảo hành** và hướng dẫn cố định. Sản phẩm chưa có mẫu thì dùng mẫu mặc định liệt kê các trường.

**Kênh bán** (Sales channel):
Nguồn phát sinh đơn cần giao, do quản trị khai báo, loại thủ công (Shopee, Facebook, Zalo...) hoặc API (website). Mỗi kênh quy định có bắt buộc mã đơn ngoài không; nếu không bắt buộc và nhân viên để trống thì mã được tự sinh.

**Hạn sử dụng** (Expiry date):
Ngày tuỳ chọn mà sau đó **Đơn vị hàng** không được bán nữa, do nhà cung cấp quyết định (tài khoản hết gói, gift card hết hạn).
_Avoid_: thời hạn (dễ nhầm với **Hạn bảo hành**)

**Hạn bảo hành** (Warranty end):
Ngày **Giao hàng** cộng thời hạn bảo hành của **Sản phẩm**, nhưng không vượt quá **Hạn sử dụng** của **Slot**. Là giá trị suy ra, không phải trạng thái. **Đổi hàng** kế thừa Hạn bảo hành của lần giao gốc, không tính lại. Hết Hạn sử dụng không bao giờ là lỗi.

**Huỷ hàng** (Void):
Việc shop tự loại một **Slot** hoặc **Đơn vị hàng** khỏi vòng đời bán vì lý do không phải lỗi hàng, kèm lý do: giao nhầm, nhân viên làm lộ nội dung, ngừng kinh doanh lô hàng. Hàng hỏng hoặc bị nhà cung cấp thu hồi thì dùng **Đánh dấu Lỗi**.
_Avoid_: xoá, thu hồi về kho

**Đánh dấu Lỗi** (Mark defective):
Quản trị chuyển một **Đơn vị hàng** sang Lỗi mà không cần **Báo lỗi** của khách, khi nhà cung cấp thu hồi hoặc phát hiện hỏng trong kho. Vẫn sinh danh sách **Lần giao bị ảnh hưởng**.

**Khôi phục** (Restore):
Quản trị đưa một **Đơn vị hàng** Lỗi về Hoạt động, kèm lý do, khi nhà cung cấp sửa được hàng. Đơn vị hàng không còn tính vào **Tỉ lệ lỗi** và bị gỡ khỏi **Khiếu nại nhà cung cấp** chưa giải quyết; **Báo lỗi** và **Đổi hàng** đã làm giữ nguyên.

**Chi phí đổi hàng** (Replacement cost):
**Giá vốn** của **Slot** giao ra trong một **Đổi hàng**. Gắn với **Nhà cung cấp** của **Đơn vị hàng** lỗi, không cộng vào lãi/lỗ của **Phiếu xuất** gốc.

**Tồn lỗi** (Defective stock):
Các **Slot** Còn hàng của **Đơn vị hàng** Lỗi: vẫn nằm trong kho nhưng không bán được, tách khỏi tồn bán được trong báo cáo và cảnh báo sắp hết.

**Tỉ lệ lỗi** (Defect rate):
Của một **Nhà cung cấp**: số **Đơn vị hàng** Lỗi chia cho số Đơn vị hàng đã giao ít nhất một **Slot**. Tử số gồm Lỗi từ **Báo lỗi** có **Phạm vi lỗi** cả Đơn vị hàng và Lỗi do **Đánh dấu Lỗi**, không gồm hàng đã **Khôi phục**. Không gồm **Giao thay** hay Báo lỗi chỉ Slot.

**Báo lỗi** (Defect report):
Ghi nhận một **Slot** đã giao (của **Tài khoản** hoặc **Mã dùng một lần**) nhưng không dùng được, do nhân viên tạo trong **Hạn bảo hành**. Có trạng thái Chờ xác minh, Xác nhận hoặc Bác bỏ. Trong lúc Chờ xác minh, các Slot còn trong kho của cùng **Đơn vị hàng** tạm ngừng bán. Khi Xác nhận, người xác minh chọn **Phạm vi lỗi**. Báo lỗi đã Xác nhận có **Kết quả xử lý**: Chờ đổi, Đã đổi hoặc Không đổi.
_Avoid_: bảo hành (khi nói về một lần khách báo)

**Phạm vi lỗi** (Defect scope):
Mức mà một **Báo lỗi** đã Xác nhận ảnh hưởng: **cả Đơn vị hàng** (Đơn vị hàng chuyển sang Lỗi, tính vào tỉ lệ lỗi **Nhà cung cấp**) hoặc **chỉ Slot** (Đơn vị hàng vẫn Hoạt động, ví dụ một profile bị khách khác phá).

**Lần giao bị ảnh hưởng** (Affected delivery):
Lần **Giao hàng** khác của một **Đơn vị hàng** vừa chuyển sang Lỗi. Hệ thống liệt kê để nhân viên chủ động liên hệ khách và tạo **Báo lỗi** hàng loạt, tự Xác nhận; không tự **Đổi hàng**.

**Đổi hàng** (Replacement):
Giao một **Slot** khác thay cho **Slot** có **Báo lỗi** đã Xác nhận, có liên kết với lần **Giao hàng** gốc và kế thừa **Hạn bảo hành** của nó. Mặc định cùng **Sản phẩm**; Slot thay thế phải có **Hạn sử dụng** phủ hết Hạn bảo hành kế thừa. Kho không xử lý hoàn tiền; khách nhận hoàn tiền ngoài kho thì Báo lỗi có kết quả Không đổi.

**Khiếu nại nhà cung cấp** (Supplier claim):
Việc đòi một **Nhà cung cấp** bồi hoàn cho một hoặc nhiều **Đơn vị hàng** Lỗi của họ. Có trạng thái Nháp, Đã gửi, Đã giải quyết hoặc Đã huỷ. Khi giải quyết, mỗi Đơn vị hàng có kết quả riêng: bồi hoàn tiền (kèm số tiền), hàng thay thế (vào kho bằng một **Lô nhập** liên kết với Khiếu nại) hoặc bị từ chối. Không bắt buộc khiếu nại mọi Đơn vị hàng Lỗi.

**Ngừng bán** (Discontinue):
Đánh dấu một **Sản phẩm** không còn được giữ hàng hay giao mới, nhưng vẫn dùng được cho **Đổi hàng** của các lần giao cũ.

**Lô nhập** (Batch):
Một lần nhập hàng vào kho từ một **Nhà cung cấp**, gồm một hoặc nhiều **Dòng nhập**. Chỉ vào kho khi nhân viên xác nhận sau bước xem trước; ghi lại số dòng bị bỏ vì lỗi hoặc trùng.

**Dòng nhập** (Batch line):
Phần của một **Lô nhập** dành cho đúng một **Sản phẩm**: một file hoặc một danh sách dán, kèm đơn giá **Giá vốn** và các giá trị mặc định (số slot, **Hạn sử dụng**) cho các **Đơn vị hàng** trong đó.

**Huỷ nhập** (Import reversal):
Rút lại hàng đã nhập nhầm, coi như chưa từng vào kho: giải phóng **Khoá chống trùng** để nhập lại được, nhưng vẫn giữ bản ghi. Chỉ làm được khi mọi **Slot** liên quan vẫn Còn hàng.
_Avoid_: xoá lô, Huỷ hàng (Huỷ hàng không giải phóng Khoá chống trùng)

**Nhà cung cấp** (Supplier):
Bên bán hàng số cho shop.

**Giá vốn** (Cost):
Giá shop trả cho một **Đơn vị hàng**, ghi khi nhập theo **Lô nhập**, dùng để tính lãi/lỗ. Giá vốn của một **Slot** bằng giá vốn Đơn vị hàng chia đều cho số slot. Không bao giờ sửa sau khi nhập, kể cả khi được bồi hoàn. Hàng thay thế từ **Khiếu nại nhà cung cấp** có Giá vốn bằng 0.

**Sổ biến động kho** (Stock ledger):
Nhật ký chỉ-ghi-thêm mọi lần chuyển trạng thái của **Slot** và **Đơn vị hàng**: ai, khi nào, từ trạng thái nào sang trạng thái nào, lý do. Tách biệt với nhật ký xem nội dung mã.

## Relationships

- Một **Sản phẩm** là loại **Mã dùng một lần** hoặc loại **Tài khoản**, và khai báo một hoặc nhiều **Trường nội dung**
- Một **Lô nhập** đến từ đúng một **Nhà cung cấp** và gồm một hoặc nhiều **Dòng nhập**
- Một **Dòng nhập** thuộc đúng một **Sản phẩm** và chứa nhiều **Đơn vị hàng**
- Mỗi **Đơn vị hàng** thuộc đúng một **Dòng nhập** (do đó đúng một **Lô nhập** và đúng một **Sản phẩm**)
- Một **Tài khoản** có một hoặc nhiều **Slot**; một **Mã dùng một lần** có đúng một **Slot**
- Một **Phiếu xuất** thuộc đúng một **Kênh bán** và gồm một hoặc nhiều **Dòng xuất**
- Một **Dòng xuất** thuộc đúng một **Sản phẩm** và gồm một hoặc nhiều lần **Giao hàng**
- Mỗi lần **Giao hàng** trao đúng một **Slot**
- Một **Đổi hàng** nối lần **Giao hàng** mới với lần **Giao hàng** có **Báo lỗi**; Đổi hàng nối tiếp nhau vẫn trỏ về lần giao gốc
- Mỗi **Báo lỗi** thuộc đúng một lần **Giao hàng**
- Một **Khiếu nại nhà cung cấp** thuộc đúng một **Nhà cung cấp** và gồm một hoặc nhiều **Đơn vị hàng** Lỗi
- Mỗi lần chuyển trạng thái sinh đúng một dòng trong **Sổ biến động kho**

## Flagged ambiguities

- Báo lỗi của khách chưa chắc là hàng lỗi. Đã chốt: **Báo lỗi** phải được xác minh trước khi **Đơn vị hàng** chuyển sang Lỗi.
- "key" và "code" được dùng lẫn cho nhau. Đã chốt: cả hai đều là **Mã dùng một lần**.
- "thời hạn" từng dùng cho cả hạn của hàng lẫn hạn bảo hành. Đã chốt: tách thành **Hạn sử dụng** và **Hạn bảo hành**.
- "thu hồi" có thể hiểu là đưa Slot đã giao về kho. Đã chốt: không có thao tác này, chỉ có **Huỷ hàng**.
- "gia hạn tài khoản" có thể hiểu là sửa **Hạn sử dụng** của Đơn vị hàng cũ. Đã chốt: gia hạn là nhập lại thành **Đơn vị hàng** mới, liên kết với cái cũ, có **Giá vốn** riêng.
- "nhà cung cấp thu hồi" và "hỏng trong kho" từng là lý do **Huỷ hàng**, khiến hàng lỗi không vào tỉ lệ lỗi. Đã chốt: đó là **Đánh dấu Lỗi**; Huỷ hàng chỉ dành cho lý do không phải lỗi hàng.
- Hàng nhập nhầm từng chỉ có cách Huỷ hàng, khiến Mã dùng một lần không nhập lại được. Đã chốt: tách riêng **Huỷ nhập**, giải phóng Khoá chống trùng.
