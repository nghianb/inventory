<?php

namespace App\Console\Commands;

use App\Inventory\Staff\OwnerAlreadyExists;
use App\Inventory\Staff\StaffManager;
use App\Inventory\Staff\StaffRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use SensitiveParameter;

/**
 * Kho vừa cài xong chưa có nhân viên nào, mà chỉ Quản trị tạo được nhân viên: đây là
 * đường chính thức để có Quản trị đầu tiên, chạy trên server (ADR 0001). Mật khẩu chỉ
 * hỏi tương tác và luôn nhập ẩn, nên không nằm lại trong lịch sử shell lẫn trên màn
 * hình. Quản trị mới phải bật 2FA ngay ở lần đăng nhập đầu tiên, như mọi nhân viên khác.
 */
#[Signature('staff:create-first-owner')]
#[Description('Tạo Quản trị đầu tiên của kho, khi kho chưa có Quản trị nào')]
class CreateFirstOwner extends Command
{
    public function handle(StaffManager $staff): int
    {
        if ($staff->hasOwner()) {
            return $this->refuse(new OwnerAlreadyExists);
        }

        $name = (string) $this->ask('Tên');
        $email = (string) $this->ask('Email');
        $password = (string) $this->secret('Mật khẩu ban đầu', fallback: false);

        if ((string) $this->secret('Nhập lại mật khẩu ban đầu', fallback: false) !== $password) {
            $this->error('Hai lần nhập mật khẩu không giống nhau.');

            return self::FAILURE;
        }

        $errors = self::errorsIn($name, $email, $password);

        if ($errors !== []) {
            foreach ($errors as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $owner = $staff->createFirstOwner($name, $email, $password);
        } catch (OwnerAlreadyExists $exception) {
            return $this->refuse($exception);
        }

        $this->info("Đã tạo Quản trị {$owner->email}. Đăng nhập vào panel bằng mật khẩu vừa đặt: panel bắt bật 2FA ngay trước khi vào.");

        return self::SUCCESS;
    }

    /**
     * Một lối ra cho cả hai lần kiểm: hỏi sớm để khỏi bắt gõ hết rồi mới từ chối, và bắt
     * lại lúc tạo phòng khi có Quản trị xuất hiện xen vào giữa lúc đang hỏi.
     */
    private function refuse(OwnerAlreadyExists $exception): int
    {
        $this->error($exception->getMessage());
        $this->line('  - Thêm Quản trị mới: trang Nhân viên trong panel.');
        $this->line('  - Quản trị bị khoá hoặc mất 2FA: staff:recover-owner');

        return self::FAILURE;
    }

    /**
     * Cùng luật với trang Nhân viên ({@see StaffRules}), nói bằng tiếng Việt như mọi
     * thông báo khác của lệnh chạy trên server.
     *
     * @return list<string>
     */
    private static function errorsIn(string $name, string $email, #[SensitiveParameter] string $password): array
    {
        return Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:'.StaffRules::MAX_LENGTH],
                'email' => ['required', 'email', 'max:'.StaffRules::MAX_LENGTH, 'unique:'.User::class.',email'],
                'password' => ['required', StaffRules::password()],
            ],
            [
                'name.required' => 'Phải nhập Tên.',
                'name.max' => 'Tên dài quá '.StaffRules::MAX_LENGTH.' ký tự.',
                'email.required' => 'Phải nhập Email.',
                'email.email' => 'Email không đúng định dạng.',
                'email.max' => 'Email dài quá '.StaffRules::MAX_LENGTH.' ký tự.',
                'email.unique' => 'Email này đã có nhân viên dùng.',
                'password.required' => 'Phải nhập Mật khẩu ban đầu.',
                'password.min' => 'Mật khẩu ban đầu phải dài ít nhất 8 ký tự.',
            ],
        )->errors()->all();
    }
}
