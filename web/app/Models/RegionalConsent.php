<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegionalConsent extends Model
{
    use HasFactory;

    protected $fillable = [
        'consent_setting_id',
        'region',
        'ad_storage',
        'analytics_storage',
        'ad_user_data',
        'ad_personalization',
        'functionality_storage',
        'personalization_storage',
        'security_storage',
        'wait_for_update'
    ];

    public function consentSetting()
    {
        return $this->belongsTo(ConsentSetting::class);
    }
}
