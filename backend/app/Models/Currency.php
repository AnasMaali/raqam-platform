<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** An ISO 4217 currency (reference data): its Arabic name and symbol. */
class Currency extends Model
{
    use Audited;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'name_ar', 'symbol', 'minor_units'];

    /** @return array<string, array{0: mixed, 1: mixed}> */
    protected function auditChanges(string $event): array
    {
        $changes = $event === 'update'
            ? collect($this->getChanges())->mapWithKeys(fn ($new, $field) => [$field => [$this->getOriginal($field), $new]])->all()
            : collect($this->getAttributes())->map(fn ($v) => [null, $v])->all();

        return $changes === [] ? [] : ['code' => [null, trim($this->code)]] + $changes;
    }
}
