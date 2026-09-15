<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\SupplierDirectory;
use App\Models\Supplier;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->createAnother(false)
                ->using(fn (CreateAction $action, array $data, SupplierDirectory $suppliers): Supplier => InventoryAction::attempt(
                    $action,
                    fn () => $suppliers->create(InventoryAction::actor(), $data['name'], $data['note'] ?? null),
                )),
        ];
    }
}
