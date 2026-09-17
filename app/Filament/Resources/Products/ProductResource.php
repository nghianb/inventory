<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Inventory\Encryption\Normalization;
use App\Models\ContentField;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Sản phẩm trong panel. Adapter mỏng: mọi thao tác gọi ProductCatalog, nơi kiểm tra
 * Vai trò, cấu hình hợp lệ và khoá cấu hình khi đã có hàng. Form chỉ khoá sẵn các ô
 * tương ứng để Quản trị không phải đoán.
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
        $hasStock = fn (Get $get): bool => (bool) $get('has_stock');
        $fieldLocked = fn (Get $get): bool => (bool) $get('../../has_stock') && (bool) $get('persisted');

        return $schema->components([
            Hidden::make('has_stock')->default(false),
            Hidden::make('has_dispatch')->default(false),
            // Hai mốc khoá khác nhau và không trùng nhau: một Sản phẩm có thể đã có hàng mà chưa
            // từng xuất. Một hộp duy nhất, nội dung dựng theo trạng thái thật, thay vì để Quản trị
            // phát hiện giới hạn bằng cách đâm vào nó. Trang Tạo không bao giờ thấy hộp này.
            Callout::make('Cấu hình bị khoá')
                ->warning()
                ->visible(fn (Get $get): bool => (bool) $get('has_stock') || (bool) $get('has_dispatch'))
                ->description(fn (Get $get): string => collect([
                    $get('has_stock')
                        ? 'Sản phẩm đã có hàng: không đổi được Loại, hai tuỳ chọn chuẩn hoá, Khoá chống trùng, và định danh, kiểu, regex, cờ bắt buộc, cờ nhạy cảm của các Trường nội dung đã lưu; cũng không xoá được trường đã lưu. Vẫn thêm được trường tuỳ chọn và đổi tên hiển thị.'
                        : null,
                    $get('has_dispatch')
                        ? 'Sản phẩm đã có Phiếu xuất: không đổi được Mã sản phẩm.'
                        : null,
                ])->filter()->implode(' '))
                ->columnSpanFull(),
            Section::make('Thông tin')
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->label('Loại')
                        ->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $type): array => [$type->value => $type->label()])->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state === null) {
                                return;
                            }

                            $normalization = ProductType::from($state)->defaultNormalization();
                            $set('case_insensitive', $normalization->caseInsensitive);
                            $set('strip_separators', $normalization->stripSeparators);
                        })
                        ->disabled($hasStock)
                        ->dehydrated(),
                    TextInput::make('name')
                        ->label('Tên')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('code')
                        ->label('Mã sản phẩm')
                        ->helperText(fn (Get $get): string => $get('has_dispatch')
                            ? 'Sản phẩm đã có Phiếu xuất: không đổi được Mã sản phẩm.'
                            : 'Chữ in hoa không dấu, chữ số, dấu chấm, gạch ngang, gạch dưới. Ví dụ NETFLIX-1M.')
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
                        ->visible(fn (Get $get): bool => $get('type') === ProductType::Account->value),
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
            // Trường nội dung là việc chính khi khai một Sản phẩm nên leo lên ngay dưới Thông tin;
            // hai khối dưới có mặc định dùng được ngay nên thu gọn sẵn.
            Repeater::make('fields')
                ->label('Trường nội dung')
                ->helperText('Sản phẩm đã có hàng chỉ thêm được trường tuỳ chọn hoặc đổi tên hiển thị.')
                ->minItems(1)
                ->defaultItems(1)
                ->reorderable(false)
                ->deletable(fn (Get $get): bool => ! $get('has_stock'))
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('persisted')->default(false),
                    TextInput::make('key')
                        ->label('Định danh')
                        ->helperText('Chữ thường không dấu, ví dụ username.')
                        ->required()
                        ->maxLength(64)
                        ->disabled($fieldLocked)
                        ->dehydrated(),
                    TextInput::make('label')
                        ->label('Tên hiển thị')
                        ->required()
                        ->maxLength(255),
                    Select::make('type')
                        ->label('Kiểu')
                        ->options(collect(ContentFieldType::cases())->mapWithKeys(fn (ContentFieldType $type): array => [$type->value => $type->label()])->all())
                        ->default(ContentFieldType::Text->value)
                        ->required()
                        ->disabled($fieldLocked)
                        ->dehydrated(),
                    TextInput::make('pattern')
                        ->label('Regex')
                        ->helperText('Tuỳ chọn, phải khớp toàn bộ giá trị.')
                        ->maxLength(255)
                        ->disabled($fieldLocked)
                        ->dehydrated(),
                    Toggle::make('required')
                        ->label('Bắt buộc')
                        ->default(true)
                        ->disabled($fieldLocked)
                        ->dehydrated(),
                    Toggle::make('sensitive')
                        ->label('Nhạy cảm')
                        ->helperText('Mã hoá và che hoàn toàn.')
                        ->default(true)
                        ->disabled($fieldLocked)
                        ->dehydrated(),
                    Toggle::make('dedupe_key')
                        ->label('Khoá chống trùng')
                        ->default(false)
                        ->disabled(fn (Get $get): bool => (bool) $get('../../has_stock'))
                        ->dehydrated(),
                ]),
            Section::make('Chuẩn hoá Khoá chống trùng')
                ->key('normalization')
                ->description('Áp khi so trùng; nội dung giao khách vẫn là chuỗi gốc.')
                // Mặc định theo loại Sản phẩm đã dùng được ngay; khối đang mang giá trị khác mặc
                // định thì mở sẵn, để Sửa không giấu mất thứ mình từng đổi.
                ->collapsed(fn (Get $get): bool => blank($get('type')) || new Normalization(
                    (bool) $get('case_insensitive'),
                    (bool) $get('strip_separators'),
                ) == ProductType::from((string) $get('type'))->defaultNormalization())
                ->columns(2)
                ->schema([
                    Toggle::make('case_insensitive')
                        ->label('Không phân biệt hoa thường')
                        ->default(true)
                        ->disabled($hasStock)
                        ->dehydrated(),
                    Toggle::make('strip_separators')
                        ->label('Bỏ gạch ngang và khoảng trắng bên trong')
                        ->default(false)
                        ->disabled($hasStock)
                        ->dehydrated(),
                ]),
            Section::make('Mẫu giao hàng')
                ->key('template')
                ->description('Văn bản ghép nội dung một Slot thành tin nhắn gửi khách. Để trống thì mỗi Trường nội dung một dòng "Tên trường: giá trị".')
                ->collapsed(fn (Get $get): bool => blank($get('delivery_template')))
                ->schema([
                    Textarea::make('delivery_template')
                        ->hiddenLabel()
                        ->rows(6)
                        ->helperText(fn (Get $get): string => 'Biến: '.collect([
                            ...array_map(fn (array $field): string => (string) ($field['key'] ?? ''), array_values((array) $get('fields'))),
                            ...DeliveryTemplate::BUILT_IN,
                        ])->filter()->map(fn (string $name): string => "{{{$name}}}")->implode(', ').'. Hạn sử dụng, Hạn bảo hành hiện dạng ngày/tháng/năm; mã đơn là mã đơn ngoài của Phiếu xuất.')
                        ->placeholder("Cảm ơn bạn đã mua {{san_pham}} (đơn {{ma_don}})\nTài khoản: {{username}}\nBảo hành đến {{han_bao_hanh}}"),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')
                    ->label('Mã sản phẩm')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (ProductType $state): string => $state->label()),
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
            'type' => $product->type->value,
            'name' => $product->name,
            'code' => $product->code,
            'default_slots' => $product->default_slots,
            'warranty_days' => $product->warranty_days,
            'min_remaining_days' => $product->min_remaining_days,
            'low_stock_threshold' => $product->low_stock_threshold,
            'case_insensitive' => $product->case_insensitive,
            'strip_separators' => $product->strip_separators,
            'delivery_template' => $product->delivery_template,
            'fields' => $product->contentFields->map(fn (ContentField $field): array => [
                'persisted' => true,
                'key' => $field->key,
                'label' => $field->label,
                'type' => $field->type->value,
                'pattern' => $field->pattern,
                'required' => $field->required,
                'sensitive' => $field->sensitive,
                'dedupe_key' => $field->is_dedupe_key,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function draftFromForm(array $data): ProductDraft
    {
        $type = ProductType::from($data['type']);

        return new ProductDraft(
            type: $type,
            name: $data['name'],
            code: $data['code'],
            fields: array_values(array_map(fn (array $field): ContentFieldDraft => new ContentFieldDraft(
                key: $field['key'],
                label: $field['label'],
                type: ContentFieldType::from($field['type']),
                pattern: filled($field['pattern'] ?? null) ? $field['pattern'] : null,
                required: (bool) $field['required'],
                sensitive: (bool) $field['sensitive'],
                dedupeKey: (bool) $field['dedupe_key'],
            ), $data['fields'])),
            defaultSlots: $type === ProductType::Account ? (int) $data['default_slots'] : 1,
            warrantyDays: (int) $data['warranty_days'],
            minRemainingDays: (int) $data['min_remaining_days'],
            lowStockThreshold: filled($data['low_stock_threshold'] ?? null) ? (int) $data['low_stock_threshold'] : null,
            normalization: new Normalization((bool) $data['case_insensitive'], (bool) $data['strip_separators']),
            deliveryTemplate: $data['delivery_template'] ?? null,
        );
    }
}
