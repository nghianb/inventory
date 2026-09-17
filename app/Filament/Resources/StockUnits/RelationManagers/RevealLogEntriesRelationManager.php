<?php

namespace App\Filament\Resources\StockUnits\RelationManagers;

use App\Filament\Resources\RevealLogEntries\RevealLogEntryResource;
use App\Filament\Support\InventoryAction;
use App\Models\RevealLogEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Nội dung Đơn vị hàng đã được xem bởi ai, khi nào. Chỉ Quản trị thấy.
 */
class RevealLogEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'revealLogEntries';

    protected static ?string $title = 'Đã được xem bởi';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return InventoryAction::actor()->can('viewAny', RevealLogEntry::class);
    }

    public function table(Table $table): Table
    {
        return RevealLogEntryResource::configureColumns($table);
    }
}
