<?php

namespace App\Filament\Resources\AdminNotifications\Pages;

use App\Filament\Resources\AdminNotifications\AdminNotificationResource;
use App\Panel\AdminAlerts;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListAdminNotifications extends ListRecords
{
    protected static string $resource = AdminNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('readAll')->label('تعليم الكل كمقروء')->icon('heroicon-o-check-circle')
            ->action(fn () => AdminAlerts::markRead((int) Filament::auth()->id()))];
    }
}
