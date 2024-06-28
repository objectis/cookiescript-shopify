<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsentSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_domain',
        'ad_storage',
        'analytics_storage',
        'ad_user_data',
        'ad_personalization',
        'functionality_storage',
        'personalization_storage',
        'security_storage',
        'wait_for_update'
    ];

    public function regionalConsents()
    {
        return $this->hasMany(RegionalConsent::class);
    }
}
