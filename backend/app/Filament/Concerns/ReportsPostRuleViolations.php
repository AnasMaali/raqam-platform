<?php

namespace App\Filament\Concerns;

use App\Content\PostRuleViolation;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/** A save the content rules refuse shows under the field it is about, or as a notification when no field owns it. */
trait ReportsPostRuleViolations
{
    private function reportingViolations(Closure $save): mixed
    {
        try {
            return $save();
        } catch (PostRuleViolation $violation) {
            if ($violation->field) {
                throw ValidationException::withMessages(["data.{$violation->field}" => $violation->getMessage()]);
            }

            Notification::make()->danger()->persistent()->title('تعذّر الحفظ')->body($violation->getMessage())->send();
            $this->halt();
        }
    }
}
