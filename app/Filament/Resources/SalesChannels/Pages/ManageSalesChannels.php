<?php

namespace App\Filament\Resources\SalesChannels\Pages;

use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Models\SalesChannel;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSalesChannels extends ManageRecords
{
    protected static string $resource = SalesChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->createAnother(false)
                ->using(fn (CreateAction $action, array $data, SalesChannelDirectory $channels): SalesChannel => InventoryAction::attempt(
                    $action,
                    fn () => $channels->create(InventoryAction::actor(), SalesChannelResource::draftFromForm($data)),
                )),
        ];
    }
}
