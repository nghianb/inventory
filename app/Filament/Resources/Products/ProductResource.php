<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Models\Product;
use App\Models\ProductType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Sản phẩm trong panel. Adapter mỏng: mọi thao tác gọi ProductCatalog, nơi kiểm tra Vai trò,
 * cấu hình hợp lệ và khoá cấu hình khi đã có hàng.
 *
 * Trường nội dung, Dạng hàng và chuẩn hoá Khoá chống trùng không có mặt ở đây: chúng thuộc
 * Loại sản phẩm ({@see ProductTypeResource}). Sản phẩm chỉ ghi đè được Mẫu giao hàng.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::KhoHang;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'sản phẩm';

    protected static ?string $pluralModelLabel = 'Sản phẩm';

    protected static ?string $navigationLabel = 'Sản phẩm';

    protected static ?string $slug = 'san-pham';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Một mạch cuộn: mỗi khối một card chiếm trọn bề ngang. Phải tự khai cột vì trang
        // resource ép lưới 2 cột khi form không khai (CreateRecord và EditRecord::defaultForm),
        // làm card Thông tin chỉ ăn nửa bề ngang.
        return $schema->columns(1)->components([
            Hidden::make('has_stock')->default(false),
            Hidden::make('has_dispatch')->default(false),
            // Hai mốc khoá khác nhau và không trùng nhau: một Sản phẩm có thể đã có hàng mà chưa
            // từng xuất. Một hộp duy nhất, nội dung dựng theo trạng thái thật, thay vì để Quản trị
            // phát hiện giới hạn bằng cách đâm vào nó. Trang Tạo không bao giờ thấy hộp này.
            Callout::make('Cấu hình bị khoá')
                ->warning()
                ->visible(fn (Get $get): bool => (bool) $get('has_stock') || (bool) $get('has_dispatch'))
                ->description(fn (Get $get): string => collect([
                    $get('has_stock') ? ProductCatalog::STOCK_LOCKS_PRODUCT_TYPE : null,
                    $get('has_dispatch') ? ProductCatalog::DISPATCH_LOCKS_CODE : null,
                ])->filter()->implode(' ')),
            Section::make('Thông tin')
                ->columns(2)
                ->schema([
                    Select::make('product_type_id')
                        ->label('Loại sản phẩm')
                        ->helperText('Loại khai Dạng hàng và Trường nội dung của hàng thuộc Sản phẩm này.')
                        // Loại đã Ngừng dùng không nhận Sản phẩm mới, nhưng Sản phẩm đang thuộc nó
                        // vẫn phải thấy Loại của chính mình, không thì ô Loại trống trơn khi Sửa.
                        ->options(fn (?Product $record): array => ProductType::query()
                            ->where(fn (Builder $query) => $query->notDiscontinued()->orWhereKey($record?->product_type_id))
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (ProductType $type): array => [$type->id => "{$type->name} ({$type->form->label()})"])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get): bool => (bool) $get('has_stock'))
                        ->dehydrated(),
                    TextInput::make('name')
                        ->label('Tên')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('code')
                        ->label('Mã sản phẩm')
                        // Ô bị khoá thì hộp Cấu hình bị khoá đã nói lý do ngay đầu trang; ở đây
                        // chỉ nhắc dạng mã, không lặp lại câu ấy lần thứ hai trên cùng màn hình.
                        ->helperText('Chữ in hoa không dấu, chữ số, dấu chấm, gạch ngang, gạch dưới. Ví dụ NETFLIX-1M.')
                        ->required()
                        ->maxLength(64)
                        ->disabled(fn (Get $get): bool => (bool) $get('has_dispatch'))
                        ->dehydrated(),
                    TextInput::make('default_slots')
                        ->label('Số slot mặc định')
                        ->integer()
                        ->minValue(1)
                        ->default(1)
                        ->required()
                        ->visible(fn (Get $get): bool => self::chosenType($get)?->form === StockForm::Account),
                    TextInput::make('warranty_days')
                        ->label('Thời hạn bảo hành')
                        ->suffix('ngày')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    TextInput::make('min_remaining_days')
                        ->label('Hạn còn lại tối thiểu')
                        ->suffix('ngày')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    TextInput::make('low_stock_threshold')
                        ->label('Ngưỡng sắp hết')
                        ->helperText('Để trống thì không cảnh báo sắp hết.')
                        ->suffix('slot')
                        ->integer()
                        ->minValue(0),
                ]),
            Section::make('Mẫu giao hàng')
                ->key('template')
                ->description('Văn bản ghép nội dung một Slot thành tin nhắn gửi khách. Để trống thì dùng mẫu của Loại sản phẩm; Loại cũng không có mẫu thì mỗi Trường nội dung một dòng "Tên trường: giá trị".')
                ->collapsed(fn (?Product $record): bool => blank($record?->delivery_template))
                ->schema([
                    Textarea::make('delivery_template')
                        ->hiddenLabel()
                        ->rows(6)
                        ->helperText(fn (Get $get): string => 'Biến: '.collect([
                            ...self::chosenType($get)?->contentFields->pluck('key')->all() ?? [],
                            ...DeliveryTemplate::BUILT_IN,
                        ])->filter()->map(fn (string $name): string => "{{{$name}}}")->implode(', ').'. Hạn sử dụng, Hạn bảo hành hiện dạng ngày/tháng/năm; mã đơn là mã đơn ngoài của Phiếu xuất.')
                        ->placeholder("Cảm ơn bạn đã mua {{san_pham}} (đơn {{ma_don}})\nTài khoản: {{username}}\nBảo hành đến {{han_bao_hanh}}"),
                ]),
        ]);
    }

    /**
     * Loại sản phẩm đang chọn trong form; null khi chưa chọn.
     */
    private static function chosenType(Get $get): ?ProductType
    {
        return filled($get('product_type_id'))
            ? ProductType::query()->with('contentFields')->find($get('product_type_id'))
            : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('productType'))
            ->columns([
                TextColumn::make('code')
                    ->label('Mã sản phẩm')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productType.name')
                    ->label('Loại sản phẩm')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('form')
                    ->label('Dạng hàng')
                    ->badge()
                    ->state(fn (Product $record): string => $record->form()->label()),
                TextColumn::make('warranty_days')
                    ->label('Bảo hành')
                    ->suffix(' ngày'),
                TextColumn::make('low_stock_threshold')
                    ->label('Ngưỡng sắp hết')
                    ->placeholder('Không cảnh báo'),
                TextColumn::make('in_stock_slots_count')
                    ->label('Còn hàng')
                    ->counts('inStockSlots')
                    ->suffix(' slot')
                    ->sortable(),
                // Hàng đang giữ cho đơn web vẫn ở trong kho: không có cột này thì nó trông như
                // vừa bốc hơi khỏi cột Còn hàng.
                TextColumn::make('held_slots_count')
                    ->label('Đã giữ')
                    ->counts('heldSlots')
                    ->suffix(' slot')
                    ->sortable(),
                TextColumn::make('defective_stock_slots_count')
                    ->label('Tồn lỗi')
                    ->counts('defectiveStockSlots')
                    ->suffix(' slot')
                    ->sortable(),
                IconColumn::make('has_stock')
                    ->label('Đã có hàng')
                    ->boolean()
                    ->state(fn (Product $record): bool => $record->hasStock()),
                TextColumn::make('discontinued_at')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (Product $record): string => $record->isDiscontinued() ? 'Ngừng bán' : 'Đang bán')
                    ->color(fn (Product $record): string => $record->isDiscontinued() ? 'gray' : 'success'),
            ])
            ->recordActions([
                // Không modal: Filament tự trỏ nút này sang trang Sửa vì resource có trang 'edit'.
                EditAction::make(),
                self::discontinueAction(),
                self::deleteAction(),
            ]);
    }

    /**
     * Ngừng bán đứng ở cả hàng của bảng (xử lý vài Sản phẩm một lượt) lẫn header trang Sửa
     * (người đang sửa không phải quay ra danh sách).
     */
    public static function discontinueAction(): Action
    {
        return Action::make('discontinue')
            ->label('Ngừng bán')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Không Giữ hàng hay Giao hàng mới cho Sản phẩm; Đổi hàng của các lần giao cũ vẫn dùng được.')
            ->visible(fn (Product $record): bool => InventoryAction::actor()->can('discontinue', $record))
            ->action(function (Action $action, Product $record, ProductCatalog $catalog): void {
                InventoryAction::attempt($action, fn () => $catalog->discontinue(InventoryAction::actor(), $record));

                Notification::make()->success()->title('Đã Ngừng bán Sản phẩm.')->send();
            });
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Chỉ xoá được Sản phẩm chưa từng có hàng.')
            ->successNotificationTitle('Đã xoá Sản phẩm.')
            // Filament đọc giá trị trả về làm cờ thành công, mà ProductCatalog::delete() trả về
            // void: thiếu `true` ở đây thì xoá xong vẫn hiện thông báo thất bại và không điều hướng.
            ->using(function (DeleteAction $action, Product $record, ProductCatalog $catalog): bool {
                InventoryAction::attempt($action, fn () => $catalog->delete(InventoryAction::actor(), $record));

                return true;
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/tao'),
            'edit' => EditProduct::route('/{record}/sua'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formData(Product $product): array
    {
        return [
            'has_stock' => $product->hasStock(),
            'has_dispatch' => $product->hasDispatch(),
            'product_type_id' => $product->product_type_id,
            'name' => $product->name,
            'code' => $product->code,
            'default_slots' => $product->default_slots,
            'warranty_days' => $product->warranty_days,
            'min_remaining_days' => $product->min_remaining_days,
            'low_stock_threshold' => $product->low_stock_threshold,
            'delivery_template' => $product->delivery_template,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function draftFromForm(array $data): ProductDraft
    {
        $type = ProductType::query()->with('contentFields')->findOrFail($data['product_type_id']);

        return new ProductDraft(
            productType: $type,
            name: $data['name'],
            code: $data['code'],
            // Ô Số slot mặc định ẩn hẳn với Dạng hàng Mã dùng một lần: nó luôn đúng một slot.
            defaultSlots: $type->form === StockForm::Account ? (int) $data['default_slots'] : 1,
            warrantyDays: (int) $data['warranty_days'],
            minRemainingDays: (int) $data['min_remaining_days'],
            lowStockThreshold: filled($data['low_stock_threshold'] ?? null) ? (int) $data['low_stock_threshold'] : null,
            deliveryTemplate: $data['delivery_template'] ?? null,
        );
    }
}
