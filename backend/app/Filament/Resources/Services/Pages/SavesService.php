<?php

namespace App\Filament\Resources\Services\Pages;

use App\Models\Service;
use App\Services\Money;
use App\Services\PriceRejected;
use App\Services\ServiceCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The create and edit pages save through ServiceCatalog; a refusal becomes a message under the field it is about. */
trait SavesService
{
    private function saveService(?Service $service, array $data): Service
    {
        try {
            return app(ServiceCatalog::class)->save($service, $data, auth()->id());
        } catch (PriceRejected $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?: 'name_ar') => $e->getMessage()]);
        }
    }

    /** The form's view of a service: amounts as typed money, the lists, and an offer only while it lasts. */
    public static function formData(Service $s): array
    {
        $live = $s->sale_ends_at !== null && $s->sale_ends_at > now();

        return [
            'price' => $s->price_amount === null ? null : Money::fromMinor($s->price_amount),
            'offer' => $live,
            'sale_price' => $live ? Money::fromMinor((int) $s->sale_price_amount) : null,
            'sale_ends_at' => $live ? $s->sale_ends_at->format('Y-m-d H:i:s') : null,
            'offer_expired' => ! $live && $s->sale_ends_at !== null ? $s->sale_ends_at->setTimezone('Asia/Hebron')->format('Y-m-d H:i') : null,
            'deliverables' => $s->deliverables(),
            'faqs' => DB::table('faqs')->where('service_id', $s->id)->orderBy('sort_order')->get(['question', 'answer', 'is_published'])
                ->map(fn ($f) => ['question' => $f->question, 'answer' => $f->answer, 'is_published' => (bool) $f->is_published])->all(),
        ];
    }
}
