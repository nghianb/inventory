<?php

namespace App\Console\Commands;

use App\Inventory\Staff\QuanTriAlreadyExists;
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
 * đường chính thức để có Quản trị đầu tiên, chạy trên server (ADR 0001). Hỏi tương tác
 * nên mật khẩu không nằm lại trong lịch sử shell. Quản trị mới phải bật 2FA ngay ở lần
 * đăng nhập đầu tiên, như mọi nhân viên khác.
 */
#[Signature('staff:create-first-quan-tri')]
#[Description('Tạo Quản trị đầu tiên của kho, khi kho chưa có Quản trị nào')]
class CreateFirstQuanTri extends Command
{
    public function handle(StaffManager $staff): int
    {
        $name = (string) $this->ask('Tên');
        $email = (string) $this->ask('Email');
        $password = (string) $this->secret('Mật khẩu ban đầu');

        if ((string) $this->secret('Nhập lại mật khẩu ban đầu') !== $password) {
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
            $quanTri = $staff->createFirstQuanTri($name, $email, $password);
        } catch (QuanTriAlreadyExists $exception) {
            $this->error($exception->getMessage());
            $this->line('  - Thêm Quản trị mới: trang Nhân viên trong panel.');
            $this->line('  - Quản trị bị khoá hoặc mất 2FA: staff:recover-quan-tri');

            return self::FAILURE;
        }

        $this->info("Đã tạo Quản trị {$quanTri->email}. Đăng nhập vào panel bằng mật khẩu vừa đặt: panel bắt bật 2FA ngay trước khi vào.");

        return self::SUCCESS;
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
