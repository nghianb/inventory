# Kho hàng số (Inventory)

Hệ thống quản lý kho nội bộ cho một shop bán hàng số (CD key, code, tài khoản). Người dùng là nhân viên nội bộ của shop.

## Language

**Sản phẩm** (Product):
Một mặt hàng mà shop bán, ví dụ "Windows 11 Pro key" hay "Netflix Premium 1 tháng". Sản phẩm là thứ được bán nói chung, không phải từng đơn vị hàng cụ thể trong kho. Mỗi thời hạn bán khác nhau là một Sản phẩm riêng, có kho riêng. Có **Mã sản phẩm** duy nhất do quản trị đặt (ví dụ `NETFLIX-1M`), là cách **Kênh bán** loại API tham chiếu tới Sản phẩm; không đổi được sau khi đã có **Phiếu xuất**. Sản phẩm thuộc đúng một **Loại sản phẩm**, nơi khai báo **Dạng hàng** và các **Trường nội dung** của hàng thuộc nó; chuyển sang Loại khác chỉ được khi Sản phẩm chưa có hàng. Sản phẩm tự khai thời hạn bảo hành, **Hạn còn lại tối thiểu** và **Ngưỡng sắp hết** của riêng mình. Sản phẩm đã có hàng thì không bị xoá, chỉ có thể **Ngừng bán**.
_Avoid_: mặt hàng, SKU (khi nói về đơn vị trong kho), gói bán

**Loại sản phẩm** (Product type):
Khuôn dùng chung cho nhiều **Sản phẩm** mô tả hàng giống nhau, ví dụ "Thẻ nạp" hay "Tài khoản có 2FA": khai **Dạng hàng**, các **Trường nội dung**, cách chuẩn hoá **Khoá chống trùng** và **Mẫu giao hàng** mặc định. Mọi Sản phẩm thuộc đúng một Loại và không khai lại những thứ đó, nên sửa Loại là sửa mọi Sản phẩm thuộc nó. Có tên duy nhất, không có mã vì không **Kênh bán** nào tham chiếu tới nó. Thay đổi chạm vào dữ liệu đã lưu bị từ chối trọn gói khi Loại đã có Sản phẩm có hàng (ADR 0004). Chưa Sản phẩm nào dùng thì xoá được; đang có thì chỉ ngừng dùng, tức ẩn khỏi danh sách chọn khi tạo Sản phẩm mới.
_Avoid_: mẫu sản phẩm, nhóm sản phẩm, danh mục (`ProductCatalog` trong code nghĩa là sổ đăng ký Sản phẩm, không phải Loại)

**Dạng hàng** (Stock form):
Hàng của một **Loại sản phẩm** nằm trong kho dưới hình thức nào: **Mã dùng một lần** hay **Tài khoản**. Quyết định một **Đơn vị hàng** chia được mấy **Slot** (Mã dùng một lần luôn đúng một).
_Avoid_: loại sản phẩm (đó là **Loại sản phẩm**), kiểu hàng

**Mã dùng một lần** (One-time code):
Một đơn vị hàng là chuỗi kích hoạt, giao cho đúng một khách và hết giá trị sau khi giao. Gồm cả CD key (Steam, Windows...) và code (gift card, thẻ nạp có mệnh giá, voucher). CD key và code chỉ khác nhau ở thuộc tính của **Sản phẩm**.
_Avoid_: key, serial (khi cần phân biệt với **Tài khoản**)

**Tài khoản** (Account):
Một đơn vị hàng là thông tin đăng nhập (username, password, có thể kèm email khôi phục hoặc 2FA). Tài khoản có thể chia thành nhiều slot để bán cho nhiều khách, có thể có thời hạn và có thể phải bảo hành.
_Avoid_: acc, nick

**Đơn vị hàng** (Stock unit):
Một thứ cụ thể nằm trong kho: một **Mã dùng một lần** hoặc một **Tài khoản**. Có trạng thái Hoạt động, Lỗi, Đã huỷ hoặc Đã huỷ nhập; khi Lỗi hoặc Đã huỷ thì mọi **Slot** còn trong kho của nó không bán được. Mang **Giá vốn** của cả đơn vị.
_Avoid_: item, hàng (khi nói chung chung), đơn vị sản phẩm (lẫn với **Sản phẩm** và **Loại sản phẩm**)

**Trường nội dung** (Content field):
Một phần nội dung của **Đơn vị hàng** do **Loại sản phẩm** khai báo, ví dụ Serial và Mã thẻ của thẻ nạp, hay username và password của **Tài khoản**. Mỗi trường có cờ nhạy cảm, mặc định bật: trường nhạy cảm được mã hoá và che hoàn toàn; trường không nhạy cảm (ví dụ Serial thẻ nạp) hiển thị và tìm kiếm được. Một trường được chọn làm **Khoá chống trùng**. Định danh và tên hiển thị dùng chung một không gian tên duy nhất trong một Loại sản phẩm (so sau khi trim, không phân biệt hoa thường), vì nội dung giao khách và dạng che đánh chỉ mục theo tên hiển thị, còn cột file nhập khớp theo định danh hoặc tên hiển thị. Khi Loại sản phẩm đã có **Sản phẩm** nào có hàng, chỉ được thêm trường tuỳ chọn hoặc đổi tên hiển thị — hai thay đổi này áp cho mọi Sản phẩm của Loại; mọi thay đổi khác bị từ chối trọn gói, kể cả với Sản phẩm chưa có hàng (ADR 0004).

**Khoá chống trùng** (Dedupe key):
**Trường nội dung** dùng để phát hiện một **Đơn vị hàng** bị nhập hai lần. Kho chỉ lưu HMAC của nó, và không bao giờ giữ song song hai hash: khi xoay khoá mã hoá HMAC, hash được tính lại, nhập hàng và tra cứu theo Khoá chống trùng tạm dừng cho tới khi xong (ADR 0003). Với **Tài khoản** là định danh đăng nhập, không phải mật khẩu. Mã dùng một lần là duy nhất toàn kho mãi mãi, trừ khi bị **Huỷ nhập**; một Tài khoản được nhập lại khi Đơn vị hàng cũ đã bị **Huỷ hàng** hoặc quá **Hạn sử dụng**, và Đơn vị hàng mới liên kết với cái cũ.

**Slot**:
Một phần của **Tài khoản** được bán cho một khách. Shop tự khai báo số slot tối đa khi nhập; mã dùng một lần coi như có đúng một slot. Có trạng thái Còn hàng, Đã giữ, Đã giao, Đã huỷ hoặc Đã huỷ nhập. Slot đã giao không bao giờ quay về Còn hàng.

**Giữ hàng** (Reserve):
Tạm khoá một **Slot** cho một **Phiếu xuất** để không giao trùng cho đơn khác, trước khi **Giao hàng**. Giữ hàng luôn có hạn, do **Kênh bán** quy định; quá hạn thì Slot tự trở về Còn hàng và Phiếu xuất sang Hết hạn giữ. Xuất kho thủ công giữ và giao trong một bước.

**Giao hàng** (Deliver):
Việc trao nội dung **Slot** cho khách. Đây là thời điểm hàng rời kho và là mốc tính **Hạn bảo hành**.
_Avoid_: bán (kho không quản lý việc bán)

**Xuất hàng** (Outbound):
Nửa công việc đưa hàng ra khỏi kho: nhận đơn của **Kênh bán** thành **Phiếu xuất**, **Giao hàng**, rồi xử lý **Báo lỗi** và **Đổi hàng** sau khi giao. Là phần việc của **Vai trò** Bán hàng, không phải tên của Vai trò đó.
_Avoid_: bán hàng (đó là tên **Vai trò**), xuất kho (dễ nhầm với **Tạm dừng xuất kho**)

**Phiếu xuất** (Dispatch):
Một đơn cần giao từ một **Kênh bán**, gồm một hoặc nhiều **Dòng xuất**, định danh bằng mã đơn ngoài duy nhất trong kênh bán đó. Ghi thông tin khách dạng văn bản tự do để tra cứu khi bảo hành. Có trạng thái Đang giữ, Hết hạn giữ, Hoàn tất hoặc Đã huỷ; huỷ phiếu thì nhả mọi **Slot** đang giữ. Phiếu Hết hạn giữ đã nhả Slot nhưng vẫn giao được nếu giữ lại đủ hàng; Đã huỷ là trạng thái cuối. Mã đơn ngoài bị chiếm vĩnh viễn khi phiếu được tạo, kể cả khi phiếu bị huỷ. Phiếu xuất giữ đủ số lượng cho mọi Dòng xuất hoặc thất bại toàn bộ, không giao thiếu.
_Avoid_: đơn hàng (kho không quản lý đơn bán), Khách hàng (kho không có thực thể khách)

**Dòng xuất** (Dispatch line):
Phần của một **Phiếu xuất** dành cho đúng một **Sản phẩm**: số lượng **Slot** cần giao và **Giá bán** tuỳ chọn. Các **Slot** được chọn tự động theo **Thứ tự xuất**, nhân viên không chọn đích danh, trừ **Ghi nhận giao bù**.

**Thứ tự xuất** (Pick order):
Quy tắc chọn **Slot** Còn hàng cho một **Dòng xuất**: slot của **Tài khoản** đã giao dở trước, rồi **Hạn sử dụng** gần nhất trước (hàng không có hạn xếp sau), rồi hàng nhập trước. Bỏ qua Slot không đạt **Hạn còn lại tối thiểu**.

**Hạn còn lại tối thiểu** (Minimum remaining shelf life):
Số ngày **Hạn sử dụng** còn lại ít nhất mà một **Slot** phải có để được giao, do **Sản phẩm** khai báo, mặc định 0. Slot không đạt vẫn là tồn kho nhưng không bán được.

**Giá bán** (Sale price):
Tổng số tiền (VND) khách trả cho cả một **Dòng xuất**, không phải đơn giá; ghi tuỳ chọn khi xuất, trừ khi **Kênh bán** bắt buộc. Dòng xuất loại **Đổi hàng** và **Giao thay** không có Giá bán, và DB chặn ghi; loại **Ghi nhận giao bù** thì có, vì nó chép lại một lần bán đã thu tiền. Khi khách được hoàn tiền ngoài kho, Giá bán được sửa xuống số tiền shop thực giữ. Kho chỉ lưu để tính **Lãi gộp**, không quản lý việc bán.

**Giao thêm** (Additional delivery):
Thêm **Dòng xuất** mới vào một **Phiếu xuất** đã Hoàn tất, khi khách của cùng đơn mua thêm. Phần thêm giữ đủ hoặc thất bại.

**Giao thay** (Corrective delivery):
Sửa một lần **Giao hàng** nhầm do nhân viên: **Huỷ hàng** Slot đã giao với lý do giao nhầm, rồi giao Slot của **Đơn vị hàng** khác (có thể của Sản phẩm khác) vào cùng **Phiếu xuất**, liên kết với lần giao bị huỷ. Giao sang Sản phẩm khác thì thêm một **Dòng xuất** loại Giao thay, dòng cũ giữ nguyên số lượng. Nếu nhân viên chọn Huỷ hàng cả **Đơn vị hàng** thì các lần giao khác của nó là **Lần giao bị ảnh hưởng**. Không đi qua **Báo lỗi** và không tính là hàng lỗi.
_Avoid_: Đổi hàng (Đổi hàng là do hàng lỗi)

**Mẫu giao hàng** (Delivery template):
Văn bản để ghép nội dung một **Slot** thành tin nhắn gửi khách, gồm các **Trường nội dung**, **Hạn sử dụng**, **Hạn bảo hành** và hướng dẫn cố định. Tìm theo ba bậc lúc ghép tin nhắn, không chép sẵn: mẫu riêng của **Sản phẩm**, rồi mẫu của **Loại sản phẩm**, rồi mẫu mặc định liệt kê các trường. Sản phẩm ghi đè mẫu riêng vì hướng dẫn kích hoạt khác nhau giữa các Sản phẩm cùng Loại; Loại đổi mẫu thì Sản phẩm đã ghi đè không bị đụng tới.

**Kênh bán** (Sales channel):
Nguồn phát sinh đơn cần giao, do quản trị khai báo, loại thủ công (Shopee, Facebook, Zalo...) hoặc API (website). Mỗi kênh quy định có bắt buộc mã đơn ngoài không; nếu không bắt buộc và nhân viên để trống thì mã được tự sinh. Kênh loại API gọi vào kho bằng **Khoá API**, quy định hạn **Giữ hàng** (mặc định 15 phút) và có bắt buộc **Giá bán** không (mặc định có).

**Khoá API** (API key):
Bí mật mà một **Kênh bán** loại API dùng để gọi vào kho. Chỉ hiển thị một lần khi tạo; chỉ **Quản trị** tạo, thu hồi hoặc xoay khoá, và mỗi thao tác đó ghi vào **Nhật ký bảo mật**. Một kênh có thể có hai khoá cùng hoạt động trong lúc xoay. Là "ai" trong **Nhật ký xem mã** khi nội dung được trả qua API.
_Avoid_: token (khi nói về nghiệp vụ)

**Hạn sử dụng** (Expiry date):
Ngày tuỳ chọn mà sau đó **Đơn vị hàng** không được bán nữa, do nhà cung cấp quyết định (tài khoản hết gói, gift card hết hạn).
_Avoid_: thời hạn (dễ nhầm với **Hạn bảo hành**)

**Hạn bảo hành** (Warranty end):
Ngày **Giao hàng** cộng thời hạn bảo hành mà **Sản phẩm** khai báo tại thời điểm Giao hàng, nhưng không vượt quá **Hạn sử dụng** của **Slot**. Sản phẩm đổi thời hạn bảo hành sau đó thì chỉ áp cho lần giao mới. Là giá trị suy ra, không phải trạng thái. **Đổi hàng** kế thừa Hạn bảo hành của lần giao gốc, không tính lại. Hết Hạn sử dụng không bao giờ là lỗi.

**Huỷ hàng** (Void):
Việc shop tự loại một **Slot** hoặc **Đơn vị hàng** khỏi vòng đời bán vì lý do không phải lỗi hàng, kèm lý do: giao nhầm, nhân viên làm lộ nội dung, ngừng kinh doanh lô hàng. Hàng hỏng hoặc bị nhà cung cấp thu hồi thì dùng **Đánh dấu Lỗi**.
_Avoid_: xoá, thu hồi về kho

**Đánh dấu Lỗi** (Mark defective):
Quản trị chuyển một **Đơn vị hàng** sang Lỗi mà không cần **Báo lỗi** của khách, khi nhà cung cấp thu hồi hoặc phát hiện hỏng trong kho. Vẫn sinh danh sách **Lần giao bị ảnh hưởng**.

**Khôi phục** (Restore):
Quản trị đưa một **Đơn vị hàng** Lỗi về Hoạt động, kèm lý do, khi nhà cung cấp sửa được hàng. Đơn vị hàng không còn tính vào **Tỉ lệ lỗi** và bị gỡ khỏi **Khiếu nại nhà cung cấp** chưa giải quyết; **Báo lỗi** và **Đổi hàng** đã làm giữ nguyên.

**Chi phí đổi hàng** (Replacement cost):
**Giá vốn** của **Slot** giao ra trong một **Đổi hàng**. Gắn với **Nhà cung cấp** và **Sản phẩm** của **Đơn vị hàng** lỗi, không trừ vào **Lãi gộp** của **Phiếu xuất** gốc mà trừ vào **Lãi ròng kho**.

**Lãi gộp** (Gross profit):
**Giá bán** trừ **Giá vốn** của các **Slot** khách thực nhận, tính theo thời điểm **Giao hàng**. Slot giao nhầm đã **Huỷ hàng** không tính; Slot của **Giao thay** tính vào **Dòng xuất** gốc. Dòng xuất chưa có Giá bán không tính vào Lãi gộp. Hàng thay thế từ **Khiếu nại nhà cung cấp** không ghi thu nhập riêng, vì Giá vốn 0 đã phản ánh bồi hoàn khi bán.
_Avoid_: lãi (khi không rõ gộp hay ròng)

**Tổn thất** (Loss):
**Giá vốn** của **Slot** rời vòng đời bán mà không thu tiền, tính theo thời điểm phát sinh: Tổn thất hàng Lỗi (Slot còn trong kho khi **Đơn vị hàng** chuyển Lỗi, mất đi nếu **Khôi phục**), Tổn thất **Huỷ hàng** (theo lý do) và Tổn thất hết hạn (Slot Còn hàng quá **Hạn sử dụng**). Mỗi Slot chỉ tính tổn thất một lần. Hàng bị **Huỷ nhập** không phải tổn thất.

**Điều chỉnh** (Adjustment):
Phần làm mỏng lãi mà không đi qua một lần bán nào, tính theo thời điểm phát sinh và gắn với **Sản phẩm** cùng **Nhà cung cấp** của **Đơn vị hàng**: **Chi phí đổi hàng**, **Tổn thất** (hàng Lỗi, **Huỷ hàng** theo từng lý do, hết hạn), trừ đi bồi hoàn tiền từ **Khiếu nại nhà cung cấp**. **Lãi ròng kho** = **Lãi gộp** − Điều chỉnh. Âm được khi trong kỳ đòi được nhiều hơn phần đã mất.

**Lỗ ròng** (Net supplier loss):
Phần shop mất vì hàng của một **Nhà cung cấp**, sau khi trừ tiền đã đòi được: **Tổn thất** hàng Lỗi cộng **Chi phí đổi hàng**, trừ bồi hoàn tiền. Hẹp hơn **Điều chỉnh**: Tổn thất **Huỷ hàng** và Tổn thất hết hạn là chuyện của shop nên không tính cho Nhà cung cấp. Bồi hoàn bằng hàng không trừ vào đây vì hàng thay thế đã có **Giá vốn** 0.

**Lãi ròng kho** (Net stock profit):
**Lãi gộp** trừ **Chi phí đổi hàng** và **Tổn thất**, cộng bồi hoàn tiền từ **Khiếu nại nhà cung cấp** (tính theo ngày giải quyết). Chỉ là lãi của hàng hoá, không gồm chi phí vận hành của shop. Luôn tính lại theo dữ liệu hiện tại, nên con số của một kỳ cũ có thể đổi.

**Tồn lỗi** (Defective stock):
Các **Slot** Còn hàng của **Đơn vị hàng** Lỗi: vẫn nằm trong kho nhưng không bán được, tách khỏi **Tồn bán được** trong báo cáo và cảnh báo sắp hết. Đã tính Tổn thất hàng Lỗi nên không **Huỷ hàng** được và không cộng vào giá trị tồn của báo cáo Tồn kho (hiện thành Giá vốn Tồn lỗi riêng); muốn huỷ thì **Khôi phục** trước.

**Tồn bán được** (Sellable stock):
Các **Slot** Còn hàng giao được ngay: thuộc **Đơn vị hàng** Hoạt động, không bị tạm ngừng vì **Báo lỗi** Chờ xác minh, chưa quá **Hạn sử dụng** và đạt **Hạn còn lại tối thiểu**. Slot Đã giữ không thuộc Tồn bán được. Đơn vị đếm tồn kho là Slot.
_Avoid_: tồn kho (khi nói chung chung, vì còn gồm Slot đang giữ, tạm ngừng, **Tồn lỗi**)

**Ngưỡng sắp hết** (Low-stock threshold):
Số **Slot** do **Sản phẩm** khai báo tuỳ chọn; khi **Tồn bán được** không vượt quá ngưỡng thì Sản phẩm bị cảnh báo sắp hết. Để trống thì không cảnh báo; Sản phẩm **Ngừng bán** không bị cảnh báo.

**Tỉ lệ lỗi** (Defect rate):
Của một **Nhà cung cấp**: số **Đơn vị hàng** Lỗi chia cho số Đơn vị hàng đã giao ít nhất một **Slot**. Tử số gồm Lỗi từ **Báo lỗi** có **Phạm vi lỗi** cả Đơn vị hàng và Lỗi do **Đánh dấu Lỗi**, không gồm hàng đã **Khôi phục**. Không gồm **Giao thay** hay Báo lỗi chỉ Slot. Tính theo lứa nhập: tỉ lệ lỗi của một khoảng thời gian xét các Đơn vị hàng có **Lô nhập** xác nhận trong khoảng đó, nên con số của một kỳ cũ còn tăng dần. Dòng bị bỏ vì lỗi định dạng hoặc trùng khi nhập không tính vào Tỉ lệ lỗi.

**Báo lỗi** (Defect report):
Ghi nhận một **Slot** đã giao (của **Tài khoản** hoặc **Mã dùng một lần**) nhưng không dùng được, do nhân viên tạo trong **Hạn bảo hành** (ngoài hạn, hoặc Sản phẩm không có bảo hành, thì chỉ **Quản trị** tạo kèm lý do). Mỗi Slot có nhiều nhất một Báo lỗi Chờ xác minh hoặc Xác nhận; tạo lại được sau Bác bỏ. Có trạng thái Chờ xác minh, Xác nhận hoặc Bác bỏ. Trong lúc Chờ xác minh, các Slot còn trong kho của cùng **Đơn vị hàng** tạm ngừng bán. Khi Xác nhận, người xác minh chọn **Phạm vi lỗi**. Báo lỗi đã Xác nhận có **Kết quả xử lý**: Chờ đổi, Đã đổi hoặc Không đổi.
_Avoid_: bảo hành (khi nói về một lần khách báo)

**Phạm vi lỗi** (Defect scope):
Mức mà một **Báo lỗi** đã Xác nhận ảnh hưởng: **cả Đơn vị hàng** (Đơn vị hàng chuyển sang Lỗi, tính vào tỉ lệ lỗi **Nhà cung cấp**) hoặc **chỉ Slot** (Đơn vị hàng vẫn Hoạt động, ví dụ một profile bị khách khác phá).

**Lần giao bị ảnh hưởng** (Affected delivery):
Lần **Giao hàng** khác của một **Đơn vị hàng** vừa chuyển sang Lỗi, hoặc vừa bị **Huỷ hàng** cả đơn vị trong một **Giao thay**; hoặc lần giao **Tài khoản** còn trong **Hạn bảo hành** khi nội dung kho bị coi là đã lộ. Hệ thống liệt kê để nhân viên chủ động liên hệ khách; với Đơn vị hàng Lỗi thì tạo được **Báo lỗi** hàng loạt, tự Xác nhận. Không tự Báo lỗi hay **Đổi hàng**.

**Đổi hàng** (Replacement):
Giao một **Slot** khác thay cho **Slot** có **Báo lỗi** đã Xác nhận, có liên kết với lần **Giao hàng** gốc và kế thừa **Hạn bảo hành** của nó. Mặc định cùng **Sản phẩm** (sang Sản phẩm khác phải có lý do). Slot được chọn theo **Thứ tự xuất** nhưng thay **Hạn còn lại tối thiểu** bằng điều kiện **Hạn sử dụng** phủ hết Hạn bảo hành kế thừa; không có Slot phủ đủ thì nhân viên chấp nhận Slot hạn ngắn hơn sau cảnh báo, và Hạn bảo hành không vượt Hạn sử dụng của Slot đó. Từ lần đổi thứ 3 trong cùng chuỗi, Bán hàng chỉ Đổi hàng được sau khi **Quản trị** duyệt. Kho không xử lý hoàn tiền; khách nhận hoàn tiền ngoài kho thì Báo lỗi có kết quả Không đổi.

**Khiếu nại nhà cung cấp** (Supplier claim):
Việc đòi một **Nhà cung cấp** bồi hoàn cho một hoặc nhiều **Đơn vị hàng** Lỗi của họ. Có trạng thái Nháp, Đã gửi, Đã giải quyết hoặc Đã huỷ. Khi giải quyết, mỗi Đơn vị hàng có kết quả riêng: bồi hoàn tiền (kèm số tiền và ngày nhận tiền; **Lãi ròng kho** vẫn tính theo ngày giải quyết), hàng thay thế (vào kho bằng **Lô nhập** liên kết với Khiếu nại, không quá số Đơn vị hàng được thay) hoặc bị từ chối. Khiếu nại Đã gửi mà mọi Đơn vị hàng đã **Khôi phục** thì tự huỷ. Không bắt buộc khiếu nại mọi Đơn vị hàng Lỗi.

**Ngừng bán** (Discontinue):
Đánh dấu một **Sản phẩm** không còn được giữ hàng hay giao mới, nhưng vẫn dùng được cho **Đổi hàng** và **Giao thay** của các lần giao cũ của chính nó.

**Nhập hàng** (Intake):
Nửa công việc đưa hàng vào kho: làm việc với **Nhà cung cấp**, tạo **Lô nhập**, và đòi bồi hoàn cho hàng lỗi qua **Khiếu nại nhà cung cấp**. Là phần việc của **Vai trò** Nhập kho, không phải tên của Vai trò đó.
_Avoid_: nhập kho (đó là tên **Vai trò**)

**Lô nhập** (Batch):
Một lần nhập hàng vào kho từ một **Nhà cung cấp**, gồm một hoặc nhiều **Dòng nhập**. Chỉ vào kho khi nhân viên xác nhận sau bước xem trước; ghi lại số dòng bị bỏ vì lỗi hoặc trùng.

**Dòng nhập** (Batch line):
Phần của một **Lô nhập** dành cho đúng một **Sản phẩm**: một file hoặc một danh sách dán, kèm **Giá trị áp cho Đơn vị hàng** của các **Đơn vị hàng** trong đó.

**Giá trị áp cho Đơn vị hàng** (Unit values):
**Số slot**, **Hạn sử dụng** và **Giá vốn** mà mỗi **Đơn vị hàng** nhận khi vào kho. Quyết định theo ba tầng, tầng sau thắng tầng trước: **Sản phẩm** (chỉ Số slot), **Dòng nhập**, rồi cột tuỳ chọn trong file nhập, ghi đè cho riêng từng dòng. Danh sách dán không có tầng file, nên mọi Đơn vị hàng của một Dòng nhập nhận cùng một bộ giá trị. Nhân viên thấy giá trị đã chốt ở màn xem trước, không phải tự suy ra luật.
_Avoid_: giá trị ghi đè, giá trị mặc định của Dòng nhập

**Huỷ nhập** (Import reversal):
Rút lại hàng đã nhập nhầm, coi như chưa từng vào kho: giải phóng **Khoá chống trùng** để nhập lại được, nhưng vẫn giữ bản ghi. Làm theo **Dòng nhập** hoặc cả **Lô nhập**, chỉ **Quản trị**; chỉ huỷ **Đơn vị hàng** mà mọi **Slot** vẫn Còn hàng, phần còn lại ở lại. Hàng Đã huỷ nhập không tính là tồn.
_Avoid_: xoá lô, Huỷ hàng (Huỷ hàng không giải phóng Khoá chống trùng)

**Nhà cung cấp** (Supplier):
Bên bán hàng số cho shop.

**Giá vốn** (Cost):
Giá shop trả cho một **Đơn vị hàng**, ghi khi nhập theo **Lô nhập**, dùng để tính lãi/lỗ. Giá vốn của một **Slot** bằng giá vốn Đơn vị hàng chia đều cho số slot. Không bao giờ sửa sau khi nhập, kể cả khi được bồi hoàn. Hàng thay thế từ **Khiếu nại nhà cung cấp** có Giá vốn bằng 0.

**Sổ biến động kho** (Stock ledger):
Nhật ký chỉ-ghi-thêm mọi lần chuyển trạng thái của **Slot** và **Đơn vị hàng**: ai, khi nào, từ trạng thái nào sang trạng thái nào, lý do. Tách biệt với **Nhật ký xem mã**.

**Vai trò** (Role):
Nhóm quyền gán cho một nhân viên. Có ba vai trò: **Quản trị** (chủ shop, mọi quyền), **Nhập kho** (nhập hàng, làm việc với **Nhà cung cấp**) và **Bán hàng** (xuất kho và xử lý sau giao). Một nhân viên có thể mang nhiều vai trò; không gán quyền lẻ cho từng người. Luôn còn ít nhất một Quản trị đang hoạt động.
_Trong code_: **Quản trị** là `Role::Owner`, giá trị `owner` (không phải `admin`, vốn dễ nhầm với **Người vận hành server**). **Nhập kho** và **Bán hàng** vẫn là `Role::NhapKho`, `Role::BanHang`.

**Ngữ cảnh xem mã** (Reveal context):
Bản ghi mà qua đó nội dung đầy đủ của một **Slot** được hiển thị hoặc tải về: một lần **Giao hàng**, **Đổi hàng**, **Báo lỗi**, **Khiếu nại nhà cung cấp** hoặc **Lô nhập**. Lý do xem suy ra từ ngữ cảnh. Nội dung chỉ xem được qua một ngữ cảnh, trừ **Quản trị** xem hàng Còn hàng kèm lý do tự do.

**Nhật ký xem mã** (Reveal log):
Nhật ký chỉ-ghi-thêm mỗi lần nội dung đầy đủ bị hiển thị hoặc tải về: ai (nhân viên hoặc **Khoá API**), khi nào, Slot nào, **Ngữ cảnh xem mã**, lý do. Không chứa nội dung mã, không ghi thao tác Copy, trừ Copy tất cả khi màn kết quả chỉ hiện dạng che (từ 50 **Slot** trở lên); lưu vĩnh viễn, chỉ **Quản trị** xem được.

**Nhật ký bảo mật** (Security log):
Nhật ký chỉ-ghi-thêm các sự kiện về quyền truy cập: đăng nhập, sai 2FA, reset 2FA, tạo hoặc **Khoá nhân viên**, đổi **Vai trò**, tạo, thu hồi hoặc xoay **Khoá API**, đăng ký dấu vân tay hoặc xoay khoá mã hoá của kho, bật hoặc tắt **Tạm dừng xuất kho**. Không bao giờ chứa giá trị khoá. Lưu vĩnh viễn, chỉ **Quản trị** xem được.

**Khoá nhân viên** (Deactivate staff):
Chặn một nhân viên đăng nhập, có hiệu lực ngay kể cả phiên đang mở. Nhân viên không bao giờ bị xoá vì các nhật ký tham chiếu tới họ.
_Avoid_: xoá nhân viên

**Người vận hành server** (Server operator):
Người có quyền quản trị máy chủ chạy kho, đọc được mọi nội dung mà không đi qua **Nhật ký xem mã**. Không phải một **Vai trò** trong app. Chỉ chủ shop giữ lâu dài; người khác chỉ được cấp theo từng đợt và bị thu hồi khi xong việc.
_Avoid_: admin (dễ nhầm với **Quản trị**)

**Tạm dừng xuất kho** (Dispatch freeze):
Trạng thái toàn kho trong đó không **Kênh bán** nào, kể cả kênh API, được **Giữ hàng** hay **Giao hàng**. Chỉ **Quản trị** bật hoặc tắt, kèm lý do. Kho luôn ở trạng thái này sau khi khôi phục từ backup, cho tới khi Quản trị đối chiếu xong các đơn phát sinh sau mốc khôi phục; cũng được bật khi nghi nội dung kho bị lộ.
_Avoid_: bảo trì, khoá kho

**Ghi nhận giao bù** (Recorded lost delivery):
Quản trị ghi lại một lần **Giao hàng** đã thực sự xảy ra nhưng bị mất khỏi kho do khôi phục từ backup, bằng cách chọn đích danh **Slot** qua **Khoá chống trùng**, kèm lý do. Là ngoại lệ duy nhất cho việc Slot được chọn theo **Thứ tự xuất**.

## Relationships

- Mỗi **Sản phẩm** thuộc đúng một **Loại sản phẩm**; một Loại sản phẩm có không hoặc nhiều Sản phẩm
- Một **Loại sản phẩm** có **Dạng hàng** là **Mã dùng một lần** hoặc **Tài khoản**, và khai báo một hoặc nhiều **Trường nội dung**
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
- "audit log" từng dùng chung cho mọi thứ được ghi lại. Đã chốt: tách thành **Nhật ký xem mã** (ai thấy mã nào), **Nhật ký bảo mật** (ai vào hệ thống, ai đổi quyền của ai), **Sổ biến động kho** (chuyển trạng thái) và lịch sử sửa **Phiếu xuất**.
- "tỉ lệ lỗi tháng X" có thể hiểu là số hàng chuyển Lỗi trong tháng chia số hàng giao trong tháng. Đã chốt: **Tỉ lệ lỗi** tính theo lứa nhập, tử số và mẫu số trên cùng một tập Đơn vị hàng.
- "tồn kho" từng gộp cả Slot đang giữ và hàng không bán được. Đã chốt: cảnh báo và con số chính dùng **Tồn bán được**; Slot Đã giữ, tạm ngừng, không đạt hạn tối thiểu, **Tồn lỗi** và Slot còn dùng được của **Sản phẩm** **Ngừng bán** hiện tách riêng.
- "giá trị tồn" từng hiểu là tổng **Giá vốn** của mọi **Slot** còn trong kho. Đã chốt: không gồm **Tồn lỗi**, vì Giá vốn đó đã là **Tổn thất** hàng Lỗi; Tồn lỗi có cột Giá vốn riêng.
- "lãi/lỗ" có thể hiểu là chỉ Giá bán trừ Giá vốn hàng đã giao. Đã chốt: tách **Lãi gộp** và **Lãi ròng kho**; hàng lỗi, huỷ, hết hạn và Chi phí đổi hàng chỉ trừ vào Lãi ròng kho.
- "khoá" dùng cho nhiều thứ khác nhau. Đã chốt: **Khoá API** (bí mật của kênh), **Khoá chống trùng** (trường nội dung), **Khoá nhân viên** (chặn đăng nhập); còn khoá mã hoá của kho chỉ **Người vận hành server** đụng tới và luôn nói rõ là "khoá mã hoá".
- Đổi thời hạn bảo hành của **Sản phẩm** có thể hiểu là đổi cả **Hạn bảo hành** của các lần giao cũ. Đã chốt: không; Hạn bảo hành theo thời hạn bảo hành tại thời điểm **Giao hàng**, nên Sản phẩm vẫn đổi được thời hạn bảo hành khi đã có hàng.
- Hàng nhập nhầm từng chỉ có cách Huỷ hàng, khiến Mã dùng một lần không nhập lại được. Đã chốt: tách riêng **Huỷ nhập**, giải phóng Khoá chống trùng.
- "hàng nhập trong kỳ" có thể hiểu theo ngày tạo **Lô nhập** hoặc ngày xác nhận. Đã chốt: tính theo ngày xác nhận, vì đó mới là lúc hàng thực vào kho; hàng **Huỷ nhập** bị loại hẳn, nên số Nhập của một kỳ cũ giảm đi sau khi Huỷ nhập.
- "hàng chuyển Lỗi trong kỳ" có thể hiểu là số lần **Đánh dấu Lỗi**, hoặc số **Slot** thành **Tồn lỗi**. Đã chốt: báo cáo Nhập/xuất đếm Slot thành Tồn lỗi (cùng mốc với Tổn thất hàng Lỗi), nên cột tên là Chuyển Tồn lỗi; Slot đã giao của **Đơn vị hàng** chuyển Lỗi không tính, vì chúng không nằm trong kho.
- Lần **Giao hàng** bị **Giao thay** vẫn còn bản ghi, nên dễ bị đếm là hàng đã bán. Đã chốt: báo cáo Nhập/xuất bỏ lần giao đã bị Giao thay ra khỏi phần Xuất, như **Lãi gộp** bỏ Slot giao nhầm đã **Huỷ hàng**; Slot đó hiện ở cột Huỷ hàng.
- **Giá bán** của một **Dòng xuất** có thể gồm Slot của nhiều **Nhà cung cấp**, mà Giá bán là tổng tiền của cả dòng chứ không phải đơn giá. Đã chốt: báo cáo Nhập/xuất tính Giá bán một lần cho cả Dòng xuất, vào kỳ có lần **Giao hàng** đầu tiên của dòng, và ẩn cột Giá bán khi lọc Nhà cung cấp thay vì chia một con số không có thật.
- "Đơn vị hàng đã giao" ở mẫu số **Tỉ lệ lỗi** có thể hiểu là có bản ghi **Giao hàng** nào đó. Đã chốt: lần giao đã bị **Giao thay** không tính, vì Slot đó đã **Huỷ hàng** và khách nhận Slot khác, nên hàng chưa đến tay ai để mà lộ lỗi; cùng cách báo cáo Nhập/xuất bỏ lần giao bị Giao thay ra khỏi phần Xuất.
- **Đơn vị hàng** Lỗi chưa giao Slot nào (thường do **Đánh dấu Lỗi** khi nhà cung cấp thu hồi hoặc phát hiện hỏng trong kho) có thuộc tử số **Tỉ lệ lỗi** không. Đã chốt: có, vì tử số là số Đơn vị hàng Lỗi của lứa nhập chứ không riêng hàng đã đến tay khách; bỏ ra thì nhà cung cấp bị bắt lỗi hết trong kho lại hiện 0%. Hệ quả: tử số không phải tập con của mẫu số nên tỉ lệ vượt 100% được khi phần lớn lứa còn trong kho, và cột 'Lỗi trong kho' là phần tử số chưa giao chứ không phải cột nằm ngoài tỉ lệ.
- Dòng bị bỏ vì lỗi định dạng hoặc trùng khi nhập cũng nói lên chất lượng của **Nhà cung cấp**, nhưng chưa từng thành **Đơn vị hàng**. Đã chốt: để ở cột riêng (Dòng lỗi/trùng khi nhập), ngoài cả tử số lẫn mẫu số của Tỉ lệ lỗi.
- Dòng tổng của một **Nhà cung cấp** có thể hiểu là trung bình **Tỉ lệ lỗi** các **Sản phẩm**. Đã chốt: cộng số **Đơn vị hàng** của mọi Sản phẩm rồi mới chia, để Sản phẩm bán nhiều có trọng số đúng.
- **Lãi gộp** của một kỳ có thể hiểu theo từng lần **Giao hàng**, nhưng **Giá bán** là tổng tiền của cả **Dòng xuất** chứ không phải đơn giá. Đã chốt: đơn vị tính lãi là Dòng xuất; cả Giá bán lẫn **Giá vốn** của một dòng rơi vào kỳ có lần Giao hàng đầu tiên của dòng, nên Doanh thu trừ Giá vốn hàng bán luôn đúng bằng Lãi gộp của kỳ, không có phần lãi nào bị cắt đôi giữa hai kỳ.
- Slot giao bù của một **Giao thay** sang **Sản phẩm** khác nằm ở một Dòng xuất loại Giao thay không có Giá bán, nên dễ bị tính thành Giá vốn của Sản phẩm kia. Đã chốt: báo cáo Lãi/lỗ quy Giá vốn ấy về **Dòng xuất gốc**, cùng chỗ với Giá bán đã thu; nếu không, Sản phẩm gốc có doanh thu không kèm Giá vốn còn Sản phẩm kia có Giá vốn không kèm doanh thu.
- **Dòng xuất** chưa ghi Giá bán có thể bị coi là bán với giá 0, làm Lãi gộp âm giả. Đã chốt: chúng đứng ngoài doanh thu và Lãi gộp, gom vào một dòng 'Chưa có Giá bán' (số **Slot** + Giá vốn) để nhân viên đi điền nốt; Dòng xuất loại **Đổi hàng** và **Giao thay** không vào đó vì theo thiết kế chúng không có Giá bán.
- Lọc **Nhà cung cấp** trên báo cáo Lãi/lỗ gặp đúng vấn đề của báo cáo Nhập/xuất: một Dòng xuất có thể gồm Slot của nhiều Nhà cung cấp. Đã chốt: khác báo cáo Nhập/xuất (ẩn hẳn cột Giá bán), báo cáo Lãi/lỗ **chia đều Giá bán theo Slot**, vì ở đây bỏ doanh thu đi thì Lãi gộp của Nhà cung cấp mất nghĩa hoàn toàn, còn chia theo Slot vẫn là một phép quy đổi nói được.
- Hàng thay thế từ **Khiếu nại nhà cung cấp** có thể bị coi là một khoản bồi hoàn trừ vào lỗ. Đã chốt: trong báo cáo lỗ theo Nhà cung cấp nó chỉ là cột tham khảo (đếm **Đơn vị hàng**), không trừ vào lỗ ròng, vì hàng ấy vào kho với **Giá vốn** 0 nên đã tự phản ánh khi bán; trừ thêm lần nữa là tính hai lần.
- Lỗ "của một **Nhà cung cấp**" có thể hiểu là mọi **Tổn thất** của hàng họ giao. Đã chốt: chỉ gồm **Giá vốn** hàng Lỗi và **Chi phí đổi hàng**; Tổn thất **Huỷ hàng** (giao nhầm, lộ nội dung, ngừng kinh doanh lô) và Tổn thất hết hạn là chuyện của shop, tính cho Nhà cung cấp là đổ oan.
- "nhập kho" và "bán hàng" vừa là tên **Vai trò**, vừa có thể hiểu là công việc. Đã chốt: công việc gọi là **Nhập hàng** và **Xuất hàng**; **Nhập kho** và **Bán hàng** chỉ là tên Vai trò. Hai cặp từ này cố tình khác nhau để một câu nói ra là biết đang nói về người hay về việc.
- "loại sản phẩm" từng nghĩa là **Mã dùng một lần** hay **Tài khoản**. Đã chốt: tách làm hai. **Dạng hàng** là hàng nằm trong kho dưới hình thức nào, **Loại sản phẩm** là khuôn khai **Trường nội dung** dùng chung cho nhiều **Sản phẩm**. Câu hỏi "sản phẩm này loại gì?" từ nay trả lời bằng tên Loại sản phẩm, không phải Mã dùng một lần hay Tài khoản.
- Có **Loại sản phẩm** rồi thì dễ tưởng sửa Loại là sửa được mọi **Sản phẩm** thuộc nó. Đã chốt: chỉ thêm trường tuỳ chọn và đổi tên hiển thị mới áp xuống; mọi thay đổi chạm vào dữ liệu đã lưu (kiểu trường, cờ nhạy cảm, **Khoá chống trùng**, chuẩn hoá, **Dạng hàng**, xoá trường) bị từ chối trọn gói khi Loại đã có Sản phẩm có hàng, chứ không áp cho những Sản phẩm áp được (ADR 0004). Hệ quả cố ý: một Sản phẩm chưa có hàng vẫn bị chặn vì Sản phẩm anh em cùng Loại đã có hàng.
- **Sản phẩm** dùng chung **Trường nội dung** nhưng cần hướng dẫn kích hoạt riêng. Đã chốt: chỉ **Mẫu giao hàng** được Sản phẩm ghi đè, vì nó chi phối văn bản gửi khách chứ không chi phối dữ liệu đã lưu; mọi khai báo khác của **Loại sản phẩm** thì Sản phẩm không lệch được.
