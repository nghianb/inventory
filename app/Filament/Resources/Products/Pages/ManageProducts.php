<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\ProductCatalog;
use App\Models\Product;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProducts extends ManageRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->createAnother(false)
                ->using(fn (CreateAction $action, array $data, ProductCatalog $catalog): Product => InventoryAction::attempt(
                    $action,
                    fn () => $catalog->create(InventoryAction::actor(), ProductResource::draftFromForm($data)),
                )),
        ];
    }
}
