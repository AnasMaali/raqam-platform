<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Sections\SectionResource;
use Filament\Resources\Pages\EditRecord;

class EditSection extends EditRecord
{
    use ReportsDatabaseRefusals, SavesSeoMeta;

    protected static string $resource = SectionResource::class;
}
