<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Services\PostRuleViolation;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait ReportsPostRuleViolations
{
    /** Runs a save; a refused rule shows under its field, or as a notification when no field owns it. */
    private function reportingViolations(Closure $save): mixed
    {
        try {
            return $save();
        } catch (PostRuleViolation $violation) {
            if ($violation->field) {
                throw ValidationException::withMessages(["data.{$violation->field}" => $violation->getMessage()]);
            }

            Notification::make()->danger()->title('تعذّر الحفظ')->body($violation->getMessage())->send();
            $this->halt();
        }
    }
}
