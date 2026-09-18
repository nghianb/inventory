<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Catalog\SupplierDirectory;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Nhà cung cấp trong panel. Adapter mỏng: mọi thao tác gọi SupplierDirectory.
 * Bán hàng không thấy trang này (SupplierPolicy).
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::NhapHang;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'nhà cung cấp';

    protected static ?string $pluralModelLabel = 'Nhà cung cấp';

    protected static ?string $navigationLabel = 'Nhà cung cấp';

    protected static ?string $slug = 'nha-cung-cap';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Tên')
                ->required()
                ->maxLength(255),
            Textarea::make('note')
                ->label('Ghi chú')
                ->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('note')
                    ->label('Ghi chú')
                    ->limit(80),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (EditAction $action, Supplier $record, array $data, SupplierDirectory $suppliers): Supplier => InventoryAction::attempt(
                        $action,
                        fn () => $suppliers->update(InventoryAction::actor(), $record, $data['name'], $data['note'] ?? null),
                    )),
                DeleteAction::make()
                    ->successNotificationTitle('Đã xoá Nhà cung cấp.')
                    // Filament đọc giá trị trả về làm cờ thành công, mà SupplierDirectory::delete()
                    // trả về void: thiếu `true` ở đây thì xoá xong vẫn hiện thông báo thất bại.
                    ->using(function (DeleteAction $action, Supplier $record, SupplierDirectory $suppliers): bool {
                        InventoryAction::attempt($action, fn () => $suppliers->delete(InventoryAction::actor(), $record));

                        return true;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
