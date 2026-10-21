<?php

namespace App\Models;

use App\Audit\Audited;
use App\Models\Concerns\HasSeoMeta;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A service of the services and prices page (decision 14): name, short and full description, what it includes, picture,
 * order, shown or hidden, and its price (amount in the currency's smallest unit, mode, note) with an optional special offer
 * that ends by itself. Saved through App\Services\ServiceCatalog. Every price change is audited from → to (14.12).
 */
class Service extends Model
{
    use Audited { auditChanges as baseAuditChanges; }
    use HasSeoMeta, SoftDeletes;

    public const MODES = ['fixed' => 'سعر ثابت', 'from' => 'يبدأ من', 'on_request' => 'السعر عند التواصل'];

    /** The currencies a service may be priced in (14.2). */
    public const CURRENCIES = ['ILS' => 'شيكل', 'USD' => 'دولار'];

    private const PRICE_FIELDS = ['price_mode', 'price_amount', 'currency_code', 'price_note', 'sale_price_amount', 'sale_ends_at'];

    protected $fillable = ['group_id', 'section_id', 'name_ar', 'slug', 'summary', 'description', 'cover_media_id', 'price_mode', 'price_amount',
        'currency_code', 'price_note', 'sale_price_amount', 'sale_ends_at', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['price_amount' => 'integer', 'sale_price_amount' => 'integer', 'sale_ends_at' => 'immutable_datetime', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function group()
    {
        return $this->belongsTo(ServiceGroup::class, 'group_id');
    }

    /** @return list<string> */
    public function deliverables(): array
    {
        return $this->exists ? DB::table('service_deliverables')->where('service_id', $this->id)->orderBy('sort_order')->pluck('label')->all() : [];
    }

    /** The price as one readable line, for the audit log: "16.12 ILS (يبدأ من) — عرض 12.00 حتى 2026-11-01 10:00". */
    public static function priceLine(array $a): ?string
    {
        if (($a['price_mode'] ?? null) === null) {
            return null;
        }
        if ($a['price_mode'] === 'on_request') {
            return self::MODES['on_request'];
        }
        $line = Money::fromMinor((int) $a['price_amount']).' '.trim((string) $a['currency_code']).' ('.self::MODES[$a['price_mode']].')';
        if (filled($a['price_note'] ?? null)) {
            $line .= ' '.$a['price_note'];
        }
        if (($a['sale_price_amount'] ?? null) !== null) {
            $ends = CarbonImmutable::parse($a['sale_ends_at'] instanceof \DateTimeInterface ? $a['sale_ends_at']->format('Y-m-d H:i:sP') : (string) $a['sale_ends_at'], 'UTC');
            $line .= ' — عرض '.Money::fromMinor((int) $a['sale_price_amount']).' حتى '.$ends->setTimezone('Asia/Hebron')->format('Y-m-d H:i');
        }

        return $line;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> the changed fields, and the price before and after in words when it changed */
    protected function auditChanges(string $event): array
    {
        $changes = $this->baseAuditChanges($event);
        $touched = array_intersect(array_keys($changes), self::PRICE_FIELDS);
        if ($touched === [] || $event === 'delete') {
            return $changes;
        }
        $before = $event === 'create' ? null : self::priceLine(array_intersect_key($this->getRawOriginal(), array_flip(self::PRICE_FIELDS)));
        $after = self::priceLine($this->getAttributes());

        return ['price' => [$before, $after]] + $changes;
    }
}
