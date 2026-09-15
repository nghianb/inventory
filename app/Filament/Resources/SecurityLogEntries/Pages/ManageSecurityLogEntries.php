<?php

namespace App\Filament\Resources\SecurityLogEntries\Pages;

use App\Filament\Resources\SecurityLogEntries\SecurityLogEntryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSecurityLogEntries extends ManageRecords
{
    protected static string $resource = SecurityLogEntryResource::class;
}
