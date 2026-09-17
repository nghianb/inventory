<?php

namespace App\Filament\Resources\ProductTypes;

use App\Filament\Resources\ProductTypes\Pages\CreateProductType;
use App\Filament\Resources\ProductTypes\Pages\EditProductType;
use App\Filament\Resources\ProductTypes\Pages\ListProductTypes;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductTypeCatalog;
use App\Inventory\Catalog\ProductTypeDraft;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Inventory\Encryption\Normalization;
use App\Models\ContentField;
use App\Models\ProductType;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Loại sản phẩm trong panel. Adapter mỏng: mọi thao tác gọi ProductTypeCatalog.
 *
 * Sửa Loại là sửa mọi Sản phẩm thuộc nó, nên trang Sửa đi qua bước xem trước rồi mới ghi
 * ({@see EditProductType}). Form không khoá sẵn ô nào: hạng thay đổi nào bị từ chối còn
 * tuỳ Sản phẩm của Loại đã có hàng chưa, và đó là thứ bản xem trước nói ra.
 */
class ProductTypeResource extends Resource
{
    protected static ?string $model = ProductType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::KhoHang;

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'loại sản phẩm';

    protected static ?string $pluralModelLabel = 'Loại sản phẩm';

    protected static ?string $navigationLabel = 'Loại sản phẩm';

    protected static ?string $slug = 'loai-san-pham';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Một mạch cuộn: mỗi khối một card chiếm trọn bề ngang, như trang Sản phẩm.
        return $schema->columns(1)->components([
            Hidden::make('blocking_products')->default([]),
            // Hộp này nói trước cái mà bản xem trước sẽ nói lại lúc bấm Lưu: Loại đã có Sản phẩm
            // có hàng thì mọi thay đổi chạm dữ liệu đã lưu bị từ chối trọn gói (ADR 0004).
            Callout::make('Loại đã có Sản phẩm có hàng')
                ->warning()
                ->visible(fn (Get $get): bool => (array) $get('blocking_products') !== [])
                ->description(fn (Get $get): string => ProductTypeCatalog::LOCKED_CHANGES_NOTE
                    .' Sản phẩm đang chặn: '.implode(', ', (array) $get('blocking_products')).'.'),
            Section::make('Thông tin')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Tên Loại')
                        ->helperText('Ví dụ Thẻ nạp, Tài khoản có 2FA. Loại không có mã: không Kênh bán nào tham chiếu tới nó.')
                        ->required()
                        ->maxLength(255),
                    Select::make('form')
                        ->label('Dạng hàng')
                        ->helperText('Hàng nằm trong kho dưới hình thức nào. Mã dùng một lần luôn đúng một Slot.')
                        ->options(collect(StockForm::cases())->mapWithKeys(fn (StockForm $form): array => [$form->value => $form->label()])->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state === null) {
                                return;
                            }

                            $normalization = StockForm::from($state)->defaultNormalization();
                            $set('case_insensitive', $normalization->caseInsensitive);
                            $set('strip_separators', $normalization->stripSeparators);
                        }),
                ]),
            // Trường nội dung là việc chính khi khai một Loại nên leo lên ngay dưới Thông tin;
            // hai khối dưới có mặc định dùng được ngay nên bị đẩy xuống đáy và thu gọn sẵn.
            Section::make('Trường nội dung')
                ->key('content-fields')
                ->description('Nội dung của mỗi Đơn vị hàng thuộc mọi Sản phẩm của Loại này.')
                ->schema([self::contentFields()]),
            Section::make('Chuẩn hoá Khoá chống trùng')
                ->key('normalization')
                ->description('Áp khi so trùng; nội dung giao khách vẫn là chuỗi gốc.')
                // Mặc định theo Dạng hàng đã dùng được ngay; khối đang mang giá trị khác mặc định
                // thì mở sẵn, để Sửa không giấu mất thứ mình từng đổi.
                // Đọc bản ghi chứ không đọc state sống: ô Dạng hàng là live(), nên closure đọc
                // state sẽ đóng sập khối ngay khi Quản trị vừa mở tay ra để sửa.
                ->collapsed(fn (?ProductType $record): bool => $record === null
                    || $record->normalization() == $record->form->defaultNormalization())
                ->columns(2)
                ->schema([
                    Toggle::make('case_insensitive')
                        ->label('Không phân biệt hoa thường')
                        ->default(true),
                    Toggle::make('strip_separators')
                        ->label('Bỏ gạch ngang và khoảng trắng bên trong')
                        ->default(false),
                ]),
            Section::make('Mẫu giao hàng')
                ->key('template')
                ->description('Mẫu mặc định cho mọi Sản phẩm của Loại. Sản phẩm ghi đè được mẫu riêng; đổi mẫu ở đây không đụng tới Sản phẩm đã ghi đè.')
                ->collapsed(fn (?ProductType $record): bool => blank($record?->delivery_template))
                ->schema([
                    Textarea::make('delivery_template')
                        ->hiddenLabel()
                        ->rows(6)
                        ->helperText(fn (Get $get): string => 'Biến: '.collect([
                            ...array_map(fn (array $field): string => (string) ($field['key'] ?? ''), array_values((array) $get('fields'))),
                            ...DeliveryTemplate::BUILT_IN,
                        ])->filter()->map(fn (string $name): string => "{{{$name}}}")->implode(', ').'. Hạn sử dụng, Hạn bảo hành hiện dạng ngày/tháng/năm; mã đơn là mã đơn ngoài của Phiếu xuất.')
                        ->placeholder("Cảm ơn bạn đã mua {{san_pham}} (đơn {{ma_don}})\nTài khoản: {{username}}\nBảo hành đến {{han_bao_hanh}}"),
                ]),
        ]);
    }

    /**
     * Repeater Trường nội dung: 4 cột mỗi dòng, nhãn ẩn vì card bao ngoài đã mang tên khối.
     */
    private static function contentFields(): Repeater
    {
        return Repeater::make('fields')
            ->hiddenLabel()
            ->helperText('Thứ tự ở đây là thứ tự cột khi dán danh sách lúc nhập hàng.')
            ->minItems(1)
            ->defaultItems(1)
            ->reorderable(false)
            ->columns(4)
            ->schema([
                TextInput::make('key')
                    ->label('Định danh')
                    ->helperText('Chữ thường không dấu, ví dụ username.')
                    ->required()
                    ->maxLength(64),
                TextInput::make('label')
                    ->label('Tên hiển thị')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->label('Kiểu')
                    ->options(collect(ContentFieldType::cases())->mapWithKeys(fn (ContentFieldType $type): array => [$type->value => $type->label()])->all())
                    ->default(ContentFieldType::Text->value)
                    ->required(),
                TextInput::make('pattern')
                    ->label('Regex')
                    ->helperText('Tuỳ chọn, phải khớp toàn bộ giá trị.')
                    ->maxLength(255),
                Toggle::make('required')
                    ->label('Bắt buộc')
                    ->default(true),
                Toggle::make('sensitive')
                    ->label('Nhạy cảm')
                    ->helperText('Mã hoá và che hoàn toàn.')
                    ->default(true),
                Toggle::make('dedupe_key')
                    ->label('Khoá chống trùng')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['products', 'contentFields']))
            ->columns([
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('form')
                    ->label('Dạng hàng')
                    ->badge()
                    ->formatStateUsing(fn (StockForm $state): string => $state->label()),
                TextColumn::make('content_fields_count')
                    ->label('Trường nội dung')
                    ->suffix(' trường'),
                TextColumn::make('products_count')
                    ->label('Sản phẩm')
                    ->suffix(' sản phẩm')
                    ->sortable(),
                TextColumn::make('discontinued_at')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (ProductType $record): string => $record->isDiscontinued() ? 'Ngừng dùng' : 'Đang dùng')
                    ->color(fn (ProductType $record): string => $record->isDiscontinued() ? 'gray' : 'success'),
            ])
            ->recordActions([
                EditAction::make(),
                self::discontinueAction(),
                self::deleteAction(),
            ]);
    }

    /**
     * Ngừng dùng đứng ở cả hàng của bảng lẫn header trang Sửa, như Ngừng bán của Sản phẩm.
     */
    public static function discontinueAction(): Action
    {
        return Action::make('discontinue')
            ->label('Ngừng dùng')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Loại bị ẩn khỏi ô chọn khi tạo Sản phẩm mới. Sản phẩm đang dùng Loại này không đổi gì.')
            ->visible(fn (ProductType $record): bool => InventoryAction::actor()->can('discontinue', $record))
            ->action(function (Action $action, ProductType $record, ProductTypeCatalog $catalog): void {
                InventoryAction::attempt($action, fn () => $catalog->discontinue(InventoryAction::actor(), $record));

                Notification::make()->success()->title('Đã Ngừng dùng Loại sản phẩm.')->send();
            });
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Chỉ xoá được Loại chưa Sản phẩm nào dùng.')
            // Filament đọc giá trị trả về làm cờ thành công, mà delete() trả về void: thiếu
            // `true` ở đây thì xoá xong vẫn hiện thông báo thất bại và không điều hướng.
            ->using(function (DeleteAction $action, ProductType $record, ProductTypeCatalog $catalog): bool {
                InventoryAction::attempt($action, fn () => $catalog->delete(InventoryAction::actor(), $record));

                return true;
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductTypes::route('/'),
            'create' => CreateProductType::route('/tao'),
            'edit' => EditProductType::route('/{record}/sua'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formData(ProductType $type): array
    {
        return [
            'blocking_products' => $type->stockedProducts()->orderBy('code')->pluck('code')->all(),
            'name' => $type->name,
            'form' => $type->form->value,
            'case_insensitive' => $type->case_insensitive,
            'strip_separators' => $type->strip_separators,
            'delivery_template' => $type->delivery_template,
            'fields' => $type->contentFields->map(fn (ContentField $field): array => [
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
    public static function draftFromForm(array $data): ProductTypeDraft
    {
        return new ProductTypeDraft(
            name: $data['name'],
            form: StockForm::from($data['form']),
            fields: array_values(array_map(fn (array $field): ContentFieldDraft => new ContentFieldDraft(
                key: $field['key'],
                label: $field['label'],
                type: ContentFieldType::from($field['type']),
                pattern: filled($field['pattern'] ?? null) ? $field['pattern'] : null,
                required: (bool) $field['required'],
                sensitive: (bool) $field['sensitive'],
                dedupeKey: (bool) $field['dedupe_key'],
            ), $data['fields'])),
            normalization: new Normalization((bool) $data['case_insensitive'], (bool) $data['strip_separators']),
            deliveryTemplate: $data['delivery_template'] ?? null,
        );
    }
}
