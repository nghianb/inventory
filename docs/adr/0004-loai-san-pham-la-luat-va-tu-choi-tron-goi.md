# Loại sản phẩm là luật, và sửa Loại bị từ chối trọn gói khi đã có hàng

**Trường nội dung** chuyển chủ từ **Sản phẩm** lên **Loại sản phẩm**: `product_content_fields` gắn vào `product_type_id`, và trang Sửa Sản phẩm **không còn** khai trường, chuẩn hoá **Khoá chống trùng** hay **Dạng hàng**. Một Sản phẩm không lệch khỏi Loại của nó được, trừ **Mẫu giao hàng**.

Sửa Loại chia làm hai hạng, theo đúng ranh giới `ProductCatalog::ensureOnlyUnlockedChanges()` đã có:

- **Áp xuống mọi Sản phẩm**: thêm trường tuỳ chọn, đổi tên hiển thị. Chúng không đụng tới `stock_units.content`, `secret_ciphertext` hay `dedupe_hash`.
- **Chạm dữ liệu đã lưu**: kiểu trường, `pattern`, bắt buộc, cờ nhạy cảm, **Khoá chống trùng**, hai cờ chuẩn hoá, **Dạng hàng**, xoá trường.

Khi Loại đã có **Sản phẩm** nào có hàng, thay đổi hạng thứ hai **bị từ chối trọn gói** — không áp cho bất kỳ Sản phẩm nào, kể cả những Sản phẩm của Loại đó chưa có hàng. Bước xem trước nêu đích danh Sản phẩm đang chặn.

## Considered Options

- **Áp cho những Sản phẩm áp được, bỏ lặng phần bị khoá**: nghe hữu ích nhất và là thứ người đọc code sẽ muốn "sửa thành". Nhưng kết quả là hai Sản phẩm cùng một Loại mô tả hàng theo hai cách khác nhau, trong khi cả hệ thống — và cả màn hình — vẫn nói chúng cùng Loại. Sai lệch đó nằm trên chính thứ chi phối mã hoá nội dung và **Khoá chống trùng**, và không có chỗ nào để phát hiện ra. Một phép ghi thành công một nửa ở đây đắt hơn nhiều so với việc Quản trị phải tạo Loại mới.
- **Chỉ áp cho Sản phẩm chưa có hàng**: chính là "áp một phần" nói trên, chỉ khác cách chia, nên mang đúng khuyết điểm đó.
- **Chặn hẳn mọi thay đổi trên Loại đã có hàng**: an toàn nhưng chặn luôn hai thao tác vô hại (thêm trường tuỳ chọn, đổi tên hiển thị) — mà đó đúng là hai việc phát sinh thường xuyên nhất, và là lý do ban đầu người ta muốn có Loại.
- **Nhân bản Sản phẩm, không thêm thực thể nào**: rẻ hơn hẳn và diệt đúng cơn đau gõ lại form. Bị loại vì nó không cho ai *ra lệnh* rằng mọi thẻ nạp phải khai cùng một bộ trường; hai Sản phẩm sinh từ cùng một bản sao lập tức trôi khỏi nhau mà không ai biết.
- **Liên kết sống đầy đủ** (sửa Loại là sửa hết, kể cả hạng chạm dữ liệu): bất khả thi trên hàng đã nhập — nội dung đã mã hoá theo bộ trường cũ và `dedupe_hash` đã tính theo luật chuẩn hoá cũ.

## Consequences

- Một Sản phẩm mới tinh, chưa một **Đơn vị hàng** nào, vẫn không đổi được kiểu trường nếu Sản phẩm anh em cùng Loại đã có hàng. Đây là hệ quả cố ý, không phải lỗi. Lối thoát là tạo Loại mới rồi chuyển Sản phẩm sang — chuyển Loại được phép chừng nào Sản phẩm chưa có hàng.
- Một Sản phẩm cần thêm đúng một trường riêng phải đẻ ra một Loại mới cho riêng nó. Đổi lại, "cùng Loại" luôn là một lời khẳng định đúng chứ không phải một gợi ý.
- **Mẫu giao hàng** là ngoại lệ duy nhất được Sản phẩm ghi đè, vì nó chi phối văn bản gửi khách chứ không chi phối dữ liệu đã lưu. `products.delivery_template` nullable, `NULL` nghĩa là dùng mẫu của Loại; đọc lúc ghép tin nhắn, không chép lúc tạo, nên Loại đổi mẫu thì Sản phẩm đã ghi đè không bị đụng tới.
- Không có nhật ký riêng cho việc sửa Loại. Mọi thứ áp xuống được đều thuộc hạng vô hại, còn hạng chạm dữ liệu thì không bao giờ chạm tới Sản phẩm đã có hàng — nên chưa có gì để điều tra. **Nhật ký bảo mật** giữ đúng nghĩa "sự kiện về quyền truy cập".
