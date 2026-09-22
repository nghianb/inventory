<?php

use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\BatchStatus;
use App\Models\Batch;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Bố cục "Bàn làm việc" của trang xem Lô nhập (issue #97). Kiểm đúng những thứ bố cục này THÊM,
 * vì phần không đổi đã có BatchResourceTest lo: đồng hồ hạn 24 giờ, lô đã chết không còn là trang
 * trắng, số liệu tồn kho sau khi xác nhận, và nút chính bày lại trong thân trang.
 *
 * Không khai hàm toàn cục nào: helper Pest là hàm toàn cục và tests/ đang có hơn 100 cái, trùng
 * tên một cái là chết cả lượt chạy bộ.
 */
beforeEach(function () {
    Storage::fake('intake');
    Repeater::fake();
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::Owner);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->product = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
    );

    $this->actingAs($this->admin);

    // Một dòng hợp lệ, một dòng trùng trong chính nội dung: để phần "bị bỏ" có thật thứ để hiện.
    $this->guiLo = fn (): Batch => app(BatchIntake::class)->submit($this->admin, new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($this->product, 95_000, "AAAA-BBBB\nCCCC-DDDD\naaaabbbb")],
    ));

    $this->xem = fn (Batch $batch) => Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()]);
});

it('lô Chờ xác nhận hiện cái giá của việc Xác nhận và đồng hồ hạn 24 giờ', function () {
    $batch = ($this->guiLo)();

    ($this->xem)($batch)
        ->assertOk()
        // Câu hỏi đầu tiên của người mở trang, trước đây phải tự cộng từng Dòng nhập.
        ->assertSee('Còn 2 Đơn vị hàng chờ vào kho')
        ->assertSee('1 dòng bị bỏ và KHÔNG vào kho')
        // ADR 0007: hạn tính từ lúc tạo lô, sửa không gia hạn.
        ->assertSee('để xác nhận (hết hạn')
        ->assertSee('sửa lại không gia hạn', escape: false);
});

it('đồng hồ hạn chuyển sang đã quá hạn khi lô tạo quá 24 giờ', function () {
    $batch = ($this->guiLo)();
    Batch::whereKey($batch->id)->update(['created_at' => CarbonImmutable::now()->subHours(30)]);

    ($this->xem)($batch->fresh())
        ->assertOk()
        ->assertSee('Đã quá hạn xác nhận');
});

/**
 * Trước đây khối Kết quả kiểm tra ẩn với các trạng thái này nên trang gần như trắng: mở lại một lô
 * quá hạn thì không biết nó đã kiểm ra gì rồi mới chết.
 */
it('lô đã chết nói rõ nó chết vì gì thay vì để trang trắng', function (BatchStatus $status, string $expected) {
    $batch = ($this->guiLo)();
    Batch::whereKey($batch->id)->update(['status' => $status]);

    ($this->xem)($batch->fresh())
        ->assertOk()
        ->assertSee($status->label())
        ->assertSee($expected);
})->with([
    'Đã bỏ' => [BatchStatus::Discarded, 'hàng chưa từng vào kho'],
    'Quá hạn xác nhận' => [BatchStatus::Expired, 'nội dung tạm đã bị dọn'],
    'Kiểm tra thất bại' => [BatchStatus::ValidationFailed, 'Job kiểm tra không chạy xong'],
]);

it('lô Đã xác nhận đổi hình: hiện hàng giờ ra sao thay cho cái giá của việc xác nhận', function () {
    $intake = app(BatchIntake::class);
    $batch = ($this->guiLo)();
    $intake->confirm($this->admin, $batch);

    ($this->xem)($batch->fresh())
        ->assertOk()
        ->assertSee('Hàng của lô này giờ ra sao')
        ->assertSee('Slot đã nhập')
        ->assertSee('Còn hàng')
        ->assertSee('Tồn lỗi')
        // Dải quyết định chỉ dành cho lô còn Chờ xác nhận.
        ->assertDontSee('chờ vào kho');
});

it('số liệu tồn kho đếm đúng Slot của lô sau khi giao và Huỷ nhập', function () {
    $intake = app(BatchIntake::class);
    $batch = ($this->guiLo)();
    $intake->confirm($this->admin, $batch);

    // Hai Đơn vị hàng Mã dùng một lần = hai Slot, chưa ai mua nên còn hàng cả hai.
    ($this->xem)($batch->fresh())
        ->assertOk()
        ->assertSeeInOrder(['Slot đã nhập', '2'])
        ->assertSeeInOrder(['Còn hàng', '2']);
});

/**
 * Bố cục A đặt nút chính vào thân trang. Nút khai cùng một chỗ với nút header (BatchActions) nhưng
 * mang tên riêng, vì cacheAction() keyed theo tên.
 */
it('dải quyết định bày lại nút Xác nhận trong thân trang khi lô còn Chờ xác nhận', function () {
    $batch = ($this->guiLo)();

    ($this->xem)($batch)
        ->assertOk()
        ->assertSeeHtml('daiQuyetDinh')
        ->assertActionVisible(TestAction::make('confirmInline')->schemaComponent('daiQuyetDinh'))
        ->assertActionVisible(TestAction::make('reviseInline')->schemaComponent('daiQuyetDinh'));
});

it('nút Xác nhận trong thân trang nhập kho thật, đúng như nút ở header', function () {
    $batch = ($this->guiLo)();

    ($this->xem)($batch)
        ->callAction(TestAction::make('confirmInline')->schemaComponent('daiQuyetDinh'))
        ->assertHasNoActionErrors();

    expect($batch->fresh()->status)->toBe(BatchStatus::Confirmed);
});

it('nút trong thân trang biến mất khi lô đã xác nhận, như nút ở header', function () {
    $intake = app(BatchIntake::class);
    $batch = ($this->guiLo)();
    $intake->confirm($this->admin, $batch);

    ($this->xem)($batch->fresh())
        ->assertOk()
        // Dải quyết định không còn: hỏi bằng chữ nhân viên thật sự đọc, không bằng key của
        // component. Key vẫn nằm trong HTML kể cả khi container invisible (wire:key/snapshot),
        // mà action thì không resolve nổi qua nó nữa — nên cả assertDontSeeHtml lẫn
        // assertActionHidden đều là câu hỏi sai ở đây.
        ->assertDontSee('chờ vào kho')
        // Nút ở header thì vẫn tồn tại, chỉ ẩn — như BatchResourceTest đã chốt.
        ->assertActionHidden('confirm');
});

/**
 * Khoản nợ đi kèm bố cục: bản cũ gọi BatchIntake::preview() 4+ lần mỗi lần render, mỗi lần một
 * findOrFail kèm authorize, mà trang còn poll 3 giây một lần lúc đang kiểm tra.
 */
it('một lần render chỉ lấy bản kiểm tra một lần', function () {
    $batch = ($this->guiLo)();

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from "batches"')) {
            $queries++;
        }
    });

    ($this->xem)($batch)->assertOk();

    expect($queries)->toBeLessThanOrEqual(3);
});
