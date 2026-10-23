<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Mail\EmailTemplates;
use Filament\Resources\Pages\ListRecords;

class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    /** Every message of the catalogue is listed, even before anything sent it. */
    public function mount(): void
    {
        EmailTemplates::install();
        parent::mount();
    }
}
