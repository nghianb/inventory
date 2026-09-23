<?php

namespace App\Filament\Resources\Batches;

use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Catalog\InvalidSupplier;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLinePreview;
use App\Inventory\Intake\BatchPreview;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Intake\LineClassifier;
use App\Inventory\Intake\RejectedLine;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use stdClass;
use UnitEnum;

/**
 * Lô nhập trong panel. Adapter mỏng: trang tạo gọi BatchIntake::submit, trang xem hiện
 * BatchIntake::preview, nút xác nhận gọi BatchIntake::confirm và nút bỏ gọi BatchIntake::discard.
 * Bán hàng không thấy.
 */
class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::NhapHang;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'lô nhập';

    protected static ?string $pluralModelLabel = 'Lô nhập';

    protected static ?string $navigationLabel = 'Lô nhập';

    protected static ?string $slug = 'lo-nhap';

    /**
     * Ký tự phân tách gợi ý khi dán, theo tên: Livewire trim giá trị form nên không giữ được tab.
     *
     * @var array<string, array{string, string}>
     */
    public const SEPARATORS = [
        'tab' => ["\t", 'Tab'],
        'pipe' => ['|', 'Dấu |'],
        'comma' => [',', 'Dấu phẩy'],
        'semicolon' => [';', 'Dấu chấm phẩy'],
    ];

    /**
     * Nhịp màn xem hỏi lại trong lúc job pha 1 chạy. Đủ ngắn để nhân viên coi là "xong thì hiện",
     * và lúc này trang gần như trống: kết quả kiểm tra chưa có gì để vẽ.
     */
    private const VALIDATION_POLL_INTERVAL = '3s';

    /**
     * Bản kiểm tra đã lấy trong request này, theo id Lô nhập. Xem self::preview().
     *
     * @var array<int, BatchPreview>
     */
    private static array $previewCache = [];

    /**
     * Số Slot theo trạng thái của một Lô nhập đã xác nhận. Xem self::slotTally().
     *
     * @var array<int, object>
     */
    private static array $slotTallyCache = [];

    /**
     * Ba lựa chọn Hạn sử dụng trên form ↔ luật của Dòng nhập. Form tạo Lô nhập và form sửa Lô
     * nhập Chờ xác nhận đọc cùng một chỗ, để hai màn không lệch nghĩa của "Không có".
     *
     * @param  array<string, mixed>  $state
     */
    public static function expiryRule(array $state): ?ExpiryRule
    {
        return match ($state['expiry_mode'] ?? 'none') {
            'date' => ExpiryRule::on(CarbonImmutable::parse($state['expires_on'])),
            'days' => ExpiryRule::afterDays((int) $state['expires_after_days']),
            default => null,
        };
    }

    /**
     * Chiều ngược: giá trị đã lưu của một Dòng nhập thành state của form sửa.
     *
     * @return array{expiry_mode: string, expires_on: ?CarbonImmutable, expires_after_days: ?int}
     */
    public static function expiryState(?CarbonImmutable $date, ?int $days): array
    {
        return [
            'expiry_mode' => match (true) {
                $date !== null => 'date',
                $days !== null => 'days',
                default => 'none',
            },
            'expires_on' => $date,
            'expires_after_days' => $days,
        ];
    }

    /**
     * Các ô Giá trị áp cho Đơn vị hàng mà form tạo và form sửa dùng chung, trừ Giá vốn: luật số
     * slot và Hạn sử dụng khai một chỗ để hai màn không lệch nhau. Hai màn tìm Sản phẩm của Dòng
     * nhập theo hai đường khác nhau, nên nhận đường đó vào làm tham số.
     *
     * @param  Closure(Get): ?Product  $product
     * @return list<TextInput|ToggleButtons|DatePicker>
     */
    public static function unitValueFields(Closure $product): array
    {
        return [
            TextInput::make('slots')
                ->label('Số slot mỗi Tài khoản')
                ->placeholder(fn (Get $get): string => (string) $product($get)?->default_slots)
                ->integer()
                ->minValue(1)
                ->maxValue(LineClassifier::MAX_SLOTS)
                ->visible(fn (Get $get): bool => $product($get)?->form() === StockForm::Account),
            ToggleButtons::make('expiry_mode')
                ->label('Hạn sử dụng')
                ->options(['none' => 'Không có', 'date' => 'Ngày cụ thể', 'days' => 'Số ngày kể từ ngày nhập'])
                ->default('none')
                ->inline()
                ->live()
                ->required(),
            DatePicker::make('expires_on')
                ->label('Ngày hết hạn')
                ->visible(fn (Get $get): bool => $get('expiry_mode') === 'date')
                ->required(),
            TextInput::make('expires_after_days')
                ->label('Số ngày')
                ->integer()
                ->minValue(0)
                ->visible(fn (Get $get): bool => $get('expiry_mode') === 'days')
                ->required(),
        ];
    }

    /**
     * Gọn hết mức: chỉ Nhà cung cấp và Ngày nhập đứng ngoài, phần chứng từ còn lại thu gọn.
     * Hạn sử dụng nằm ở thân chính chứ không thu gọn: nó là Giá trị áp cho Đơn vị hàng duy nhất
     * không có tầng Sản phẩm đỡ, lại mặc định im lặng thành "Không có", mà Lô nhập thì không sửa
     * được sau khi gửi đi. Giá trị đã chốt hiện ở màn xem trước, nên form không giải thích luật
     * ghi đè nữa.
     */
    public static function form(Schema $schema): Schema
    {
        $contentFieldCount = fn (Get $get): int => Product::query()->with('contentFields')->find($get('product_id'))?->contentFields->count() ?? 2;

        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('supplier_id')
                        ->label('Nhà cung cấp')
                        ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => filled(request()->query(CreateBatch::CLAIM_QUERY)) ? SupplierClaim::query()->find(request()->query(CreateBatch::CLAIM_QUERY))?->supplier_id : null)
                        ->searchable()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Tên Nhà cung cấp')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            try {
                                return app(SupplierDirectory::class)->create(InventoryAction::actor(), (string) $data['name'])->getKey();
                            } catch (InvalidSupplier $exception) {
                                Notification::make()->danger()->title($exception->getMessage())->send();

                                throw new Halt;
                            }
                        }),
                    DatePicker::make('received_on')
                        ->label('Ngày nhập')
                        ->default(now())
                        ->required(),
                ]),
            Section::make('Chi tiết chứng từ')
                ->collapsible()
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextInput::make('document_number')
                        ->label('Số chứng từ')
                        ->maxLength(255),
                    Select::make('supplier_claim_id')
                        ->label('Hàng thay thế cho Khiếu nại')
                        ->helperText('Hàng thay thế từ Khiếu nại nhà cung cấp có Giá vốn 0.')
                        ->options(fn (): array => SupplierClaim::query()
                            ->with('supplier')
                            ->acceptsReplacementGoods()
                            ->latest('id')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(fn (SupplierClaim $claim): array => [$claim->id => "#{$claim->id} · {$claim->supplier->name}"])
                            ->all())
                        ->default(fn (): ?int => filled(request()->query(CreateBatch::CLAIM_QUERY)) ? (int) request()->query(CreateBatch::CLAIM_QUERY) : null)
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            if (filled($state)) {
                                $set('supplier_id', SupplierClaim::query()->find($state)?->supplier_id);
                            }
                        }),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->helperText('Hàng mua bằng ngoại tệ: ghi tỷ giá đã quy đổi.')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            Section::make('Dòng nhập')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->addActionLabel('Thêm Dòng nhập')
                        ->minItems(1)
                        ->defaultItems(1)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => filled($state['product_id'] ?? null)
                            ? Product::query()->find($state['product_id'])?->name
                            : null)
                        ->columns(2)
                        ->schema([
                            Select::make('product_id')
                                ->label('Sản phẩm')
                                ->options(fn (): array => Product::query()->with('productType')->orderBy('name')->get()
                                    ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->name} ({$product->form()->label()})"])
                                    ->all())
                                ->searchable()
                                ->distinct()
                                ->live()
                                ->required(),
                            TextInput::make('unit_cost')
                                ->label('Giá vốn mỗi Đơn vị hàng')
                                ->suffix('₫')
                                ->integer()
                                ->minValue(0)
                                ->required()
                                // Hàng thay thế từ Khiếu nại luôn Giá vốn 0.
                                ->visible(fn (Get $get): bool => blank($get('../../supplier_claim_id'))),
                            ToggleButtons::make('source')
                                ->label('Nguồn')
                                ->options(['paste' => 'Dán văn bản', 'file' => 'File CSV/XLSX'])
                                ->default('paste')
                                ->inline()
                                ->live()
                                ->required(),
                            Select::make('separator')
                                ->label('Ký tự phân tách')
                                ->options(array_map(fn (array $separator): string => $separator[1], self::SEPARATORS))
                                ->default('tab')
                                ->selectablePlaceholder(false)
                                // Sản phẩm một Trường nội dung thì không có gì để tách.
                                ->visible(fn (Get $get): bool => $get('source') !== 'file' && $contentFieldCount($get) > 1)
                                ->required(),
                            Textarea::make('content')
                                ->label('Danh sách Đơn vị hàng')
                                ->helperText('Mỗi dòng một Đơn vị hàng, các trường theo thứ tự khai báo trên Sản phẩm.')
                                ->rows(10)
                                ->visible(fn (Get $get): bool => $get('source') !== 'file')
                                ->required()
                                ->columnSpanFull(),
                            FileUpload::make('file')
                                ->label('File CSV hoặc XLSX')
                                ->helperText('Dòng đầu là tiêu đề trùng tên Trường nội dung; cột slot, han_su_dung, gia_von tuỳ chọn; cột khác bị bỏ qua.')
                                ->storeFiles(false)
                                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                                ->maxSize(fn (): int => intdiv((int) config('inventory.intake.max_bytes'), 1024))
                                ->visible(fn (Get $get): bool => $get('source') === 'file')
                                ->required()
                                ->columnSpanFull(),
                            // Chỗ của "Tuỳ chọn thêm" trong prototype, nhưng không còn vỏ thu gọn:
                            // Hạn sử dụng là Giá trị áp cho Đơn vị hàng duy nhất không có tầng
                            // Sản phẩm đỡ, lại mặc định im lặng thành "Không có".
                            ...self::unitValueFields(fn (Get $get): ?Product => Product::query()->with('productType')->find($get('product_id'))),
                        ]),
                ]),
        ]);
    }

    /**
     * Bản kiểm tra của một Lô nhập, memo hoá trong một request.
     *
     * Trang xem hỏi nó ở nhiều chỗ (số liệu, tiền, từng Dòng nhập, mô tả modal Xác nhận) và còn
     * poll 3 giây một lần lúc đang kiểm tra, mà mỗi lần gọi là một findOrFail kèm authorize.
     */
    public static function preview(Batch $record): BatchPreview
    {
        return self::$previewCache[$record->getKey()] ??= app(BatchIntake::class)
            ->preview(InventoryAction::actor(), $record);
    }

    /**
     * Sau Xác nhận hoặc Sửa thì bản kiểm tra cũ hết đúng, mà cùng request còn vẽ lại trang.
     */
    public static function forgetPreview(Batch $record): void
    {
        unset(self::$previewCache[$record->getKey()]);
    }

    /**
     * Bố cục "Bàn làm việc": trang mở đầu bằng việc cần làm, không bằng dữ liệu, và đổi hình theo
     * trạng thái. Lô còn Chờ xác nhận thì kết quả kiểm tra là nhân vật chính và chứng từ tụt xuống
     * aside; lô Đã xác nhận thì câu hỏi đổi thành "hàng của lô này giờ ra sao", kết quả kiểm tra
     * co lại thành lịch sử gấp gọn. Xem issue #97.
     *
     * Không dùng class Tailwind: panel không có custom theme nên chúng rụng im lặng. Bố cục dựng
     * bằng API của component.
     */
    public static function infolist(Schema $schema): Schema
    {
        $money = fn (int $amount): string => number_format($amount, 0, ',', '.').' ₫';

        // Giá trị áp cho Đơn vị hàng: cột file ghi đè từng dòng nên một Dòng nhập ra nhiều giá
        // trị được. Nói thẳng là "nhiều", không bịa ra một con số mà hàng thật không mang.
        $oneOrMany = function (array $values, callable $format): ?string {
            if ($values === []) {
                return null;
            }

            return count($values) === 1
                ? $format($values[0])
                : sprintf('Nhiều giá trị (%d khác nhau)', count($values));
        };

        $chuaVaoKho = fn (Batch $record): bool => $record->status === BatchStatus::Validated;
        $daVaoKho = fn (Batch $record): bool => $record->status === BatchStatus::Confirmed;

        // Một cột ở gốc như mọi schema resource khác; lý do và hàng rào ở
        // tests/Feature/SchemaLayoutTest.php. Thiếu dòng này là bản cũ phải gắn columnSpanFull()
        // lên từng RepeatableEntry.
        return $schema->columns(1)->components([
            // Job pha 1 chạy ngoài request, nên màn xem tự hỏi lại cho tới khi có kết quả: nhân
            // viên không phải tự đoán lúc nào xong mà bấm lại. Kho chạy trên một node (ADR 0005)
            // nên polling đủ, không cần broadcast. Mỗi lần hỏi vẽ lại cả trang, nên kết quả kiểm
            // tra và các nút hiện ra ngay; ViewBatch::notifyValidationResult báo một tiếng.
            // keep-alive vì Livewire bóp nhịp còn ~5% khi tab chạy nền, mà gửi xong lô lớn thì
            // nhân viên hay chuyển tab đi làm việc khác — đúng lúc cần báo nhất.
            Callout::make('Đang kiểm tra Lô nhập')
                ->description('Màn hình tự cập nhật khi kiểm tra xong, không phải bấm gì.')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (Batch $record): bool => $record->status === BatchStatus::Validating)
                ->extraAttributes(['wire:poll.'.self::VALIDATION_POLL_INTERVAL.'.keep-alive' => 'notifyValidationResult']),

            // Lô đã chết: trước đây khối Kết quả kiểm tra ẩn với các trạng thái này nên trang gần
            // như trắng, mở lại không biết nó đã kiểm ra gì rồi mới chết.
            Callout::make(fn (Batch $record): string => $record->status->label())
                ->description(fn (Batch $record): string => match ($record->status) {
                    BatchStatus::ValidationFailed => 'Job kiểm tra không chạy xong. '.((string) $record->validation_error),
                    BatchStatus::Discarded => 'Lô này đã bị bỏ, hàng chưa từng vào kho. Nội dung tạm đã xoá nên không kiểm tra lại được.',
                    BatchStatus::Expired => 'Quá 24 giờ kể từ lúc tạo mà chưa xác nhận, nên nội dung tạm đã bị dọn. Muốn nhập thì tạo Lô nhập mới.',
                    default => '',
                })
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (Batch $record): bool => in_array(
                    $record->status,
                    [BatchStatus::ValidationFailed, BatchStatus::Discarded, BatchStatus::Expired],
                    true,
                )),

            // Dải quyết định: nói thẳng cái giá của việc bấm Xác nhận, rồi đặt nút ngay tại chỗ.
            Callout::make(fn (Batch $record): string => sprintf(
                'Còn %s Đơn vị hàng chờ vào kho',
                number_format(self::preview($record)->importCount(), 0, ',', '.'),
            ))
                ->description(fn (Batch $record): string => self::caiGiaCuaXacNhan($record, $money))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('warning')
                ->visible($chuaVaoKho),

            // Nút bày lại đúng hai action của header, khai một lần trong BatchActions. Tên riêng
            // vì cacheAction() keyed theo tên: hai action trùng tên trên một trang là xung đột
            // âm thầm.
            Actions::make([
                BatchActions::confirm('confirmInline'),
                BatchActions::revise('reviseInline'),
            ])
                ->key('daiQuyetDinh')
                ->visible($chuaVaoKho),

            // Hạn 24 giờ. ADR 0007: hạn tính từ lúc TẠO lô và sửa KHÔNG gia hạn, nên nhân viên sửa
            // đi sửa lại sát hạn sẽ mất trắng nếu không ai nói ra.
            Callout::make(fn (Batch $record): string => self::hanConLai($record))
                ->description('Hạn tính từ lúc tạo Lô nhập; sửa lại không gia hạn (ADR 0007). Quá hạn thì nội dung tạm bị dọn và phải dán lại từ đầu.')
                ->icon(Heroicon::OutlinedClock)
                ->color(fn (Batch $record): string => self::gioConLai($record) <= 3 ? 'danger' : 'gray')
                ->visible(fn (Batch $record): bool => in_array($record->status, BatchStatus::pending(), true)),

            // Nhân vật chính khi chưa vào kho: câu hỏi đầu tiên của người mở trang là vào kho được
            // bao nhiêu, bỏ bao nhiêu, vì sao bỏ — trước đây phải tự cộng từng Dòng nhập.
            Section::make('Phần vào kho được')
                ->description('Dòng lỗi và dòng trùng bị bỏ, không vào kho.')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->columns(4)
                ->visible($chuaVaoKho)
                ->schema([
                    ...self::soLieuVaoKho(),
                    self::ketQuaKiemTra($money, $oneOrMany, 4)->label('Từng Dòng nhập'),
                ]),

            // Sau khi vào kho, câu hỏi đổi: hàng của lô này giờ còn gì.
            Section::make('Hàng của lô này giờ ra sao')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->columns(4)
                ->visible($daVaoKho)
                ->schema(self::tinhTrangHang()),

            Section::make('Kết quả kiểm tra lúc nhập')
                ->collapsible()
                ->collapsed()
                ->visible($daVaoKho)
                ->schema([self::ketQuaKiemTra($money, $oneOrMany, 4)->hiddenLabel()]),

            // Chứng từ là bối cảnh, không phải việc cần làm, nên xuống aside. Ba loại thông tin
            // trước đây trộn trong một Section 13 entry giờ tách: chứng từ, tiền, liên kết.
            Section::make('Chứng từ')
                ->description('Lô này từ đâu, ai tạo, khi nào.')
                ->aside()
                ->columns(2)
                ->schema([
                    TextEntry::make('supplier.name')->label('Nhà cung cấp'),
                    TextEntry::make('received_on')->label('Ngày nhập')->date('d/m/Y'),
                    TextEntry::make('document_number')->label('Số chứng từ')->placeholder('Không có'),
                    TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn (BatchStatus $state): string => $state->label())
                        ->color(fn (BatchStatus $state): string => match ($state) {
                            BatchStatus::Validating => 'gray',
                            BatchStatus::Validated => 'warning',
                            BatchStatus::ValidationFailed => 'danger',
                            BatchStatus::Confirmed => 'success',
                            BatchStatus::Discarded, BatchStatus::Expired => 'gray',
                        }),
                    TextEntry::make('creator.name')->label('Người tạo'),
                    TextEntry::make('confirmed_at')->label('Xác nhận lúc')->dateTime('d/m/Y H:i')->placeholder('Chưa xác nhận'),
                    // Hai liên kết nằm trong Chứng từ chứ không thành Section riêng: chúng chỉ hiện
                    // khi lô có, tức hầu hết lô không thấy gì — một khối aside trống chỗ cho phần
                    // lớn trường hợp không đáng. Đây cũng là chỗ người ta đang đọc bối cảnh của lô.
                    TextEntry::make('supplements_batch_id')
                        ->label('Bổ sung cho lô')
                        ->prefix('#')
                        // Trước đây là text trần nên muốn sang lô kia phải tự sửa URL.
                        ->url(fn (Batch $record): ?string => $record->supplements_batch_id === null
                            ? null
                            : self::getUrl('view', ['record' => $record->supplements_batch_id]))
                        ->visible(fn (Batch $record): bool => $record->supplements_batch_id !== null),
                    TextEntry::make('supplier_claim_id')
                        ->label('Hàng thay thế cho Khiếu nại')
                        ->prefix('#')
                        ->helperText('Giá vốn 0.')
                        ->url(fn (Batch $record): ?string => $record->supplier_claim_id === null ? null : SupplierClaimResource::getUrl('view', ['record' => $record->supplier_claim_id]))
                        ->visible(fn (Batch $record): bool => $record->supplier_claim_id !== null),
                    TextEntry::make('note')->label('Ghi chú')->placeholder('Không có')->columnSpanFull(),
                ]),

            Section::make('Tiền')
                ->description('Giá vốn bất biến sau khi nhập; Tổng tiền hoá đơn thì sửa được cả sau khi xác nhận (ADR 0006).')
                ->aside()
                ->columns(3)
                ->schema([
                    TextEntry::make('total_cost')
                        ->label('Tổng Giá vốn phần nhập được')
                        ->state(fn (Batch $record): string => $money(self::preview($record)->totalCost()))
                        ->visible(fn (Batch $record): bool => in_array($record->status, [BatchStatus::Validated, BatchStatus::Confirmed], true)),
                    TextEntry::make('invoice_total')
                        ->label('Tổng tiền hoá đơn')
                        ->formatStateUsing(fn (int $state): string => $money($state))
                        ->placeholder('Chưa ghi'),
                    TextEntry::make('invoice_difference')
                        ->label('Chênh lệch hoá đơn − Giá vốn')
                        ->state(fn (Batch $record): string => $money((int) self::preview($record)->invoiceDifference()))
                        ->color(fn (Batch $record): string => self::preview($record)->invoiceDifference() === 0 ? 'success' : 'danger')
                        ->visible(fn (Batch $record): bool => $record->invoice_total !== null
                            && in_array($record->status, [BatchStatus::Validated, BatchStatus::Confirmed], true)),
                ]),

        ]);
    }

    /**
     * Cái giá của việc bấm Xác nhận, nói thành một câu thay vì để nhân viên tự cộng trừ.
     */
    private static function caiGiaCuaXacNhan(Batch $record, callable $money): string
    {
        $bo = $record->rejectedInvalidCount() + $record->rejectedDuplicateCount();

        return $bo === 0
            ? sprintf('Tổng Giá vốn %s. Không dòng nào bị bỏ. Xác nhận là Lô nhập đóng lại, chỉ Quản trị Huỷ nhập được.', $money(self::preview($record)->totalCost()))
            : sprintf(
                'Tổng Giá vốn %s. %s dòng bị bỏ và KHÔNG vào kho. Xác nhận là Lô nhập đóng lại, chỉ Quản trị Huỷ nhập được.',
                $money(self::preview($record)->totalCost()),
                number_format($bo, 0, ',', '.'),
            );
    }

    /**
     * Vào kho được bao nhiêu, bỏ bao nhiêu, vì sao bỏ.
     *
     * @return array<Entry>
     */
    private static function soLieuVaoKho(): array
    {
        return [
            TextEntry::make('proto_import_count')
                ->label('Đơn vị hàng vào kho')
                ->state(fn (Batch $record): string => number_format(self::preview($record)->importCount(), 0, ',', '.'))
                ->weight(FontWeight::Bold)
                ->size(TextSize::Large)
                ->color('success'),
            TextEntry::make('rejected_invalid')
                ->label('Bỏ vì lỗi định dạng')
                ->state(fn (Batch $record): string => number_format($record->rejectedInvalidCount(), 0, ',', '.'))
                ->color(fn (Batch $record): string => $record->rejectedInvalidCount() > 0 ? 'danger' : 'gray'),
            TextEntry::make('rejected_duplicate')
                ->label('Bỏ vì trùng')
                ->state(fn (Batch $record): string => number_format($record->rejectedDuplicateCount(), 0, ',', '.'))
                ->color(fn (Batch $record): string => $record->rejectedDuplicateCount() > 0 ? 'warning' : 'gray'),
            // Số Dòng nhập, không phải Tổng Giá vốn: con số ấy đã có ở Section Tiền, để đây nữa là
            // hiện hai lần trên cùng một trang.
            TextEntry::make('line_count')
                ->label('Dòng nhập')
                ->state(fn (Batch $record): string => (string) count(self::preview($record)->lines)),
        ];
    }

    /**
     * Hàng của lô sau khi đã vào kho. Không có quan hệ Eloquent nào đi hết đường này
     * (hasManyThrough chỉ nhảy một bảng trung gian, đây cần hai), nên join tay.
     *
     * @return array<Entry>
     */
    private static function tinhTrangHang(): array
    {
        $slot = fn (string $key): callable => fn (Batch $record): string => number_format(
            (int) (self::slotTally($record)->{$key} ?? 0),
            0,
            ',',
            '.',
        );

        return [
            TextEntry::make('slots_total')->label('Slot đã nhập')->state($slot('slots')),
            TextEntry::make('slots_in_stock')
                ->label('Còn hàng')
                ->state($slot('in_stock_slots'))
                ->weight(FontWeight::Bold)
                ->color('success'),
            TextEntry::make('slots_delivered')->label('Đã giao')->state($slot('delivered_slots')),
            TextEntry::make('slots_reserved')->label('Đã giữ')->state($slot('reserved_slots')),
            TextEntry::make('slots_defective')->label('Tồn lỗi')->state($slot('defective_slots'))->color('danger'),
            TextEntry::make('slots_voided')->label('Đã huỷ hàng')->state($slot('voided_slots')),
            TextEntry::make('slots_reversed')->label('Đã huỷ nhập')->state($slot('reversed_slots'))->color('danger'),
        ];
    }

    /**
     * Một query, một hàng: đếm Slot của Lô nhập theo trạng thái. Tồn lỗi là giao của hai trạng thái
     * (Slot Còn hàng của Đơn vị hàng Lỗi) nên phải FILTER riêng, không nhóm được.
     */
    private static function slotTally(Batch $record): object
    {
        if (array_key_exists($record->getKey(), self::$slotTallyCache)) {
            return self::$slotTallyCache[$record->getKey()];
        }

        $inStock = "slots.status = '".SlotStatus::InStock->value."'";
        $defective = $inStock." AND stock_units.status = '".StockUnitStatus::Defective->value."'";

        return self::$slotTallyCache[$record->getKey()] = DB::table('slots')
            ->join('stock_units', 'stock_units.id', '=', 'slots.stock_unit_id')
            ->join('batch_lines', 'batch_lines.id', '=', 'stock_units.batch_line_id')
            ->where('batch_lines.batch_id', $record->getKey())
            ->selectRaw('COUNT(*) AS slots')
            ->selectRaw("COUNT(*) FILTER (WHERE {$inStock}) AS in_stock_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE slots.status = '".SlotStatus::Reserved->value."') AS reserved_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE slots.status = '".SlotStatus::Delivered->value."') AS delivered_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE slots.status = '".SlotStatus::Voided->value."') AS voided_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE slots.status = '".SlotStatus::Reversed->value."') AS reversed_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE {$defective}) AS defective_slots")
            ->first() ?? new stdClass;
    }

    private static function hanConLai(Batch $record): string
    {
        $deadline = self::deadline($record);
        $now = CarbonImmutable::now();

        if ($deadline === null) {
            return 'Không rõ hạn xác nhận';
        }

        if ($now->greaterThanOrEqualTo($deadline)) {
            return 'Đã quá hạn xác nhận';
        }

        return sprintf(
            'Còn %d giờ %d phút để xác nhận (hết hạn %s)',
            (int) $now->diffInHours($deadline),
            (int) $now->diffInMinutes($deadline) % 60,
            $deadline->format('d/m/Y H:i'),
        );
    }

    private static function gioConLai(Batch $record): int
    {
        $deadline = self::deadline($record);

        return $deadline === null ? 99 : max(0, (int) CarbonImmutable::now()->diffInHours($deadline));
    }

    private static function deadline(Batch $record): ?CarbonImmutable
    {
        return $record->created_at === null
            ? null
            : CarbonImmutable::instance($record->created_at)
                ->addHours((int) config('inventory.intake.pending_ttl_hours', 24));
    }

    /**
     * Kết quả kiểm tra từng Dòng nhập. Trước đây là grid 7 cột với 9 entry span-1 nên hàng đầu ăn
     * đúng 7 ô rồi Hạn sử dụng và Số slot rơi xuống đứng lẻ; giờ số cột là tham số và các ô dài đi
     * riêng. Dòng bị bỏ vẫn là bảng lồng, nhưng có sẵn cột Sản phẩm ở nhãn của từng khối.
     */
    private static function ketQuaKiemTra(callable $money, callable $oneOrMany, int $columns): RepeatableEntry
    {
        return RepeatableEntry::make('line_previews')
            ->state(fn (Batch $record): array => array_map(fn (BatchLinePreview $line): array => [
                'product' => $line->productName,
                'source' => $line->fileName === null ? $line->source->label() : "{$line->source->label()}: {$line->fileName}",
                'vao_kho' => number_format($line->importCount(), 0, ',', '.'),
                'bo' => self::soDongBo($line),
                'ignored_columns' => $line->ignoredColumns === [] ? null : implode(', ', $line->ignoredColumns),
                'total_cost' => $money($line->totalCost),
                'slots' => $oneOrMany($line->slots, fn (int $slots): string => $slots.' slot'),
                'expires_on' => $oneOrMany(
                    $line->expiresOn,
                    fn (?string $date): string => $date === null ? 'Không có' : CarbonImmutable::parse($date)->format('d/m/Y'),
                ),
                'reversed' => $line->reversedCount === 0 ? null : $line->reversedCount.' Đơn vị hàng',
                'sample' => array_map(
                    fn (array $unit): string => collect($unit)->map(fn (string $value, string $label): string => "{$label}: {$value}")->implode(' · '),
                    $line->sample,
                ),
                'rejected' => array_map(fn (RejectedLine $rejected): array => [
                    'line' => $rejected->lineNumber,
                    'class' => $rejected->class->label(),
                    'reason' => $rejected->reason,
                ], $line->rejected),
            ], self::preview($record)->lines))
            ->columnSpanFull()
            ->columns($columns)
            ->schema([
                TextEntry::make('product')->label('Sản phẩm')->weight(FontWeight::Bold),
                TextEntry::make('vao_kho')->label('Vào kho')->color('success'),
                TextEntry::make('bo')->label('Bị bỏ')->placeholder('Không có'),
                TextEntry::make('slots')->label('Số slot mỗi Đơn vị hàng')->placeholder('Không có dòng hợp lệ'),
                TextEntry::make('expires_on')->label('Hạn sử dụng')->placeholder('Không có dòng hợp lệ'),
                TextEntry::make('total_cost')->label('Tổng Giá vốn'),
                TextEntry::make('source')->label('Nguồn'),
                TextEntry::make('reversed')->label('Đã Huỷ nhập')->color('danger')->placeholder('Không'),
                TextEntry::make('ignored_columns')->label('Cột bị bỏ qua')->placeholder('Không có'),
                TextEntry::make('sample')
                    ->label('Mẫu Đơn vị hàng nhập được (đã che)')
                    ->listWithLineBreaks()
                    ->limitList(5)
                    ->expandableLimitedList()
                    ->placeholder('Không có dòng hợp lệ')
                    ->columnSpanFull(),
                RepeatableEntry::make('rejected')
                    ->label('Dòng bị bỏ')
                    ->placeholder('Không có dòng bị bỏ')
                    ->table([
                        RepeatableEntry\TableColumn::make('Dòng')->width('8%')->alignEnd(),
                        RepeatableEntry\TableColumn::make('Loại')->width('22%'),
                        RepeatableEntry\TableColumn::make('Lý do'),
                    ])
                    ->schema([
                        TextEntry::make('line')->alignEnd(),
                        TextEntry::make('class')->badge()->color('danger'),
                        TextEntry::make('reason'),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Ba loại dòng bị bỏ gộp thành một câu, thay cho ba ô rời mà phần lớn là số 0.
     */
    private static function soDongBo(BatchLinePreview $line): ?string
    {
        $phan = [];

        if ($line->invalidCount > 0) {
            $phan[] = $line->invalidCount.' lỗi định dạng';
        }

        if ($line->fileDuplicateCount > 0) {
            $phan[] = $line->fileDuplicateCount.' trùng trong file';
        }

        if ($line->stockDuplicateCount > 0) {
            $phan[] = $line->stockDuplicateCount.' trùng trong kho';
        }

        return $phan === [] ? null : implode(' · ', $phan);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('received_on')
                    ->label('Ngày nhập')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Nhà cung cấp')
                    ->searchable(),
                TextColumn::make('document_number')
                    ->label('Số chứng từ')
                    ->searchable(),
                TextColumn::make('products')
                    ->label('Sản phẩm')
                    ->state(fn (Batch $record): string => $record->lines->map(fn (BatchLine $line): string => $line->product->name)->implode(', ')),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (BatchStatus $state): string => $state->label()),
                TextColumn::make('creator.name')
                    ->label('Người tạo'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(collect(BatchStatus::cases())->mapWithKeys(fn (BatchStatus $status): array => [$status->value => $status->label()])->all()),
                // Hẹp hơn Trạng thái = Chờ xác nhận: bỏ các lô đã quá hạn thật mà job dọn chưa chạy.
                // `$query` không khai kiểu vì Larastan không thấy scope của model trên Builder chung.
                Filter::make('awaiting_confirmation')
                    ->label('Chờ xác nhận, còn trong hạn')
                    ->query(fn ($query) => $query->awaitingConfirmation()),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['supplier', 'creator', 'lines.product']))
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBatches::route('/'),
            'create' => CreateBatch::route('/tao'),
            'view' => ViewBatch::route('/{record}'),
        ];
    }
}
