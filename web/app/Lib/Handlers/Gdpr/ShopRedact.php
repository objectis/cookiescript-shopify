<?php

declare(strict_types=1);

namespace App\Lib\Handlers\Gdpr;

use App\Models\ConsentSetting;
use App\Models\DomainBannerSetting;
use App\Models\RegionalConsent;
use App\Models\ScriptSrc;
use App\Models\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;

/**
 * 48 hours after a store owner uninstalls your app, Shopify invokes this
 * webhook. All data stored for the shop must be deleted.
 *
 * https://shopify.dev/docs/apps/webhooks/configuration/mandatory-webhooks#shop-redact
 */
class ShopRedact implements Handler
{
    public function handle(string $topic, string $shop, array $body): void
    {
        Log::debug("Handling GDPR shop redaction request for $shop");
        // Payload has the following shape:
        // {
        //   "shop_id": 954889,
        //   "shop_domain": "{shop}.myshopify.com"
        // }

        DB::transaction(function () use ($shop) {
            $consentSettingIds = ConsentSetting::where('shop_domain', $shop)->pluck('id');

            if ($consentSettingIds->isNotEmpty()) {
                RegionalConsent::whereIn('consent_setting_id', $consentSettingIds)->delete();
            }

            ConsentSetting::where('shop_domain', $shop)->delete();
            DomainBannerSetting::where('shop_domain', $shop)->delete();
            ScriptSrc::where('shop_domain', $shop)->delete();
            Session::where('shop', $shop)->delete();
        });
    }
}
