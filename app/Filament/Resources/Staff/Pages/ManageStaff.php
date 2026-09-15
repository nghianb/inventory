<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Inventory\Staff\StaffManager;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->createAnother(false)
                ->using(fn (array $data, StaffManager $staff): User => $staff->create(
                    StaffResource::actor(),
                    $data['name'],
                    $data['email'],
                    $data['password'],
                    StaffResource::rolesFromForm($data['roles']),
                )),
        ];
    }
}
