<?php

use App\Inventory\Access\Role;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Chạy lệnh và trả lời đủ bốn câu hỏi. Mật khẩu chỉ nhập tương tác nên không có cách nào
 * truyền qua tham số.
 */
function answerCreateFirstQuanTri(
    string $email = 'chu@shop.test',
    string $password = 'mat-khau-ban-dau',
    ?string $again = null,
): PendingCommand {
    return test()->artisan('staff:create-first-quan-tri')
        ->expectsQuestion('Tên', 'Chủ shop')
        ->expectsQuestion('Email', $email)
        ->expectsQuestion('Mật khẩu ban đầu', $password)
        ->expectsQuestion('Nhập lại mật khẩu ban đầu', $again ?? $password);
}

it('hỏi tương tác rồi tạo Quản trị đầu tiên của kho', function () {
    answerCreateFirstQuanTri()->assertSuccessful();

    $quanTri = User::sole();

    expect($quanTri)
        ->name->toBe('Chủ shop')
        ->email->toBe('chu@shop.test')
        ->and($quanTri->hasRole(Role::QuanTri))->toBeTrue()
        ->and($quanTri->isDeactivated())->toBeFalse()
        ->and(Hash::check('mat-khau-ban-dau', $quanTri->password))->toBeTrue()
        ->and(SecurityLogEntry::where('event', SecurityEvent::StaffCreated)->sole())
        ->user_id->toBe($quanTri->id)
        ->actor_id->toBeNull();
});

it('Quản trị đầu tiên phải bật 2FA trước khi vào được panel', function () {
    answerCreateFirstQuanTri()->assertSuccessful();

    $quanTri = User::sole();

    expect(AppAuthentication::make()->isEnabled($quanTri))->toBeFalse();

    $this->actingAs($quanTri)
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('từ chối ngay khi kho đã có Quản trị, không hỏi gì', function (bool $locked) {
    $existing = staffMember(Role::QuanTri);

    if ($locked) {
        $existing->forceFill(['deactivated_at' => now()])->save();
    }

    $this->artisan('staff:create-first-quan-tri')
        ->expectsOutputToContain('Kho đã có Quản trị.')
        ->expectsOutputToContain('trang Nhân viên')
        ->expectsOutputToContain('staff:recover-quan-tri')
        ->assertFailed();

    expect(User::count())->toBe(1)
        ->and(SecurityLogEntry::count())->toBe(0);
})->with([
    'Quản trị đang hoạt động' => false,
    'Quản trị bị khoá' => true,
]);

it('từ chối dữ liệu không hợp lệ và không tạo ai', function (string $email, string $password, ?string $again, string $message) {
    answerCreateFirstQuanTri($email, $password, $again)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::count())->toBe(0)
        ->and(SecurityLogEntry::count())->toBe(0);
})->with([
    'nhập lại mật khẩu khác' => ['chu@shop.test', 'mat-khau-ban-dau', 'go-nham-mat-khau', 'không giống nhau'],
    'email sai định dạng' => ['khong-phai-email', 'mat-khau-ban-dau', null, 'Email không đúng định dạng.'],
    'mật khẩu quá ngắn' => ['chu@shop.test', 'ngan', null, 'ít nhất 8 ký tự'],
]);
