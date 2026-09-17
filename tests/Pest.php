<?php

use App\Inventory\Access\Role;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\ExpiryRule;
use App\Models\Batch;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Test đồng thời cần dữ liệu commit thật giữa các tiến trình: không bọc transaction.
pest()->extend(TestCase::class)
    ->in('Concurrency');

/**
 * Nhân viên đã bật 2FA, mang các Vai trò cho trước. Cần chạy RoleSeeder trước.
 */
function staffMember(Role ...$roles): User
{
    return tap(User::factory()->withTwoFactor()->create())->assignRole($roles);
}

/**
 * Request vào API xuất kho, đã gắn Khoá API. Cần `$this->secret` trong beforeEach; truyền khoá khác
 * để thử một khoá cụ thể. Test nào cần gọi *không* kèm header thì tự dựng request, đừng qua đây.
 */
function apiAs(?string $secret = null): TestCase
{
    return test()->withHeaders(['Authorization' => 'Bearer '.($secret ?? test()->secret)]);
}

/**
 * Nhập và xác nhận một Lô nhập một Dòng nhập vào kho, để test có hàng mà giao. Cần `$this->admin`
 * (Quản trị hoặc Nhập kho) và `$this->supplier` trong beforeEach.
 */
function stockUp(Product $product, string $content, ?ExpiryRule $expiry = null): Batch
{
    $intake = app(BatchIntake::class);

    return $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 100_000, $content, expiry: $expiry)],
    )));
}
