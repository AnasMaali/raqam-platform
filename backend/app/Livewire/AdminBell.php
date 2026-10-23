<?php

namespace App\Livewire;

use App\Panel\AdminAlerts;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The bell of the panel (20.10): the admin's unread alerts and the newest ones. It is drawn with each page and changes
 * only when the admin clicks it; it never asks the server on its own (no polling in the admin, decision of phase 1).
 */
class AdminBell extends Component
{
    public function open(int $id): mixed
    {
        $userId = (int) Filament::auth()->id();
        $row = collect(AdminAlerts::latest($userId, 50))->firstWhere('id', $id);
        AdminAlerts::markRead($userId, $id);

        $url = $row ? self::url($row->action_path) : null;

        return $url ? $this->redirect($url) : null;
    }

    public function readAll(): void
    {
        AdminAlerts::markRead((int) Filament::auth()->id());
    }

    /** A path inside the panel, or nothing: an alert never leads out of it. */
    public static function url(?string $path): ?string
    {
        if ($path === null || preg_match('~^[a-z0-9][a-z0-9\-/]*$~', $path) !== 1 || str_contains($path, '//')) {
            return null;
        }

        return url(Filament::getPanel('admin')->getPath().'/'.$path);
    }

    public function render(): View
    {
        $userId = (int) Filament::auth()->id();

        return view('livewire.admin-bell', [
            'unread' => AdminAlerts::unreadCount($userId),
            'items' => AdminAlerts::latest($userId),
            'categories' => AdminAlerts::CATEGORIES,
            'allUrl' => url(Filament::getPanel('admin')->getPath().'/notifications'),
        ]);
    }
}
