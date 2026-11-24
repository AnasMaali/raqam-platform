<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A destructive action, quiet until it is asked for (panel phase 3): its button is grey like any other, and red appears
 * only inside the confirmation, on the button that does it, under a heading that names what goes. No screen of the panel
 * draws a red button of its own (DestructiveActionsTest).
 */
final class Destructive
{
    /** Columns that name a record on screen, in the order they are tried. */
    private const NAMES = ['title', 'display_title', 'subject', 'question', 'name_ar', 'label_ar', 'name', 'contact_name', 'from_path', 'original_name', 'email', 'code'];

    public static function quiet(Action $action): Action
    {
        return $action->color('gray')->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedTrash)->modalIconColor('danger')
            // Filament hands closure arguments over by name: here `$action` is the confirmation's submit button (any
            // other name would get the destructive action itself, and its dialog's button would open the dialog again).
            ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
            // The red button says what it does («نقل إلى السلة», «حذف»), not «تأكيد».
            ->modalSubmitActionLabel(fn (Action $action) => $action->getLabel())
            ->modalHeading(fn (Action $action, ?Model $record = null) => self::heading(
                (string) $action->getLabel(),
                $action->isBulk() ? null : $record,
                $action->isBulk() && $action->canAccessSelectedRecords() ? collect($action->getSelectedRecords()) : null,
            ));
    }

    /** «حذف «الوسم»» for one record, «حذف المحدد (3)» for a selection, the label alone when nothing names the record. */
    public static function heading(string $label, ?Model $record = null, ?Collection $records = null): string
    {
        if ($records !== null) {
            return $label.' ('.$records->count().')';
        }
        $name = self::name($record);

        return $name === '' ? $label : $label.' «'.$name.'»';
    }

    public static function name(?Model $record): string
    {
        foreach (self::NAMES as $column) {
            $value = $record?->getAttribute($column);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_strimwidth(trim((string) $value), 0, 80, '…');
            }
        }

        return '';
    }
}
