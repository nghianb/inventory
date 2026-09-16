<?php

namespace App\Filament\Resources\ApiKeys\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Api\IssuedApiKey;
use App\Models\ApiKey;
use App\Models\SalesChannel;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageApiKeys extends ManageRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo Khoá API')
                ->createAnother(false)
                ->using(function (CreateAction $action, array $data, ApiKeys $keys): ApiKey {
                    $issued = InventoryAction::attempt($action, fn (): IssuedApiKey => $keys->issue(
                        InventoryAction::actor(),
                        SalesChannel::query()->findOrFail($data['sales_channel_id']),
                        $data['label'] ?? null,
                    ));

                    ApiKeyResource::announce($issued);

                    return $issued->key;
                }),
        ];
    }
}
