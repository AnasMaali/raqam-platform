<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** Whoever pays for campaigns: a company or body (organizations) with a contact person (decision 13.3). */
class Advertiser extends Model
{
    use Audited;

    protected $fillable = ['organization_id', 'contact_name', 'contact_phone', 'contact_email', 'notes'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function campaigns()
    {
        return $this->hasMany(AdCampaign::class);
    }
}
