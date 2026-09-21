<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomainBannerSetting extends Model
{
    use HasFactory;

    protected $table = 'domain_banner_settings';

    protected $fillable = [
        'shop_domain',
        'multi_domain_enabled',
        'domain_map',
    ];

    protected $casts = [
        'multi_domain_enabled' => 'boolean',
        'domain_map' => 'array',
    ];
}

