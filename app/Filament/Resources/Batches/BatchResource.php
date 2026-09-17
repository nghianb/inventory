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
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Intake\LineClassifier;
use App\Inventory\Intake\RejectedLine;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

    public static function form(Schema $schema): Schema
    {
        $stockForm = fn (Get $get): ?StockForm => Product::query()->with('productType')->find($get('product_id'))?->form();

        return $schema->components([
            Section::make('Chứng từ')
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
                    TextInput::make('document_number')
                        ->label('Số chứng từ')
                        ->maxLength(255),
                    TextInput::make('invoice_total')
                        ->label('Tổng tiền hoá đơn')
                        ->helperText('Để đối chiếu với tổng Giá vốn ở màn xem trước.')
                        ->suffix('₫')
                        ->integer()
                        ->minValue(0),
                    Select::make('supplements_batch_id')
                        ->label('Bổ sung cho lô')
                        ->options(fn (): array => Batch::query()
                            ->with('supplier')
                            ->where('status', BatchStatus::Confirmed)
                            ->latest('id')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(fn (Batch $batch): array => [$batch->id => "#{$batch->id} · {$batch->supplier->name} · {$batch->received_on->format('d/m/Y')}"])
                            ->all())
                        ->searchable(),
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
                        ->rows(2),
                ]),
            Repeater::make('lines')
                ->label('Dòng nhập')
                ->addActionLabel('Thêm Dòng nhập')
                ->minItems(1)
                ->defaultItems(1)
                ->columns(3)
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
                        ->helperText('Cột gia_von trong file ghi đè.')
                        ->suffix('₫')
                        ->integer()
                        ->minValue(0)
                        ->required()
                        // Hàng thay thế từ Khiếu nại luôn Giá vốn 0.
                        ->visible(fn (Get $get): bool => blank($get('../../supplier_claim_id'))),
                    TextInput::make('slots')
                        ->label('Số slot mỗi Tài khoản')
                        ->helperText('Để trống thì theo Sản phẩm; cột slot trong file ghi đè.')
                        ->placeholder(fn (Get $get): string => (string) Product::query()->find($get('product_id'))?->default_slots)
                        ->integer()
                        ->minValue(1)
                        ->maxValue(LineClassifier::MAX_SLOTS)
                        ->visible(fn (Get $get): bool => $stockForm($get) === StockForm::Account),
                    Select::make('expiry_mode')
                        ->label('Hạn sử dụng')
                        ->options(['none' => 'Không có', 'date' => 'Ngày cụ thể', 'days' => 'Số ngày kể từ ngày nhập'])
                        ->default('none')
                        ->helperText('Cột han_su_dung trong file ghi đè.')
                        ->selectablePlaceholder(false)
                        ->live(),
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
                        ->helperText('Bỏ qua nếu Sản phẩm chỉ có một Trường nội dung.')
                        ->selectablePlaceholder(false)
                        ->visible(fn (Get $get): bool => $get('source') !== 'file')
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
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $preview = fn (Batch $record) => app(BatchIntake::class)->preview(InventoryAction::actor(), $record);
        $money = fn (int $amount): string => number_format($amount, 0, ',', '.').' ₫';

        return $schema->components([
            Section::make('Chứng từ')
                ->columns(3)
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
                    TextEntry::make('note')->label('Ghi chú')->placeholder('Không có')->columnSpanFull(),
                    TextEntry::make('validation_error')
                        ->label('Lỗi kiểm tra')
                        ->color('danger')
                        ->visible(fn (Batch $record): bool => $record->status === BatchStatus::ValidationFailed)
                        ->columnSpanFull(),
                    TextEntry::make('supplements_batch_id')
                        ->label('Bổ sung cho lô')
                        ->prefix('#')
                        ->visible(fn (Batch $record): bool => $record->supplements_batch_id !== null),
                    TextEntry::make('supplier_claim_id')
                        ->label('Hàng thay thế cho Khiếu nại')
                        ->prefix('#')
                        ->helperText('Giá vốn 0.')
                        ->url(fn (Batch $record): ?string => $record->supplier_claim_id === null ? null : SupplierClaimResource::getUrl('view', ['record' => $record->supplier_claim_id]))
                        ->visible(fn (Batch $record): bool => $record->supplier_claim_id !== null),
                    TextEntry::make('total_cost')
                        ->label('Tổng Giá vốn phần nhập được')
                        ->state(fn (Batch $record): string => $money($preview($record)->totalCost()))
                        ->visible(fn (Batch $record): bool => in_array($record->status, [BatchStatus::Validated, BatchStatus::Confirmed], true)),
                    TextEntry::make('invoice_total')
                        ->label('Tổng tiền hoá đơn')
                        ->formatStateUsing(fn (int $state): string => $money($state))
                        ->visible(fn (Batch $record): bool => $record->invoice_total !== null),
                    TextEntry::make('invoice_difference')
                        ->label('Chênh lệch hoá đơn − Giá vốn')
                        ->state(fn (Batch $record): string => $money((int) $preview($record)->invoiceDifference()))
                        ->color(fn (Batch $record): string => $preview($record)->invoiceDifference() === 0 ? 'success' : 'danger')
                        ->visible(fn (Batch $record): bool => $record->invoice_total !== null
                            && in_array($record->status, [BatchStatus::Validated, BatchStatus::Confirmed], true)),
                ]),
            RepeatableEntry::make('line_previews')
                ->label('Kết quả kiểm tra')
                ->visible(fn (Batch $record): bool => in_array($record->status, [BatchStatus::Validated, BatchStatus::Confirmed], true))
                ->state(fn (Batch $record): array => array_map(fn (BatchLinePreview $line): array => [
                    'product' => $line->productName,
                    'source' => $line->fileName === null ? $line->source->label() : "{$line->source->label()}: {$line->fileName}",
                    'valid' => $line->validCount,
                    'renewal' => $line->renewalCount,
                    'ignored_columns' => $line->ignoredColumns === [] ? null : implode(', ', $line->ignoredColumns),
                    'invalid' => $line->invalidCount,
                    'file_duplicate' => $line->fileDuplicateCount,
                    'stock_duplicate' => $line->stockDuplicateCount,
                    'total_cost' => $money($line->totalCost),
                    'reversed' => $line->reversedCount === 0 ? null : $line->reversedCount,
                    'sample' => array_map(
                        fn (array $unit): string => collect($unit)->map(fn (string $value, string $label): string => "{$label}: {$value}")->implode(' · '),
                        $line->sample,
                    ),
                    'rejected' => array_map(fn (RejectedLine $rejected): array => [
                        'line' => $rejected->lineNumber,
                        'class' => $rejected->class->label(),
                        'reason' => $rejected->reason,
                    ], $line->rejected),
                ], $preview($record)->lines))
                ->columnSpanFull()
                ->columns(7)
                ->schema([
                    TextEntry::make('product')->label('Sản phẩm'),
                    TextEntry::make('valid')->label('Hợp lệ')->color('success'),
                    TextEntry::make('renewal')->label('Nhập lại Tài khoản')->color('success'),
                    TextEntry::make('invalid')->label('Lỗi định dạng'),
                    TextEntry::make('file_duplicate')->label('Trùng trong file'),
                    TextEntry::make('stock_duplicate')->label('Trùng trong kho'),
                    TextEntry::make('total_cost')->label('Tổng Giá vốn'),
                    TextEntry::make('source')->label('Nguồn')->columnSpan(2),
                    TextEntry::make('reversed')->label('Đã Huỷ nhập')->color('danger')->placeholder('Không'),
                    TextEntry::make('ignored_columns')
                        ->label('Cột bị bỏ qua')
                        ->placeholder('Không có')
                        ->columnSpan(4),
                    TextEntry::make('sample')
                        ->label('Mẫu Đơn vị hàng nhập được (đã che)')
                        ->listWithLineBreaks()
                        ->placeholder('Không có dòng hợp lệ')
                        ->columnSpanFull(),
                    RepeatableEntry::make('rejected')
                        ->label('Dòng bị bỏ')
                        ->placeholder('Không có dòng bị bỏ')
                        ->table([
                            RepeatableEntry\TableColumn::make('Dòng'),
                            RepeatableEntry\TableColumn::make('Loại'),
                            RepeatableEntry\TableColumn::make('Lý do'),
                        ])
                        ->schema([
                            TextEntry::make('line'),
                            TextEntry::make('class'),
                            TextEntry::make('reason'),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
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
