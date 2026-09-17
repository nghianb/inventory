# Ghi nhận giao bù là một loại Dòng xuất riêng, và loại ấy có Giá bán

**Ghi nhận giao bù** ghi lại một lần **Giao hàng** đã thực sự xảy ra nhưng bị mất khỏi kho khi khôi phục từ backup. Chúng tôi cho nó một **loại Dòng xuất** riêng (`dispatch_lines.kind = 'recorded'`) thay vì dùng lại loại Giao bán, và loại ấy **có Giá bán** — nên ràng buộc `dispatch_lines_sale_price_kind` (siết ở #65: chỉ Giao bán và Giao thêm mới được ghi Giá bán) được nới thêm đúng một giá trị.

Lý do là mục đích của chính tính năng này: sau khôi phục, Quản trị phải **đối chiếu** kho với thực tế. Dòng nào do kho tự giao và dòng nào do người dựng lại bằng tay là hai thứ khác nhau khi soát, và khác nhau về mức tin cậy — dòng dựng lại chỉ đúng bằng trí nhớ và bằng chứng ngoài kho. Nếu chép chúng thành Giao bán thì sau này không còn cách nào tách ra: vết duy nhất còn lại là câu lý do trong **Sổ biến động kho**, thứ không lọc hay đếm được trên màn Phiếu xuất.

Loại này có Giá bán vì lần bán ấy **đã thu tiền thật**. Bỏ Giá bán đi thì **Lãi gộp** của kỳ bị hụt đúng bằng phần doanh thu của những đơn không may rơi vào vùng mất dữ liệu, và con số ấy không bao giờ đòi lại được. Vì vậy nó vào **Lãi gộp** (`SoldLines`) và phần Xuất của báo cáo Nhập/xuất (`MovementReport`) đúng như Giao bán.

## Considered Options

- **Dùng lại loại Giao bán**: bị loại. Không đụng ràng buộc của #65 và không phải sửa hai báo cáo, nhưng mất hẳn khả năng phân biệt hàng dựng lại bằng tay — đúng thứ mà một đợt đối chiếu sau khôi phục cần nhất.
- **Loại riêng nhưng không có Giá bán** (như Đổi hàng, Giao thay): bị loại. Hai loại kia không có Giá bán vì tiền của chúng đã nằm ở Dòng xuất gốc; Ghi nhận giao bù thì không có dòng gốc nào cả, nên tiền sẽ biến mất khỏi báo cáo.
- **Một cột cờ trên `deliveries`** thay cho loại Dòng xuất mới: bị loại. Cùng lượng thay đổi, nhưng đặt thông tin ở tầng lần giao trong khi Giá bán và các báo cáo đều tính theo **Dòng xuất**.

## Consequences

- Danh sách "loại nào có Giá bán" giờ nằm ở `DispatchLineKind::salePriceKinds()`, dùng chung cho `allowsSalePrice()`, `SoldLines` và `MovementReport`. Ràng buộc DB cố tình chép cứng danh sách ấy: migration là bản ghi lịch sử, phải chạy ra cùng một kết quả về sau.
- Thêm một loại Dòng xuất nữa trong tương lai vẫn phải viết một migration đổi ràng buộc. Đó là cái giá của việc giữ bất biến ở tầng DB, và #65 đã chọn trả giá ấy.
- Báo cáo của một kỳ **đã chốt** có thể đổi khi Quản trị ghi nhận giao bù cho kỳ đó, vì mốc tính là thời điểm giao thật chứ không phải lúc ghi nhận. Đây là hệ quả mong muốn: con số cũ đang sai vì thiếu dữ liệu.
