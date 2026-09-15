<?php

namespace App\Filament\Resources\Batches;

use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLinePreview;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Intake\RejectedLine;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\Product;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lô nhập trong panel. Adapter mỏng: trang tạo gọi BatchIntake::submit, trang xem hiện
 * BatchIntake::preview và nút xác nhận gọi BatchIntake::confirm. Bán hàng không thấy.
 */
class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

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
        return $schema->components([
            Section::make('Chứng từ')
                ->columns(2)
                ->schema([
                    Select::make('supplier_id')
                        ->label('Nhà cung cấp')
                        ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    DatePicker::make('received_on')
                        ->label('Ngày nhập')
                        ->default(now())
                        ->required(),
                    TextInput::make('document_number')
                        ->label('Số chứng từ')
                        ->maxLength(255),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->helperText('Hàng mua bằng ngoại tệ: ghi tỷ giá đã quy đổi.')
                        ->rows(2),
                ]),
            Section::make('Dòng nhập')
                ->columns(3)
                ->schema([
                    Select::make('product_id')
                        ->label('Sản phẩm')
                        ->options(fn (): array => Product::query()->where('type', ProductType::OneTimeCode)->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Hiện chỉ nhập được Sản phẩm Mã dùng một lần.')
                        ->searchable()
                        ->required(),
                    TextInput::make('unit_cost')
                        ->label('Giá vốn mỗi Đơn vị hàng')
                        ->suffix('₫')
                        ->integer()
                        ->minValue(0)
                        ->required(),
                    Select::make('separator')
                        ->label('Ký tự phân tách')
                        ->options(array_map(fn (array $separator): string => $separator[1], self::SEPARATORS))
                        ->default('tab')
                        ->helperText('Bỏ qua nếu Sản phẩm chỉ có một Trường nội dung.')
                        ->selectablePlaceholder(false)
                        ->required(),
                    Textarea::make('content')
                        ->label('Danh sách Đơn vị hàng')
                        ->helperText('Mỗi dòng một Đơn vị hàng, các trường theo thứ tự khai báo trên Sản phẩm.')
                        ->rows(12)
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
                        }),
                    TextEntry::make('creator.name')->label('Người tạo'),
                    TextEntry::make('confirmed_at')->label('Xác nhận lúc')->dateTime('d/m/Y H:i')->placeholder('Chưa xác nhận'),
                    TextEntry::make('note')->label('Ghi chú')->placeholder('Không có')->columnSpanFull(),
                    TextEntry::make('validation_error')
                        ->label('Lỗi kiểm tra')
                        ->color('danger')
                        ->visible(fn (Batch $record): bool => $record->status === BatchStatus::ValidationFailed)
                        ->columnSpanFull(),
                    TextEntry::make('total_cost')
                        ->label('Tổng Giá vốn phần hợp lệ')
                        ->state(fn (Batch $record): string => $money($preview($record)->totalCost()))
                        ->visible(fn (Batch $record): bool => in_array($record->status, [BatchStatus::Validated, BatchStatus::Confirmed], true)),
                ]),
            RepeatableEntry::make('line_previews')
                ->label('Kết quả kiểm tra')
                ->visible(fn (Batch $record): bool => in_array($record->status, [BatchStatus::Validated, BatchStatus::Confirmed], true))
                ->state(fn (Batch $record): array => array_map(fn (BatchLinePreview $line): array => [
                    'product' => $line->productName,
                    'valid' => $line->validCount,
                    'invalid' => $line->invalidCount,
                    'file_duplicate' => $line->fileDuplicateCount,
                    'stock_duplicate' => $line->stockDuplicateCount,
                    'total_cost' => $money($line->totalCost),
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
                ->columns(6)
                ->schema([
                    TextEntry::make('product')->label('Sản phẩm'),
                    TextEntry::make('valid')->label('Hợp lệ')->color('success'),
                    TextEntry::make('invalid')->label('Lỗi định dạng'),
                    TextEntry::make('file_duplicate')->label('Trùng trong file'),
                    TextEntry::make('stock_duplicate')->label('Trùng trong kho'),
                    TextEntry::make('total_cost')->label('Tổng Giá vốn'),
                    TextEntry::make('sample')
                        ->label('Mẫu Đơn vị hàng hợp lệ (đã che)')
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
