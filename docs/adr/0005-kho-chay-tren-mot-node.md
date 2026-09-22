# Kho chạy trên một node, và stack production từ chối nhiều node

Kho chạy bằng Docker Compose trên **một** VPS: FrankenPHP, Postgres, queue worker và scheduler cùng một máy, sau một reverse proxy cùng máy đã cầm TLS. Chúng tôi từ chối hình thái nhiều node (Swarm, Kubernetes, nhiều VPS sau load balancer) không phải vì chưa cần, mà vì ứng dụng hiện tại sẽ **hỏng âm thầm** nếu có node thứ hai, và điều đó không đọc ra được từ code.

## Hệ quả

Bốn chỗ phải sửa trước khi node thứ hai tồn tại, xếp theo mức khó chịu:

- **Livewire temporary upload.** Form nhập hàng dùng `FileUpload::storeFiles(false)`, nên file đi qua `livewire-tmp` trên disk `local` của đúng container nhận request. Node A nhận upload, request submit kế tiếp rơi vào node B là file biến mất — hỏng ngay request thứ hai, và là loại lỗi "thỉnh thoảng mới xảy ra" tệ nhất để chẩn đoán.
- **State trên đĩa cục bộ.** `storage/app/intake` (nội dung Lô nhập, mã hoá) và `storage/app/private/defect-reports` (ảnh Báo lỗi) nằm trên ổ của một máy. Nhiều node cần disk dùng chung, mà `league/flysystem-aws-s3-v3` chưa được cài: disk `s3` trong `config/filesystems.php` hiện là khai báo chết.
- **Scheduler.** `schedule:work` chạy trên mọi node, nên `inventory:dispatches:release-holds` sẽ nhả giữ chỗ nhiều lần mỗi phút. Chưa lệnh nào có `->onOneServer()`.
- **Khoá mã hoá.** ADR 0001 chốt khoá nằm trong `.env` trên chính VPS chạy kho. Nhiều node nghĩa là nhân bản `INVENTORY_CONTENT_KEY` ra mọi máy — đúng thứ ADR 0001 từ chối. Đổi hình thái là phải mở lại ADR đó.

Ba thứ vốn đã chịu được nhiều tiến trình và không cần đụng tới: session, cache và queue đều nằm trên Postgres, còn tranh chấp khi xuất kho xử bằng `FOR UPDATE SKIP LOCKED`.

Đổi lại, deploy chấp nhận gián đoạn 15–60 giây: container cũ hạ xuống, `migrate` chạy một lần, rồi ba service PHP lên. Với shop 1–10 nhân viên, zero-downtime mua một thứ gần như không ai nhận ra và trả bằng việc hai phiên bản code chạy đồng thời trên một schema vừa đổi — nguy hiểm thật sự ở kho có trigger chỉ-ghi-thêm.
